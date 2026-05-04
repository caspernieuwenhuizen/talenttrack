# TalentTrack v3.90.1 — Demo Excel upload no longer surfaces as a hosting 500

Hardening pass on the **Tools → TalentTrack Demo Data → Excel upload** path so a too-big or malformed workbook produces a friendly red notice instead of the generic blank-page / 500 the host's reverse proxy would otherwise return.

## Why

A pilot operator reported an Excel upload error that "almost looks like a hosting server side error" — no TalentTrack notice, no friendly bounce, just a generic page. Three failure modes in the upload path could each produce that symptom:

1. **`catch (\Exception $e)` in `ExcelImporter::importFile()` does not catch `\Throwable`.** PhpSpreadsheet can surface `\TypeError` on malformed XLSX, and an OOM during `load()` throws `\Error` / `\OutOfMemoryError`. Any of those bubbled out of WordPress's request lifecycle as a fatal — the host's reverse proxy turned that into a generic 500.
2. **No memory / time-limit raise before reading the workbook.** PhpSpreadsheet wants 64–128MB even with `setReadDataOnly(true)`. Shared hosts default to 64MB. OOM → fatal → 500.
3. **`post_max_size` overflow leaves `$_POST` and `$_FILES` empty.** `admin-post.php` then refuses the action entirely (no `action`, no nonce). The user sees WP's generic "Are you sure you want to do this?" page or a 413 from the host.

## What changed

- **`ExcelImporter::importFile()` now catches `\Throwable`**, not just `\Exception`. Any TypeError / Error from PhpSpreadsheet becomes the same friendly "Could not read the workbook: …" error the existing path returns.
- **`DemoDataPage::handleExcelImport()` raises `wp_raise_memory_limit('admin')` + `set_time_limit(0)`** before invoking the importer.
- **The importer call is wrapped in another `\Throwable` catch** as a defence-in-depth net for any fatal slipping past the inner catch (Logger writes the error class + message before bouncing).
- **Both the `tt_demo_excel_import` and `tt_demo_generate` handlers detect the `post_max_size` overflow** (POST request with non-zero Content-Length but empty `$_POST`) at the top of the handler and bounce with "Upload exceeded the server's POST size limit (post_max_size = …MB)".
- **`UPLOAD_ERR_*` codes get specific operator-readable messages** instead of "Upload failed (error code 1)". `UPLOAD_ERR_INI_SIZE` names `upload_max_filesize`; `UPLOAD_ERR_NO_TMP_DIR` names `upload_tmp_dir`; and so on.
- **The upload form surfaces the server's actual limits** below the file input — `upload_max_filesize`, `post_max_size`, `memory_limit` — so an operator can size their workbook before the upload fails.

## Files touched

- `talenttrack.php` — version bump to 3.90.1
- `src/Modules/DemoData/Excel/ExcelImporter.php` — catch `\Throwable`
- `src/Modules/DemoData/Admin/DemoDataPage.php` — raise memory + time limits, wrap importer in Throwable catch, detect post_max_size overflow, friendlier UPLOAD_ERR messages, surface server limits in the form
- `languages/talenttrack-nl_NL.po` — 14 new NL msgids
- `SEQUENCE.md` — Done row added
