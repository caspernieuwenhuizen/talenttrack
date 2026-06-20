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

Rebuilt v4.6.0 (#972) — clean port of the in-tree prototype at `.local-mockups/blueprint-editor/index.html`. Four regions:

- **Action bar** above the layout — one compact bar that consolidates every top-level action (#1527). The **Formation** dropdown stays a labelled control on the left (every active template from `tt_formation_templates`). Then a row of icon-only buttons for the frequent actions — **Save** (primary), **Clear all slots**, **Coverage heatmap** toggle (squad plans only), and the **chemistry show/hide** toggle — each with a tooltip and screen-reader label. A save-state hint flashes "Saving…" between writes. Everything infrequent or destructive lives behind the **⋯ More** menu at the end of the bar (Save as…, Open / Rotate share link, the status transitions, Delete blueprint).
- **Roster sidebar** — title `<Team> — roster (<N>)`, then every active player on the team as a draggable row: avatar with initials + name + a meta line (position, age, kind for non-team entries). An `×N` badge appears next to the name as soon as that player sits in one or more slots on the current formation. Below the list, a **+ Add guest / custom name** button opens an inline 3-tab form (Other team / Guest / Custom — more on that below).
- **Pitch** — the formation slots. Each position renders a numbered circle (e.g. `9` / `ST`) and a three-row stack directly underneath: **primary / secondary / tertiary** depth. The tier is encoded twice — by the digit on the left of each row AND by the row's border colour (teal / amber / grey) — so the depth chart stays readable without colour.
- **Link chemistry headline** — `— / 100` until you start placing players, then updates after every change.

### The ⋯ More menu

The overflow menu is a native disclosure: click (or focus + Enter / Space) the **⋯** button to open it, click outside or press Escape to close. It holds, with full text labels:

- **Save as…** — clone the current blueprint to a fresh draft.
- **Open share link** / **Rotate share link** — the public read-only link controls (see *Public share-link* below).
- The legal **status transitions** for the current state (*Share with staff*, *Move back to draft*, *Lock*, *Reopen*).
- **Delete blueprint** — hidden once the blueprint is locked (Reopen first).

Each of these keeps its own confirmation prompt where it had one (Rotate, Delete, Clear all). Nothing changed about what the actions do — #1527 only moved them into one bar.

### Picking a player

Two ways to fill a slot:

- **Click a tier slot row** → an anchored dropdown picker opens beside it with a search box and the full roster. Filter by name / position; click a row to place. When a slot already has someone, a *Clear this slot* row appears at the bottom of the picker.
- **Drag a roster row** onto any tier slot. The slot accepts the drop; previous occupant of the target tier is replaced (the displaced player stays in every other slot they occupy — drops do NOT pull them from elsewhere). Drag-drop and the picker both call the same save endpoint.

The same player can sit in any number of slots and at any number of tiers — no automatic dedupe. The `×N` badge in the roster reflects how many placements they hold on the **current** formation (stale assignments from a previous formation don't count toward the badge, but they do survive in the database). Tier-1 placements feed the chemistry score; tier-2 and tier-3 are pure depth-chart signal and don't contribute to chemistry.

The **`×` clear button** on every filled slot empties just that tier in just that slot.

After every successful save the editor reloads so the chemistry headline, occupant names and any server-side state come back authoritative.

### + Add guest / custom name

Three tabs on the inline add form:

- **Other team** — pick a sibling team in the club, then a player from that team. Adds them to the roster as a cross-team pick (the player's home team appears in the meta line). Cross-team players are stored exactly like home-team players (`ref_kind=player`) — what makes them "cross-team" is just the home-team mismatch.
- **Guest** — type a name (e.g. *"visiting trialist"*) and an optional position. Adds a guest row to the roster.
- **Custom** — type a free-text label (e.g. *"Scout target #4"*). Adds a custom placeholder to the roster.

Guest and custom additions are **session-only until placed**. They live in the editor's in-memory roster and are only persisted when actually dropped into a tier slot — so closing the editor without placing them effectively erases them. Once placed, the assignment row carries the ref (`ref_kind=guest` or `ref_kind=custom`) and the entry survives a reload.

### Formation switch

Picking a different formation from the **Formation** dropdown updates the blueprint's template (via `PUT /blueprints/{id}` with the new `formation_template_id`) and reloads the editor. Assignments survive by **slot label** — every slot whose label exists in both the old and new formation keeps its tier-1/2/3 picks. New slots come in empty. Slots dropped from the new formation are kept in the database silently, so a round-trip switch restores them. (The `×N` placement badge counts only what's visible on the current formation.)

### Clear all slots

The **Clear all slots** button in the action bar wipes every assignment for the current blueprint after a confirmation prompt. There's no undo — use it when starting fresh from an existing blueprint via *Save As*.

### Saving

Each pick / clear / formation change saves immediately. There's no batch save. The **Save** button in the action bar is "done editing, take me back to the list" — it navigates to the team's blueprint list with a confirmation toast. **Save as…**, in the **⋯ More** menu, prompts for a new name and clones the current blueprint (including every assignment row) to a fresh draft, then opens that draft's editor.

### Hide chemistry

The **chemistry show/hide** toggle in the action bar hides the chemistry headline card AND every chemistry link line on the pitch. The eye icon shows the current state and its tooltip / label flips between *Hide chemistry* and *Show chemistry*. The state persists in `sessionStorage` keyed by blueprint id, so a refresh keeps the preference. Useful when reviewing the depth chart without the chemistry noise.

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

A status pill below the action bar shows where you are. The legal next moves live in the action bar's **⋯ More** menu:

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

The **coverage heatmap** toggle in the action bar (squad-plan blueprints only) flips the pitch into a depth-coverage view:

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

The **Open share link** item in the action bar's **⋯ More** menu generates a URL of shape:

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
