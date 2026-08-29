<?php

namespace Ernestdefoe\DiscussionParticipants;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Keeps `discussion_participants` in step with the posts table.
 *
 * Every write path recomputes from `posts` rather than incrementing a
 * counter. A hide, an unhide, a hard delete and a hide-then-delete all
 * converge on the same answer, and a missed event self-heals on the next
 * post — which an increment/decrement pair cannot promise.
 */
class ParticipantSynchronizer
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * Recompute one (discussion, user) pair. Cheap enough to call on every
     * post event: it is a single indexed aggregate over the discussion.
     */
    public function syncPair(int $discussionId, ?int $userId): void
    {
        if (! $discussionId || ! $userId) {
            return;
        }

        $agg = $this->visiblePosts()
            ->where('discussion_id', $discussionId)
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as posts, MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->first();

        $rows = (int) ($agg->posts ?? 0);
        $table = $this->db->table('discussion_participants')
            ->where('discussion_id', $discussionId)
            ->where('user_id', $userId);

        $existed = $table->exists();

        if ($rows === 0) {
            if ($existed) {
                $table->delete();
                $this->syncCount($discussionId);
            }

            return;
        }

        $values = [
            'post_count' => $rows,
            'first_post_at' => $agg->first_at,
            'last_post_at' => $agg->last_at,
        ];

        if ($existed) {
            $table->update($values);

            return;
        }

        $this->db->table('discussion_participants')->insertOrIgnore($values + [
            'discussion_id' => $discussionId,
            'user_id' => $userId,
        ]);

        $this->syncCount($discussionId);
    }

    /** Rebuild every participant row for one discussion from scratch. */
    public function rebuild(int $discussionId): void
    {
        $this->db->table('discussion_participants')->where('discussion_id', $discussionId)->delete();

        $this->db->table('discussion_participants')
            ->insertUsing(
                ['discussion_id', 'user_id', 'post_count', 'first_post_at', 'last_post_at'],
                $this->aggregateQuery()->where('discussion_id', $discussionId)
            );

        $this->syncCount($discussionId);
    }

    /**
     * Rebuild a contiguous block of discussion ids in bulk. Used by the
     * backfill command and the admin Recalculate button, which walk the
     * table in chunks so a large forum never loads it all at once.
     */
    public function rebuildRange(int $fromId, int $toId): void
    {
        $this->db->table('discussion_participants')
            ->whereBetween('discussion_id', [$fromId, $toId])->delete();

        $this->db->table('discussion_participants')
            ->insertUsing(
                ['discussion_id', 'user_id', 'post_count', 'first_post_at', 'last_post_at'],
                $this->aggregateQuery()->whereBetween('discussion_id', [$fromId, $toId])
            );

        $this->db->table('discussion_participant_meta')
            ->whereBetween('discussion_id', [$fromId, $toId])->delete();

        $this->db->table('discussion_participant_meta')
            ->insertUsing(
                ['discussion_id', 'participant_count', 'replier_count'],
                $this->countQuery()->whereBetween('dp.discussion_id', [$fromId, $toId])
            );
    }

    /** ---- internals ------------------------------------------------- */

    protected function syncCount(int $discussionId): void
    {
        $counts = $this->countQuery()->where('dp.discussion_id', $discussionId)->first();
        $total = (int) ($counts->total ?? 0);

        if ($total === 0) {
            $this->db->table('discussion_participant_meta')->where('discussion_id', $discussionId)->delete();

            return;
        }

        $this->db->table('discussion_participant_meta')->updateOrInsert(
            ['discussion_id' => $discussionId],
            [
                'participant_count' => $total,
                'replier_count' => (int) ($counts->repliers ?? 0),
            ]
        );
    }

    /**
     * Both tallies in one pass.
     *
     * COALESCE rather than a plain `<>`: when the person who started a
     * discussion has been deleted their id is NULL, and comparing against
     * NULL yields NULL, which would score every replier as zero.
     */
    protected function countQuery(): Builder
    {
        return $this->db->table('discussion_participants as dp')
            ->join('discussions as d', 'd.id', '=', 'dp.discussion_id')
            ->groupBy('dp.discussion_id')
            ->selectRaw(
                'dp.discussion_id, COUNT(*) as total, '
                .'SUM(CASE WHEN dp.user_id <> COALESCE(d.user_id, 0) THEN 1 ELSE 0 END) as repliers'
            );
    }

    /**
     * Posts that count towards participation: visible public comments with
     * a real author. Matches what core's own participants() query means by
     * "participant", minus the hidden ones a moderator has taken down.
     */
    protected function visiblePosts(): Builder
    {
        return $this->db->table('posts')
            ->where('type', 'comment')
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->whereNotNull('user_id');
    }

    protected function aggregateQuery(): Builder
    {
        return $this->visiblePosts()
            ->groupBy('discussion_id', 'user_id')
            ->selectRaw('discussion_id, user_id, COUNT(*), MIN(created_at), MAX(created_at)');
    }
}
