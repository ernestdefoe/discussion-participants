<?php

namespace Ernestdefoe\DiscussionParticipants\Api\Controller;

use Ernestdefoe\DiscussionParticipants\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/discussion-participants/{id} — one page of the full participant
 * list, behind the strip's overflow badge.
 *
 * Unlike the strip this always includes the person who started the
 * discussion: the modal is the complete answer to "who is in here", so
 * leaving them out would make the total look wrong.
 */
class ListParticipantsController implements RequestHandlerInterface
{
    public const PER_PAGE = 10;

    public function __construct(
        protected Settings $settings,
        protected SlugManager $slugs,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $id = (int) Arr::get($params, 'id', 0);

        $discussion = Discussion::whereVisibleTo($actor)->find($id);

        if (! $discussion) {
            throw new ModelNotFoundException();
        }

        $limit = max(1, min(50, (int) Arr::get($params, 'limit', self::PER_PAGE)));
        $offset = max(0, (int) Arr::get($params, 'offset', 0));

        $query = User::query()
            ->join('discussion_participants', 'discussion_participants.user_id', '=', 'users.id')
            ->where('discussion_participants.discussion_id', $discussion->id)
            ->select('users.*', 'discussion_participants.post_count');

        $total = (clone $query)->count();

        // Ordering only — the strip's original-poster rule is deliberately
        // not applied here, so the modal reads as the full roster.
        foreach ($this->settings->orderColumns() as [$column, $direction]) {
            $query->orderBy('discussion_participants.'.$column, $direction);
        }

        $users = $query->skip($offset)->take($limit)->get();
        $opId = (int) $discussion->user_id;

        return new JsonResponse([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'participants' => $users->map(fn (User $user) => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'displayName' => (string) $user->display_name,
                'slug' => $this->slugs->forResource(User::class)->toSlug($user),
                'avatarUrl' => $user->avatar_url ?: null,
                'posts' => (int) $user->getAttribute('post_count'),
                'isOp' => (int) $user->id === $opId,
            ])->all(),
        ]);
    }
}
