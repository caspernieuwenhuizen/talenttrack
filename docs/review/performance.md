---
title: Review gate — performance
group: developer
summary: The checklist a PR must pass when it touches list queries, REST collections, dashboards, scheduled work or front-end assets.
audience: [dev]
order: 41
---

# Review gate: performance

**Triggered by** any change to: a repository `list*` method or a
`FrontendListTable` surface, a REST collection route, a dashboard tile or
KPI, the analytics fact registry, a report renderer, a cron or workflow
task, `assets/js/**` or `assets/css/**` that is enqueued on every view.

**Run by** the architect. The reviewer ticks the list in the PR.

The budget is the one `CLAUDE.md` §2 sets: first interaction within 200 ms
on a mid-range Android, no layout shift after first paint, the front-end
bundle under 50 KB gzipped.

## Checklist

- [ ] A list query runs a bounded number of statements regardless of row
      count: no query inside a loop over rows, no per-row lookups of
      players, teams or lookups that a `JOIN` or one `IN (...)` fetch can
      supply.
- [ ] Every collection route is paginated and sorts on an indexed column;
      the orderby whitelist is the `columns` declaration, not user input.
- [ ] On a `JOIN`ed list query, `filterClause` receives the table alias so
      `club_id` and status filters qualify the right table (project memory:
      filterClause alias).
- [ ] A dashboard tile or KPI reads one aggregate per tile; it does not
      load rows to count them in PHP.
- [ ] A report or export streams or chunks beyond a few thousand rows; the
      printed-size estimate still holds (`docs/reports.md`).
- [ ] Scheduled work goes through the workflow engine, is idempotent, and
      is bounded per tick (`CLAUDE.md` §4, Background work); no new ad-hoc
      `wp_cron` registration.
- [ ] No new script or stylesheet is enqueued on every view when one
      surface needs it; enqueue on the surface, `defer` non-critical JS.
- [ ] The enqueued front-end JS stays under 50 KB gzipped in total; images
      reserve space (`width`/`height` or `aspect-ratio`) and load lazily
      below the fold.
- [ ] A new mobile list surface starts its first data row above 400 px and
      keeps rows under 120 px at 390 px, or the issue says why not
      (`tests/e2e/mobile-viewport.spec.js`).
- [ ] Caching is explicit: a transient has a key, a TTL and an invalidation
      path named in the PR; nothing is cached per request in a static that
      outlives the request in tests.

## Sweep (quarterly)

Profile the twenty most-used REST routes and list surfaces on the local
install with the demo academy loaded (query count and time per request,
`SAVEQUERIES`), measure the enqueued bundle sizes, and run the mobile gate's
density output over every native surface with rows. File regressions as
issues with the numbers.
