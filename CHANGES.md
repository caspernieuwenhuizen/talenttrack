# TalentTrack v3.110.1 — Session management UI + login-fail tracking (#0086 Workstream B Children 2 + 3)

Two children of #0086 Workstream B bundled in one ship at user direction. Child 1 (TT-native MFA) shipped in v3.100.1 / v3.101.1 / v3.103.1. Child 4 (admin IP allowlist) is explicitly killed — see below.

This closes #0086 Workstream B except for Child 4 (killed). Workstream C (annual external audit by Securify or Computest) is the remaining work on the epic.

Renumbered v3.109.0 → v3.110.1 across multiple rebases as 8 successive parallel-agent ships took the v3.109.x and v3.110.0 slots.

## Child 2 — `?tt_view=my-sessions`

Every logged-in user manages their own active WP sessions. The view reads `WP_Session_Tokens::get_for_user( $user_id )` directly — no parallel storage. Each session card surfaces a UA-derived device label (`Chrome on macOS`, `Safari on iPhone`, …), the IP address that started the session, sign-in time + expiry via `human_time_diff()`, and a *This session* badge on the cookie that authenticated this request.

Per-session *Revoke* button on every session except the current one (revoking the current cookie would log the user out of the page that revoked it — they use the browser sign-out for that). *Revoke all other sessions* bulk button (only when 2+ sessions) calls `WP_Session_Tokens::destroy_others()` and keeps the current session.

Every revocation writes a `tt_audit_log` row with action `session_revoked`, payload `mode = 'single' | 'all_others'`. Single-revoke payloads also carry a truncated token-id prefix for traceability without leaking the full SHA-256 hash.

Wired in `DashboardShortcode::dispatchAccountView` alongside `my-settings`; new entry "My sessions" added to the user-menu dropdown next to "My settings". New admin-post endpoints `tt_my_sessions_revoke` + `tt_my_sessions_revoke_others` registered in `Kernel::boot()`.

Mobile-first per CLAUDE.md §2: ≥48px touch targets, native form POST works without JS, card layout uses `flex-wrap: wrap` so the *Revoke* button drops below the metadata block on phones.

## Child 3 — login-fail tracking + Failed-logins tab

New `SecurityModule` registered in `config/modules.php`. `boot()` calls `LoginFailedSubscriber::init()`, which hooks `wp_login_failed` (priority 10, 2 args).

On every failed attempt: resolves the attempted username to a `wp_user.id` if it matches a real account (login OR email lookup); otherwise `user_id = 0`. Writes a `tt_audit_log` row with action `login_fail`, entity_type `auth`, entity_id = resolved user_id. Payload carries `username` (255-char), `user_agent` (255-char), and `error_code` from the `WP_Error` (`incorrect_password` / `invalid_username` / `invalidcombo` / etc.) so brute-force detection can distinguish account-targeted attacks from spray attempts. Source IP rides on the existing `ip_address` column.

The audit-log surface (`?tt_view=audit-log`) gains a tab switcher at the top: **All entries** (the existing browser, default tab) / **Failed logins** (new aggregate view at `?tab=failed-logins`). The aggregate view shows last-7-day + last-30-day count cards, a daily breakdown table for the 30-day window, top-10 attempted usernames, and top-10 source IPs.

The username-aggregation query uses `JSON_EXTRACT` against the `payload` column (MySQL 5.7+ / MariaDB 10.2.3+); on older hosts the username table is hidden gracefully and the daily breakdown + IP aggregate still work.

**No automatic lockout in v1** per spec — visibility only; lockout is a v2 enhancement once real volume is observed.

## Why Child 4 is killed

Spec proposed `feat-admin-ip-whitelist` (~3-5 days, ~120 LOC, optional): per-club CIDR allowlist that returns 403 on admin / impersonation actions outside the list. Killed because (a) few academies have stable admin IPs — coaches and HoD log in from home, away matches, parent meetings, the academy itself, (b) MFA already covers the threat model — Workstream B Child 1 requires academy_admin + head_of_development to verify TOTP on every new session, (c) future SaaS migration moves IP gating to the platform (Cloudflare Access / Amazon WAF). The user direction `2a` made this decision explicit.

## CI guard updates

`No legacy 'sessions' strings (#0035)` — three independent updates:

1. Removed `FrontendMySessionsView`, `tt-sessions`, and `my_sessions` from the forbidden-token list. Those names belong to the v3.110.1 WP-login-session feature, not the OLD training-session vocabulary the guard was put in place to protect. The remaining tokens still catch the OLD vocabulary regressions.
2. Added `src/Shared/Admin/MySessionsActionHandlers.php` and `src/Shared/Frontend/FrontendMySessionsView.php` to the i18n-strings allow-list — both files legitimately use "session"/"sessions" in user-visible strings, all translated in the .po.
3. Whitelisted `'my-sessions'` slug literal + `My sessions` user-menu label in the i18n-phrases allow-list — the shortcode dispatcher legitimately references the slug.

Also fixes two duplicate msgids (`Head coach` / `Team manager`) introduced by v3.108.1.

## Files

### New
- `src/Modules/Security/SecurityModule.php` — module registered in `config/modules.php`.
- `src/Modules/Security/Hooks/LoginFailedSubscriber.php` — `wp_login_failed` → audit row.
- `src/Shared/Frontend/FrontendMySessionsView.php` — `?tt_view=my-sessions` view.
- `src/Shared/Admin/MySessionsActionHandlers.php` — admin-post endpoints.

### Modified
- `config/modules.php` — registers `SecurityModule`.
- `src/Core/Kernel.php` — inits `MySessionsActionHandlers`.
- `src/Shared/Frontend/DashboardShortcode.php` — `my-sessions` added to `$account_slugs` + dispatch arm + user-menu entry.
- `src/Shared/Frontend/FrontendAuditLogView.php` — tab switcher + `Failed logins` aggregate view.
- `.github/workflows/release.yml` — three CI guard updates per the section above.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.
- `languages/talenttrack-nl_NL.po` — 9+ new msgids.
- `docs/security-operator-guide.md` (EN+NL) — *Session management + login-fail tracking* section.

## Translations

9+ new NL msgids covering session labels (`This session`, `Signed in %s`, `Expires in %s`, `IP %s`, `Unknown device`), bulk + per-session button labels, the *Revoke all other sessions* confirm prompt, the tab switcher, and the four flash messages, plus failed-logins aggregate copy.

## Player-centricity

Indirect — sessions belong to user accounts, not players. But the threat this addresses is "a coach loses their phone with the academy account logged in"; the academy can lock out the device before sensitive player data is exposed. Closing the loop on the player-centricity-includes-protecting-the-player principle from CLAUDE.md §1.

## SaaS-readiness

The session-management surface is built against `WP_Session_Tokens` rather than direct `wp_user_meta` reads, so a future swap of the session backend (Redis-backed token store, JWT bearer tokens, etc.) only needs to honour the WP `WP_Session_Tokens` interface to keep the view working. The audit hook is portable — `wp_login_failed` is a stable WordPress hook; the SaaS migration will need to fire an equivalent signal on its own auth surface, but the consumer (audit row writer) doesn't care where the signal comes from.
