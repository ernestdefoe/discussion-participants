<?php

namespace Ernestdefoe\DiscussionParticipants;

use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Keeps `discussion_participants` in step with the posts table.
 *
 * Every write path recomputes from `posts` rather than incrementing a
 * counter. A hide, an unhide, a hard delete and a hide-then-delete all
 * converge on the same answer, and a missed event self-heals on the next
 * post — which an increment/decrement pair cannot promise.
 *
 * Each public method is one transaction. The participant rows and the meta
 * row holding the tallies are two tables that must agree: without a
 * transaction, an exception or a crash between the two writes leaves a
 * participant with no meta row, or a meta row carrying a count that no
 * longer matches. The queries are all indexed and short, so the cost of
 * wrapping them is nil next to the cost of the two disagreeing.
 */
class ParticipantSynchronizer
{
    /**
     * Recompute one (discussion, user) pair. Cheap enough to call on every
     * post event: it is a single indexed aggregate over the discussion.
     */
    public function syncPair(int $discussionId, ?int $userId): void
    {
        if (! $discussionId || ! $userId) {
            return;
        }

        $this->connection()->transaction(function () use ($discussionId, $userId) {
            $agg = $this->visiblePosts()
                ->where('discussion_id', $discussionId)
                ->where('user_id', $userId)
                ->selectRaw('COUNT(*) as posts, MIN(created_at) as first_at, MAX(created_at) as last_at')
                ->first();

            $rows = (int) ($agg->posts ?? 0);

            $row = DiscussionParticipant::query()
                ->where('discussion_id', $discussionId)
                ->where('user_id', $userId);

            $existed = $row->exists();

            if ($rows === 0) {
                if ($existed) {
                    $row->delete();
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
                $row->update($values);

                return;
            }

            DiscussionParticipant::query()->insertOrIgnore($values + [
                'discussion_id' => $discussionId,
                'user_id' => $userId,
            ]);

            $this->syncCount($discussionId);
        });
    }

    /** Rebuild every participant row for one discussion from scratch. */
    public function rebuild(int $discussionId): void
    {
        $this->connection()->transaction(function () use ($discussionId) {
            DiscussionParticipant::query()->where('discussion_id', $discussionId)->delete();

            DiscussionParticipant::query()->insertUsing(
                ['discussion_id', 'user_id', 'post_count', 'first_post_at', 'last_post_at'],
                $this->aggregateQuery()->where('discussion_id', $discussionId)
            );

            $this->syncCount($discussionId);
        });
    }

    /**
     * Rebuild a contiguous block of discussion ids in bulk. Used by the
     * backfill command and the admin Recalculate button, which walk the
     * table in chunks so a large forum never loads it all at once.
     */
    public function rebuildRange(int $fromId, int $toId): void
    {
        $this->connection()->transaction(function () use ($fromId, $toId) {
            DiscussionParticipant::query()
                ->whereBetween('discussion_id', [$fromId, $toId])->delete();

            DiscussionParticipant::query()->insertUsing(
                ['discussion_id', 'user_id', 'post_count', 'first_post_at', 'last_post_at'],
                $this->aggregateQuery()->whereBetween('discussion_id', [$fromId, $toId])
            );

            ParticipantMeta::query()
                ->whereBetween('discussion_id', [$fromId, $toId])->delete();

            ParticipantMeta::query()->insertUsing(
                ['discussion_id', 'participant_count', 'replier_count'],
                $this->countQuery()->whereBetween('dp.discussion_id', [$fromId, $toId])
            );
        });
    }

    /** ---- internals ------------------------------------------------- */

    /**
     * A transaction is a connection-level concern that no model expresses, so
     * this is the one place the connection itself is needed. Taken from the
     * model rather than injected, so that every table this class touches is
     * still reached through the model layer.
     */
    protected function connection(): ConnectionInterface
    {
        return DiscussionParticipant::query()->getConnection();
    }

    protected function syncCount(int $discussionId): void
    {
        $counts = $this->countQuery()->where('dp.discussion_id', $discussionId)->first();
        $total = (int) ($counts->total ?? 0);

        if ($total === 0) {
            ParticipantMeta::query()->where('discussion_id', $discussionId)->delete();

            return;
        }

        ParticipantMeta::query()->updateOrInsert(
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
        /*
         * 🚨 Laravel prefixes the ALIAS as well as the table.
         *
         * `from('discussion_participants as dp')` is wrapped by
         * Grammar::wrapTable(), which calls wrap($table, prefixAlias: true), so
         * on a forum with the prefix `xf_` the SQL reads
         * `xf_discussion_participants as xf_dp` — the alias is `xf_dp`, not `dp`.
         *
         * Builder references survive that: groupBy('dp.discussion_id') and
         * where('dp.…') go through wrapSegments(), which wrapTable()s the first
         * segment and lands on `xf_dp` too. RAW SQL does not — selectRaw() is
         * passed through verbatim — so this query asked for `dp.discussion_id`
         * against a table aliased `xf_dp` and MySQL answered
         * "Unknown column 'dp.discussion_id'".
         *
         * That took out discussion creation entirely on a prefixed forum: the
         * synchroniser runs on every new post. getTablePrefix() is '' where
         * there is no prefix, so this is byte-identical there.
         */
        $prefix = $this->connection()->getTablePrefix();
        $dp = $prefix.'dp';
        $d = $prefix.'d';

        return DiscussionParticipant::query()
            ->from('discussion_participants as dp')
            ->join('discussions as d', 'd.id', '=', 'dp.discussion_id')
            ->groupBy('dp.discussion_id')
            ->selectRaw(
                $dp.'.discussion_id, COUNT(*) as total, '
                .'SUM(CASE WHEN '.$dp.'.user_id <> COALESCE('.$d.'.user_id, 0) THEN 1 ELSE 0 END) as repliers'
            )
            ->toBase();
    }

    /**
     * Posts that count towards participation: visible public comments with
     * a real author. Matches what core's own participants() query means by
     * "participant", minus the hidden ones a moderator has taken down.
     *
     * Read through the Post model rather than a hand-held connection. That
     * pulls in core's RegisteredTypesScope, which appends `type IN (…every
     * registered type…)`. Redundant next to the explicit `type = 'comment'`
     * above and it cannot change the result — verified against a real forum,
     * where both spellings return byte-identical rows — but worth knowing it
     * is in the generated SQL if you ever read an EXPLAIN of this query.
     */
    protected function visiblePosts(): Builder
    {
        return Post::query()
            ->where('type', 'comment')
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->whereNotNull('user_id')
            ->toBase();
    }

    protected function aggregateQuery(): Builder
    {
        return $this->visiblePosts()
            ->groupBy('discussion_id', 'user_id')
            ->selectRaw('discussion_id, user_id, COUNT(*), MIN(created_at), MAX(created_at)');
    }
}
