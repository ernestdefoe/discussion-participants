<?php

namespace Ernestdefoe\DiscussionParticipants;

use Flarum\Database\AbstractModel;

/**
 * One row per (discussion, user) who has a visible comment in that discussion.
 *
 * The table is a pivot, so nothing here ever saves a model instance — every
 * write is a set operation (`insertUsing`, a scoped `update`, a scoped
 * `delete`). It exists so those operations go through the model layer rather
 * than a hand-held connection, and so other extensions have something to
 * relate to.
 *
 * ⚠️ The primary key is composite (discussion_id, user_id), which Eloquent
 * cannot express. `find()`, `save()` and `delete()` on an instance are
 * therefore unsupported — use `query()` with explicit `where` clauses, which
 * is all this extension does.
 *
 * @property int $discussion_id
 * @property int $user_id
 * @property int $post_count visible comments by this user in this discussion
 * @property string|null $first_post_at
 * @property string|null $last_post_at
 */
class DiscussionParticipant extends AbstractModel
{
    protected $table = 'discussion_participants';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'discussion_id' => 'integer',
        'user_id' => 'integer',
        'post_count' => 'integer',
    ];
}
