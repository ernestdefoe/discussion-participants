import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import stringToColor from 'flarum/common/utils/stringToColor';

const PER_PAGE = 10;

/**
 * The full participant roster behind the overflow badge.
 *
 * Unlike the strip this includes whoever started the discussion — the
 * modal answers "who is in here", and a total that excluded the author
 * would not add up against the list beneath it.
 */
export default class ParticipantsModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    this.participants = [];
    this.total = 0;
    this.offset = 0;
    this.loading = true;
    this.failed = false;

    this.load(0);
  }

  className() {
    return 'ParticipantsModal Modal--small';
  }

  title() {
    // A count of zero while the first page is still in flight reads as an
    // answer rather than a wait, so the plain noun stands in until the
    // real number arrives.
    if (this.loading && !this.total) {
      return app.translator.trans('ernestdefoe-discussion-participants.forum.modal_title_loading');
    }

    return app.translator.trans('ernestdefoe-discussion-participants.forum.modal_title', {
      count: this.total,
    });
  }

  content() {
    if (this.loading) {
      return m('div', { className: 'Modal-body' }, m(LoadingIndicator));
    }

    if (this.failed) {
      return m(
        'div',
        { className: 'Modal-body ParticipantsModal-error' },
        app.translator.trans('ernestdefoe-discussion-participants.forum.load_failed')
      );
    }

    return m('div', { className: 'Modal-body' }, [
      m(
        'ul',
        { className: 'ParticipantsModal-list' },
        this.participants.map((p) => this.row(p))
      ),
      this.pagination(),
    ]);
  }

  row(participant) {
    // A plain object from our own endpoint, not a store record, so the
    // shape core's helpers want is built by hand. Every getter here is
    // one core actually calls: Avatar reads displayName/avatarUrl/color,
    // and app.route.user reads slug.
    const user = {
      id: () => String(participant.id),
      username: () => participant.username,
      displayName: () => participant.displayName,
      slug: () => participant.slug,
      avatarUrl: () => participant.avatarUrl,
      // Same derivation core's own User model uses for avatarless
      // accounts, so a letter avatar is the same colour here as it is in
      // the strip. Without it the modal's avatars come out unpainted.
      color: () => (participant.avatarUrl ? '' : '#' + stringToColor(participant.displayName)),
    };

    return m('li', { className: 'ParticipantsModal-row', key: participant.id }, [
      m(
        Link,
        { href: app.route.user(user), className: 'ParticipantsModal-user', onclick: () => this.hide() },
        [
          m(Avatar, { user, className: 'ParticipantsModal-avatar' }),
          m('span', { className: 'ParticipantsModal-name' }, participant.displayName),
        ]
      ),
      m('span', { className: 'ParticipantsModal-meta' }, [
        participant.isOp
          ? m(
              'span',
              { className: 'ParticipantsModal-badge' },
              app.translator.trans('ernestdefoe-discussion-participants.forum.author_badge')
            )
          : null,
        m(
          'span',
          { className: 'ParticipantsModal-posts' },
          app.translator.trans('ernestdefoe-discussion-participants.forum.post_count', {
            count: participant.posts,
          })
        ),
      ]),
    ]);
  }

  pagination() {
    const pages = Math.ceil(this.total / PER_PAGE);
    if (pages <= 1) return null;

    const current = Math.floor(this.offset / PER_PAGE) + 1;

    return m('nav', { className: 'ParticipantsModal-pagination' }, [
      m(Button, {
        className: 'Button Button--icon',
        icon: 'fas fa-chevron-left',
        disabled: current <= 1,
        'aria-label': app.translator.trans('ernestdefoe-discussion-participants.forum.previous_page'),
        onclick: () => this.load(this.offset - PER_PAGE),
      }),
      m(
        'span',
        { className: 'ParticipantsModal-page' },
        app.translator.trans('ernestdefoe-discussion-participants.forum.page_counter', {
          current,
          total: pages,
        })
      ),
      m(Button, {
        className: 'Button Button--icon',
        icon: 'fas fa-chevron-right',
        disabled: current >= pages,
        'aria-label': app.translator.trans('ernestdefoe-discussion-participants.forum.next_page'),
        onclick: () => this.load(this.offset + PER_PAGE),
      }),
    ]);
  }

  load(offset) {
    this.loading = true;
    this.failed = false;
    m.redraw();

    return app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/discussion-participants/${this.attrs.discussion.id()}`,
        params: { offset: Math.max(0, offset), limit: PER_PAGE },
      })
      .then((result) => {
        this.participants = result.participants || [];
        this.total = result.total || 0;
        this.offset = result.offset || 0;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.failed = true;
        this.loading = false;
        m.redraw();
      });
  }
}
