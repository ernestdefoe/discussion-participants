<?php

namespace Ernestdefoe\DiscussionParticipants;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed, clamped reader for the extension's settings. Every default here
 * is mirrored in extend.php so a fresh install and a saved-then-cleared
 * field land on the same value.
 */
class Settings
{
    public const ORDER_FIRST = 'first_post';
    public const ORDER_ACTIVE = 'most_active';
    public const ORDER_RECENT = 'recent';

    public const OP_NEVER = 'never';
    public const OP_IF_REPLIED = 'if_replied';
    public const OP_ALWAYS = 'always';

    public const PLACEMENT_BELOW = 'below';
    public const PLACEMENT_INLINE = 'inline';

    public const MAX_STRIP = 12;

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function stripSize(): int
    {
        return max(1, min(self::MAX_STRIP, (int) $this->get('strip_size', 6)));
    }

    public function order(): string
    {
        $order = (string) $this->get('order', self::ORDER_FIRST);

        return in_array($order, [self::ORDER_FIRST, self::ORDER_ACTIVE, self::ORDER_RECENT], true)
            ? $order
            : self::ORDER_FIRST;
    }

    public function includeOp(): string
    {
        $mode = (string) $this->get('include_op', self::OP_NEVER);

        return in_array($mode, [self::OP_NEVER, self::OP_IF_REPLIED, self::OP_ALWAYS], true)
            ? $mode
            : self::OP_NEVER;
    }

    public function minParticipants(): int
    {
        return max(1, (int) $this->get('min_participants', 1));
    }

    public function showOverflow(): bool
    {
        return (bool) $this->get('show_overflow', true);
    }

    /**
     * Column and direction for the chosen ordering. `user_id` is the tie
     * break throughout so the strip does not reshuffle between requests
     * when two people posted in the same second.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function orderColumns(): array
    {
        return match ($this->order()) {
            self::ORDER_ACTIVE => [['post_count', 'desc'], ['first_post_at', 'asc'], ['user_id', 'asc']],
            self::ORDER_RECENT => [['last_post_at', 'desc'], ['user_id', 'asc']],
            default => [['first_post_at', 'asc'], ['user_id', 'asc']],
        };
    }

    protected function get(string $key, mixed $default): mixed
    {
        $value = $this->settings->get('discussion-participants.'.$key);

        return $value === null || $value === '' ? $default : $value;
    }
}
