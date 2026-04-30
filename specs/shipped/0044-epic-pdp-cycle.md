# Player Development Plan (PDP) cycle

## Problem

Coaches today track player development through a scattered combination of evaluations, goals, attendance, and methodology pins — each useful in isolation, none of them holding the throughline of "what does this player need over the course of a season, and did we actually do anything about it." End-of-season conversations happen on paper or in heads, and the next season starts cold; a coach moving on takes their picture of each player with them. The methodology module from #0027 captures *what good football looks like at this club*; the goals system records *what we'd like this player to work on*; nothing today connects the two into a structured per-season cycle that the academy can audit, repeat, and improve.

The shape needed: a per-(player, season) file that gathers everything relevant, structures 2 / 3 / 4 conversations across the season around it, ties goals to the methodology + values vocabulary, and ends with a deliberate verdict the head of academy signs off on.

## Proposal

A first-class PDP entity, season-scoped, plugged into the existing methodology / workflow / evaluations / authorization / activities infrastructure rather than parallel to them.

- **Seasons become first-class.** New `tt_seasons` table; the existing free-form `season_label` config becomes a derived label for the current season's row.
- **PDP file per (player, season).** Real entity, not a virtual aggregation, so it can carry status, owning coach, sign-off state, configuration overrides.
- **Conversation cadence is configurable per-club, overridable per-team.** Two, three, or four conversations across the season; each is a templated form with structured fields plus free-text and an optional player self-reflection panel filled in *before* the meeting.
- **Goals link polymorphically** to methodology principles, football actions, positions, and a new player-value lookup. Single `tt_goal_links` join table covers all four.
- **End-of-season verdict is its own entity** — distinct from the final conversation because the data shape (promote / retain / release / transfer) and sign-off path (multi-coach + head of academy) differ.
- **Workflow engine drives cadence.** New `pdp_cycle` template registered against #0022; the engine handles deadlines, nudges, no-show escalation. No new scheduler.
- **Calendar planning** writes meeting events natively today and through Spond once #0031 lands — design the hook in this epic, leave the integration as a #0031 deliverable.
- **Authorization** slots into the #0033 matrix as a new entity row. Coach: full edit on own teams. Head of academy: edit + override. Parent: read-only of completed conversations + their own ack. Player: read-only own.
- **Print and digital share the same template** — single A4 by default with an optional "include evidence" toggle that adds the period's evaluation summary + methodology pins block.
- **Carryover** rolls open goals into the next season's PDP automatically; conversation prose does not pre-populate (avoids stale-text echo).

## Scope

### Data model (new)

| Table | Purpose | Key columns |
| - | - | - |
| `tt_seasons` | First-class season entity | `id`, `name`, `start_date`, `end_date`, `is_current` (TINYINT, only one row TRUE), `created_at` |
| `tt_pdp_files` | One row per (player, season) | `id`, `player_id`, `season_id` (UNIQUE pair), `owner_coach_id`, `cycle_size` (2 / 3 / 4 — overrides club default), `status` (`open` / `completed` / `archived`), `notes`, `created_at`, `updated_at` |
| `tt_pdp_conversations` | Per-conversation row | `id`, `pdp_file_id`, `sequence` (1..N within the cycle), `template_key` (`start` / `mid_a` / `mid_b` / `end` — derived from cycle_size), `scheduled_at`, `conducted_at`, `agenda`, `notes`, `agreed_actions`, `player_reflection`, `coach_signoff_at`, `parent_ack_at`, `player_ack_at` |
| `tt_pdp_verdicts` | End-of-season decision | `id`, `pdp_file_id` (UNIQUE), `decision` (`promote` / `retain` / `release` / `transfer`), `summary`, `coach_id`, `head_of_academy_id`, `signed_off_at` |
| `tt_goal_links` | Polymorphic goal linkage | `id`, `goal_id`, `link_type` (`principle` / `football_action` / `position` / `value`), `link_id` (FK target), composite UNIQUE on the three together |

### Lookups (new)

- `tt_lookups` type `player_value`. Seeded with: `commitment`, `coachability`, `leadership`, `resilience`, `communication`, `work_ethic`, `fair_play`, `ambition`. Per-club editable.

### Configuration (new)

- `tt_config.pdp_cycle_default` — int 2 / 3 / 4. Default 3.
- `tt_config.pdp_print_include_evidence` — bool, default false.
- Per-team override: optional column on `tt_teams.pdp_cycle_size` (NULL = follow club default).

### Workflow templates (new — registered against #0022)

- `pdp_conversation_due` — fires per (player, conversation index, scheduled_at) with a configurable lead time. Assigned to `owner_coach_id`. Escalates to head of academy on overdue.
- `pdp_verdict_due` — fires once per PDP file at season-end-minus-N-days. Assigned to head of academy.

### Frontend surfaces

- **Coach** — `?tt_view=pdp` (list of own players' PDPs for the current season, status pill, last conversation, next conversation due) → `?tt_view=pdp&id=<file_id>` (file detail: conversations list, goals block, evidence sidebar, print button).
- **Conversation form** — opens via tile or workflow inbox; pre-meeting and post-meeting modes share the same form with sections shown/hidden by state.
- **Verdict form** — opens via workflow inbox or PDP file detail when `cycle_size`-th conversation is signed off.
- **Player self-reflection** — small editable section appears on the conversation form for the player's role, read-only for everyone else once submitted.
- **Parent / player views** — `?tt_view=my-pdp` shows the file in read-only mode plus an "acknowledge" button per completed conversation.

### Wp-admin surfaces

- `?page=tt-seasons` — list / create / set-current. Tiny CRUD; #0019 doesn't extend here unless the user asks.
- PDP itself is frontend-first — no wp-admin equivalent.

### Print

- Single A4 default: photo, season label, current period goals + status, agreed actions from the conversation, signature lines.
- "Include evidence" toggle: appends evaluation summary (last N evals, weighted overall, top 3 categories) + methodology pins on the goals block.
- Render server-side via the existing PDF flow (#0027's print router).

### Authorization (slots into #0033 matrix)

New entity row `pdp_file` with the standard read / change / create-delete columns. Default cap-set per persona:

- Head of academy: change-global (full edit, all teams).
- Head coach: change-team (edit own teams).
- Assistant coach: change-team (read-write within own teams).
- Parent: read-self (read-only own children's PDPs).
- Player: read-self.
- Read-only observer: read-global.

`pdp_verdict` is a sibling entity with stricter defaults — head-of-academy + head-coach only.

### Carryover

When a season ends and a new one is set current, a one-shot job per player:

1. Skip if a PDP file already exists for the new season.
2. Create a new file with empty conversations (templated by club / team config).
3. Copy goals where `status NOT IN ('completed', 'archived')` from the previous season's PDP, fresh `created_at`, `due_date` cleared.
4. No prose carryover.

### Spond write-back

Calendar writeback is conditional on the #0031 integration being active:

- New service interface `PdpCalendarWriter::onConversationScheduled( int $conversation_id ): void`.
- Default implementation no-ops (writes a row to `tt_pdp_calendar_links` with `provider = 'native'`).
- Spond implementation lands in #0031 — same interface, writes to the Spond API and records the returned event id.

## Out of scope

- **Trial player coverage** — trial players (#0017) don't get PDP files. Revisit when #0017 ships.
- **Per-age-group conversation templates** — single template, with the cycle size flexible per club / per team. Per-age-group differentiation is a v2 ask.
- **Multi-coach concurrent editing** — one `owner_coach_id` per PDP file. Co-coached teams pick a primary; assistants get read access. Real concurrent editing is a v2 ask.
- **Bulk PDP creation from a CSV** — not in v1; carryover handles seasonal start.
- **Public API for external HR systems** — not in v1.
- **Heavy revision history** — `tt_audit_log` already records writes; we don't ship a per-conversation versioned editor.
- **Linking goals retroactively to methodology entities for old goals** — only applies to goals created after the schema lands.

## Acceptance criteria

1. A coach can see, for the current season, a list of every player on their teams with that player's current PDP status (open / overdue / completed) and the next conversation date.
2. Creating the first PDP file for a player auto-templates 2, 3, or 4 conversation rows (matching the club / team configured cycle size) with `scheduled_at` distributed evenly across the season's start/end dates.
3. A coach can open a conversation, fill in agenda + notes + agreed actions, and sign it off. The PDP file's status updates accordingly.
4. A player linked to the PDP can fill in a self-reflection text *before* the conversation. After the coach signs off, the field becomes read-only.
5. The conversation form shows an "evidence" sidebar listing every evaluation, activity, and goal change for that player since the previous conversation. None of those rows are editable from the sidebar.
6. Goals can be linked to one or more methodology principles, football actions, positions, or player-values. The new `tt_goal_links` table is queryable from the player profile and the print template.
7. Player values exist as a `tt_lookups` row of type `player_value`, seeded with the 8 starters, editable from Configuration → Lookups.
8. The end-of-season verdict is created as a separate row (`tt_pdp_verdicts`), with a decision (promote / retain / release / transfer), summary, and signature pair (coach + head of academy).
9. The auth matrix has new `pdp_file` and `pdp_verdict` rows with the cap-sets above. Personas matching those rows can see and act on the appropriate surfaces; personas without can't.
10. Workflow templates `pdp_conversation_due` and `pdp_verdict_due` register on activation, fire reminders to the right user, and escalate to head of academy on overdue.
11. The print template renders with the photo, season label, current goals + status, agreed actions, and signature lines on a single A4. The "include evidence" toggle adds the eval summary + methodology block as a second page.
12. When a new season is set current, open goals from the previous PDP carry over to a freshly-created file for that player. Conversations are blank.
13. The native calendar entry for each scheduled conversation persists in `tt_pdp_calendar_links` with `provider = 'native'`. The interface is in place for #0031 to add a `'spond'` writer.
14. PHP lint passes; legacy-sessions-gate, msgfmt, docs-audience CI all green.
15. NL translations updated for every new user-facing string. New documentation topic `pdp-cycle.md` (English + Dutch) covering coach + head-of-academy + parent perspectives.

## Notes

### Sprint plan (2 sprints)

#### Sprint 1 — Data + write paths (~16-22h)

- Migration: `tt_seasons`, `tt_pdp_files`, `tt_pdp_conversations`, `tt_pdp_verdicts`, `tt_goal_links`, `tt_pdp_calendar_links`. Idempotent CREATE-IF-NOT-EXISTS + Activator schema mirror.
- New `player_value` lookup type seeded.
- `tt_teams.pdp_cycle_size` nullable column.
- `tt_config` defaults: `pdp_cycle_default = 3`, `pdp_print_include_evidence = 0`.
- Repositories: `SeasonsRepository`, `PdpFilesRepository`, `PdpConversationsRepository`, `PdpVerdictsRepository`, `GoalLinksRepository`.
- REST endpoints: `/seasons`, `/pdp-files`, `/pdp-files/{id}/conversations`, `/pdp-files/{id}/verdict`. Standard `permission_callback` patterns.
- Goal save handler extended to accept `links` array; persist via `GoalLinksRepository::sync`.
- `PdpCalendarWriter` interface + `NativeWriter` default. No Spond — that's #0031.
- Auth matrix entries seeded for `pdp_file` + `pdp_verdict` (additive — existing matrix rows untouched).
- Workflow template registration scaffold (templates exist, no UI yet).

#### Sprint 2 — UX + integration (~14-20h)

- Coach `?tt_view=pdp` list view + detail view (FrontendListTable + custom detail render).
- Conversation form (pre-meeting / post-meeting modes, evidence sidebar, player self-reflection field).
- Verdict form.
- Player / parent `?tt_view=my-pdp` read-only view + ack flow.
- Wp-admin `?page=tt-seasons` thin CRUD.
- Workflow templates wired to actually fire (cadence, escalation).
- Carryover one-shot job (runs on `tt_seasons.is_current` flip).
- Print template rebuild (single A4 + evidence-toggle second page).
- Tile in dashboard (Performance group, gated by `tt_view_pdp` cap).
- Documentation topic `pdp-cycle.md` + Dutch translation + audience marker.
- NL `.po` updates.
- SEQUENCE.md update.

### Estimate

~30-42h across the two sprints. Sprint 1 is heavier (schema + repositories + REST + matrix); sprint 2 is wider but uses existing patterns (FrontendListTable, workflow engine, print router, auth matrix). Compresses sharply if we reuse the #0022 / #0033 / #0027 surfaces aggressively, which the design assumes.

### Cross-references

- **#0017** (trial player module) — out of scope; revisit once #0017 ships.
- **#0022** (workflow & tasks engine) — `pdp_conversation_due` and `pdp_verdict_due` are templates registered against the existing engine; no engine changes needed.
- **#0027** (methodology) — provides the principles, football actions, learning goals that goals link to. Read-only consumer.
- **#0028** (goals as conversational thread) — orthogonal; #0028 is about the goal entity's history. The PDP cycle is the structural container that gives a goal its "why" and timeline. Could ship before or after — they don't block each other.
- **#0031** (Spond integration) — owns the actual Spond write. This epic ships the interface + the native fallback; the Spond writer plugs in there.
- **#0033** (auth matrix) — `pdp_file` and `pdp_verdict` are new entity rows. Matrix admin handles seeding + per-club overrides.
- **#0036–#0041** (recent demo-readiness sweep) — establishes the dashboard tile / drawer / save-redirect / list-table conventions this epic follows.
- **#0042** (youth-aware contact strategy) — orthogonal; the PDP doesn't need phone-as-alt-to-email or push notifications to ship. If #0042 lands first, PDP workflow nudges automatically pick up the better delivery channel.

### Decisions locked during shaping

All 15 questions answered. The locked decisions are above; the question-and-answer trail itself isn't kept here (per the "questions inline, not in a doc" rule). The four overrides on initial recommendations:

- Cycle configurability is per-club default + per-team override (was: per-club only).
- Spond write-back is in scope as an interface today; the actual Spond writer is #0031's deliverable.
- Two sprints, not four — bundled via the existing #0022 / #0033 / #0027 reuse.
