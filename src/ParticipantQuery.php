<?php

namespace Ernestdefoe\DiscussionParticipants;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The avatar strip, expressed as a relation on Discussion.
 *
 * Defining it as a relation rather than a hand-rolled attribute is what
 * keeps the discussion list at a fixed query count: Eloquent batches the
 * eager load across every row on the page and applies the per-discussion
 * limit with a window function, so twenty discussions cost one query, not
 * twenty. It also means the frontend receives genuine `users` resources,
 * so core's own Avatar and UserCard components work on them untouched.
 */
class ParticipantQuery
{
    public function __construct(protected Settings $settings)
    {
    }

    public function relation(Discussion $discussion): BelongsToMany
    {
        $relation = $discussion
            ->belongsToMany(
                User::class,
                'discussion_participants',
                'discussion_id',
                'user_id',
                'id',
                'id',
                'participantUsers'
            )
            ->withPivot('post_count', 'first_post_at', 'last_post_at');

        $this->constrain($relation);

        return $relation->limit($this->settings->stripSize());
    }

    /**
     * Ordering and the original-poster rule, applied to a query over the
     * pivot table. Shared with the participants modal so the strip and the
     * first page of the modal can never fall out of order with each other.
     */
    public function constrain(Builder|BelongsToMany $query): void
    {
        $mode = $this->settings->includeOp();

        if ($mode !== Settings::OP_ALWAYS) {
            // The discussion's own row is the only place the starter's id
            // lives, so join it rather than correlating a subquery per row.
            $query->join('discussions as dp_discussions', 'dp_discussions.id', '=', 'discussion_participants.discussion_id');

            // COALESCE, not a plain column comparison: a discussion whose
            // starter has since been deleted has a NULL user_id, and
            // `user_id != NULL` is NULL, which would silently drop every
            // participant on that discussion.
            /*
             * 🚨 Both halves of this raw fragment need the table prefix, and
             * for two different reasons. `discussion_participants` is a plain
             * table name, which raw SQL never prefixes. `dp_discussions` is an
             * ALIAS, and Laravel prefixes aliases too — the join above really
             * emits `xf_discussions as xf_dp_discussions` — so the raw text has
             * to spell the alias the way the grammar will. Empty prefix leaves
             * this string exactly as it was.
             */
            $prefix = (new Discussion())->getConnection()->getTablePrefix();

            $notTheStarter = $prefix.'discussion_participants.user_id <> COALESCE('
                .$prefix.'dp_discussions.user_id, 0)';

            if ($mode === Settings::OP_NEVER) {
                $query->whereRaw($notTheStarter);
            } else {
                // "If they replied": the starter earns a place only once
                // they have posted beyond the post that opened it.
                $query->where(function (Builder|BelongsToMany $q) use ($notTheStarter) {
                    $q->whereRaw($notTheStarter)
                        ->orWhere('discussion_participants.post_count', '>', 1);
                });
            }
        }

        foreach ($this->settings->orderColumns() as [$column, $direction]) {
            $query->orderBy('discussion_participants.'.$column, $direction);
        }
    }

    /**
     * How many participants the strip is not showing.
     *
     * Counted against repliers, never the full total: the person who
     * started the discussion already has their avatar on the row, in
     * core's own author column, so counting them as "hidden" would
     * overstate the badge by one on almost every discussion.
     */
    public function overflow(Discussion $discussion): int
    {
        if (! $this->settings->showOverflow() || $this->belowThreshold($discussion)) {
            return 0;
        }

        $opId = (int) $discussion->user_id;
        $shown = $discussion->participantUsers
            ->filter(fn (User $user) => (int) $user->id !== $opId)
            ->count();

        return max(0, $this->replierCount($discussion) - $shown);
    }

    public function total(Discussion $discussion): int
    {
        return (int) ($this->meta($discussion)?->participant_count ?? 0);
    }

    public function replierCount(Discussion $discussion): int
    {
        return (int) ($this->meta($discussion)?->replier_count ?? 0);
    }

    /** True when this discussion has too few participants to be worth a strip. */
    public function belowThreshold(Discussion $discussion): bool
    {
        return $this->replierCount($discussion) < $this->settings->minParticipants();
    }

    /**
     * Loading this lazily is safe, and necessary.
     *
     * The discussion list is the only endpoint that serializes many
     * discussions at once, and it eager loads both relations — so a lazy
     * load here can only ever happen on an endpoint holding a single
     * discussion. That includes the response to posting a reply, where
     * this is what lets the poster's avatar appear without a refresh.
     */
    protected function meta(Discussion $discussion): ?ParticipantMeta
    {
        return $discussion->participantMeta;
    }
}
