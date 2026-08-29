<?php

namespace Ernestdefoe\DiscussionParticipants;

use Flarum\Database\AbstractModel;

/**
 * The exact participant tallies for one discussion.
 *
 * Core's `discussions.participants_count` looks like it would do, but it
 * counts the authors of hidden posts too — so the overflow badge and the
 * modal would quietly disagree. This is the number both read from, and it
 * is written only by this extension, to its own table.
 *
 * @property int $discussion_id
 * @property int $participant_count everyone with a visible comment
 * @property int $replier_count the same, minus whoever started the discussion
 */
class ParticipantMeta extends AbstractModel
{
    protected $table = 'discussion_participant_meta';

    protected $primaryKey = 'discussion_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'discussion_id' => 'integer',
        'participant_count' => 'integer',
        'replier_count' => 'integer',
    ];
}
