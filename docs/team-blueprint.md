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

- **Roster sidebar** — every active player on the team, as a draggable row with avatar + name + meta line. An `×N` badge appears next to the name as soon as that player sits in one or more slots on the current formation. Hitting **+ Add cross-team / guest / custom** opens an inline 3-tab form (more on that below).
- **Pitch** — the formation slots. Each position renders a numbered circle (e.g. `9 ST`) and a three-row stack underneath: **primary / secondary / tertiary** depth. The tier is encoded twice — by the digit on the left of each row AND by the row's border colour — so the depth chart stays readable without colour.
- **Link chemistry headline** — `0 / 100` until you start placing players, then updates after every change.

### Picking a player

Two ways to fill a slot:

- **Click a slot** → a small dropdown opens with a search box and the team roster. Filter by name / position, click a row to place. When a slot already has someone, a *Clear this slot* row appears at the bottom of the dropdown.
- **Drag a roster row** onto any slot. The slot accepts the drop; previous occupant of the target tier is replaced. Drag-drop and the dropdown both call the same save endpoint.

The same player can sit in multiple slots and at multiple tiers — there's no automatic dedupe. The `×N` badge in the roster reflects how many placements they hold on the current formation (stale assignments from a previous formation don't count). Tier-1 placements feed the chemistry score; tier-2 and tier-3 are pure depth-chart signal and don't contribute to chemistry.

### + Add cross-team / guest / custom

Three tabs on the inline add form:

- **Other team** — pick a sibling team in the club, then a player from that team. Adds them to the roster as a cross-team pick. The player's home team appears in their roster meta line. Cross-team players are stored exactly like home-team players (`ref_kind=player`) — what makes them "cross-team" is just the home-team mismatch.
- **Guest** — type a name (e.g. *"visiting trialist"*) and an optional position. Adds a guest row to the roster.
- **Custom** — type a free-text label (e.g. *"Scout target #4"*). Adds a custom placeholder to the roster.

Guest and custom additions are **session-only until placed**. They live in the editor's local roster and are only persisted when actually dropped into a tier slot — so closing the editor without placing them effectively erases them. Once placed, the placement row carries the ref and the entry survives a reload.

### Formation switch

Picking a different formation from the **Formation** dropdown above the pitch updates the blueprint's template. Slot labels that exist in both formations keep their assignments; new slots come in empty; slots that disappear from the new formation are kept in the database silently (so a round-trip switch restores them).

### Saving

Each pick saves immediately to the assignments endpoint. There's no batch "Save" — the editor is the source of truth.

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

The match-day and squad-plan flavours **share the same editor surface** now (#953) — every position on the pitch carries the primary / secondary / tertiary stack inline. Squad-plan blueprints rely on the depth chart more heavily, but a match-day coach is free to fill tier 2 / 3 too (handy for "if A gets injured, B comes in").

The same player CAN sit in two slots or tiers on one blueprint — useful for a versatile player who's the primary at one slot and the secondary cover at another. The roster's `×N` badge keeps the picture honest.

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

Chemistry only scores the **starting XI** — i.e. the primary tier. Tier 2 and 3 are depth signal, not lineup signal. The headline number reflects the primary lineup; lines render between the primary players. Guest- and custom-occupied cells are skipped by the engine because they have no `tt_players.id` to look up coach-pairings or side preferences against.

If a slot has tier-2 or tier-3 entries but **no** tier-1 occupant, a warning strip lists the affected slots above the pitch — chemistry silently ignores those cells, so the strip makes the score drop visible. Fill tier-1 to bring them back into the score.

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
