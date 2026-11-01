---
title: Review gate — REST contract
group: developer
summary: The checklist a PR must pass when it adds or changes a REST route, a payload field, a query parameter or an error code under talenttrack/v1.
audience: [dev]
order: 44
---

# Review gate: REST contract

**Triggered by** any change to a `register_rest_route()` call, a REST
controller's response shape, a query parameter or body field a route
accepts, a status code or error code a route answers with, or a domain
method whose output a route returns as-is.

**Run by** the architect. The reviewer ticks the list in the PR.

The contract is the one `CLAUDE.md` §4 sets: REST is the contract and the
rendered plugin is one consumer of it, so a non-WordPress front end must get
the same answer the page shows. The narrative reference is
[`docs/rest-api.md`](../rest-api.md) and the machine-readable one is
`docs/openapi.yaml`.

## Checklist

- [ ] **Additive only on v1.** No field is removed, renamed or retyped, and
      no status code changes for a case that already exists. A breaking
      change bumps the namespace (`talenttrack/v2`) instead of mutating
      `talenttrack/v1`.
- [ ] **Documented.** Every new route, field, query parameter and error code
      is in `docs/rest-api.md` in the same PR, and in `docs/openapi.yaml`
      where the route is listed there.
- [ ] **Error vocabulary.** Errors use the shared envelope
      (`RestResponse::error()`, see "Response envelope") and the shared
      codes:
      - `400 invalid_field`, `unknown_field`, `missing_fields`, `bad_filter`
        or `bad_date`, with `details`;
      - `402` for a plan refusal and `403` for a permission refusal
        (rest-api.md, "Plan refusals are 402");
      - `404 not_found` for a record that does not exist or is not visible
        to the caller, **unless a sibling route on the same record already
        answers `403`** (`forbidden_team`, `forbidden_player`). The status
        follows the siblings ("A route that takes a record id checks that
        record").

      There is no ad-hoc code where a shared one fits. A write route
      declares its `args` and checks them with `BaseController::checkBody()`
      ("Body contract"; enforced by `tools/check-rest-args.php`).
- [ ] **Permission by capability.** The `permission_callback` asks the
      capability model, through `current_user_can()` or `AuthorizationService`
      (`userCanOrMatrix()` and the `can*()` methods). It is never
      `__return_true`, a role-name compare, or `is_user_logged_in()` alone.
      A route that takes a record id checks that record, not only the
      capability ("A route that takes a record id checks that record";
      enforced by `tools/check-record-scope.php`), and a list narrows its
      rows to the caller's scope.
- [ ] **Plain URLs.** A URL in a payload carries no `tt_back` and does not
      depend on the Referer or the request URI. The page that renders the
      link adds the back hint, and so does the list table for REST-fed lists
      (`docs/back-navigation.md`, "Domain code returns plain URLs").
- [ ] **List filters.** A list route accepts its filters both plainly and as
      `filter[...]`, folded the way `ActivitiesRestController::foldListAliases()`
      does it ("Pagination + filters"). An unreadable filter value answers
      `400 bad_filter`; it is never silently dropped.
- [ ] **Partial updates.** A `PUT` or `PATCH` leaves an omitted field alone.
      A field sent empty clears it, a field left out keeps its stored value,
      with a test in the `tests/php/AutosaveWriteContractTest.php` pattern
      (`CLAUDE.md` §6).
- [ ] **Tests.** There is at least one REST test per new route and per new
      field or error code, including a refusal case (403 or 404). A test
      that depends on the Referer defines `REST_REQUEST` in its own process,
      as `tests/php/ListRestPlainUrlTest.php` does.
- [ ] **Tenancy and no WP-isms.** Every query filters by `club_id`. The
      payload exposes capabilities and ids, never WordPress role names, and
      treats `wp_user_id` as a mapping, not as the player's identity.

## Sweep (quarterly)

Diff the registered routes (`rest_get_server()->get_routes()` on the local
install) against `docs/rest-api.md` and `docs/openapi.yaml`, and list every
undocumented route, field or error code. Grep the controllers for
`__return_true`, role-name compares, `BackLink::appendTo()` and
`detailUrlForWithBack()`. Spot-check five list routes for the plain and
`filter[...]` forms and a `bad_filter` refusal. File each finding as an
issue.
