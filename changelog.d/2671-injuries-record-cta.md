# Injuries overview: record an injury without opening a player file first (#2671)

Bump: patch

The squad-level Injuries page was read-only by omission — the injury wizard
existed and was registered, but only the player file linked to it, so a coach
who opened the overview to see who was out had no way in from there. The page
now carries a **Record injury** action in its header, and the "Nobody is
currently out injured" state carries the same call to action instead of a bare
notice.

Entering from the overview starts the wizard on its team → player step, which
is scoped to the squads the coach holds. The button follows the same gate as
the player file: it is absent for roles without `tt_edit_player_medical` and
when wizards are switched off, because there is no flat-form path to fall back
on. The docs already described this entry point; the code has caught up.
