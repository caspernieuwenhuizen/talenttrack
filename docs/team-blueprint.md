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

## Squad plan flavour

When you create a blueprint, the wizard asks for the type:

- **Match-day lineup** — one starting XI for an upcoming match. Single player per slot. (The default; everything above describes this flavour.)
- **Squad plan** — planning towards next season or trial decisions. Each slot has three tiers (primary / secondary / tertiary) and the roster sidebar adds a *Trials* section.

The flavour is locked at create time.

### Tiered depth chart

On a squad-plan blueprint, a depth-chart table appears below the pitch:

| Slot | Primary | Secondary | Tertiary |
| --- | --- | --- | --- |
| GK | Lucas | Jonas | — |
| LB | Eve | Mira | Jamal |
| LCB | Sam | — | — |

Each cell is a drop target. Drag any roster chip onto any cell to fill that tier. Drag a depth-chart chip back to the roster panel to remove. The pitch slots above keep accepting drops too — they target the **primary** tier.

The same player can't sit in two slots or tiers on one blueprint. If you drag Lucas from `GK / Primary` onto `LB / Secondary`, his GK slot empties automatically.

### Trial overlay

The roster sidebar gets a *Trials* divider listing trial players assigned to this team — i.e. `tt_players` rows on this team's roster with `status = 'trial'`. Trial chips have a yellow border and a small `TRIAL` badge. Drag-drop is identical to regular roster chips, so you can stage a trial in tier 2 / 3 of a slot to make the "should we sign this kid?" conversation visible against the depth chart.

### Coverage heatmap

A *Show coverage heatmap* button on squad-plan blueprints flips the pitch into a depth-coverage view:

- **Red** — 0 tiers covered (uncovered)
- **Orange** — 1 (primary only, no backup)
- **Yellow** — 2 (primary + secondary, no third)
- **Green** — 3 (full depth)

Each slot shows `N/3` so you can read the page at a glance: where are the gaps? `← Back to lineup view` returns to the editor.

### Chemistry on a squad-plan blueprint

Chemistry only scores the **starting XI** — i.e. the primary tier. Tier 2 and 3 are depth signal, not lineup signal. The headline number reflects the primary lineup; lines render between the primary players.

## Comments (#0068 Phase 3)

Every blueprint has a per-blueprint discussion thread reachable from the editor's **Comments** tab. Staff-only by design (parents on the share-link never see comments):

- **Read** = `tt_view_team_chemistry` — every coach who can open the editor can read.
- **Post** = `tt_manage_team_chemistry` — every coach who can lock the blueprint can post.

System messages auto-post on status transitions (`Status changed to: shared` / `locked` / `draft`). Per-assignment swaps stay silent — they show up on the chemistry refresh.

## Public share-link (#0068 Phase 4)

The editor's **Open share link** button generates a URL of shape:

```
?tt_view=team-blueprint-share&id=<uuid>&token=<hmac>
```

Anyone with the URL sees a read-only render: status pill + chemistry headline + pitch + lineup table. No comments, no editing controls, no login required. Parents and external coaches can be sent the link directly.

**Rotate share link** sets a fresh seed. Every prior URL fails verification immediately. Use it when a link has been over-shared, or after a roster change you don't want previous viewers to keep tracking.

The token is an HMAC-SHA256 over `(blueprint_id, uuid, share_token_seed)` keyed on the install's `wp_salt('auth')`. The seed is per-blueprint, lazily initialised to the blueprint's uuid (cryptographically random by construction); rotation replaces the seed with a fresh `wp_generate_password(16)` value.

## Mobile drag-drop (#0068 Phase 4)

iPads work fine with HTML5 drag-and-drop; iPhones don't. v3.109.8 ships a touch fallback:

- **Long-press 300ms** on a roster chip to pick it up.
- Drag the chip onto a slot or back into the roster panel.
- A short tap-and-scroll keeps scrolling — the long-press threshold disambiguates.
- Pickup + drop trigger a 50ms haptic tap on devices that support `navigator.vibrate()`.

Mouse + trackpad keep using the existing HTML5 drag flow.

## REST

The list / show / create / update endpoints are documented in `docs/rest-api.md` under `talenttrack/v1/teams/{id}/blueprints` and `talenttrack/v1/blueprints/{id}`. The per-drop assignment endpoint is `PUT /blueprints/{id}/assignment` with body `{ slot_label, player_id? }` — the editor calls it on every drop and uses the recomputed `blueprint_chemistry` from the response to refresh the page.
