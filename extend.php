<?php

use Ernestdefoe\DiscussionParticipants\Api\AuthorEagerLoads;
use Ernestdefoe\DiscussionParticipants\Api\Controller\ListParticipantsController;
use Ernestdefoe\DiscussionParticipants\Api\Controller\RebuildController;
use Ernestdefoe\DiscussionParticipants\Console\PopulateCommand;
use Ernestdefoe\DiscussionParticipants\Listener\SyncParticipants;
use Ernestdefoe\DiscussionParticipants\ParticipantMeta;
use Ernestdefoe\DiscussionParticipants\ParticipantQuery;
use Ernestdefoe\DiscussionParticipants\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/discussion-participants/{id}', 'discussion-participants.list', ListParticipantsController::class)
        ->post('/discussion-participants/rebuild', 'discussion-participants.rebuild', RebuildController::class),

    (new Extend\Console())
        ->command(PopulateCommand::class),

    (new Extend\Model(Discussion::class))
        ->relationship('participantUsers', function (Discussion $discussion) {
            return resolve(ParticipantQuery::class)->relation($discussion);
        })
        ->hasOne('participantMeta', ParticipantMeta::class, 'discussion_id'),

    (new Extend\ApiResource(DiscussionResource::class))
        // Closures registered here are invoked with ZERO arguments — typed
        // parameters are not injected — so services are resolved inside.
        ->fields(function () {
            $participants = resolve(ParticipantQuery::class);

            return [
                Schema\Relationship\ToMany::make('participantUsers')
                    ->type('users')
                    ->includable()
                    ->withLinkage()
                    // Where the "minimum participants" setting is actually
                    // enforced: below it the strip serializes as empty, so
                    // a quiet discussion sends no avatars at all.
                    //
                    // ->all(), never the Collection itself: Flarum's ToMany
                    // calls toArray() on whatever it is handed, and an
                    // Eloquent collection converts its models to arrays all
                    // the way down, which the serializer then chokes on.
                    ->get(fn (Discussion $discussion) => $participants->belowThreshold($discussion)
                        ? []
                        : $discussion->getAttribute('participantUsers')->all()),

                Schema\Integer::make('participantOverflow')
                    ->get(fn (Discussion $discussion) => $participants->overflow($discussion)),

                Schema\Integer::make('participantTotal')
                    ->get(fn (Discussion $discussion) => $participants->total($discussion)),
            ];
        })
        ->endpoint([Endpoint\Index::class, Endpoint\Show::class], function (Endpoint\Index|Endpoint\Show $endpoint) {
            return $endpoint
                ->addDefaultInclude(['participantUsers'])
                // Both named explicitly. The include alone does NOT eager
                // load `participantUsers` — the custom getter on the field
                // hides the relation from the include compiler — and
                // without this the discussion list lazy loads one query
                // per row, which is the whole thing this design avoids.
                ->eagerLoad(['participantMeta', 'participantUsers'])
                // The strip's users serialize like any user, so they need
                // the same relations the author does — see AuthorEagerLoads.
                ->eagerLoad(fn () => AuthorEagerLoads::for($endpoint));
        }),

    // Posting a reply returns the discussion; pulling the participants
    // along with it is what makes a new replier's avatar appear on the
    // list without a refresh. Without the include the response carries
    // ids the frontend store has never seen.
    (new Extend\ApiResource(PostResource::class))
        ->endpoint(Endpoint\Create::class, function (Endpoint\Create $endpoint) {
            return $endpoint->addDefaultInclude(['discussion.participantUsers']);
        }),

    (new Extend\Event())
        ->subscribe(SyncParticipants::class),

    (new Extend\Settings())
        ->default('discussion-participants.strip_size', 6)
        ->default('discussion-participants.placement', Settings::PLACEMENT_BELOW)
        ->default('discussion-participants.avatar_size', 'medium')
        ->default('discussion-participants.order', Settings::ORDER_FIRST)
        ->default('discussion-participants.include_op', Settings::OP_NEVER)
        ->default('discussion-participants.min_participants', 1)
        ->default('discussion-participants.show_overflow', true)
        ->default('discussion-participants.hover_cards', true)
        ->default('discussion-participants.tags', '[]')
        ->serializeToForum('participantsPlacement', 'discussion-participants.placement')
        ->serializeToForum('participantsAvatarSize', 'discussion-participants.avatar_size')
        ->serializeToForum('participantsHoverCards', 'discussion-participants.hover_cards', 'boolval')
        ->serializeToForum('participantsTags', 'discussion-participants.tags'),
];
