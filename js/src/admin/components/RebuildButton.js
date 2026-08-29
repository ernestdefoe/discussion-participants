import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

/**
 * Walks the forum in chunks, rebuilding participant data for discussions
 * that predate the extension. The equivalent of `php flarum
 * participants:populate` for anyone who has no shell.
 */
export default class RebuildButton extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.running = false;
    this.processed = 0;
    this.max = 0;
    this.records = null;
    this.failed = false;
  }

  view() {
    return m('div', { className: 'Form-group DiscussionParticipants-rebuild' }, [
      m('label', app.translator.trans('ernestdefoe-discussion-participants.admin.rebuild_label')),
      m(
        'div',
        { className: 'helpText' },
        app.translator.trans('ernestdefoe-discussion-participants.admin.rebuild_help')
      ),
      m(
        Button,
        {
          className: 'Button',
          loading: this.running,
          disabled: this.running,
          onclick: () => this.start(),
        },
        app.translator.trans('ernestdefoe-discussion-participants.admin.rebuild_button')
      ),
      this.status(),
    ]);
  }

  status() {
    if (this.failed) {
      return m(
        'p',
        { className: 'DiscussionParticipants-rebuildStatus helpText' },
        app.translator.trans('ernestdefoe-discussion-participants.admin.rebuild_failed')
      );
    }

    if (this.running) {
      const percent = this.max ? Math.min(100, Math.round((this.processed / this.max) * 100)) : 0;

      return m('div', { className: 'DiscussionParticipants-progress' }, [
        m('div', { className: 'DiscussionParticipants-progressBar', style: { width: `${percent}%` } }),
        m(
          'span',
          { className: 'DiscussionParticipants-progressLabel' },
          app.translator.trans('ernestdefoe-discussion-participants.admin.rebuild_progress', { percent })
        ),
      ]);
    }

    if (this.records !== null) {
      return m(
        'p',
        { className: 'DiscussionParticipants-rebuildStatus helpText' },
        app.translator.trans('ernestdefoe-discussion-participants.admin.rebuild_done', { count: this.records })
      );
    }

    return null;
  }

  start() {
    this.running = true;
    this.failed = false;
    this.processed = 0;
    this.records = null;

    return this.step(1);
  }

  step(from) {
    return app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/discussion-participants/rebuild`,
        body: { from },
      })
      .then((result) => {
        this.max = result.max || 0;
        this.processed = result.processed || 0;
        this.records = result.records ?? null;
        m.redraw();

        if (!result.done && result.next) {
          return this.step(result.next);
        }

        this.running = false;
        m.redraw();
      })
      .catch(() => {
        this.running = false;
        this.failed = true;
        m.redraw();
      });
  }
}
