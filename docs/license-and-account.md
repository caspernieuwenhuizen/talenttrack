---
title: License & account
group: configuration
summary: Tier, usage caps, and how a plan is set on an install.
audience: [admin]
module: TT\Modules\License\LicenseModule
order: 100
---

# License and account

TalentTrack runs on three tiers — **Free**, **Standard** and **Pro**. Which one an install is on is decided when it is provisioned, not inside the plugin: there is no checkout here, no license key to paste, and nothing a club admin can toggle to change what their install is entitled to.

## How an install learns its tier

Your TalentTrack operator records the plan when the install is set up. The install keeps a local copy of that answer so it keeps working normally if the operator's systems are briefly unreachable — a plan does not evaporate because a server was down for an afternoon.

Resolution order, first match wins:

1. **Developer override** — only on installs where the owner has configured `TT_DEV_OVERRIDE_SECRET`. See below.
2. **The recorded plan.**
3. **Free** — when no plan has been recorded, or the recorded one has gone unrefreshed for so long that it is no longer trusted.

If the Account page says no plan is recorded and that looks wrong, contact your operator. It is a one-line fix on their side and nothing in your data is affected.

## Changing plan

Ask your operator. Your install moves to the new tier in place: the same site, the same URL, the same data, with more room and more features. Nothing is migrated, exported, re-imported, or rebuilt, and there is no downtime.

Going the other way works the same, with one caveat worth knowing: dropping to a tier whose caps you are already over does not delete anything. Existing teams and players stay readable; you just cannot add past the cap until you are back under it.

## Tiers

| Feature | Free | Standard | Pro |
| - | - | - | - |
| Core players / teams / activities / goals / basic evaluations | ✓ | ✓ | ✓ |
| Backup local + email destinations | ✓ | ✓ | ✓ |
| Up to 1 team and 25 players | ✓ | unlimited | unlimited |
| Radar charts, player comparison, rate cards (full) | — | ✓ | ✓ |
| CSV bulk import | — | ✓ | ✓ |
| Functional roles | — | ✓ | ✓ |
| Backup partial restore + 14-day undo | — | ✓ | ✓ |
| Scheduled reports | — | ✓ | ✓ |
| Multi-academy / federation | — | — | ✓ |
| Trial player module | — | — | ✓ |
| Scout access | — | — | ✓ |
| Team chemistry + blueprints | — | — | ✓ |
| S3 / Dropbox / GDrive backup destinations | — | — | ✓ |

> **This table is out of date against the product.** It describes the split as it was drawn in v3.17.0. Most of what TalentTrack has gained since — match analysis, the media library, training plans, alerts, courses, tournaments, the analytics platform and more — has no tier assigned and therefore behaves as Free. Re-drawing it is a known, tracked piece of work; until it lands, treat the table as historical rather than authoritative.

## Free-tier caps

**1 team, 25 players, unlimited evaluations.** Hitting the team or player cap surfaces an upgrade nudge instead of saving. Caps apply only on Free; Standard and Pro have none.

The caps are enforced in the UI, in the wizards, and on the REST API, so they cannot be sidestepped by the import path or by a direct API call.

## Account page

Clicking **TalentTrack** in the wp-admin sidebar lands on the Account page. It has three tabs:

| Tab | Cap | What's there |
| - | - | - |
| **Account** | `tt_edit_settings` (operators only) | Current tier, usage versus caps, what the next tier adds, phone-home diagnostics |
| **Plan & restrictions** | `read` (everyone logged in) | Current plan, caps table with at-cap warnings, and the full Free / Standard / Pro feature matrix with your effective tier highlighted |
| **MFA** | `read` (everyone logged in) | Your own two-factor enrollment and backup codes |

The Plan tab is open to everyone deliberately: a coach who cannot find a feature should be able to see for themselves whether it is missing or merely locked.

## Non-commercial test instances

`TT_COMMERCIAL_MODE` in `talenttrack.php` decides whether any of this is enforced.

When it is `false` — the default, and the case on every developer and demo install — the install is a **non-commercial test instance**: every feature is unlocked, caps do not apply, and the Account page renders a single explanatory notice instead of the plan UI. When it is `true`, the resolution order above applies.

## Developer tier override (owner-only)

For demos and local testing without provisioning a real plan.

**One-time setup on your demo / dev install:**

1. Generate a bcrypt hash of a password you'll memorize. In a PHP shell:
   ```php
   echo password_hash( 'your-password-here', PASSWORD_BCRYPT );
   ```
2. Add to `wp-config.php`:
   ```php
   define( 'TT_DEV_OVERRIDE_SECRET', '$2y$10$....your-hash-here....' );
   ```
3. Visit `wp-admin/admin.php?page=tt-dev-license` (no menu link — type the URL).
4. Enter your password, pick a tier, click Activate.

The override is stored as a 24h transient. A "🔓 DEV: Pro" pill appears in the wp-admin top bar so you remember it's on. Re-visit the URL to clear it early.

**Customer installs never see this code path** — without the constant defined, the admin page 404s and the gate ignores the override entirely.
