<!-- type: feat -->

# #0027 — Football methodology module

## Problem

TalentTrack today is strong on player tracking (evaluations, goals, attendance) but light on the **coaching content** side: principles, formations, position-specific role definitions, set-piece intent. Coaches at most academies work from external PDFs and personal notes; the plugin doesn't help them codify, share, and operationalize their methodology.

The user (Casper) has a complete coaching methodology document — _"Het spelen van voetbal"_, UEFA B-Youth project, May 2023 ([07. Voetbalmethode.pdf](../07.%20Voetbalmethode.pdf)) — that defines a coherent framework: vision → game principles → positions in formations → set pieces. This spec brings that framework into TalentTrack as a structured, queryable, club-customizable library that the rest of the plugin (sessions, evaluations, future team-planning #0006) can reference.

## Proposal

A **methodology library module** with five primary content types:

1. **Formaties** (formations) — formation definitions (e.g. 1:4:2:3:1), the canonical visual + jersey-number map.
2. **Posities binnen formatie** (positions in formations) — each position card has attacking-phase tasks, defending-phase tasks, and a clear role label (e.g. "Vleugelverdediger / Wing-back").
3. **Spelprincipes** (game principles) — the core IP. Each principle is coded (e.g. `AO-01`), classified by team-function + team-task, and ships with explanation, team-level guidance, per-line guidance (Aanvallers / Middenvelders / Verdedigers / Keeper), and a visual on the formation.
4. **Spelhervattingen** (set pieces) — corners, free kicks (direct + cross), penalties, in attacking + defending variants. Bullet-list intent + diagrammed positions.
5. **Speelwijze / Visie** (style + vision) — the umbrella record: formation, style of play, way of playing, important player traits. One per club; references specific formations and principles.

Both **TalentTrack-shipped** (read-only, seeded from Casper's methodology document) AND **club-authored** content live side-by-side. Clone-to-edit gives clubs a fork point; the shipped originals stay intact through plugin updates.

**Drills** and **session templates** explicitly defer to v2 — Casper's actual methodology is principles-and-roles-in-formations centric, not drill-centric, and the v1 scope rebalances accordingly.

Decisions locked during shaping (25 April 2026):

- **Casper authors all seed content** — sourced from the methodology PDF. Approximately: 1 formation (1:4:2:3:1), 11 position cards, ~18-20 game principles across the 6 team-task buckets, ~7 set-piece entries. ~12h of authoring time on top of dev work, but the content is already structured in the PDF.
- **Multilingual JSON columns on every catalogue row.** Same pattern as `tt_lookups.translations` (v3.6.0). Each user-facing string field (title, explanation, guidance, role label, task list) stores `{"en": "...", "nl": "..."}` JSON; the renderer falls back to NL → EN → empty.
- **Ship #0027 first, designed for #0006 (team planning) to consume later.** Principles get a `tt_team_plan_id` reverse-index that's empty until #0006 ships; no schema change needed when team planning lands.
- **v1 scope: principles + formations + positions + set pieces.** Drills and session templates explicitly v2.

## Scope

### Schema

Six new tables. All include the standard `is_shipped` flag (1 = TT-curated, immutable; 0 = club-authored), `archived_at`, `created_at`, `updated_at`. Multilingual fields store JSON keyed by locale.

```sql
tt_formations
  id, slug (e.g. "1-4-2-3-1"),
  name_json,                        -- {"en":"1-4-2-3-1","nl":"1:4:2:3:1"}
  description_json,
  diagram_data_json,                -- {x,y} positions for each jersey number on a normalized 0-100 grid
  is_shipped, archived_at, created_at, updated_at

tt_formation_positions
  id, formation_id, jersey_number (1-11),
  short_name_json,                  -- e.g. {"en":"Wing-back","nl":"Vleugelverdediger"}
  long_name_json,
  attacking_tasks_json,             -- array of strings keyed by locale: {"nl":["...","..."],"en":["..."]}
  defending_tasks_json,
  sort_order, is_shipped

tt_principles
  id, code (e.g. "AO-01"),
  team_function_key,                -- enum: aanvallen | omschakelen_naar_verdedigen | omschakelen_naar_aanvallen | verdedigen
  team_task_key,                    -- enum: opbouwen | scoren | overgang_balverlies | overgang_balwinst | storen | doelpunten_voorkomen
  title_json,
  explanation_json,
  team_guidance_json,
  line_guidance_json,               -- keyed: {"aanvallers": {...}, "middenvelders": {...}, "verdedigers": {...}, "keeper": {...}}
  default_formation_id,             -- formation the visual was authored against
  diagram_overlay_json nullable,    -- arrows / highlights on the formation
  is_shipped, archived_at, created_at, updated_at

tt_set_pieces
  id, slug,
  kind_key,                         -- enum: corner | free_kick_direct | free_kick_pass | penalty | throw_in
  side,                             -- enum: attacking | defending
  title_json,
  bullets_json,                     -- array per locale of bullet strings
  default_formation_id,
  is_shipped, archived_at, created_at, updated_at

tt_methodology_visions
  id, club_scope (NULL for shipped, club_id when added),
  formation_id, style_of_play_key,
  way_of_playing_json,
  important_traits_json,            -- list of player traits per locale
  notes_json,
  is_shipped, created_at, updated_at

tt_methodology_principle_links     -- reverse index for #0006 (team planning)
  id, principle_id, entity_type ('team_plan' | future), entity_id,
  created_at
```

The lookup-style enums (`team_function_key`, `team_task_key`, `kind_key`, `side`, `style_of_play_key`) are PHP constants — not `tt_lookups` rows — because they're tied to the methodology's structural taxonomy and clubs shouldn't add new ones. If a club's methodology truly needs a new team-task category, that's a code-level extension.

### Library browser UI

New top-level admin menu: **Methodology** (under TalentTrack), with four tabs:

- **Spelprincipes** — filterable list. Filters: team-function, team-task, source (shipped / club / both), formation, search. Each row shows code, title, formation badge. Click → detail view.
- **Formaties & Posities** — list of formations; click into a formation reveals all 11 position cards laid out on the diagram. Click a position → detail panel with attacking + defending tasks.
- **Spelhervattingen** — grouped by kind (corner / vrije trap / penalty); each entry shows the bullet list and the diagrammed positions.
- **Visie** — single record per club (the active club's vision). Edit form for the clubs to articulate their own style + chosen formation + traits. Shipped sample: one populated example based on the PDF.

Frontend tile (under Coaching group): **Methodology** with sub-routes mirroring the four tabs. Read-only on the frontend in v1 — the authoring UI lives in wp-admin (consistent with #0019's "admin tools migrate later" sequencing). Reading on the frontend is enough for coaches to reference during session planning and conversations.

### Detail views

**Principle detail** (single page or modal):
- Header: code badge (e.g. "AO-01"), team-function + team-task chips, source badge (shipped / club).
- Title (locale-rendered).
- Explanation block.
- Team guidance block.
- Per-line guidance: Aanvallers / Middenvelders / Verdedigers / Keeper as four tabs or stacked sections.
- Formation diagram with the principle's overlay.
- Cross-link: "Used in N team plans" (placeholder for #0006).
- Buttons: **Edit** (only if club-authored), **Clone & edit** (always available), **Archive** (club-authored only).

**Position detail** (single page or modal):
- Header: jersey number, short + long role names.
- Formation context (which formation this position belongs to).
- Two columns: Attacking tasks (bulleted list, locale-rendered), Defending tasks.
- Diagram with this position highlighted.
- Edit / Clone & edit / Archive same pattern.

**Set-piece detail**:
- Header: kind, side, title.
- Bullet list.
- Diagram.
- Edit / Clone & edit / Archive.

### Formation diagram rendering

A reusable component: SVG-based formation pitch rendered from `tt_formations.diagram_data_json`. Inputs:
- `formation_id`
- Optional `highlight_position` (jersey number to emphasize)
- Optional `overlay_data` (arrows + zones from the principle's `diagram_overlay_json`)
- Locale (for any text annotations)

Reused across principle detail, position detail, set-piece detail. Single component, single CSS file.

v1 supports static positions + simple overlay arrows. Animated drill rendering is a v2+ concern.

### Clone-to-edit flow

Click "Clone & edit" on any shipped record → a copy of the row is created with `is_shipped = 0` and the originating shipped row's ID stored in a `cloned_from_id` column (added to each table — already in the schema sketch implicitly via a generic `cloned_from_id BIGINT NULL`). The copy opens in the edit form. Saving creates a new club-authored entry; the shipped original is unaffected.

The library list shows both: shipped originals + the club's clones, with a small "modified from AO-01" badge on the cloned row. Clubs can choose which is "their" canonical version via a small `is_active_for_club` flag (one-of-each-code per club).

### Seed content (from the PDF)

Casper authors all seed content during the implementation PR. Approximate content list (extracted from the PDF):

**Formations (1):**
- 1:4:2:3:1

**Position cards on 1:4:2:3:1 (11):**
- Keeper (1) — opbouw + organisatie
- Vleugelverdedigers (2, 5) — wing-backs, attacking + defending tasks
- Centrale verdedigers (3, 4) — central defenders, attacking + defending tasks
- Verdedigende middenvelders (6, 8) — defensive midfielders
- Aanvallende middenvelder (10) — attacking midfielder
- Buitenspelers (7, 11) — wingers
- Centrale spits (9) — striker

(positions 2 + 5 share the same role card; 3 + 4 share; 6 + 8 share; 7 + 11 share — six unique role cards filling 11 jersey numbers, plus keeper = 7 distinct cards mapped to 11 slots).

**Principles (~18-20):**
- **AO-01..AO-05** — Aanvallen / Opbouwen (build-up): 5 principles
- **AS-01..AS-02** — Aanvallen / Scoren (scoring): 2 principles
- **OV-01..OV-03** — Omschakelen aanvallen→verdedigen (transition after loss): 3 principles
- **OA-01..OA-03** — Omschakelen verdedigen→aanvallen (transition after gain): 3 principles
- **VS-01..VS-05** — Verdedigen / Storen (disrupting): 5 principles
- **VV-01..VV-03+** — Verdedigen / Doelpunten Voorkomen (preventing goals): 3+ principles

**Set pieces (~7):**
- Aanvallen: Corner, Vrije trap direct, Vrije trap voorzet, Penalty (4)
- Verdedigen: Corner, Vrije trap direct, Vrije trap voorzet (3)

**Vision (1 sample):**
- The PDF's articulation: 1:4:2:3:1 + Aanvallend + Verzorgd positiespel diepte zoekend via zijkanten + 3 belangrijke eigenschappen.

Authoring effort: ~12h. The PDF is the source of truth; the migration translates each section into the appropriate JSON-keyed table rows. Dutch is the source language; English translations follow per the multilingual JSON pattern (Casper authors NL; EN can be a separate pass or use #0025 if/when that ships).

### Cross-module wiring (placeholders for v1)

- **Goals** — a goal can optionally reference a principle (`goal.linked_principle_id`). Renders as "Towards principle AO-01: ..." on the goal card. Drives the "what principle does this goal support?" coaching conversation. v1: schema column added; UI hookup is a small toggle on the goal create/edit form.
- **Sessions** — a session can optionally reference 1-N principles being practiced. Schema: pivot table `tt_session_principles (session_id, principle_id, sort_order)`. UI hookup: optional multi-select on the session form. Renders on the session detail.
- **Team plans (#0006)** — placeholder reverse-index in `tt_methodology_principle_links`. Schema-ready; UI lands when #0006 ships.

These cross-links are explicitly v1 features because they're cheap (one column or one pivot table each) and they're what makes the methodology library functional rather than ornamental.

## Out of scope (v1)

- **Drills.** Deferred to v2. The methodology centerpiece is principles + roles in formations; drills are a separate content type that doesn't share schema or UI with principles. Cleaner to ship as a separate increment.
- **Session templates.** Deferred to v2. Session structuring is part of the same drill-side concern.
- **Animated/interactive drill viewers.** Out of v1 entirely.
- **Video upload.** Out of v1.
- **Multiple formations.** v1 ships only 1:4:2:3:1 as the seeded formation. The schema supports multiple; clubs (or a v2 update) can add more. Casper hasn't authored other formation content yet.
- **Cross-club sharing of methodology** (publishing a club's library publicly). Defer; potentially intersects with #0011 multi-tenant + community-content layer.
- **AI-assisted authoring** of new principles. A coach asks "draft me a build-up principle for high-press response" → generated text. Tempting but quality-risky for an opinionated coaching framework. Defer.
- **Front-end authoring** (frontend create/edit for principles). v1 keeps authoring in wp-admin; frontend is read-only. Migrating the authoring UI mirrors #0019's pattern post-launch.
- **Methodology-driven evaluation rubrics.** A future bridge: an evaluation form for "how did the team execute principle AO-02 today?" — interesting but separate. Out of v1.

## Acceptance criteria

- [ ] **Schema**: all 6 tables + 1 pivot table created via migration. Existing data unaffected.
- [ ] **Seed migration**: TT-shipped content (1 formation, 11 position cards, ~18-20 principles, ~7 set pieces, 1 sample vision) inserted on plugin activation/update. All rows have `is_shipped = 1`. Idempotent: re-running the migration doesn't duplicate.
- [ ] **Multilingual rendering**: each catalogue field renders in the viewer's locale, falls back NL → EN → empty if locale missing.
- [ ] **Library browser**: admin can browse principles by team-function/team-task filter; formations + positions; set pieces; vision.
- [ ] **Detail views**: principle, position, set-piece detail pages render with full content + diagram.
- [ ] **Formation diagram**: SVG-based, renders from `diagram_data_json`, supports highlighted position + overlay arrows.
- [ ] **Authoring CRUD**: club admin can create / edit / archive club-authored principles, positions, set pieces, and the club's vision record.
- [ ] **Clone-to-edit**: any shipped row can be cloned; the clone is editable; the shipped original remains read-only.
- [ ] **Goal linkage**: goal create/edit form has an optional principle picker. Renders on the goal card.
- [ ] **Session linkage**: session create/edit form has an optional multi-select principle picker. Renders on the session detail.
- [ ] **Frontend read-only**: a coach on the frontend sees the four-tab Methodology view (read access) but cannot edit. wp-admin authoring is the authoritative surface.
- [ ] **No regression** on goals, sessions, or evaluations rendering when no principle is linked.
- [ ] **Translations**: all UI strings (filter labels, headers, badges, button text) translated to nl_NL.po. Catalogue content is multilingual via JSON columns, not via .po.
- [ ] **Docs**: new `docs/methodology.md` (audience: user — coaches reference it) and `docs/admin-methodology.md` (audience: admin — authoring guide) plus nl_NL counterparts.
- [ ] **Help-link integration**: tile + admin page wired into the existing help-link infrastructure pointing to the new docs.

## Notes

### Why principles + formations are the centerpiece, not drills

The user's actual methodology — captured in the [PDF](../07.%20Voetbalmethode.pdf) — is a Dutch UEFA B-Youth-style framework: vision → team functions → team tasks → game principles → role-specific tasks per formation. Drills are a downstream concern (you build drills _from_ principles, not the other way around). v1 puts the framework first; v2 builds drills/templates on top of an already-authored methodology.

This rebalances the original idea (#0027 raw idea framed drills as primary). The reshape happened during shaping when the user shared the methodology document; the spec follows the document's actual emphasis.

### Why multilingual JSON columns instead of #0025

The methodology library is admin-authored, low-volume, high-craft content. Auto-translation would butcher the precise football terminology ("verzorgd positiespel diepte zoekend via zijkanten" doesn't survive a Google Translate roundtrip). Manual NL + EN authoring per row, stored in JSON, gives quality control. If/when #0025 ships and the translation engine demonstrates good football-terminology fidelity, an opt-in path could fall back to the engine for missing locales — but never overwrite manually authored content.

### Why wp-admin authoring, frontend read-only

Methodology authoring is a careful, infrequent activity (write the principle once, refine over months). The wp-admin form table is fine for that workflow; the frontend's strength is everyday use, which is reading + linking. Following the same pattern that #0019 took for other admin surfaces, methodology authoring will eventually migrate to the frontend, but not in v1 — it'd add ~10h of frontend form work for negligible UX gain.

### How #0006 (team planning) consumes #0027

When #0006 ships, a "team plan" can declare which principles it builds toward (e.g. "this season we focus on AO-02 + VS-01 + OV-03"). That linkage flows through `tt_methodology_principle_links`. #0006 reads from `tt_principles`, no schema change required. Coaches see "principles in this team's plan" on each principle's detail view via the reverse index.

### Touches

New:
- `src/Modules/Methodology/MethodologyModule.php`
- `src/Modules/Methodology/Repositories/PrinciplesRepository.php`
- `src/Modules/Methodology/Repositories/FormationsRepository.php`
- `src/Modules/Methodology/Repositories/SetPiecesRepository.php`
- `src/Modules/Methodology/Repositories/MethodologyVisionRepository.php`
- `src/Modules/Methodology/Admin/MethodologyPage.php` — admin browser + tabs.
- `src/Modules/Methodology/Admin/PrincipleEditPage.php`, `PositionEditPage.php`, `SetPieceEditPage.php`, `VisionEditPage.php`
- `src/Modules/Methodology/Frontend/MethodologyView.php` — read-only frontend tile view.
- `src/Modules/Methodology/Components/FormationDiagram.php` — reusable SVG renderer.
- `src/Modules/Methodology/Helpers/MultilingualField.php` — JSON-locale rendering helper.
- `database/<NN>-add-methodology-tables.sql`
- `database/<NN+1>-seed-methodology-content.sql` — the bulk of the authoring work.
- `assets/css/methodology.css` (or a section in `frontend-admin.css`) — formation diagram + library list styles.
- `docs/methodology.md` (audience: user) + `docs/admin-methodology.md` (audience: admin) + nl_NL counterparts.

Existing:
- `src/Modules/Goals/` — add `linked_principle_id` column + form picker + render.
- `src/Modules/Sessions/` — add session-principle pivot + form picker + render.
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — no changes required (Vision lives under Methodology, not Configuration).
- Tile router (`DashboardShortcode::dispatchCoachingView`) — add Methodology tile entry.
- `languages/talenttrack-nl_NL.po` — UI strings.

### Depends on

- **Nothing blocking.** Schema is self-contained. Cross-links to goals + sessions are additive (nullable column / nullable pivot).
- **Does NOT depend on** #0006, #0011, #0023, #0025, #0026, #0029. Can ship independently.

### Blocks (or unblocks)

- **#0006 (team planning)** — consumes #0027's `tt_principles` table. Without #0027, #0006 has to invent its own principles concept; with #0027, #0006 reads from existing rows.
- **Future drill / session template module** (the deferred v2 of this idea) — adds two tables (`tt_drills`, `tt_session_templates`) that reference principles via similar pivots.
- **#0017 (trial player module)** — trial evaluations may want to reference "did the player demonstrate principle X?" — light future linkage.

### Sequence position

Phase 1 follow-on. Lands after #0019 closes (already done per memory). Independent of #0011, #0023, #0025, #0026, #0029. Slot when convenient — significant authoring effort is the long pole.

### Sizing

~52 hours:

| Work | Hours |
| --- | --- |
| Schema + 6 tables + 1 pivot + activator wiring | 2.5 |
| Repositories (4 × ~1h) | 4.0 |
| Library browser admin page (4 tabs, filters, search) | 4.0 |
| Principle detail view + edit form | 3.0 |
| Position detail view + edit form | 2.5 |
| Set-piece detail view + edit form | 2.0 |
| Vision detail view + edit form | 1.5 |
| FormationDiagram SVG component | 4.0 |
| Multilingual JSON helpers + per-field rendering | 2.0 |
| Clone-to-edit flow | 2.5 |
| Goal linkage (column + picker + render) | 2.0 |
| Session linkage (pivot + picker + render) | 2.5 |
| Frontend read-only methodology view (tile + tabs) | 3.5 |
| Seed migration: Casper authors 1 formation + 11 position cards + 18-20 principles + 7 set pieces + 1 vision (NL primary; EN follows) | 12.0 |
| Documentation (`methodology.md` + `admin-methodology.md` + nl_NL) | 3.0 |
| `nl_NL.po` updates | 1.0 |
| Testing across browse / clone / edit / link flows | 3.0 |
| Buffer for the diagram component (SVG always more work than estimated) | 2.0 |
| **Total** | **~52h** |

This is genuinely a multi-PR epic. Suggested split:

- **Sprint A (~18h)**: schema + repositories + library browser + detail views (read-only, shipped content rendering).
- **Sprint B (~14h)**: authoring CRUD + clone-to-edit + Vision form.
- **Sprint C (~12h)**: seed migration (authoring time-dominated).
- **Sprint D (~8h)**: cross-module linkage (goals, sessions) + frontend read-only view + docs.

Each sprint is shippable on its own (the library has value with shipped content even before authoring lands; cross-linkage adds value after the library exists).

### Cross-references

- Idea origin: [`ideas/0027-feat-football-methodology-module.md`](../ideas/0027-feat-football-methodology-module.md).
- Source content: [`07. Voetbalmethode.pdf`](../07.%20Voetbalmethode.pdf) — Casper Nieuwenhuizen, UEFA B-Youth project, May 2023. The seed migration translates this document's structure into the catalogue tables.
- Adjacent: #0006 (team planning — primary consumer), #0017 (trial evaluations may link to principles), #0010/#0025 (multilingual layer — JSON columns avoid the engine for content; UI strings still go through `.po`).
- v2 follow-on idea: drill library + session template library, building on this foundation.
