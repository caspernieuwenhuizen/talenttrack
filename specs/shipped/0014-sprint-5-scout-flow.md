<!-- type: feat -->

# #0014 Sprint 5 — Part B.3: Scout flow with dual access paths

## Problem

Sprint 4 delivered three audience templates (parent, internal, player) via the wizard. The fourth audience — scout — was deferred because it has an entirely different access problem:

- Internal reports go to people who already have plugin logins.
- Scout reports go to people *outside* the club who need access to a specific player's profile, often on a one-off basis. Sometimes a scout has an ongoing relationship (warrants a real account); sometimes it's a drive-by request from a scout representing another club.

Meanwhile, the plugin has a documented-but-missing `tt_readonly_observer` role (readme has claimed it exists since v2.21.0, but `Activator.php` never registered it). This is fine to fix now — the role is the right semantic basis for observer-style access. Scout is distinct and adds atop it.

Privacy implications are sharper here than any other audience: emailed links leave your site, and the data is about minors.

Who feels it: HoD (releasing a player to a scout), scouts (receiving access), parents (expecting responsible data handling).

## Proposal

Three deliverables:

1. **Fix the missing observer role** — register `tt_readonly_observer` properly in `Activator.php`, bringing code in sync with documentation. Capabilities scoped to viewing/reading only; no editing, no scout-specific grants.
2. **Add `tt_scout` role + "Release to scout" flow** — a distinct role with `tt_generate_scout_report` capability, plus two access paths:
   - **Emailed one-time links**: HoD selects a player, generates a scout report, enters scout's email. Plugin sends an email with a time-limited, single-player, single-report link. No scout account needed.
   - **Internal scout accounts**: for scouts with ongoing relationships — a normal WP user with `tt_scout` role. HoD explicitly assigns specific players to this scout; scout can view/print those players' scout reports on demand.
3. **`tt_player_reports` table** — persistence for scout reports only, supporting expiry, revocation, and audit.

## Scope

### Fix `tt_readonly_observer` registration

In `includes/Activator.php`, during role registration:

- Register `tt_readonly_observer` with capabilities: `read`, `tt_view_players`, `tt_view_teams`, `tt_view_sessions`, `tt_view_goals`, `tt_view_evaluations`. View-only across the board.
- Does not get `tt_access_frontend_admin` (from #0019 Sprint 5) — observers don't see admin surfaces.
- Add a migration to backfill the role onto any existing installs that previously had the role's capabilities granted in a non-standard way. If no installs have that state (likely), migration is a no-op but safe to include for future-proofing.

### New role: `tt_scout`

In `Activator.php`:

- Register `tt_scout` with capabilities: `read` (core), `tt_generate_scout_report` (new).
- Explicitly does NOT grant `tt_view_players` globally. A scout can only view players explicitly assigned to them.

New capability `tt_generate_scout_report`:
- Granted to `tt_head_dev` (they initiate the release).
- Granted to `tt_scout` (they can generate the report from their assigned player's record — or more accurately, view the pre-generated report).

### `tt_player_reports` persistence

New table via migration:

```sql
CREATE TABLE tt_player_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  player_id BIGINT UNSIGNED NOT NULL,
  generated_by BIGINT UNSIGNED NOT NULL,        -- wp_users.ID of HoD
  audience VARCHAR(32) NOT NULL,                 -- 'scout_emailed_link', 'scout_assigned_account'
  config_json TEXT NOT NULL,                     -- serialized ReportConfig
  rendered_html LONGTEXT NOT NULL,               -- base64-photo-inlined HTML
  access_token VARCHAR(64) DEFAULT NULL,         -- for emailed-link flow
  scout_user_id BIGINT UNSIGNED DEFAULT NULL,    -- for assigned-account flow
  recipient_email VARCHAR(255) DEFAULT NULL,     -- for emailed-link flow
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  first_accessed_at DATETIME DEFAULT NULL,
  access_count INT UNSIGNED DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_player (player_id),
  KEY idx_token (access_token),
  KEY idx_scout (scout_user_id),
  KEY idx_expiry (expires_at)
);
```

### Path A — Emailed one-time link flow

From the wizard (Sprint 4) when audience is Scout:

**Step 4.5 (new, scout-only) — Delivery.**
- Recipient email input (required).
- Link expiry: 7 / 14 / 30 days (default 14).
- Optional message: text input for a covering note to the scout.
- HoD confirms → system generates access token, persists to `tt_player_reports`, sends email.

Email contents:
- Subject: "Player report from {club_name}"
- Body: brief greeting, covering note (if provided), expiry notice, the link.
- Link format: `https://{site}/tt-scout-report/?token={64-char-token}`.
- No login page on the link — token validation gives direct access.

Link viewer (new frontend surface):
- URL: `/tt-scout-report/?token=...`
- No authentication required; token is the auth.
- Lookups `tt_player_reports` by token. If valid (not expired, not revoked), increments `access_count`, sets `first_accessed_at` if null, serves the rendered HTML.
- If invalid: clean "This link has expired or been revoked" page. Log the attempt for audit.

Print-friendly: the rendered HTML uses the existing print CSS.

**Photos are base64-inlined** at render time (per shaping decision): the `rendered_html` column contains the complete renderable HTML with photos as data URIs. No later dependency on the plugin's uploads directory being accessible.

### Path B — Internal scout account flow

New page (under frontend Administration, gated to HoD): "Manage scout access."

- List of scout users.
- For each scout: add/remove player assignments. "Assign player X to Scout Y."
- Assignments stored in a new table `tt_scout_player_access` OR via user meta on the scout user — implementation decision during dev. Meta is simpler; table is cleaner. Prefer meta for simplicity.

Scout user experience (after login):
- Scout sees a new "My players" view listing players assigned to them.
- Clicking a player shows that player's scout-audience report (generated on demand via `PlayerReportRenderer` with a scout config).
- Scout cannot: edit, create, delete, see un-assigned players, see admin surfaces.
- Capabilities: strictly the `tt_scout` role's minimal set.

Persistence: each time a scout views a player, a `tt_player_reports` record is written for audit (`audience = 'scout_assigned_account'`). `expires_at` for this flow = NULL (doesn't expire for assigned accounts). `revoked_at` is set when the HoD removes the assignment.

### Revocation and audit UI

New page (under Administration): "Scout reports history."

- Lists all `tt_player_reports` with filters: player, scout, audience, date range, status (active / expired / revoked).
- Per row: player, scout/recipient, audience, sent date, expiry, access count, actions (Revoke, Resend).
- Revoke: sets `revoked_at = NOW()`. Future link clicks hit the "revoked" page.
- Resend: regenerates the email (new token, new expiry). Old token invalidated.

### Privacy safeguards (baked in)

- Scout reports use the Scout audience defaults from Sprint 4: contact details OFF, full DOB OFF, photo ON (base64), coach notes OFF.
- The HoD can opt-in to any of these per report at wizard time.
- Emailed link page shows a visible watermark "Confidential — for {recipient_email} only" footer.
- Emailed link page has no navigation, no site chrome — just the report content. No way for the recipient to drill into anything else even if they guess URLs.

## Out of scope

- **Payment/licensing for scouts.** Any monetization question belongs to #0011.
- **Multi-player scout reports** (e.g. "here are 5 players to consider"). One player per link.
- **Bulk release** (assign 10 players to a scout in one action). Future idea.
- **Scout messaging back to the club** (e.g. scout comments on a player). Not in scope — one-way information flow.
- **Real-time access notifications** to HoD when a scout views. Audit table records it; no push.
- **Two-factor auth for scout accounts.** Uses WP's standard auth + whatever the site has enabled.

## Acceptance criteria

### Roles

- [ ] `tt_readonly_observer` role is registered in `Activator.php` with correct read-only capabilities. Fresh install and upgrade both have the role.
- [ ] `tt_scout` role is registered with `tt_generate_scout_report` capability. Fresh install and upgrade both have the role.
- [ ] The "missing observer role" issue documented in the readme is now factually correct.

### `tt_player_reports` table

- [ ] Migration creates the table on fresh install and existing installs.
- [ ] No existing data is affected.

### Emailed link flow

- [ ] HoD can run the wizard, choose Scout audience, enter recipient email, select expiry, optionally add a message, and send.
- [ ] Email arrives at the recipient with a working link.
- [ ] Clicking the link shows the generated report.
- [ ] The report is self-contained (photos as base64, no cross-site asset dependencies).
- [ ] Link expires at the set date; subsequent clicks show an "expired" page.
- [ ] HoD can revoke an active link; subsequent clicks show a "revoked" page.
- [ ] HoD can resend; old link invalidates, new link works.
- [ ] Access count and first-accessed-at are correctly tracked.

### Internal scout account flow

- [ ] HoD can create a `tt_scout` user and assign specific players.
- [ ] Scout logs in, sees only assigned players, can view each player's scout-audience report.
- [ ] Scout cannot access un-assigned players even by URL tampering.
- [ ] Scout cannot edit, create, or delete anything.
- [ ] Scout cannot access admin surfaces.
- [ ] Revoking a player assignment sets the corresponding `tt_player_reports` row's `revoked_at`.

### Privacy

- [ ] Default scout reports omit: contact details, full DOB, coach free-text notes.
- [ ] Toggling privacy checkboxes in the wizard changes what's included.
- [ ] The emailed link page displays a "Confidential — for {recipient_email}" watermark.
- [ ] The emailed link page has no navigation elements.

### Audit

- [ ] "Scout reports history" page lists all sent reports with filters.
- [ ] Each row shows access count, expiry, revocation status, actions.

## Notes

### Sizing

~18–20 hours. Breakdown:

- Fix `tt_readonly_observer` + add `tt_scout` role: ~1 hour
- `tt_player_reports` table migration: ~1 hour
- Emailed link flow (wizard step, email sending, token validation, link viewer): ~6 hours
- Internal scout account flow (assignment UI, scout-side view, capability gating): ~5 hours
- Base64 photo inlining in the renderer: ~1 hour
- Revocation and audit UI: ~2 hours
- Privacy watermark and chrome-free link viewer: ~1 hour
- Testing (role isolation, link expiry, revocation, cross-browser print): ~3 hours

This is the largest sprint in the #0014 epic and one of the higher-risk sprints in the backlog — privacy and external access both go wrong in surprising ways.

### Key design decisions from shaping

- **Both access paths** (emailed links + scout accounts) per Option C.
- **Base64 photos inlined** in emailed-link reports — eliminates photo-hosting dependencies.
- **Persist scout reports only**; other audiences remain ephemeral.
- **`tt_readonly_observer` fixed during this sprint** — adjacent to scout work, logical grouping.

### Privacy review

Before shipping, at minimum:
- Review privacy defaults with a trusted third party (club legal, or a colleague with GDPR familiarity).
- Update the plugin's privacy policy docs (if any exist) to reflect scout data flows.
- Document in the help wiki how HoD should think about releasing data (e.g. "only after verifying the scout's identity with the recipient club").

None of these are code-blocking but all should happen before any live club uses the scout flow with real minors' data.

### Depends on

- #0014 Sprint 4 (wizard exists).
- #0014 Sprint 3 (`PlayerReportRenderer` consumes `ReportConfig`).
- #0019 Sprint 1 (frontend conventions for the new admin pages).
- #0019 Sprint 5 (Administration tile group exists to house "Manage scout access" and "Scout reports history").

### Blocks

Nothing in this epic. Post-epic: #0017 (trial player module) uses scout-like emailed-link patterns for external panels; it can either copy from this sprint or generalize. Flag for #0017's shaping.

### Touches

- `includes/Activator.php` — two role registrations, one new capability.
- `database/migrations/NNNN_create_player_reports.php` — new table.
- `src/Shared/Frontend/FrontendReportWizardView.php` (from Sprint 4) — add scout delivery step.
- `src/Shared/Frontend/FrontendScoutLinkView.php` (new) — the emailed-link viewer. Chrome-free, standalone.
- `src/Shared/Frontend/FrontendScoutAssignmentsView.php` (new) — HoD manages scout↔player assignments.
- `src/Shared/Frontend/FrontendScoutReportsHistoryView.php` (new) — audit/revoke UI.
- `src/Shared/Frontend/FrontendScoutMyPlayersView.php` (new) — scout's view of their assigned players.
- `src/Modules/Reports/` — extensions for persistence, base64 photo inlining, token generation.
- `includes/REST/` — endpoints for: create scout report, revoke, resend, list scout assignments.
- Administration tile group (from #0019 Sprint 5) — add new tiles for scout management and history.
