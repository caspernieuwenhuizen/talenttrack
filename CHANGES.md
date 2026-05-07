# TalentTrack v3.110.0 — Finish-deferred sweep: 3 binary renderers + 3 channel adapters + RecipientResolver + federation JSON

Picks up the remaining infrastructure deferred by v3.105.0 (#0063 Export foundation) and v3.106.0 (#0066 Communication foundation). Ships the three binary renderers (PDF / XLSX / ZIP), the three remaining channel adapters (Push / SMS / In-app), the youth-contact `RecipientResolver` per #0042, and one more Export use case (federation JSON).

## What landed

### #0063 Export — three binary renderers + use case 11

**`ZipRenderer`** (format `zip`) — pure PHP via the bundled `ZipArchive` extension. Bundles a payload of named entries into a single archive with an optional `MANIFEST.json` describing the archive (useful for GDPR exports where the receiving party benefits from a "what's in the box" file). Defensive: rejects `..` traversal in entry paths. Used by the future GDPR subject-access ZIP (use case 10) and full-club backup (use case 9 — delegates the data dump to #0013 Backup & DR).

**`XlsxRenderer`** (format `xlsx`) — multi-sheet `.xlsx` via PhpSpreadsheet (already a production composer dep). Two payload shapes: single-sheet `[ headers, rows ]` (mirrors `CsvRenderer`'s contract; renderer puts both into a sheet named "Data") or multi-sheet `[ 'sheets' => [ 'Sheet name' => [ headers, rows ], ... ] ]`. Sheet names truncated to Excel's 31-char limit and stripped of `[]:*?\/`. Used by use case 6 (multi-sheet evaluations export) and use case 15 (round-tripped demo data).

**`PdfRenderer`** (format `pdf`) — DomPDF-backed, per spec Q1 lean (locked at v3.105.0: "DomPDF default + wkhtml escape hatch"). Adds `dompdf/dompdf ^2.0` to `composer.json` `require`. Two payload shapes: plain HTML string or `[ 'html' => ..., 'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ] ]`. Wraps body-only HTML in a minimal styled shell; full `<html>` documents pass through unchanged. `tt_pdf_render_html` filter lets brand-kit consumers prepend their letterhead. Self-gates on `Dompdf\Dompdf` class existence so a dev install that skipped composer install gets a clean `no_renderer` 500 instead of a fatal.

**`FederationJsonExporter`** (`exporter_key = federation_json`, use case 11). Per spec Q5 lean ("v1 = single neutral envelope; v2 = per-federation adapters as clubs request them"). Joins `tt_players` × `tt_teams`, groups players by team, emits `{ club, teams: [ { id, name, age_group, players: [...] } ] }` shape inside `JsonRenderer`'s standard meta envelope. Filters: `team_id` (optional), `status` (active / archived / trial / all). Cap `tt_view_players`. Federation-specific adapters (KNVB / FA / DFB / NFF) ship as separate exporters per-club request rather than upfront.

All three renderers and the new exporter are registered in `ExportModule::boot()` alongside the v3.105.0 / v3.109.0 entries. The "register from owning module" plan was reversed at v3.110.0 — keeping renderers and CSV/JSON exporters in this module is clearer for the dispatcher to reason about than scattering them across owning modules.

### #0066 Communication — three remaining channel adapters + RecipientResolver

**`PushChannelAdapter`** (channel `push`) per spec Q4 lean ("extend existing Push module + register Push as a Comms channel adapter"). Wraps the existing `Modules\Push` infrastructure rather than re-implementing it: subscription lookup via `PushSubscriptionsRepository::activeForUser()`, dispatch via `WebPushSender::send()`, dead-subscription pruning on HTTP 410 Gone (mirrors the existing #0042 lifecycle rule). `tt_comms_push_send` filter lets alternative push backends or a queue-dispatch pattern short-circuit the default. Body strips HTML and truncates to 280 chars (Web Push 4KB ceiling). Reachability: at least one active subscription within the last 90 days.

**`SmsChannelAdapter`** (channel `sms`) per spec Q2 lean ("abstract from day one"). Provider-agnostic shell — does NOT pick a transport itself. Delivery via `tt_comms_sms_send` filter that a per-club provider plugin registers (Twilio, MessageBird, Infobip, etc.). No filter registered → `STATUS_FAILED` with `error_code = 'no_sms_provider'` so the audit trail records the miss cleanly. Phone normalisation: digits-only with optional leading `+` preserved for transports that require canonical E.164. Subject prepended to body when distinct; HTML stripped; truncated to 480 chars (~3 SMS segments).

**`InappChannelAdapter`** (channel `inapp`). Persists rendered messages into a new `tt_comms_inbox` table (migration `0076_comms_inbox`) keyed on `recipient_user_id` with `read_at` for first-view tracking. The persona-dashboard inbox surface lands separately; a future `GET /comms/inbox` REST endpoint backs the web-app variant. "Delivery" semantics: `STATUS_SENT` once the row hits the database; whether the recipient opens it is a delivery-receipt concern captured by `read_at`. Defensive short-circuit when the migration hasn't run yet. Emits `tt_comms_inapp_delivered` action on successful insert. Reachability: `userId > 0`.

**Migration `0076_comms_inbox`** — `tt_comms_inbox` table. One row per recipient × message; columns mirror `tt_comms_log` plus `body`, `payload_json`, `read_at`. Indexes on `(recipient_user_id, read_at)` for the unread-count query, `(club_id, created_at)` for tenant-scoped pagination, `uuid` for cross-reference with the audit log. Coexists with v3.109.3's `0076_custom_widgets` migration — both files share the `0076_` prefix but are disambiguated by their `getName()` return values, mirroring the existing `0075_comms_log` + `0075_scheduled_reports` precedent.

**`RecipientResolver`** (#0042 enforcer, `Modules\Comms\Recipient\RecipientResolver`). Translates a "who is this message about" intent into the concrete `Recipient[]` the dispatcher delivers to. Rules per #0042's `AgeTier`:

- **U8-U10**: parent only (no direct player surface).
- **U11-U12**: player primary (push / phone) + parent fallback — both returned so the dispatcher's channel-resolver picks based on reachability.
- **U12+**: player primary, parent NOT cc'd by default (the 16-17 cohort club-policy bit lands when the setting exists).
- **Unknown** (no DOB): conservative default — both player and parents returned.

`forPlayer($playerId)` applies the rule. `forPlayerWithParents($playerId)` returns ALL linked parents regardless of tier — used by mass announcements and safeguarding broadcasts (use cases 14 / 15). Falls back to legacy `tt_players.guardian_email` / `tt_players.guardian_phone` when no `tt_player_parents` rows exist (older installs that haven't migrated).

`CommsModule::boot()` now registers all five channel adapters in one place (Email + WhatsApp + Push + SMS + In-app), reversing the original "register from owning module" plan — keeping the channel registry in one module is clearer for the dispatcher's channel-resolver and the thin-wrap adapters don't actually couple Comms to the wrapped modules' lifecycles.

### Composer

- Adds `dompdf/dompdf ^2.0` to production `require`. Inflates the release ZIP by ~5MB; CI's `composer install --no-dev` resolves it automatically on tag push.

## What's NOT in this PR (intentionally deferred)

- **The 14 remaining Comms use-case templates** — per the #0066 spec each lands "with its owning module's first send"; concrete copy + per-locale wording, not infrastructure.
- **The 9 remaining #0063 use-case-specific binary exporters** (use cases 1, 2, 4, 6, 8, 9, 10, 13, 14) — each needs brand-kit + use-case-specific design.
- **Async pipeline + Action Scheduler** (Q2) — major architectural change; lands with first big-export use case (likely GDPR ZIP).
- **Brand-kit template inheritance for PDFs** (Q7) — `tt_pdf_render_html` filter lets per-use-case consumers prepend letterhead today; *automatic* inheritance lands when the PDF renderer earns it.
- **Per-coach signed-token iCal subscription URLs** (Q4) — needs the "subscribe to this calendar" UI surface.
- **Two-way inbound + auto-reply** (Q8) — separate epic; Comms is one-way in v1.
- **Operator-facing opt-out preferences UI** — pure UI work; underlying `OptOutPolicy` already reads `wp_user_meta`.
- **6 follow-up Playwright specs** — landing pass-or-skip stubs would create the appearance of coverage without delivering it; need iterative selector tuning against real CI per the v3.107.0 cadence.

## Notes

- Renumbered v3.109.1 → v3.110.0 mid-rebase against the parallel-agent #0078 ship train: v3.109.1 (Analytics tab follow-ups) + v3.109.2 (Seed review) + v3.109.3-7 (#0078 Phases 2–6, closing the Custom widget builder epic).
- Zero new operator-facing strings — federation JSON exporter's label uses an existing `__()` pattern.
- One new migration: `0076_comms_inbox`.
