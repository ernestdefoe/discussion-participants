/**
 * Which avatar's profile card is open, per discussion row.
 *
 * This lives outside the component on purpose. `DiscussionListItem` holds
 * a SubtreeRetainer and refuses to rebuild unless one of the things it
 * watches changes, so a card kept in component state would be set and
 * then never drawn. Keeping it here lets the row's retainer watch it.
 */
const hovered = {};

export function setHoveredUser(discussionId, userId) {
  hovered[discussionId] = userId;
}

export function getHoveredUser(discussionId) {
  return hovered[discussionId] ?? null;
}

/** The value the row's SubtreeRetainer watches. */
export function hoverKey(discussionId) {
  return hovered[discussionId] ?? '';
}

/**
 * Teach one discussion row's retainer about the card, once per component
 * instance. Without this the card is state nobody ever renders.
 */
export function trackHover(item, discussionId) {
  if (!item.subtree || item._dpHoverTracked) return;

  item._dpHoverTracked = true;
  item.subtree.check(() => hoverKey(discussionId));
}
