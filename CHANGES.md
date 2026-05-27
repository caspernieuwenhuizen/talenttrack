# TalentTrack v4.3.17 — Exports XLSX/CSV corruption fix (closes #939)

## Symptom

From the central Exports surface (`?tt_view=exports`):

- **XLSX**: Excel refused to open the file ("not a valid file" / corrupt). Affected every XLSX exporter.
- **CSV**: the file contained the dashboard page HTML (theme chrome, header, nav) rather than CSV data.
- **JSON / ZIP / iCal**: same defect class — anything served via the in-page POST handler.

Regression introduced in v4.2.2 / #903 when the surface flipped from REST `fetch()` to form POST.

## Root cause — shortcode runs after `wp_head()`

`FrontendExportsView::handleExportPost()` was called from `FrontendExportsView::render()`, called from the `[talenttrack_dashboard]` shortcode. By the time the shortcode runs:

1. The theme has already emitted `<!DOCTYPE html>`, `<head>`, `wp_head()`, opening `<body>`, header / nav, everything up to `the_content()`.
2. WordPress has already sent the response headers (Content-Type: text/html).
3. The shortcode wraps its work in `ob_start()`.

So when `handleExportPost()` runs:

- `header('Content-Type: …')` silently fails — headers already sent.
- `echo $result->bytes` writes into the shortcode's OB buffer, appended AFTER the page chrome the theme has already streamed.
- `exit` flushes the buffer, dumping the binary/CSV bytes onto the wire AFTER the HTML.
- Browser saves the whole HTML-plus-binary blob with the requested filename, Content-Type `text/html`.

XLSX is corrupt because the ZIP signature isn't at byte 0 — HTML is. CSV is HTML because the actual CSV bytes come after `</html>`.

## Fix — switch to admin-post.php (same pattern as #940)

`FrontendExportsView::render()` is now GET-only. New static `handleAdminPostExport()` mirrors the legacy handler's logic, hooked in `ExportModule::boot()`:

```php
add_action( 'admin_post_tt_export', [ FrontendExportsView::class, 'handleAdminPostExport' ] );
```

`admin-post.php` loads `wp-load.php` but does NOT bootstrap the theme, so download headers fire cleanly and bytes go straight onto the wire.

Each export-card form now carries three hidden fields:

```html
<form method="POST" action="<admin-post.php>">
  <input type="hidden" name="action" value="tt_export">
  <input type="hidden" name="tt_export_key" value="<key>">
  <input type="hidden" name="tt_export_return_url" value="<exports-url>">
```

Errors transit back to the view via a coded query param (`?tt_export_error=nonce|missing_key|unknown_key|service`) plus a short-TTL transient (`tt_export_err_<uid>`) carrying the human-readable service-error message. The next GET resolves the code and surfaces the same `tt-notice tt-notice--error` it always did above the export grid.

## What this restores

| Format | Before | After |
|---|---|---|
| XLSX | Corrupt (HTML preamble) | Valid ZIP from byte 0 |
| CSV | HTML-prefixed | Plain CSV from byte 0 |
| JSON / ZIP / iCal | HTML-prefixed | Correct format |

## Unaffected paths

- `ExportRestController` at `/wp-json/talenttrack/v1/exports/<key>` was always working — it bypasses the theme entirely. Stays registered for direct-link / API integrations.
- `ScheduledReportsRunner` calls `ExportService::run()` directly server-side, no HTTP layer. Unaffected.

## Why patch

Bug fix restoring every broken exporter format within the 4.3 minor. No schema, no migration, no REST contract change.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.16` → `4.3.17`.
