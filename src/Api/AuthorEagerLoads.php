<?php

namespace Ernestdefoe\DiscussionParticipants\Api;

use Flarum\Api\Endpoint\Endpoint;

/**
 * Whatever the endpoint eager loads for a discussion's author, loaded for the
 * strip's users too.
 *
 * The strip sends real `users` resources, and a user resource is not free to
 * serialize: core's own fields read `groups` (an admin viewer's
 * `editCredentials` check calls isAdmin() on every user), and extensions hang
 * more on it — fof/badges, social groups, and so on. Each of them eager loads
 * its relation under the paths it knows about (`user.`, `lastPostedUser.`),
 * never under ours, so without this every avatar in the strip that is not also
 * the starter or last poster cost a query per relation: 7 strip users on a
 * page of 20 rows ran 14 extra queries on the demo forum.
 *
 * Mirroring the `user.*` loads rather than naming relations keeps this right
 * for extensions that do not exist yet. The list is read when the request is
 * served, so loads registered by extensions booted after this one are seen.
 */
class AuthorEagerLoads
{
    public static function for(Endpoint $endpoint): array
    {
        $loads = ['participantUsers.groups'];

        try {
            // `loadRelations` is protected on HasEagerLoading; read it in the
            // endpoint's own scope. If core ever renames it, the strip just
            // falls back to loading groups.
            $registered = (fn () => get_object_vars($this)['loadRelations'] ?? [])->call($endpoint);
        } catch (\Throwable) {
            $registered = [];
        }

        foreach ((array) $registered as $relation) {
            if (is_string($relation) && str_starts_with($relation, 'user.')) {
                $loads[] = 'participantUsers.'.substr($relation, 5);
            }
        }

        return array_values(array_unique($loads));
    }
}
