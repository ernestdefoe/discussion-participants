import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import Settings from '../common/settings';
import ParticipantStrip from './components/ParticipantStrip';
import { trackHover } from './hoverState';

const placement = () => app.forum.attribute('participantsPlacement') || Settings.PLACEMENT_BELOW;

app.initializers.add('ernestdefoe-discussion-participants', () => {
  // "Below": its own line under the title, aligned with it.
  //
  // The strip is wrapped alongside core's <Link> rather than placed
  // inside it — infoItems would have been the obvious home, but that
  // list renders inside the link that covers the whole title block, and
  // neither a profile link nor the overflow button may nest in another
  // link.
  override(DiscussionListItem.prototype, 'mainView', function (original) {
    const view = original();
    const discussion = this.attrs.discussion;

    if (placement() !== Settings.PLACEMENT_BELOW || !ParticipantStrip.shouldShow(discussion)) {
      return view;
    }

    trackHover(this, discussion.id());

    return m('div', { className: 'DiscussionParticipants-mainWrap' }, [
      view,
      m(ParticipantStrip, { discussion, placement: Settings.PLACEMENT_BELOW }),
    ]);
  });

  // "Inline": out on the right, between the title block and the reply
  // count, for forums that would rather keep rows one line tall.
  extend(DiscussionListItem.prototype, 'contentItems', function (items) {
    const discussion = this.attrs.discussion;

    if (placement() !== Settings.PLACEMENT_INLINE || !ParticipantStrip.shouldShow(discussion)) {
      return;
    }

    trackHover(this, discussion.id());

    items.add('participants', m(ParticipantStrip, { discussion, placement: Settings.PLACEMENT_INLINE }), 75);
  });
});
