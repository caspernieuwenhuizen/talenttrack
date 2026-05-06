<!-- audience: user -->

# Team blueprint

A **blueprint** is a saved lineup. Build one in advance for an upcoming match, share it with the staff, lock it once you're decided. Each blueprint sits on top of a formation template and lets you drag players onto slots — with the same chemistry lines and team chemistry score you see on the *Team chemistry* board, computed live as you build.

## Where to find it

Coaches and head-of-academy users see a **Team blueprint** tile in the *Performance* group on the dashboard, right next to *Team chemistry*. Pick a team and you land on the list of saved blueprints for that team.

## Creating a blueprint

Click **+ New blueprint**. A wizard asks:

1. **Team** — usually pre-filled when you arrive from the team's blueprint list.
2. **Formation** — pick from the seven seeded templates (4-3-3 in four play-style flavours, plus 4-4-2 / 3-5-2 / 4-2-3-1 neutral).
3. **Blueprint name** — anything that helps you find it later (e.g. "Cup final starting XI").

Click **Create** and you land on the editor with empty slots, ready to fill.

## The editor

Three regions:

- **Roster sidebar** — every active player on the team, as a draggable chip. Players already in the lineup show greyed out.
- **Pitch** — the formation slots. Empty slots show a dashed `—`.
- **Link chemistry headline** — `0 / 100` until you start placing players, then updates after every drop.

### Drag-drop rules

- Drag a chip onto a slot to assign that player there.
- Drag a chip from one slot onto another to move them.
- Drag a chip back onto the roster sidebar to remove from the lineup.
- Each drop saves immediately. There's no "Save" button — the editor is the source of truth.

### Chemistry score

The same green / amber / red lines as the *Team chemistry* board appear between adjacent slots. Hover any line for the breakdown:

- **Green** (2.0–3.0) — strong fit
- **Amber** (1.0–2.0) — workable
- **Red** (0–1.0) — poor

Pair score combines coach-marked pairings (+2), same line of play (+1), and side-preference fit (+1, or −1 for a side mismatch). The 0–100 headline is the mean of all scored adjacent pairs scaled to 100.

Lines render even on rosters with zero evaluations because the inputs are coach-set or roster-set — coach pairings, formation slot adjacency, side preferences. So the chemistry score is useful from day 1.

## Status flow

Every blueprint moves through three states:

- **Draft** — your private working copy. Other coaches don't see it.
- **Shared** — visible to everyone with read access on team chemistry. Use this when you want feedback from the staff.
- **Locked** — read-only. Drag-drop is disabled; the assignment endpoints reject every write. Use this when the blueprint is final and you don't want anyone (including yourself) to nudge a player by accident before the match.

The status row above the pitch shows where you are. Buttons appear for the legal next moves:

- *Share with staff* (draft → shared)
- *Move back to draft* (shared → draft) or *Lock* (shared → locked)
- *Reopen* (locked → shared)

Reopen requires the same manage permission as creating a blueprint, so a head coach can reopen a locked blueprint if there's a late change.

## Permissions

- **View** — coaches see blueprints for teams they head-coach; head-of-academy / academy admin see all teams. Same scope as the Team chemistry board.
- **Create / edit / lock / delete** — gated on `tt_manage_team_chemistry` (head coach by default; head-of-academy / admin globally).

## Phase 1 limits

- **Match-day flavour only**. Squad-plan flavour (multi-tier position fits, primary / secondary / tertiary) lands in Phase 2.
- **No trials on the pitch yet** — trial overlay arrives with squad-plan in Phase 2.
- **No comments** — staff discussion lives outside the blueprint for now; Phase 3 will add a comments thread per blueprint via the Threads module.
- **Mobile drag-drop is awkward**. HTML5 drag-and-drop on touch devices works but isn't great. A long-press-to-pick-up fallback is on the polish list.
- **No share-link**. A public URL for parents / external coaches is Phase 4.

## REST

The list / show / create / update endpoints are documented in `docs/rest-api.md` under `talenttrack/v1/teams/{id}/blueprints` and `talenttrack/v1/blueprints/{id}`. The per-drop assignment endpoint is `PUT /blueprints/{id}/assignment` with body `{ slot_label, player_id? }` — the editor calls it on every drop and uses the recomputed `blueprint_chemistry` from the response to refresh the page.
