# TalentTrack v3.100.1 — MFA foundation (#0086 Workstream B Child 1, sprint 1 of 3)

First of three sprints for the TalentTrack-native MFA implementation, locked in #0086 Q1 (build native, not a WP-plugin recommendation, so the auth surface ports cleanly into the future SaaS migration). **No user-facing change yet** — sprint 1 lays the data layer + domain services + Account-page status indicator so sprints 2 and 3 (enrollment wizard + login integration) can build on a stable foundation.

Renumbered v3.98.2 → v3.100.1 mid-rebase after parallel-agent ships of v3.99.0 (#0081 onboarding-pipeline closure) and v3.100.0 (Team Blueprint Phase 2) landed in succession.

## Why split into three sprints

The full feature is ~700-900 LOC at conventional rates. A single-PR ship would put the data layer, the wizard UX, the WordPress `authenticate` filter integration, and the rate-limit / lockout machinery all into one review. Splitting reduces the parallel-PR collision surface (shorter branches collide less) and lets each sprint go through CI on its own. Sprint 1 ships dead code today — that's intentional. Sprint 2 lights up the enrollment surface; sprint 3 wires the login filter.

## What landed in sprint 1

### Migration `0073_user_mfa`

`tt_user_mfa` carries one row per (`wp_user_id`, `club_id`) pair: encrypted TOTP secret, hashed backup codes JSON, remembered devices JSON, enrollment + verification timestamps, rate-limit counters (`failed_attempts` + `locked_until` consumed by sprint 3). Tenancy column `club_id` + `uuid` per CLAUDE.md §4.

### `Modules\Mfa\Domain\TotpService`

Pure RFC 6238 TOTP. HMAC-SHA1 over a 64-bit big-endian counter, ±1-step tolerance, 6-digit code, 30-second step. `verify()` uses `hash_equals` for constant-time comparison. Base32 encode/decode + `otpauth://` URI builder for QR codes. `generateSecret()` returns 20 bytes / 160 bits — the RFC default.

### `Modules\Mfa\Domain\BackupCodesService`

Single-use recovery codes. Format `XXXX-XXXX-XXXX` over a no-ambiguity alphabet (excludes I/O/0/1). `wp_hash_password` storage, `wp_check_password` verify. Constant-time iteration so a timing oracle can't reveal which code matched. Defaults to 10 codes per enrollment.

### `Modules\Mfa\MfaSecretsRepository`

CRUD on `tt_user_mfa`. Encryption + JSON parsing handled inside the repository. Methods: `findByUserId` / `isEnrolled` / `upsertSecret` / `markEnrolled` / `recordVerification` / `updateBackupCodes` / `disable`.

### Account-page MFA tab

New `?page=tt-account&tab=mfa` reachable by every logged-in user. Renders status indicator (enrolled / not enrolled), backup codes remaining when enrolled, a roadmap section listing what sprint 2 and sprint 3 ship.

### `MfaModule` registered in `config/modules.php`

Sprint 1 wires no runtime hooks — `boot()` is a no-op until sprint 2's wizard registers itself and sprint 3's login subscriber hooks `authenticate`.

## What's NOT in this PR

- **The 4-step enrollment wizard** (sprint 2). Intro → secret display + QR code → first-code verification → backup codes display.
- **WordPress `authenticate` filter integration** (sprint 3).
- **Per-club `require_mfa_for_personas` setting** (sprint 3). Default `[ academy_admin, head_of_development ]`.
- **Rate-limited verification with 15-min lockout** (sprint 3).
- **Optional 30-day "remember this device" cookie** (sprint 3).
- **Audit-log integration** (sprint 3). `mfa_enrolled` / `mfa_verified` / `mfa_lockout` / `mfa_backup_code_used` / `mfa_disabled`.

## Affected files

- `database/migrations/0073_user_mfa.php` — new.
- `src/Modules/Mfa/MfaModule.php` — new.
- `src/Modules/Mfa/Domain/TotpService.php` — new.
- `src/Modules/Mfa/Domain/BackupCodesService.php` — new.
- `src/Modules/Mfa/MfaSecretsRepository.php` — new.
- `src/Modules/License/Admin/AccountPage.php` — new MFA tab + open the page nav to non-operators on that tab.
- `config/modules.php` — register `MfaModule`.
- `languages/talenttrack-nl_NL.po` — 16 new msgids.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

16 new translatable strings covering the Account-page tab UI and the roadmap bullet list.

## Player-centricity

MFA's player-centric framing: protecting the WordPress accounts that touch player records. Every player file, every evaluation, every PDP cycle is gated by the cap-and-matrix layer — but the cap layer trusts whoever holds the WP user's session cookie. MFA is the second factor that anchors that trust. Sprint 3's per-club enforcement defaults to admin + head-of-development specifically because those personas have the broadest reach into player data; coaches and scouts can opt in if the academy chooses.
