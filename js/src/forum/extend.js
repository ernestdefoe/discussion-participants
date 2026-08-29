import { Model } from 'flarum/common/extenders';
import Discussion from 'flarum/common/models/Discussion';

export default [
  new Model(Discussion)
    .attribute('participantOverflow')
    .attribute('participantTotal')
    .hasMany('participantUsers'),
];
