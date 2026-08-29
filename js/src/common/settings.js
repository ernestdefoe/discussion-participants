/**
 * Mirrors src/Settings.php. Both sides read the same option strings, so
 * they are named in one place rather than typed out twice.
 */
export default {
  ORDER_FIRST: 'first_post',
  ORDER_ACTIVE: 'most_active',
  ORDER_RECENT: 'recent',

  OP_NEVER: 'never',
  OP_IF_REPLIED: 'if_replied',
  OP_ALWAYS: 'always',

  PLACEMENT_BELOW: 'below',
  PLACEMENT_INLINE: 'inline',

  MAX_STRIP: 12,
};
