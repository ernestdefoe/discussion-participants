<?php

namespace Ernestdefoe\DiscussionParticipants\Listener;

use Ernestdefoe\DiscussionParticipants\ParticipantSynchronizer;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Every event that can change who counts as a participant funnels through
 * one recompute, so hiding, restoring and deleting cannot drift apart.
 */
class SyncParticipants
{
    public function __construct(protected ParticipantSynchronizer $sync)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, $this->handler(...));
        $events->listen(Hidden::class, $this->handler(...));
        $events->listen(Restored::class, $this->handler(...));
        $events->listen(Deleted::class, $this->handler(...));
    }

    public function handler(Posted|Hidden|Restored|Deleted $event): void
    {
        $this->apply($event->post);
    }

    protected function apply(Post $post): void
    {
        $this->sync->syncPair((int) $post->discussion_id, $post->user_id ? (int) $post->user_id : null);

        // The reply that triggered this is serialized in the same request,
        // and its response carries the discussion. Dropping the loaded
        // relations forces them to reload with the row we just wrote — the
        // difference between an avatar appearing at once and only after a
        // refresh.
        if ($post->relationLoaded('discussion') && $post->discussion) {
            $post->discussion->unsetRelation('participantUsers');
            $post->discussion->unsetRelation('participantMeta');
        }
    }
}
