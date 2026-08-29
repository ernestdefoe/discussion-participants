import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import UserCard from 'flarum/forum/components/UserCard';
import ParticipantsModal from './ParticipantsModal';
import { getHoveredUser, setHoveredUser } from '../hoverState';

/**
 * The stack of replier avatars on a discussion list row, plus the badge
 * that opens the full participants list.
 */
export default class ParticipantStrip extends Component {
  /**
   * Whether this discussion gets a strip at all.
   *
   * The tag filter is applied here rather than on the server: the
   * discussion list has already loaded its tags, so checking them costs
   * nothing, where a server-side gate would mean another relation to load.
   */
  static shouldShow(discussion) {
    if (!discussion) return false;

    const users = discussion.participantUsers?.() || [];
    if (!users.length && !discussion.participantOverflow?.()) return false;

    const allowed = ParticipantStrip.allowedTags();
    if (!allowed.length) return true;

    // Without flarum/tags there is nothing to filter on, so a tag filter
    // simply cannot apply — show the strip rather than hiding every row.
    if (typeof discussion.tags !== 'function') return true;

    const tags = discussion.tags() || [];
    return tags.some((tag) => tag && allowed.includes(String(tag.id())));
  }

  static allowedTags() {
    try {
      const raw = app.forum.attribute('participantsTags');
      const parsed = typeof raw === 'string' ? JSON.parse(raw || '[]') : raw || [];
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch (e) {
      return [];
    }
  }

  oninit(vnode) {
    super.oninit(vnode);

    this.hoverTimeout = null;
  }

  onremove() {
    clearTimeout(this.hoverTimeout);
  }

  view() {
    const discussion = this.attrs.discussion;
    const users = (discussion.participantUsers() || []).filter(Boolean);
    const overflow = Number(discussion.participantOverflow()) || 0;
    const size = app.forum.attribute('participantsAvatarSize') || 'medium';
    const placement = this.attrs.placement || 'below';

    if (!users.length && !overflow) return null;

    return m(
      'div',
      {
        className: `DiscussionParticipants DiscussionParticipants--${size} DiscussionParticipants--${placement}`,
      },
      [
        users.length
          ? m(
              'ul',
              { className: 'DiscussionParticipants-list' },
              users.map((user, index) => this.avatarItem(user, index))
            )
          : null,
        overflow > 0 ? this.overflowBadge(discussion, overflow) : null,
      ]
    );
  }

  avatarItem(user, index) {
    const showCards = !!app.forum.attribute('participantsHoverCards');

    return m(
      'li',
      {
        className: 'DiscussionParticipants-item',
        // Earlier avatars sit on top of later ones, so the stack reads
        // left to right in the order people arrived.
        style: { '--dp-index': index },
        key: user.id(),
        onmouseover: showCards ? () => this.scheduleCard(user) : undefined,
        onmouseout: showCards ? () => this.scheduleHide() : undefined,
      },
      [
        m(
          Link,
          {
            href: app.route.user(user),
            className: 'DiscussionParticipants-link',
            title: user.displayName(),
          },
          m(Avatar, { user, className: 'DiscussionParticipants-avatar' })
        ),
        showCards && getHoveredUser(this.attrs.discussion.id()) === user.id()
          ? m(UserCard, {
              user,
              className: 'UserCard--popover DiscussionParticipants-card',
              controlsButtonClassName: 'Button Button--icon Button--flat',
            })
          : null,
      ]
    );
  }

  overflowBadge(discussion, overflow) {
    const label = app.translator.trans('ernestdefoe-discussion-participants.forum.overflow_a11y_label', {
      count: overflow,
    });

    return m(
      'button',
      {
        type: 'button',
        className: 'DiscussionParticipants-more',
        title: label,
        'aria-label': label,
        onclick: (e) => {
          e.preventDefault();
          e.stopPropagation();
          app.modal.show(ParticipantsModal, { discussion });
        },
      },
      `+${overflow}`
    );
  }

  /** Same 500ms in / 250ms out as core's own post-author card. */
  scheduleCard(user) {
    clearTimeout(this.hoverTimeout);
    this.hoverTimeout = setTimeout(() => {
      setHoveredUser(this.attrs.discussion.id(), user.id());
      m.redraw();
      setTimeout(() => this.$('.UserCard').addClass('in'));
    }, 500);
  }

  scheduleHide() {
    clearTimeout(this.hoverTimeout);
    this.hoverTimeout = setTimeout(() => {
      setHoveredUser(this.attrs.discussion.id(), null);
      m.redraw();
    }, 250);
  }
}
