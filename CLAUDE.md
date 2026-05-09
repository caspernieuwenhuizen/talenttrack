# Claude Code — repo standards

This file is loaded automatically into every Claude Code session at the root
of this repo. **Read it first**, then follow the cross-references below for
the task at hand. If a request conflicts with anything in this file, flag it
before writing code.

The rest of the standards layer already lives in the repo. This file does
**not** repeat what's in `DEVOPS.md`, `docs/architecture.md`, `docs/contributing.md`,
or `AGENTS.md` — it routes you to them. What's added here is the product
principle, the mobile-first front-end rules, and the SaaS-migration discipline
that aren't yet captured elsewhere.

---

## 1. Always-on principle — Player-centric by design

This plugin is a talent management system for youth football academies. The
**player is the center of the system**. Every feature, screen, data model,
and interaction must be designed around the individual player's journey
through the academy — from first trial to graduation or release.

Treat this as a hard architectural constraint, not flavor text. If a proposed
feature does not clearly serve a player's tracking or development, flag it
before building.

### The player is the root entity
- The player record is the spine of the data model. Coaches, sessions,
  matches, assessments, injuries, parents, teams, and notes are all
  *relationships to a player*, not standalone islands.
- Every meaningful piece of data must answer the question: *which player(s)
  does this belong to, and how does it advance their development?* If it
  can't, it probably doesn't belong in this plugin.
- Default views, queries, and reports start from a player (or a cohort of
  players) and expand outward — not from a session, coach, or admin lens.

### The journey is the narrative
Every player has a continuous, chronological journey through the academy.
The UI and data should make that journey visible and queryable:
- Timeline / longitudinal views are first-class. A player's profile must
  always be able to show "what has happened, what's happening now, what's
  next" in chronological order.
- Track progression, not just snapshots. Store dated entries (assessments,
  measurements, minutes played, position changes, age-group moves) so trends
  over months and seasons can be surfaced.
- Key transitions are events worth modeling explicitly: trial, signing,
  age-group promotion, position change, injury, return to play, release,
  graduation. These should be queryable, not buried in free-text notes.

### Development over administration
- When there's a tradeoff between making admin easier and making development
  insight clearer, choose insight.
- Every screen should answer at least one of: *Where is this player now?
  Where have they come from? Where are they going? What do they need next?*
- Avoid generic CRM features (contacts, tasks, calendars) unless they are
  scoped to a player and contribute to their development picture.

### Roles serve the player
- Coaches, scouts, physios, parents are users with permissions — but the
  data they create exists *in service of a player record*. Don't fragment
  the player's data across role-specific silos.

### Language and naming
- Use player-centric language throughout the codebase, UI copy, database
  fields, REST endpoints, and translations. Examples:
  - `tt_player_assessments`, not `tt_assessments`
  - `GET /players/{id}/timeline`, not `GET /events?player_id=...`
- Avoid corporate / HR framings ("employees", "members", "users") for
  players. They are *players*.
- The player's name and photo anchor any screen where they're the subject.

### Privacy and dignity
These are minors. Player-centricity includes protecting the player:
- No player data leaks across academies, age groups, or unauthorized roles.
- Sensitive fields (medical, safeguarding, family situation) are
  permission-gated and audit-logged.
- Parents/guardians have visibility into their own child's record by default.
- When in doubt about exposing a piece of data, default to *less* visibility
  and ask.

---

## 2. Always-on principle — Mobile-first, touch-optimized front end

The front end is Vanilla JS + HTML/CSS, no build step. Every UI change must
follow the rules below.

### Mobile-first by default
- Write base CSS for the smallest viewport (~360px). Use `min-width` media
  queries to scale UP. Never start desktop and patch downward.
  - **Note:** the legacy stylesheets (`public.css`, `frontend-admin.css`,
    `frontend-mobile.css`) were authored desktop-first. Don't extend that
    pattern — new components are mobile-first. **Tracked in #0056.** New
    components are mobile-first; legacy migrations happen one view per
    release until SEQUENCE.md shows zero legacy desktop-first sheets. The
    pilot rewrite is `assets/css/frontend-activities-manage.css` — see
    `docs/architecture-mobile-first.md` for the migration recipe.
- Breakpoints: 480px (large phone), 768px (tablet), 1024px (desktop). Don't
  invent new ones without justification.
- Never rely on hover for critical actions — hover does not exist on touch.
  Hover may only enhance, never gate functionality.

### Layout & sizing
- Use `rem` for typography and spacing, `%` / `fr` / `minmax()` for layout.
  Avoid fixed `px` widths on containers.
- Respect safe areas: apply `padding: env(safe-area-inset-*)` on any fixed
  top/bottom bars so they clear iOS notches and home indicators.
- Use CSS Grid and Flexbox. No floats, no absolute positioning for layout.

### Touch targets & input
- Minimum tappable size: **48×48 CSS px**. Applies to buttons, links, icons,
  checkboxes, list rows, pager buttons. Minimum spacing between adjacent
  targets: 8px.
- Inputs must use the correct `type` AND `inputmode` so mobile keyboards are
  right: `type="email"` + `inputmode="email"`, `type="tel"`,
  `inputmode="numeric"` (jersey numbers, age), `inputmode="decimal"` (height,
  weight, ratings), `autocomplete="..."`. **Enforced via the v3.50.0
  `inputmode` retrofit** (#0056) — all new `<input type="number|tel">`
  elements MUST include `inputmode`. Treat missing `inputmode` on a numeric
  / tel input as a bug; fix as you touch the surrounding code.
- Never disable zoom (`maximum-scale=1` / `user-scalable=no`) — accessibility
  violation.
- Set font-size ≥ 16px on inputs to prevent iOS auto-zoom on focus. Both
  the modern `.tt-input` / `.tt-field` system and the legacy `.tt-form-row`
  inputs honour this rule (the legacy bump landed in v3.50.0 #0056).
- Use `:focus-visible` for keyboard focus rings; do not remove focus outlines.

### Gestures & interaction
- Use native scrolling. Don't hijack scroll. `overscroll-behavior: contain`
  on modals/drawers to prevent body scroll bleed.
- `touch-action: manipulation` on interactive elements to kill the 300ms
  tap delay on Android.
- Long-press, swipe, pinch gestures must have a non-gesture fallback (button,
  menu item) so the feature is reachable without the gesture.
- Tap feedback within 100ms — `:active` state changes, not just JS.

### Performance budget
- First interaction ≤ 200ms after tap on a mid-range Android (Moto G class,
  4× CPU throttle).
- No layout shift after first paint: reserve space for images
  (`width`/`height` attributes or `aspect-ratio`), fonts (`font-display: swap`).
- Front-end JS bundle: keep under 50KB gzipped. No jQuery for new code — use
  native `document.querySelector` and `fetch()`. Existing front end is
  already vanilla JS + REST; preserve that.
- Lazy-load images below the fold with `loading="lazy"`. Defer non-critical
  JS with `defer` / `async` when enqueuing.

### WordPress-specific
- Enqueue all CSS/JS via `wp_enqueue_style` / `wp_enqueue_script`. Never
  inline `<script>` or `<link>`.
- Prefix every CSS class and JS global with `tt-` / `TT.` — assume the
  active theme is hostile.
- Don't apply global resets — only inside scoped wrappers (`.tt-dashboard`).
- Pass nonces on every REST call (`X-WP-Nonce`); use `wp_localize_script`
  for JS config.

### Accessibility
- Semantic HTML first: `<button>`, `<nav>`, `<main>`, `<dialog>`. Don't build
  buttons out of `<div>`s.
- Every interactive element needs a discernible name (visible text or
  `aria-label`).
- Color contrast ≥ 4.5:1 body text, ≥ 3:1 for large text and UI.
- Respect `prefers-reduced-motion` — disable non-essential animation.
- Respect `prefers-color-scheme` if the surface offers a dark variant.

---

## 3. Always-on principle — Wizard-first record creation

Any new feature that introduces a record-creation flow — a new top-level
entity, or a new sub-entity reachable from a "+ New …" button — **MUST**
ship with a wizard implemented against `Shared\Wizards\WizardInterface`,
registered in `WizardRegistry`, and reachable via
`?tt_view=wizard&slug=<…>`.

The flat-form path remains in the codebase as the power-user fallback.
The wizard's final step hands off to it. Entry-point gating via
`WizardEntryPoint::urlFor()` decides which path the user lands on,
governed by the `tt_wizards_enabled` site option.

Multi-step flows beyond record creation — settings panels with > 5
fields, anything involving file upload + mapping + confirmation,
anything that mutates more than one table on save — **SHOULD** also
ship as a wizard. Single-purpose admin pages and lookup tables stay
flat; no benefit from a wizard there.

**Exemptions** require an explicit `Wizard plan: exemption — <reason>`
line in the feature's spec. Two pre-approved exemptions:

- (a) lookup / vocabulary edits (single-field changes).
- (b) bulk operations on existing records.

**Retrofit policy**: existing flat-form-only flows are NOT required to
be retrofitted. The rule applies forward only, from the merge date of
#0058. Specific retrofit work, if it ever feels worth doing, gets its
own idea file.

---

## 4. Always-on principle — SaaS-ready by construction

The medium-term plan is to migrate to a full SaaS front-end (separate web
app, possibly mobile native, possibly multi-tenant). **Every change made now
should leave that migration easier, not harder.** This is not a "do it later"
problem — the cost of an incompatible decision today is rebuilding the same
feature twice.

The principle: **treat the WordPress plugin as one front end consuming a
clean API, not as a monolith where PHP renders coupled to PHP queries.**

### REST is the contract — render is just one consumer
- Every feature must be reachable through the REST API at
  `/wp-json/talenttrack/v1/`. If a feature is implemented as PHP-rendered
  HTML calling repositories directly, **also** add the REST endpoint, even
  if the front end doesn't use it yet.
- New endpoints follow resource-oriented design:
  `GET/POST /players`, `PUT/PATCH/DELETE /players/{id}`,
  `GET /players/{id}/timeline`. No RPC-style verbs in URLs
  (`/do_thing_to_player`).
- Request and response shapes are JSON, stable, documented in
  `docs/rest-api.md`. Breaking changes bump the namespace
  (`talenttrack/v2`), never silently mutate `v1`.
- Every endpoint declares its `permission_callback` using the capability
  model — never `__return_true`, never role-name string checks.

### Keep business logic out of view files
- View / render classes (anything in `src/Shared/Frontend/` or
  `src/Modules/*/Frontend/`) **compose** data; they don't decide it.
  Computing eligibility, weighting evaluations, deriving status, filtering
  by permission — none of that belongs in a `render*()` method.
- Business logic lives in repositories and domain services under
  `src/Modules/*/` or `src/Infrastructure/`. The REST controller and the
  PHP view both call into the same domain layer. If a future SaaS front
  end consumes the REST API, it gets the same answers as the plugin's
  rendered HTML.
- Smell test: if you deleted every file under `src/Shared/Frontend/`,
  could the REST API still return correct data for every feature? If no,
  there's logic in the wrong place.

### Auth shouldn't be permanently chained to WP cookies
- Today: `X-WP-Nonce` for browser, application passwords for integrations.
  This is fine, but lock it behind an abstraction.
- New endpoints authenticate against `current_user_can()` and the
  `AuthorizationService`, not against `is_user_logged_in()` plus role
  string comparisons. The cap layer is portable; the cookie layer is not.
- Avoid leaking WP-isms into payload shapes (`wp_user_id` is fine as a
  field, but don't make API consumers care about WP user roles by name).
  Expose the *capabilities* a user has, not the *role* they hold.

### Tenancy hooks, even before they're used
- Multi-tenant SaaS will need every player, team, evaluation, and PDP
  scoped to an `account_id` (or `tenant_id` / `club_id` — name TBD). For
  now there's one tenant per install, so this is implicit.
- New tables that hold tenant-scoped data should leave room for a
  tenancy column. The cheapest version: add a `club_id INT UNSIGNED
  DEFAULT 1` column on every new table from now on, even though it's
  always 1. That single decision is what separates "easy SaaS migration"
  from "rewrite every query."
- New repositories filter by `club_id` in their `where` clauses, even
  though it's a no-op today. Codify this in `Infrastructure/Query/`
  helpers so it's automatic.
- Don't store tenant boundaries in `wp_options` or `wp_usermeta` — those
  are global to the WP install. Tenant config goes in `tt_config` keyed
  by `club_id`.

### Identity is portable
- Player records reference `wp_user_id` today. That's fine, but the
  *primary identity* is the `tt_players.id` (and someday a `uuid`),
  never the WP user ID. Treat `wp_user_id` as a mapping to one
  authentication backend, not as the canonical identity.
- New player-related tables join on `player_id`, not `wp_user_id`.
- Adding a `uuid CHAR(36) UNIQUE` column to user-facing root entities
  (players, teams, evaluations, sessions) is cheap insurance against
  ID collisions across tenants in a future SaaS migration. Do it on
  new tables; backfill old ones when convenient.

### Front-end coupling rules
- The Vanilla JS front end calls REST. It does **not** read globals
  beyond `window.TT.*` (config, nonce, i18n, current user). No PHP-
  rendered HTML smuggling stateful data into JS via inline scripts.
- DOM selectors are stable, prefixed (`.tt-...`), and treated as a
  contract — don't rename them lightly, future SaaS code may share the
  vocabulary.
- Translatable strings go through `__()` / `_e()` server-side and are
  passed to JS via `TT.i18n` — never hardcoded English in JS.

### File / asset uploads
- Don't lean on `wp-content/uploads/` paths in new APIs. Return a URL,
  not a server-relative path. SaaS will use object storage (S3, R2);
  today's WordPress install is one of many possible backends.
- Image-handling helpers should accept a URL and not assume local FS
  access for reads.

### Background work
- New scheduled work uses the existing workflow engine (`src/Modules/Workflow/`)
  rather than ad-hoc `wp_cron` calls. SaaS migration will replace the
  scheduler underneath; one chokepoint is replaceable, fifty `wp_cron`
  registrations are not.

### What this principle means for "is this PR ready?"
A reviewer should be able to answer yes to all of:
- Could this feature be consumed by a non-WordPress front end today?
- Would a second tenant on this install break this feature? (If yes:
  add the `club_id` scaffold even if it's currently unused.)
- Is the business logic outside the view file?
- Is auth checked via capabilities, not via role-string compare or
  cookie presence?

---

## 5. Always-on principle — Two nav affordances per view, no more, no less

Every routable frontend view (anything reachable via
`?tt_view=<slug>`) emits exactly **two** navigation affordances and
nothing else:

1. **Breadcrumb chain** ending at `Dashboard` — canonical hierarchy.
   Rendered via
   `\TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard()`.
2. **Contextual `← Back to …` pill** — `tt_back`-borne, auto-rendered
   above the chain when the entry URL captured a back-target. Label is
   contextual ("Back to Ajax U17", "Back to John Doe"). Renders nothing
   when no back-target is in the URL — that's intentional.

**No third affordance is ever allowed.** No "Back to dashboard" button.
No "Back to <list>" button. No `FrontendBackButton` (deleted in
v3.110.41) or any analogue. If a custom-label back link feels
necessary, fix the breadcrumb chain to have the right intermediate
crumb — that crumb IS the back-to-list affordance.

The only exempt views are the dashboard root itself
(`PersonaLandingRenderer`), pre-login flows (`AcceptanceView`,
login form), and component renderers / sub-views composed into
other views (`FrontendThreadView`, `FrontendTeammateView`, etc.).

Full mechanism + label resolver + `tt_back` validation rules in
`docs/back-navigation.md`. Read it when adding a new view, when
modifying an existing view's nav, or when reviewing a PR that
touches frontend routing.

---

## 6. Mandatory reading by task type

These are existing repo docs. Read them when the task type matches; don't
duplicate their content here.

| Task type                       | Must read                          |
| ---                             | ---                                |
| Any code change                 | `DEVOPS.md` (ship-along rules, coding style, no AI fingerprints, plugin constants) |
| New module / architecture       | `docs/architecture.md`              |
| Schema / migration              | `docs/architecture.md` § Schema + migrations, `docs/migrations.md` |
| REST endpoint                   | `docs/rest-api.md`                  |
| Documentation change            | `docs/contributing.md` (audience markers + Dutch translation rule) |
| Capability / authorization      | `docs/access-control.md`, `docs/authorization-matrix.md` |
| Hooks / extension points        | `docs/hooks-and-filters.md`         |
| New record-creation flow        | `docs/wizards.md` (framework, registry, entry-point gating) |
| Driving the workflow            | `AGENTS.md` (one agent vs. two, parallel sessions, decision tree) |
| Frontend nav / new view         | `docs/back-navigation.md` (two-affordance contract, `tt_back` mechanism, label resolver) |

If a doc you'd expect doesn't exist, **say so before writing code** — don't
guess at conventions. The lead developer would rather add a doc than have
Claude Code invent a pattern that conflicts with one already in use.

---

## 7. Definition of done — checklist for every PR

A PR is not ready to merge until **all** of these hold:

**From `DEVOPS.md` ship-along rules:**
- [ ] User-facing strings go through `__()` / `_e()` and `tt_lookups` /
      `tt_config` for editable lists.
- [ ] `languages/talenttrack-nl_NL.po` updated in the same PR. Dutch
      `msgstr` filled in.
- [ ] `docs/<slug>.md` AND `docs/nl_NL/<slug>.md` updated for any user-
      visible behaviour change.
- [ ] `SEQUENCE.md` updated if the work is referenced there.

**Player-centricity:**
- [ ] Which player question does this feature help answer? (State it in
      the PR description.)
- [ ] Is the data attached to a player record (directly or via a clear
      relationship)?
- [ ] Would a coach reviewing a player's profile see this in context, not
      in a separate silo?

**Mobile-first front end (if any UI touched):**
- [ ] Renders at 360px width with no horizontal scroll.
- [ ] All interactive targets ≥ 48px and spaced ≥ 8px apart.
- [ ] Inputs have correct `type` / `inputmode` / `autocomplete`.
- [ ] Works with keyboard only (Tab, Enter, Escape).
- [ ] No hover-only functionality.
- [ ] CSS and JS are properly enqueued and prefixed.

**Navigation contract (`CLAUDE.md` § 5 — every routable view):**
- [ ] View calls `FrontendBreadcrumbs::fromDashboard()` (or a static
      `breadcrumbs()` override on `FrontendViewBase`) on every code
      path, including permission-denied early-returns.
- [ ] No `FrontendBackButton`, no hardcoded "Back to dashboard" /
      "Back to <list>" links, no custom back affordance that sidesteps
      the chain + `tt_back` pill.
- [ ] Cross-entity links use `RecordLink::detailUrlForWithBack()` (or
      `BackLink::appendTo()` for raw URL builders) so the destination
      view can render a contextual back-pill.

**Wizard-first (`CLAUDE.md` § 3 — record creation):**
- [ ] If this PR creates a new record-creation flow: a wizard exists for
      it, registered in `WizardRegistry`.
- [ ] If this PR is exempt: the exemption is justified in the spec's
      "Wizard plan" section.

**SaaS-readiness (if any feature added):**
- [ ] Feature is reachable through a REST endpoint, not only via PHP render.
- [ ] Business logic lives outside view files.
- [ ] Auth checked via capabilities, not role-string compare.
- [ ] New tables include the tenancy column scaffold (`club_id`) and a
      `uuid` for root entities.

**Code style (`DEVOPS.md` § no AI fingerprints):**
- [ ] No `Co-Authored-By: Claude` trailers, no robot footers, no Unicode
      box-drawing in comments, no version-history recaps in docblocks.

If a reviewer can't tick all the boxes that apply, the PR isn't done.
