import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const SETTING = 'discussion-participants.tags';

/**
 * Limits the avatar strip to chosen tags. Renders nothing at all when
 * flarum/tags is absent — an empty tag picker would be a control that
 * cannot do anything.
 */
export default class TagFilterSetting extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.tags = null;
    this.loading = false;

    if (!this.available()) return;

    this.loading = true;
    app.store
      .find('tags')
      .then((tags) => {
        this.tags = tags;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.tags = [];
        this.loading = false;
        m.redraw();
      });
  }

  available() {
    return 'flarum-tags' in flarum.extensions;
  }

  view() {
    if (!this.available()) return null;

    return m('div', { className: 'Form-group DiscussionParticipants-tagFilter' }, [
      m('label', app.translator.trans('ernestdefoe-discussion-participants.admin.tags_label')),
      m('div', { className: 'helpText' }, app.translator.trans('ernestdefoe-discussion-participants.admin.tags_help')),
      this.loading ? m(LoadingIndicator, { display: 'inline' }) : this.picker(),
    ]);
  }

  picker() {
    const selected = this.selected();
    const tags = (this.tags || []).filter(Boolean);

    if (!tags.length) {
      return m('p', { className: 'helpText' }, app.translator.trans('ernestdefoe-discussion-participants.admin.tags_empty'));
    }

    return m(
      'div',
      { className: 'DiscussionParticipants-tagList' },
      tags.map((tag) => {
        const id = String(tag.id());
        const active = selected.includes(id);

        return m(
          'button',
          {
            type: 'button',
            key: id,
            className: `DiscussionParticipants-tag ${active ? 'active' : ''}`,
            style: active && tag.color() ? { '--dp-tag-color': tag.color() } : undefined,
            onclick: () => this.toggle(id),
          },
          tag.name()
        );
      })
    );
  }

  selected() {
    try {
      const raw = app.data.settings[SETTING];
      const parsed = typeof raw === 'string' ? JSON.parse(raw || '[]') : raw || [];
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch (e) {
      return [];
    }
  }

  toggle(id) {
    const selected = this.selected();
    const next = selected.includes(id) ? selected.filter((t) => t !== id) : selected.concat(id);

    // Written straight to the settings endpoint rather than staged in the
    // page's dirty-state: this control is not one of the framework's own
    // setting fields, so the page's Save button never sees it.
    app.data.settings[SETTING] = JSON.stringify(next);
    m.redraw();

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/settings`,
        body: { [SETTING]: JSON.stringify(next) },
      })
      .catch(() => {
        app.data.settings[SETTING] = JSON.stringify(selected);
        m.redraw();
      });
  }
}
