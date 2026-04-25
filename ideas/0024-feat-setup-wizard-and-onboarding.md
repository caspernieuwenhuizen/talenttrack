<!-- type: feat -->

# Setup Wizard for new installs — guided onboarding

Origin: April 2026 idea-funnel pass. Captured here as a standalone idea after the funnel doc was merged into the formal `ideas/` + `specs/` structure.

## Why this matters (working assumption, not yet validated)

Activation determines retention. The first 10 minutes after a club installs the plugin determines whether they stick around. If they land in wp-admin and see "TalentTrack" with empty Players, empty Teams, and no idea what to do, most will leave. A guided wizard converts "I installed something" into "I have a working system."

Specifically relevant given:

- **Once monetization (#0011) ships**, trial conversion will hinge on trial users reaching a useful state quickly. Bad activation = trials never get to the point of paying.
- **Academy-specific config** (name, color, season label, default age groups, theme inheritance from #0023) currently lives across multiple Configuration tabs. A wizard front-loads it.
- **First-team / first-coach setup** has implicit dependencies (people record → WP user link → role grant) that are not obvious to a first-time admin.

## Open questions to resolve before shaping

These are the questions the funnel doc surfaced. They need answers before this becomes Ready.

### Q1 — UX pattern

Three viable patterns:

- **Mandatory wizard**: cannot enter wp-admin TalentTrack pages until wizard complete. Forceful, ensures setup but feels like a wall.
- **Optional wizard**: a banner offers it; admin can dismiss anytime and configure manually.
- **Optional with persistent re-entry** (likely best): banner on first activation, but a `TalentTrack → Welcome` menu entry stays available until completed or explicitly hidden. Each step skippable individually.

To decide: which fits the product positioning? Probably depends on whether monetization is in play (trial users benefit from forcefulness; perpetual-free users might resent it).

### Q2 — Visual treatment

- **Full-screen takeover**: hides wp-admin chrome, dedicated branded experience. Examples: WooCommerce, Yoast. Looks polished, breaks WP convention, harder to localize cleanly.
- **Inline admin page**: regular admin page with step navigation, native to WP. Less impressive visually, easier to build and maintain, easier to localize properly.

To decide: visual ambition vs development cost vs localization risk.

### Q3 — Scope of v1

Tiered options, listed roughly in order of essential-to-nice-to-have:

**Tier 1 — minimum viable wizard (probable v1 scope):**
1. Welcome / what is this — single explanatory screen
2. Academy basics — name, primary color, season label, default date format
3. First team — name + age group
4. First admin — link current WP user to a `tt_people` record, optionally explicit Club Admin role grant
5. Done — summary + suggested next actions

**Tier 2 — accelerates time-to-value:**
6. Add players (bulk paste-CSV or quick-entry rows; skippable)
7. Invite first coach (creates `tt_people` + email invite to register WP account; skippable)
8. Confirm/customize evaluation categories (defaults: Technical, Tactical, Physical, Mental)
9. Branding (logo upload, secondary color, theme inheritance toggle from #0023)
10. Frontend dashboard page (auto-create with shortcode, or pick existing)
11. Backup setup (steps from #0013's wizard — schedule + destination)

**Tier 3 — separate features, not part of wizard:**
12. First-time admin tour with overlays explaining each menu
13. Sample-data option (link to #0020 demo generator)

To decide: keep wizard short and focused (Tier 1 only) and add Tier 2 items as "Recommended next steps" buttons on the Done screen, OR include some Tier 2 inline (which expands wizard length but reduces post-wizard friction)?

### Q4 — Persistence behavior

- **Stateless**: closing the browser mid-wizard means starting over. Bad UX.
- **Stateful**: progress saved to `tt_config` per step; resume on return.

To decide: stateful is the obvious answer; the open detail is whether to also offer a "reset wizard" button (some admins may want to redo).

### Q5 — Sprint placement

Strong arguments either direction:

**Arguments for shipping immediately, before #0011 monetization:**
- Activation is everything. Without good activation, monetization conversion will be weak.
- Self-contained, ~5-7 files, ~1-2 weeks of work.
- Improves every future install, including monetization trials.
- Natural prerequisite to a paid product — a paid product needs a polished entry point.

**Arguments for later (after #0011):**
- The plugin works without it. Manual setup is possible.
- Monetization infrastructure may be more strategically valuable per unit of effort.
- DR baseline (#0013 Sprint 1) is more universally useful per minute of work.

To decide: when does this ship? Funnel recommends **before #0011** for activation reasons.

### Q6 — Interaction with related epics

- **#0011 (Monetization)**: wizard's "Done" screen is the natural place to surface the trial CTA. If trial is active when wizard completes, suggest the user explore Pro features.
- **#0013 (Backup)**: backup-config step in the wizard. If #0013 ships first, embed a thin wrapper around its wizard. If this ships first, leave the step as a placeholder that #0013 fills.
- **#0023 (Theme inheritance)**: branding step in the wizard exposes the toggle alongside Primary/Secondary colors.
- **#0020 (Demo data)**: "Try with sample data" button on the Welcome screen → uses demo generator under a temporary scope.

### Q7 — Localization

Everything in the wizard must localize cleanly. Same display-time pattern from current `.po` workflow. First impressions in Dutch matter — a Dutch club seeing English copy in their first 10 minutes loses confidence in the product.

Open detail: marketing-style copy ("Welcome to TalentTrack — let's get your academy up and running") is harder to translate well than functional UI strings. Consider whether to:

- Write translated copy directly in Dutch first, English as the translation
- Hire a Dutch native speaker for review
- Keep wizard copy intentionally simple and functional rather than warm/marketing

## Touches (when shaped)

- New module: `src/Modules/Onboarding/` — `OnboardingModule.php`, `WizardController.php`, step files
- New admin page: `TalentTrack → Welcome` (or full-screen takeover, depending on Q2)
- New REST endpoints: `POST /onboarding/step/{name}` per step + `GET /onboarding/state` for resume
- `wp_options` keys: `tt_onboarding_state`, `tt_onboarding_completed_at`
- Hooks into #0011 trial start, #0013 backup wizard, #0023 theme inheritance, #0020 demo generator
- `.po` strings — substantial copy block, written in concert with the #0012 anti-AI-fingerprint pass to avoid generic SaaS-onboarding voice

## Estimated effort (unsounded — depends on Q1–Q3 answers)

- **Tier 1 only, mandatory + full-screen** → ~14-18h
- **Tier 1 only, optional + inline** → ~10-12h
- **Tier 1 + Tier 2 (full)** → ~25-30h

## Sequence position (proposed)

Insert between Phase 4 and Phase 5 in SEQUENCE.md, ahead of #0011. Activation work pays into every monetization metric afterwards.
