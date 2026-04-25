<!-- type: feat -->

# Football methodology module — drills, principles, and content libraries

Origin: post-#0019 v3.12.0 idea capture. TalentTrack today is strong on player tracking (evaluations, goals, attendance) but light on the *coaching content* side: drills, principles, periodization frameworks, age-appropriate session templates. Coaches often work from external PDFs / their own notes; the plugin doesn't help them reuse and standardize their methodology.

This idea proposes a methodology module — a content library that ships with TalentTrack-curated catalogues AND lets each club bring their own.

## Why this matters

- **Consistency across coaches in the same academy** — when each coach builds sessions from scratch, the U10 and U12 trainings look unrelated even when they should follow a shared age-progression. A shared library produces continuity.
- **Onboarding new coaches** — a new U13 coach can copy the existing U13 plan rather than starting blank.
- **Differentiation vs generic player-tracking SaaS** — methodology + tracking is a stronger product than tracking alone.

## Decision (Casper, post-0019 prompt)

**Both** TalentTrack-shipped catalogues AND bring-your-own per club.

- **TalentTrack-shipped**: a starter library of drills, principles, and session templates curated by us. Ships read-only out of the box. Updated via plugin updates.
- **Bring-your-own**: each club can author their own drills/principles/templates. Authored entries live in club tables, not plugin-shipped tables. They survive updates and migrate cleanly.

Both surfaces share a common UX shell — a library browser, drill-detail view, and "use in session" wiring.

## What the module covers

Three content types in the v1 scope:

1. **Drills** — discrete exercises with name, age range, focus area (eg. passing, finishing), description, optional diagram/photo, suggested duration.
2. **Principles** — strategic concepts at the team level (eg. "build from the back through the half-spaces"). Used by #0006 (team planning) when shaping season-level intent.
3. **Session templates** — pre-built session structures combining drills (warmup → main → game form → cooldown). Coaches clone a template into a real session.

Out of scope for v1 (defer to later iterations): tactical-board / animated-drill viewer, video upload, peer review of community drills.

## Open questions to resolve before shaping

1. **Catalogue scope and sourcing.** What's in the TT-shipped library at v1? Working assumption: ~30 drills + ~10 principles + ~5 session templates, sourced/written internally. Need to decide who authors the seed content and how it gets reviewed.
2. **Update mechanics.** When TT ships an update with revised seed content, do clubs see the new version? Do edits to TT-shipped content fork into their own library? Spec direction: TT-shipped is read-only at the row level; clubs that want to modify must clone-then-edit, generating a club-owned copy.
3. **Naming / namespacing.** Same name in TT-shipped catalogue and club catalogue should not collide. Likely a `source` column on the table: `tt_shipped` vs `club`.
4. **Localization.** TT-shipped content is authored in some language(s). Which? English by default with Dutch translation? Use #0025's translation flow to render in viewer's locale?
5. **Sharing across clubs.** Once #0011 ships and there are multiple paying clubs, can clubs opt-in to share their library publicly? (Probably v2 scope.)
6. **Touch into existing modules.** Drills referenced by sessions need to back-link from the drill detail to all sessions that used it. Principles referenced by team plans similarly. Adds two reverse-index queries.

## Touches (when shaped)

- New module: `src/Modules/Methodology/` — `DrillsRepository`, `PrinciplesRepository`, `SessionTemplatesRepository`, frontend views, REST.
- New tables: `tt_drills`, `tt_principles`, `tt_session_templates`, optionally `tt_drill_tags` for filterable focus-area tags.
- Seed migration: ~30 drills + ~10 principles + ~5 templates as TT-shipped read-only rows.
- Frontend tile group — adds a "Methodology" tile under Coaching / Performance group with sub-views for browsing drills, principles, templates.
- Sessions tile gains a "from template" button on its create form.
- Documentation — substantial; the methodology surface is a learning curve item.

## Sequence position (proposed)

Significant size (~50-70h estimated). Best after #0006 (team planning module) since they share the principle concept. Best before #0011 (monetization) launch if a marketing-tier differentiator matters; can ship afterwards as part of the Pro/Academy tier feature set.
