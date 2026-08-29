<?php

namespace Ernestdefoe\DiscussionParticipants\Api\Controller;

use Ernestdefoe\DiscussionParticipants\Console\PopulateCommand;
use Ernestdefoe\DiscussionParticipants\ParticipantSynchronizer;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
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
        protected ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $from = max(1, (int) Arr::get($body, 'from', 1));
        $chunk = max(1, min(2000, (int) Arr::get($body, 'chunk', PopulateCommand::CHUNK)));

        $max = (int) $this->db->table('discussions')->max('id');

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
            'records' => (int) $this->db->table('discussion_participants')->count(),
        ]);
    }
}
