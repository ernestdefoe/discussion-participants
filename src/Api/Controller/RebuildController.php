<?php

namespace Ernestdefoe\DiscussionParticipants\Api\Controller;

use Ernestdefoe\DiscussionParticipants\Console\PopulateCommand;
use Ernestdefoe\DiscussionParticipants\DiscussionParticipant;
use Ernestdefoe\DiscussionParticipants\ParticipantSynchronizer;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/discussion-participants/rebuild — one chunk of the backfill.
 *
 * The admin button walks the forum a chunk per request rather than doing
 * it all in one: a forum large enough to need backfilling is exactly the
 * one whose PHP process would time out doing it in a single call.
 */
class RebuildController implements RequestHandlerInterface
{
    public function __construct(
        protected ParticipantSynchronizer $sync,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $from = max(1, (int) Arr::get($body, 'from', 1));
        $chunk = max(1, min(2000, (int) Arr::get($body, 'chunk', PopulateCommand::CHUNK)));

        $max = (int) Discussion::query()->max('id');

        if ($max === 0) {
            return new JsonResponse(['done' => true, 'next' => null, 'max' => 0, 'records' => 0]);
        }

        $to = min($from + $chunk - 1, $max);
        $this->sync->rebuildRange($from, $to);

        $done = $to >= $max;

        return new JsonResponse([
            'done' => $done,
            'next' => $done ? null : $to + 1,
            'max' => $max,
            'processed' => $to,
            'records' => DiscussionParticipant::query()->count(),
        ]);
    }
}
