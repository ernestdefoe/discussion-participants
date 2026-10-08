import { Admin } from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import Settings from '../common/settings';
import TagFilterSetting from './components/TagFilterSetting';
import RebuildButton from './components/RebuildButton';

const t = (key, params) => app.translator.trans(`ernestdefoe-discussion-participants.admin.${key}`, params);

export default [
  new Admin()
    .setting(
      () => ({
        setting: 'discussion-participants.strip_size',
        type: 'number',
        min: 1,
        max: Settings.MAX_STRIP,
        label: t('strip_size_label'),
        help: t('strip_size_help'),
        default: 6,
      }),
      100
    )
    .setting(
      () => ({
        setting: 'discussion-participants.placement',
        type: 'select',
        options: {
          [Settings.PLACEMENT_BELOW]: t('placement_below'),
          [Settings.PLACEMENT_INLINE]: t('placement_inline'),
        },
        label: t('placement_label'),
        help: t('placement_help'),
        default: Settings.PLACEMENT_BELOW,
      }),
      95
    )
    .setting(
      () => ({
        setting: 'discussion-participants.avatar_size',
        type: 'select',
        options: {
          small: t('avatar_size_small'),
          medium: t('avatar_size_medium'),
          large: t('avatar_size_large'),
        },
        label: t('avatar_size_label'),
        help: t('avatar_size_help'),
        default: 'medium',
      }),
      90
    )
    .setting(
      () => ({
        setting: 'discussion-participants.order',
        type: 'select',
        options: {
          [Settings.ORDER_FIRST]: t('order_first_post'),
          [Settings.ORDER_ACTIVE]: t('order_most_active'),
          [Settings.ORDER_RECENT]: t('order_recent'),
        },
        label: t('order_label'),
        help: t('order_help'),
        default: Settings.ORDER_FIRST,
      }),
      80
    )
    .setting(
      () => ({
        setting: 'discussion-participants.include_op',
        type: 'select',
        options: {
          [Settings.OP_NEVER]: t('include_op_never'),
          [Settings.OP_IF_REPLIED]: t('include_op_if_replied'),
          [Settings.OP_ALWAYS]: t('include_op_always'),
        },
        label: t('include_op_label'),
        help: t('include_op_help'),
        default: Settings.OP_NEVER,
      }),
      70
    )
    .setting(
      () => ({
        setting: 'discussion-participants.min_participants',
        type: 'number',
        min: 1,
        label: t('min_participants_label'),
        help: t('min_participants_help'),
        default: 1,
      }),
      60
    )
    .setting(
      () => ({
        setting: 'discussion-participants.show_overflow',
        type: 'boolean',
        label: t('show_overflow_label'),
        help: t('show_overflow_help'),
      }),
      50
    )
    .setting(
      () => ({
        setting: 'discussion-participants.hover_cards',
        type: 'boolean',
        label: t('hover_cards_label'),
        help: t('hover_cards_help'),
      }),
      40
    )
    .customSetting(() => m(TagFilterSetting), 30)
    .customSetting(() => m(RebuildButton), 20),
];
