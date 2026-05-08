# TalentTrack v3.110.15 — Eighth Export use case: GDPR subject-access ZIP (#0063 use case 10)

Required by EU GDPR Article 15 (Right of access by the data subject). Per user-direction shaping (2026-05-08): synchronous v1, JSON-per-domain inside the ZIP plus a rendered evaluation PDF for human readability, cap-gated on `tt_edit_settings` (academy admin only), audit-logged on every export.

## What landed

### `GdprSubjectAccessZipExporter` (`exporter_key = gdpr_subject_access_zip`, use case 10)

URL pattern:
`GET /wp-json/talenttrack/v1/exports/gdpr_subject_access_zip?format=zip&player_id=42`

**Filters**: `player_id` (REQUIRED) — tenant-scoped via `QueryHelpers::get_player()`.

**Cap**: `tt_edit_settings` — academy admin only. GDPR statute makes the academy the data controller; only the academy admin can extract a player's full record.

### ZIP contents

- `profile.json` — `tt_players` row, pretty-printed JSON.
- `evaluations.json` — `tt_evaluations` + `tt_eval_ratings` joined by evaluation_id, shape `{ evaluations: [...], ratings: [...] }`.
- `goals.json` — `tt_goals` rows.
- `attendance.json` — `tt_attendance` joined to `tt_activities` for date / title / location.
- `comms_log.json` — `tt_comms_log` rows where `recipient_player_id = $player_id` (tombstoned rows kept — empty `address_blob` / `subject` reflect the GDPR retention design from #0066).
- `parents.json` — `tt_player_parents` rows for the player.
- `evaluation_report.pdf` — rendered via the v3.110.4 `PlayerEvaluationPdfExporter` + `PdfRenderer`.
- `MANIFEST.json` — standard `ZipRenderer` envelope + GDPR-specific metadata (article, subject, requesting user, generated_at, entry counts, tombstones-note).
- `README.txt` — plain-text "what's in this archive" guide for the data subject.

CSV deliberately skipped as redundant — JSON round-trips cleanly to any analytics tool.

### Audit trail

Every successful export writes `gdpr.subject_access_export` to `tt_audit_log` carrying `(entity_type='player', entity_id=$player_id, payload={ requesting_user_id, generated_at, entry_count })`. Audit failures never block delivery — the data subject has a legal right to the export, but the academy needs the trail for its own compliance reporting.

### Module wiring

Registered in `ExportModule::boot()`. Foundation now at 13 of 15 use cases live.

## What's NOT in this PR

- **Async dispatch** — synchronous-only via the standard REST stream-and-exit path. Spec Q6 originally leaned async; user-direction shaping locked synchronous v1 because typical pilot data (~1-5 MB per player) fits well under the 30s request window. This exporter remains the natural first consumer of the deferred Action-Scheduler async pipeline if a real-club extract ever exceeds that budget.
- **A subject-access wizard** — spec Q4 (Wizard plan) suggests "audience → scope → confirm" wizard for big GDPR exports. Synchronous v1 is single-button-click via REST; the wizard lands when the operator-facing surface earns it.
- **Encryption-at-rest of the produced ZIP** — the spec assumes the recipient takes custody at download time; the academy operator is responsible for transmitting the ZIP through a secure channel.

## Notes

- 6 new operator-facing strings (README header / generation timestamp / requesting-user line / GDPR-Article-15 attribution / player-not-found stub / tombstones-note).
- No new migrations.
- No composer dependency changes.
- Renumbered v3.110.15 against any parallel-agent ship that took a v3.110.x slot during build.
