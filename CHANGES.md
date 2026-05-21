# TalentTrack v4.0.8 — Exports page no longer 403s on every Export click (closes #862)

## Pilot report

> exports lead to a 403

Every Export button on the central Exports page (#797) returned 403. Show-stopper — no bulk export worked.

## Root cause

Two related defects in `ExportRestController`:

1. **HTTP method mismatch**. The `/exports/{key}` route was registered `'methods' => 'GET'`. The page's inline JS submits via `fetch(..., { method: 'POST', body: JSON.stringify(body) })` — the filter set (team_id, date range, status, demo-table presets, …) is a JSON body, which is the right shape for that payload. POST hitting a GET-only route → 405; the nonce check tripped first on the unexpected method → 403.
2. **Filters read from query string only**. `run()` did `$req->get_query_params()` to assemble the filter bag. Even after we unblocked POST, the filters carried in the JSON body would have been invisible to the exporter.

## Fix

In `src/Modules/Export/Rest/ExportRestController.php`:

```php
// route — accept both GET and POST
register_rest_route( self::NS, '/exports/(?P<key>[a-z0-9_-]+)', [
    [
        'methods'             => [ 'GET', 'POST' ],
        'callback'            => [ __CLASS__, 'run' ],
        'permission_callback' => [ __CLASS__, 'permissionCallback' ],
    ],
] );

// run() — merge query-string filters AND JSON-body filters
$query   = $req->get_query_params();
$body    = $req->get_json_params();
$filters = array_merge(
    is_array( $query ) ? $query : [],
    is_array( $body )  ? $body  : []
);
unset( $filters['format'], $filters['entity_id'], $filters['brand'] );
```

- Reserved params (`format` / `entity_id` / `brand`) are still read via `$req->get_param()`, which checks every parameter source and works for both GET and POST.
- Cap-gating in `ExportService::run()` against each exporter's `requiredCap()` is unchanged.
- GET path is preserved — direct-link integrations keep working.

## Verification

- Open the Exports page, click Export on any card → file downloads (no 403, no 405).
- POST a JSON filter body → exporter sees the filters and respects them.
- A direct `GET /talenttrack/v1/exports/<key>?team_id=N&format=csv` still works for scripted integrations.

## Closes

- #862 — Exports page — 403 on every Export click
