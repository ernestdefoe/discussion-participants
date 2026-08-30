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
  // Closing a card DELETES the key rather than storing null. The strip already
  // calls this with null on mouse-out, so without the delete the map kept one
  // entry for every discussion the reader had ever hovered — on a long scroll,
  // thousands of keys that could never be read again. At most one entry lives
  // here now: whichever card is currently open.
  if (userId == null) {
    delete hovered[discussionId];

    return;
  }

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
