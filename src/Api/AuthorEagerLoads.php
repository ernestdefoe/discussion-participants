<?php

namespace Ernestdefoe\DiscussionParticipants\Api;

use Tobyz\JsonApiServer\Context;
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
 *
 * Read through core's public HasEagerLoading::getEagerLoadsFor(), the same
 * call core's EloquentBuffer makes, so it covers plain string loads AND
 * callable ones (eagerLoadWhenIncluded). That call compiles every registered
 * load, ours included, so the guard stops it recursing back into itself.
 */
class AuthorEagerLoads
{
    private static bool $resolving = false;

    public static function for(Endpoint $endpoint, Context $context): array
    {
        if (self::$resolving) {
            return [];
        }

        $loads = ['participantUsers.groups'];

        self::$resolving = true;

        try {
            $registered = $endpoint->getEagerLoadsFor('user', $context);
        } finally {
            self::$resolving = false;
        }

        foreach ($registered as $relation) {
            $loads[] = 'participantUsers.'.$relation;
        }

        return array_values(array_unique($loads));
    }
}
