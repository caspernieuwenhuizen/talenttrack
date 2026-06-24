# Enforce one WP account per player (#1772)

Bump: patch

`tt_players.wp_user_id` had no uniqueness guard and no cleanup when a WP
user was deleted, so two players could share an account and the
derived-player scope resolver could surface the wrong child's record — a
safeguarding risk for minors.

- New migration `0170` deduplicates any players sharing a `(club_id,
  wp_user_id)` (keeping the active, data-richest, newest row and
  **unlinking** — never deleting — the rest, with an audit-log entry per
  unlink), normalises "no account" from `0` to `NULL`, and adds a
  `UNIQUE (club_id, wp_user_id)` index.
- New `delete_user` cleanup nulls `tt_players` / `tt_people` account links
  and removes `tt_player_parents` rows for the deleted user, so a
  re-issued WP user id can't inherit someone else's record.
- The player/parent scope resolvers now order deterministically, and every
  write path stores `NULL` (not `0`) for an unlinked player.

No behaviour change for correctly-linked accounts; the link UI and an
app-layer "already linked" guard land with the Player accounts view (#1771).
