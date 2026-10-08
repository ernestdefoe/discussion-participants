<?php

namespace Ernestdefoe\DiscussionParticipants\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\DiscussionParticipants\ParticipantSynchronizer;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ParticipantsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-discussion-participants');

        $t = Carbon::now()->subDay();
        $users = [$this->normalUser()];
        $groups = [];
        for ($id = 3; $id <= 8; $id++) {
            $users[] = ['id' => $id, 'username' => "u$id", 'email' => "u$id@machine.local", 'is_email_confirmed' => 1];
            $groups[] = ['user_id' => $id, 'group_id' => Group::MEMBER_ID];
        }

        $post = fn (int $id, int $discussion, int $number, int $user, int $minutes, array $extra = []) => $extra + [
            'id' => $id, 'discussion_id' => $discussion, 'number' => $number, 'user_id' => $user,
            'type' => 'comment', 'content' => '<t><p>Post</p></t>', 'created_at' => $t->copy()->addMinutes($minutes),
        ];

        $this->prepareDatabase([
            User::class => $users,
            'group_user' => $groups,
            Discussion::class => [
                // Started by the member (2); replied to by 3 and 4.
                ['id' => 1, 'title' => 'Busy', 'created_at' => $t, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 8, 'last_post_number' => 9],
                ['id' => 2, 'title' => 'Hidden', 'created_at' => $t, 'user_id' => 3, 'first_post_id' => 20, 'comment_count' => 2, 'hidden_at' => $t],
            ],
            Post::class => [
                $post(1, 1, 1, 2, 0),
                $post(2, 1, 2, 3, 1),
                $post(3, 1, 3, 4, 2),
                $post(4, 1, 4, 4, 3),
                $post(5, 1, 5, 3, 4),
                $post(6, 1, 6, 4, 5),
                // A hidden post and a private one: neither makes a participant.
                $post(7, 1, 7, 5, 6, ['hidden_at' => $t]),
                $post(8, 1, 8, 6, 7, ['is_private' => true]),
                // The starter replies too.
                $post(9, 1, 9, 2, 8),
                $post(20, 2, 1, 3, 0),
                $post(21, 2, 2, 7, 1),
            ],
        ]);
    }

    /** Builds the participant tables from the posts, as `discussion-participants:populate` does. */
    private function populate(): void
    {
        $this->app()->getContainer()->make(ParticipantSynchronizer::class)->rebuildRange(1, 100);
    }

    private function discussion(int $id, ?int $actor = null): array
    {
        $response = $this->send($this->request('GET', "/api/discussions/$id", $actor ? ['authenticatedAs' => $actor] : []));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    /** @return list<int> */
    private function strip(array $discussion): array
    {
        return array_map(fn ($u) => (int) $u['id'], $discussion['relationships']['participantUsers']['data']);
    }

    #[Test]
    public function the_forum_gets_the_display_settings()
    {
        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];

        $this->assertSame('below', $forum['participantsPlacement']);
        $this->assertSame('medium', $forum['participantsAvatarSize']);
        $this->assertTrue($forum['participantsHoverCards']);
        $this->assertSame('[]', $forum['participantsTags']);
    }

    #[Test]
    public function hover_cards_can_be_switched_off()
    {
        $this->setting('discussion-participants.hover_cards', '0');

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];

        $this->assertFalse($forum['participantsHoverCards']);
    }

    #[Test]
    public function the_strip_shows_the_repliers_in_order_of_arrival()
    {
        $this->populate();

        $discussion = $this->discussion(1);

        $this->assertSame([3, 4], $this->strip($discussion), 'Not the starter, nor the hidden or private posters');
        $this->assertSame(3, $discussion['attributes']['participantTotal']);
        $this->assertSame(0, $discussion['attributes']['participantOverflow']);
    }

    #[Test]
    public function the_starter_can_be_shown_always_or_once_they_reply()
    {
        $this->setting('discussion-participants.include_op', 'if_replied');
        $this->populate();

        $this->assertSame([2, 3, 4], $this->strip($this->discussion(1)));
    }

    #[Test]
    public function the_strip_can_lead_with_the_most_active()
    {
        $this->setting('discussion-participants.order', 'most_active');
        $this->populate();

        $this->assertSame([4, 3], $this->strip($this->discussion(1)), 'Three posts before two');
    }

    #[Test]
    public function a_short_strip_counts_the_rest_as_overflow()
    {
        $this->setting('discussion-participants.strip_size', 1);
        $this->populate();

        $discussion = $this->discussion(1);
        $this->assertSame([3], $this->strip($discussion));
        $this->assertSame(1, $discussion['attributes']['participantOverflow']);
    }

    #[Test]
    public function a_quiet_discussion_shows_no_strip()
    {
        $this->setting('discussion-participants.min_participants', 3);
        $this->populate();

        $discussion = $this->discussion(1);
        $this->assertSame([], $this->strip($discussion), 'Two repliers, three needed');
        $this->assertSame(0, $discussion['attributes']['participantOverflow']);
    }

    #[Test]
    public function a_new_reply_joins_the_strip_and_hiding_it_leaves()
    {
        $this->populate();

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 7,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Me too'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        $postId = $body['data']['id'];

        // The reply's own response already carries the new strip, so the
        // avatar appears without a refresh.
        $discussion = array_values(array_filter($body['included'], fn ($r) => $r['type'] === 'discussions'))[0];
        $this->assertSame([3, 4, 7], $this->strip($discussion));

        $this->assertSame([3, 4, 7], $this->strip($this->discussion(1)));

        $response = $this->send($this->request('PATCH', "/api/posts/$postId", [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $postId, 'attributes' => ['isHidden' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame([3, 4], $this->strip($this->discussion(1)));
    }

    #[Test]
    public function the_full_list_pages_through_everyone_including_the_starter()
    {
        $this->populate();

        $response = $this->send($this->request('GET', '/api/discussion-participants/1')->withQueryParams(['id' => '1', 'limit' => '2', 'offset' => '2']));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(3, $body['total']);
        $this->assertSame([4], array_column($body['participants'], 'id'));
        $this->assertSame(3, $body['participants'][0]['posts']);

        $first = json_decode((string) $this->send($this->request('GET', '/api/discussion-participants/1')->withQueryParams(['id' => '1']))->getBody(), true);
        $this->assertSame([true, false, false], array_column($first['participants'], 'isOp'));
    }

    #[Test]
    public function a_discussion_the_viewer_cannot_see_has_no_participants_to_list()
    {
        $this->populate();

        $response = $this->send($this->request('GET', '/api/discussion-participants/2')->withQueryParams(['id' => '2']));

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function only_an_admin_rebuilds_the_tables()
    {
        $member = $this->send($this->request('POST', '/api/discussion-participants/rebuild', ['authenticatedAs' => 2, 'json' => []]));
        $this->assertSame(403, $member->getStatusCode());
        $this->assertSame(0, $this->database()->table('discussion_participants')->count());

        $admin = $this->send($this->request('POST', '/api/discussion-participants/rebuild', ['authenticatedAs' => 1, 'json' => ['from' => 1]]));
        $this->assertSame(200, $admin->getStatusCode(), (string) $admin->getBody());
        $this->assertTrue(json_decode((string) $admin->getBody(), true)['done']);
        $this->assertSame(5, $this->database()->table('discussion_participants')->count(), '2, 3 and 4 in the first; 3 and 7 in the hidden one');
    }

    #[Test]
    public function the_discussion_list_costs_the_same_queries_however_many_discussions_it_shows()
    {
        $this->populate();
        $list = fn () => $this->send($this->request('GET', '/api/discussions'));

        $list();
        $few = $this->queries($list);

        $t = Carbon::now()->subHours(2);
        for ($d = 10; $d <= 15; $d++) {
            $this->database()->table('discussions')->insert(['id' => $d, 'title' => "D$d", 'slug' => "d$d", 'created_at' => $t, 'last_posted_at' => $t, 'user_id' => 2, 'first_post_id' => $d * 10, 'comment_count' => 2, 'participant_count' => 2]);
            $this->database()->table('posts')->insert([
                ['id' => $d * 10, 'discussion_id' => $d, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>x</p></t>', 'created_at' => $t],
                ['id' => $d * 10 + 1, 'discussion_id' => $d, 'number' => 2, 'user_id' => $d - 7, 'type' => 'comment', 'content' => '<t><p>x</p></t>', 'created_at' => $t],
            ]);
        }
        $this->populate();

        $this->assertSame($few, $this->queries($list));
    }

    private function queries(callable $request): int
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $response = $request();
        $db->disableQueryLog();
        $this->assertSame(200, $response->getStatusCode());

        return count($db->getQueryLog());
    }
}
