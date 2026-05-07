<!-- audience: dev -->

# Back navigation

URL-borne "← Back to where you came from" navigation, shipped in v3.110.0.

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

## What is NOT swept

- **Admin pages** (`wp-admin/admin.php?page=…`). When clicking a
  record name from a wp-admin table, the user lands on the frontend
  detail view. Admin navigation back to wp-admin is left to the
  browser back button.
- **Form-save redirects** (`wp_safe_redirect( $detail_url )` after
  POST). Those are forward-navigations after a successful save; the
  user's "back" target should be the form's referer, not the URL the
  redirect emits.

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
