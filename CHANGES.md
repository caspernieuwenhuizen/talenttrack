# TalentTrack v3.109.0 — Deferred-cleanup: 3 #0063 CSV exporters + #0066 WhatsApp adapter + retention cron

Picks up items left behind by the v3.105.0 (#0063 Export module foundation) and v3.106.0 (#0066 Communication module foundation) ships now that both have been live in `main` through the v3.106.x / v3.107.x / v3.108.x cycle. Five small additions, two module-wiring touches, no migrations.

## What landed

### #0063 Export — three CSV use cases (3, 5, 7)

The v3.105.0 foundation shipped with one use case (Team iCal feed, use case 12) to prove the registry + service + REST controller end-to-end. v3.109.0 adds three pure-SQL CSV exporters that exercise the existing `CsvRenderer` against real production data shapes. They live in the Export module rather than each owning module because the readers are deliberately small and the registration line is cheaper than a six-line shell-module update each. Future use cases that need owning-module state (e.g. cycle-aware PDP exports) will register from their owning module.

**`PlayersListCsvExporter`** (`exporter_key = players_list_csv`, use case 3). Squad list CSV. Joins `tt_players` × `tt_teams`. Filters: `team_id` (optional, restricts to one team), `status` (`active` / `archived` / `trial` / `all`, default `active`). 13 column headers including player_id / first_name / last_name / dob / jersey_number / preferred_foot / preferred_positions / team_name / guardian_name / guardian_email / guardian_phone / status / date_joined. Cap-gated on `tt_view_players`.

**`AttendanceRegisterCsvExporter`** (`exporter_key = attendance_register_csv`, use case 5). Per-team attendance register over a date range. Joins `tt_attendance` × `tt_activities` × `tt_players` × `tt_teams`. Filters: `team_id` (optional), `date_from` (default −90 days), `date_to` (default today); the validator auto-swaps a reversed range so a defensive UI can pass them either direction. References `att.activity_id` per the migration 0027 rename. Cap-gated on `tt_view_activities`.

**`GoalsCsvExporter`** (`exporter_key = goals_csv`, use case 7). Goals CSV. Joins `tt_goals` × `tt_players` × `tt_teams` × `wp_users` (resolves `created_by` → owner's `display_name`). Filters: `team_id` (optional), `status` (`pending` / `in_progress` / `completed` / `archived` / `all`, default `all`). Cap-gated on `tt_view_goals`.

`ExportModule::boot()` registers all three with a comment explaining the in-module placement choice (small readers, cheap registration line; owning-module placement reserved for state-coupled cases).

### #0066 Communication — WhatsApp deep-link channel + retention cron

**`WhatsappLinkChannelAdapter`** (channel key `whatsapp_link`) per the spec Q3 lean — "deep-link only in v1" — locked at v3.106.0. No WhatsApp Business API onboarding, no Meta verification, no per-message cost. The adapter renders a `https://wa.me/{e164}?text={url-encoded body}` URL that opens the recipient's WhatsApp client with a pre-filled message ready to send. It does not actually deliver — it builds the link and emits a `tt_comms_whatsapp_link_built` action carrying `[ uuid, url, recipient, request ]` so callers can route the URL to the operator's interface (e.g. an "Open WhatsApp" button after a coach clicks "Notify on WhatsApp" in the cancellation flow). The actual delivery is the operator clicking the link in their interface.

`canReach()` does a digits-only normalisation (strips spaces / dashes / parentheses / leading `+`) and requires at least 6 digits — international and national both work. Returns `STATUS_SENT` with the built URL in the `CommsResult` `note` (not in the audit row's `address_blob`, since URLs encode message content and GDPR retention should not preserve them verbatim — the audit row keeps the recipient's existing `phoneE164` as fallback `address_blob` per `CommsAuditLogger`).

**`CommsRetentionCron`** per the spec Q6 lean — 18-month default audit retention. Daily wp-cron `tt_comms_retention_cron` (scheduled at boot if not already scheduled). Tombstones `tt_comms_log` rows older than the per-club `comms_audit_retention_months` setting (read via `QueryHelpers::get_config()`; default 18; explicit `0` disables for clubs with regulatory-hold orders during ongoing safeguarding investigations) by:

```sql
UPDATE {prefix}tt_comms_log
   SET address_blob = '',
       subject = NULL,
       subject_erased_at = UTC_TIMESTAMP()
 WHERE created_at < <cutoff>
   AND subject_erased_at IS NULL
 LIMIT 500
```

Operators retain the audit fact ("did the parents get the cancellation message?") without preserving the PII (recipient address + subject line) past the retention window. The 500-row LIMIT keeps the per-run footprint small for shared hosting; the daily cadence absorbs the long tail naturally and avoids a multi-thousand-row UPDATE. Defensive short-circuit when the `tt_comms_log` table doesn't exist (migration 0075 hasn't run yet).

`CommsModule::boot()` registers `WhatsappLinkChannelAdapter` alongside `EmailChannelAdapter` and calls `CommsRetentionCron::init()`.

## What's NOT in this PR

**Export — still deferred**:

- PDF renderer (DomPDF) — lands with the player evaluation PDF use case.
- XLSX renderer (PhpSpreadsheet) — lands with the evaluations Excel use case.
- ZIP renderer (ZipArchive) — lands with the GDPR subject-access ZIP use case.
- 11 remaining use cases: 1, 2, 4, 6, 8, 9, 10, 11, 13, 14, 15.
- Async pipeline + Action Scheduler — lands when a big-export use case needs it.
- Brand-kit template inheritance — lands when the PDF renderer ships, since brand kit only manifests visually.
- Per-coach signed-token iCal subscription URLs — lands with the subscribe-to-this-calendar UI.

**Comms — still deferred**:

- `PushChannelAdapter` — lands when the first push use case ships (needs the Push module spike from spec Q4).
- `SmsChannelAdapter` — lands with the first SMS use case + the provider abstraction from spec Q2.
- `InappChannelAdapter` — lands with the persona-dashboard inbox surface (no inbox model today).
- `RecipientResolver` enforcing the #0042 youth-contact rules — callers currently build the `Recipient` array directly. The resolver lands with the first use case that needs it.
- The 15 use-case templates themselves (training cancelled, selection letter, PDP ready, etc.) — each registers a `TemplateInterface` from its owning module on first send.
- Operator-facing opt-out preferences UI on the Account page.
- Two-way inbound + auto-reply (spec Q8 lean) — separate epic; Comms is one-way in v1.

**Playwright — still deferred (six specs)**:

- `players-crud.spec.js`, `goal.spec.js`, `activity.spec.js`, `evaluation.spec.js`, `persona-dashboard-editor.spec.js`, `pdp-capture.spec.js` — each lands as its own follow-up PR per the spec's "monitor 3+ CI runs for flakes before moving on" cadence; selectors need iterative tuning against real CI.

## Notes

- Mid-build catch: the WhatsApp adapter initially mutated `$recipient->emailAddress` to smuggle the URL into `address_blob`. That's a side-effect that could leak into later recipients in the same `CommsRequest`. Fixed by removing the mutation and putting the URL in `CommsResult` `note` instead, with a comment recording the GDPR rationale (URLs encode message content; retention should not preserve them verbatim).
- Renumbered v3.108.1 → v3.109.0 (rebase against parallel pilot-feedback hotfix train v3.108.1 / v3.108.2 / v3.108.3 / v3.108.4 / v3.108.5 that took the v3.108.x slots mid-CI).
- Zero new NL msgids — exporter labels and adapter copy reuse existing `__()` strings; the cron is operator-internal.
- No new migrations.
- No new translatable strings to add to `nl_NL.po`.
