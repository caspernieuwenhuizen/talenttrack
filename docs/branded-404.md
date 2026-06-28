<!-- audience: dev -->

# Branded 404

How TalentTrack replaces the unbranded "page not found" surfaces with one
consistent, theme-free branded 404. Shipped in #2035.

## What it covers

Two surfaces used to break a user out of the TalentTrack experience:

1. **The real WordPress 404** — a URL matching no page/post rendered the
   active theme's `404.php`, with whatever chrome that theme ships.
2. **The in-app "Unknown section" fallback** — requesting an unknown
   `?tt_view=<slug>` rendered a bare `Unknown section.` line.

Both now land on the same branded TalentTrack 404: a playful, football-voiced
"Offside! This page is out of play" panel with a clear path back to the
dashboard.

## How the WP-404 takeover works

`Tt404Handler` hijacks the same single `template_include` chokepoint that
`CanvasShell` uses for the dashboard. When the request is a genuine front-end
404, the handler:

- sets `status_header( 404 )` + `nocache_headers()` so crawlers and proxies
  still see a proper not-found,
- substitutes `templates/canvas-404.php` — a minimal theme-free document
  (no theme `header.php` / `footer.php` / sidebars),
- dequeues every non-TalentTrack stylesheet so no theme CSS leaks in
  (same isolation as the dashboard canvas).

The branded content itself is `Tt404Page`, a pure presentation component that
emits `.tt-404-*` markup using design tokens only — no theme calls, no inline
styles — so it ports unchanged into the future SaaS front end.

## Operator opt-out

The takeover is **on by default**. An academy running TalentTrack alongside
unrelated WordPress content can disable it and keep its theme's 404:

- set the club-scoped `tt_handle_wp_404` config flag to `0` (stored in
  `tt_config`, never `wp_options`), or
- short-circuit the `tt_handle_wp_404` filter:

  ```php
  add_filter( 'tt_handle_wp_404', '__return_false' );
  ```

The in-app `?tt_view=<unknown>` fallback always renders the branded content —
it is part of the app shell, not the theme.

## Navigation

The 404 is a terminal surface and follows the two-affordance contract
(see `back-navigation.md`):

- Inside the dashboard shell (the `?tt_view=<unknown>` fallback) the
  breadcrumb-to-Dashboard chain is the back affordance; the inner content
  carries no extra button.
- The standalone WP-404 takeover is a pre-app surface (analogous to the
  pre-login exemption), so it offers a single primary **Back to dashboard**
  button and nothing else.
