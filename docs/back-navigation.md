<!-- audience: dev -->

# Back navigation

URL-borne "← Back to where you came from" navigation, shipped in v3.110.0.

## The contract — two budgets, counted separately

Navigation is counted in **two separate budgets**:

- **Per view** — exactly two affordances, described in this section. Unchanged since v3.110.0.
- **Global chrome** — exactly one primary navigation, rendered by the application shell, described in [Global navigation is not a view affordance](#global-navigation-is-not-a-view-affordance) below.

A view owns the first budget. Moving between modules is not a view's job at all.

## Per view — two nav affordances, no more, no less

**Every routable frontend view (anything reachable via `?tt_view=<slug>`) emits exactly TWO navigation affordances and nothing else:**

1. **Breadcrumb chain** — the canonical hierarchy ending at `Dashboard`. Rendered via `\TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard()` (or `::render([...])` for ad-hoc chains). The first crumb is always `Dashboard` and links back to the persona-dashboard root.
2. **Contextual "← Back to …" pill** — `tt_back`-borne, rendered automatically by `FrontendBreadcrumbs::render()` ABOVE the chain when the visit captured a back-target. Label is contextual via `BackLabelResolver::labelFor()` (e.g. `← Back to Ajax U17`, `← Back to John Doe`, `← Back to Trial: Lucas Smith`). When no back-target is in the URL, the pill simply doesn't render — that's intentional, the breadcrumb chain is the user's only path home and that's enough.

**No third view-level affordance is ever allowed.** Specifically:

- ❌ No "← Back to dashboard" button.
- ❌ No "← Back to <list>" button (e.g. "Back to Players", "Back to Goals"). The breadcrumb chain has the parent crumb; click it.
- ❌ No "← Cancel" link that doubles as a back affordance. Cancel buttons in forms are fine but they're form actions, not navigation.
- ❌ No `FrontendBackButton` class (deleted in v3.110.41) or any analogue.
- ❌ No per-view back-link that resets `tt_back`, hard-codes a target URL, or otherwise sidesteps the chain + pill.

If a custom-label back link feels necessary, the right answer is to make sure the breadcrumb chain has the right intermediate crumb. The chain's parent crumb IS the back-to-list affordance.

### Why exactly two

Pilot operator surfaced the duplication: views that emitted both an explicit "Back to dashboard" button AND the breadcrumb chain stacked four redundant nav rows above the page title. Two affordances are sufficient — the pill answers "where did I come from?", the breadcrumb answers "where am I in the hierarchy?". Adding a third is noise.

### Skipped (correctly without a chain)

These are the only views allowed without breadcrumbs:

- The dashboard root itself (`PersonaLandingRenderer`) — it IS the destination "Dashboard" crumb resolves to.
- Pre-login flows (`AcceptanceView`, login form) — no logged-in dashboard to chain to yet.
- Component renderers, sub-views composed into other views, internal containers (`FrontendThreadView`, `FrontendTeammateView`, `FrontendMyProfileView`, `CoachDashboardView`, `PlayerDashboardView`).

If you're adding a new view and it isn't one of these, it MUST emit the chain + pill.

## Global navigation is not a view affordance

The rule above counts what a **view** emits. It does not count the application shell.

The shell renders **one** primary navigation carrying the destinations the user can reach, resolved from `TileRegistry` — slug, group, order, icon, per-persona labels, capability. Its presentation varies by viewport:

| Viewport | Presentation |
| --- | --- |
| ≥1280px | Grouped sidebar, expanded |
| ≥1024px | Same sidebar, collapsible to an icon rail |
| <1024px | Off-canvas drawer behind a hamburger |
| <768px | Drawer plus a thumb-zone bottom bar |

These are presentations of **one** affordance with one data source, rendered **once**, by the shell — not four affordances, and never four data sources.

**A view never emits module-level navigation.** A view that renders its own list of links to other modules is the violation this rule reaches for: it duplicates the shell, drifts from it, and can't be capability-filtered consistently. That is a different failure from the per-view back-link problem below, but it has the same cause — navigation authored per view instead of once.

The shell is selectable via `tt_frontend_shell` (see [`frontend-shell.md`](frontend-shell.md)). Under the `classic` value there is no global nav and only the per-view budget applies.

### Record-scoped tabs are content, not navigation

Tabs that move **within** one record — a player's Overview / Journey / Evaluations / Goals & PDP / Minutes — do not leave the view's subject, so they are not navigation away from it and don't count against the per-view budget.

They may only be rendered by the shared spine component (`\TT\Shared\Frontend\Components\RecordSpine`), which also hosts the breadcrumb chain and the back-pill. A view that hand-rolls its own tab strip **is** a violation — that's how tab styling, keyboard order and active-state logic drift apart across surfaces.

**Grandfathered:** tab strips that predate this rule aren't violations until migrated. `FrontendPlayerDetailView` is the one such surface — its capability-gated, counted strip predates the spine and works well, and rewriting it to reach the same markup would be churn on the most trafficked view in the plugin. The rule binds **new** surfaces; existing strips migrate when there's a reason to touch them, not on principle.

## Why

Breadcrumbs show where a record sits in the canonical hierarchy
(`Dashboard / Players / John Doe`). They do **not** show where the user
came from. Pilot feedback: when a coach navigates Teams → Team detail
→ Player from the roster, the breadcrumb says "Dashboard / Players /
John Doe" — there is no in-page affordance that returns to the team.

Browser back works but is small on mobile and unreliable after form
submits. Referer-based back links (the v3.108.2 approach) lose the
target on refresh and on shared deep-links. v3.110.0 replaces both with
a URL-borne mechanism: the back target lives in a `tt_back` query
parameter that survives refresh, missing referers, and sharing.

## How it works

Every cross-entity link emitted by a frontend view appends
`tt_back=<urlencoded current page URL>`. The destination view reads
`tt_back` from `$_GET`, validates it, and renders an in-page pill:

```
← Back to Team Ajax U17
```

The pill is rendered automatically by `FrontendBreadcrumbs::render()`
above the breadcrumb chain. Views that already use the breadcrumb
component get the pill for free.

## 5-hop walking

The current page URL itself already carries any inherited `tt_back`,
so each forward navigation **nests** the previous chain via URL
encoding. A user walking Teams → Team A → Player Bob → Activity 12
ends up on a URL like:

```
/?tt_view=activities&id=12&tt_back=<urlencoded /?tt_view=players&id=42&tt_back=<urlencoded /?tt_view=teams&id=5>>
```

Clicking "← Back to Bob Smith" pops one level. The next page the user
lands on still carries the remaining chain, so its own back-pill says
"← Back to Team A" — the chain walks back step by step.

The chain is capped at **5 hops**. Adding a sixth drops the deepest
entry (the oldest visited page), keeping URL length bounded.

## Entity-aware labels

`BackLabelResolver::labelFor($url)` parses the back URL's `tt_view`
and `id`, looks up the entity name (player, team, activity title, …)
and returns "Back to <name>". When the entity can't be resolved
(deleted, wrong club, missing id) it falls back to the list-level
label "Back to Players". When `tt_view` is missing entirely, it
returns "Back to Dashboard".

Per-entity labels:

| `tt_view` | Label when id resolves |
| - | - |
| `players` | "Back to <First Last>" |
| `teams` | "Back to <Team name>" |
| `activities` | "Back to <Activity title>" |
| `goals` | "Back to <Goal title>" |
| `pdp` | "Back to <Player>'s PDP" |
| `evaluations` | "Back to Evaluation: <Player> (<date>)" |
| `people` | "Back to <First Last>" |

## Never build a `tt_view` URL on `home_url()`

<!-- audience: developer -->

`tt_view` is read only where the `[talenttrack_dashboard]` shortcode runs.
That shortcode usually lives on its own page, so `home_url( '/' )` — the
site's front page — is the one base that is reliably **wrong**:

```php
// Wrong. Lands the user on the theme's homepage, silently.
add_query_arg( [ 'tt_view' => 'players', 'id' => $id ], home_url( '/' ) );

// Right.
RecordLink::detailUrlFor( 'players', $id );          // a record
add_query_arg( [ 'tt_view' => 'docs' ], RecordLink::dashboardUrl() );  // a view
```

`RecordLink::dashboardUrl()` resolves the configured page, ignores it when
it has been trashed, self-heals by scanning published pages for the
shortcode, and only then falls back. In cron and CLI it skips the
request-based fallback entirely, so a link in a notification email cannot
end up pointing at `/wp-cron.php`.

A base that merely *falls back* to `home_url()` is fine, because it starts
from the current request — already the right page:

```php
$base = remove_query_arg( [ 'tt_view', 'id' ] );
$url  = add_query_arg( 'tt_view', $view, $base ?: home_url( '/' ) );
```

Enforced by `tools/check-dashboard-urls.php` (the **Dashboard URL lint**
gate). To grandfather a genuine exception, put `tt-dashboard-url-ok` in a
comment inside the call.

## Wiring on the developer side

PHP frontend views emit cross-entity links via:

```php
$url = RecordLink::detailUrlForWithBack( 'players', $player_id );
```

This is a drop-in replacement for `RecordLink::detailUrlFor()` — same
URL plus the captured `tt_back` query param.

Raw URL builders that don't use `RecordLink` should wrap with
`BackLink::appendTo()`:

```php
$url = BackLink::appendTo(
    add_query_arg( [ 'tt_view' => 'trial-case', 'id' => $case_id ], $base_url )
);
```

REST controllers that emit detail URLs (e.g. `name_link_html` in the
players list) also use `RecordLink::detailUrlForWithBack()`. In a REST
context, `BackLink::captureCurrent()` reads the page URL from the
HTTP `Referer` header (the page that initiated the AJAX call) instead
of `REQUEST_URI` (which points at the REST endpoint).

## A new view also declares its help topic

Adding a `?tt_view=` route means deciding which help topic the Help icon
should open on that screen. There are exactly two ways to do it, and the
docs lint fails a route in neither:

**The screen has a topic** — add the slug to that doc's `views:` front
matter:

```markdown
---
title: Match preparation
group: match-day
audience: [user]
views: [match-prep]
---
```

`HelpTopics::viewToTopic()` inverts that into the map the drawer reads.
Nothing in PHP changes; there is no list to edit.

**The screen deliberately has none** — add it to
`config/no_help_topic.php` with a sentence saying why:

```php
'docs' => 'The help surface itself. A topic pointing at the page you are already reading is a loop, not help.',
```

Skipping both is what produced the state this replaced: a hand-maintained
27-entry map against 144 routes, so most screens opened "Getting started"
with nothing recording that a mapping was missing. A missing entry is
invisible precisely because it looks like a deliberate one — hence the
gate, and hence the notice the drawer now renders when it falls back.

Note the drawer takes a **topic** slug, not a view slug, when a view
overrides it:

```php
HelpDrawer::button( 'pdp-cycle' );   // the doc
HelpDrawer::button( 'pdp' );         // the route — opens the wrong thing
```

## What is NOT swept

- **Admin pages** (`wp-admin/admin.php?page=…`). When clicking a
  record name from a wp-admin table, the user lands on the frontend
  detail view. Admin navigation back to wp-admin is left to the
  browser back button.
- **Form-save redirects** (`wp_safe_redirect( $detail_url )` after
  POST). Those are forward-navigations after a successful save; the
  user's "back" target should be the form's referer, not the URL the
  redirect emits.

## Cancel resolves `tt_back` in the shared helper (#2869)

A form's **Cancel** button is a form action rather than a navigation
affordance — it does not count against the two-affordance budget above — but it
is still a place a user ends up, and §6 rule 5 has always said it should return
them to where they came from.

That is now resolved **once**, inside
`\TT\Shared\Frontend\Components\FormSaveButton::render()`, rather than at each
call site. Callers keep passing the §6 default in `cancel_url` — the record's
detail page when editing, the list when creating — and the helper prefers a
valid `tt_back` from the entry URL when one is present.

The reason it lives in the helper is that the rule did not survive being
everybody's job: of 65 `cancel_url` assignments in `src/`, four consulted
`BackLink` and 61 hard-coded a destination. A form added after this inherits
the behaviour without its author needing to know the rule exists.

Two things worth knowing before changing it:

- **Resolution goes through `BackLink::resolve()`, never `$_GET['tt_back']`.**
  Cancel is a link a user is invited to click; following an unvalidated
  parameter would make every form in the plugin an open redirect. `resolve()`
  is the validating reader and refuses an off-site or malformed target.
- **`ignore_back => true`** pins the caller's `cancel_url` for the rare form
  that must always land in one place regardless of how it was reached.

Note the method is `BackLink::resolve()`. CLAUDE.md §6 rule 5 names it
`BackLink::resolveBack()`, which does not exist.

## Validation

`tt_back` values are validated before rendering:

- Same-origin only — cross-origin URLs are rejected.
- Parseable URLs only — malformed strings are dropped.
- Escaped via `esc_url()` on render so the back link can't inject HTML
  or JavaScript through the query parameter.

## Deprecating the referer-based path

The v3.108.2 `FrontendBreadcrumbs::fromDashboardWithBack()` (referer-
based first crumb) is **kept for backwards compatibility** but the
URL-borne pill takes precedence. Existing My-Goals / My-Activities
detail views call `fromDashboardWithBack()` and additionally render
the URL-borne pill when `tt_back` is present. New views should call
`fromDashboard()` and rely on the auto-rendered pill.
