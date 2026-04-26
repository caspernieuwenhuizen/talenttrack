<!-- audience: admin -->

# License and account

TalentTrack runs on three tiers — **Free**, **Standard** (€399/yr), and **Pro** (€699/yr). The license + account module shipped in v3.17.0 lays the plumbing; actual billing kicks in once the Freemius credentials are pushed to a release.

## What ships in v3.17.0

- A **`Configuration → Account`** wp-admin page (or `TalentTrack → Account` directly under the dashboard) showing the current tier, trial / grace state, and usage versus free-tier caps.
- The **30-day Standard trial → 14-day read-only grace → Free** state machine. A single click on the Account page starts the trial.
- **Free-tier caps**: 1 team / 25 players / unlimited evaluations. Hitting the team or player cap surfaces an upgrade nudge instead of saving.
- **Three keystone feature gates** wired:
  - Player comparison view
  - Rate cards (full analytics)
  - CSV bulk import
- **Developer override** for demo recordings and local testing — see below.
- The Freemius SDK adapter is **dormant by default** and activates only when `TT_FREEMIUS_PRODUCT_ID` and `TT_FREEMIUS_PUBLIC_KEY` are defined in `wp-config.php`. Until then, every install runs Free (or trial / dev override if active).

## Tiers (provisional)

| Feature | Free | Standard | Pro |
| - | - | - | - |
| Core players / teams / sessions / goals / basic evaluations | ✓ | ✓ | ✓ |
| Backup local + email destinations | ✓ | ✓ | ✓ |
| Up to 1 team and 25 players | ✓ | unlimited | unlimited |
| Radar charts, player comparison, rate cards (full) | — | ✓ | ✓ |
| CSV bulk import | — | ✓ | ✓ |
| Functional roles | — | ✓ | ✓ |
| Backup partial restore + 14-day undo | — | ✓ | ✓ |
| Multi-academy / federation | — | — | ✓ |
| Photo-to-session AI (#0016 when ships) | — | — | ✓ |
| Trial player module (#0017) | — | — | ✓ |
| Scout access (#0014 Sprint 5) | — | — | ✓ |
| S3 / Dropbox / GDrive backup destinations | — | — | ✓ |

The matrix is **editable from the Freemius dashboard at runtime** — the PHP defaults above are a fallback; whatever Casper sets in Freemius's plan-features overrides them on every install once the SDK syncs.

## Trial flow

1. Free user clicks **Start 30-day Standard trial** on the Account page.
2. Standard-tier features unlock for 30 days; days-remaining shown on the Account page.
3. On day 30, install enters **read-only grace**: existing data accessible, gated features hidden, banner says "Trial ended — upgrade to keep adding new evaluations."
4. On day 44, install hard-degrades to Free. Data preserved.

A trial can only be started once. Resetting it requires the developer override.

## Developer tier override (owner-only)

For demos and local testing without paying for yourself.

**One-time setup on your demo / dev install**:

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

The override is stored as a 24h transient. A "🔓 DEV: Pro" pill appears in the wp-admin top bar so you remember it's on. Re-visit the URL to clear early.

**Customer installs never see this code path** — without the constant defined, the admin page 404s and `LicenseGate::tier()` ignores the override.

## Account configuration

Three constants control monetization (all in `wp-config.php`, all optional):

| Constant | Required for | Effect |
| - | - | - |
| `TT_FREEMIUS_PRODUCT_ID` | Paid plans + checkout | Activates the SDK |
| `TT_FREEMIUS_PUBLIC_KEY` | Paid plans + checkout | Authenticates with Freemius |
| `TT_DEV_OVERRIDE_SECRET` | Dev override | Enables the hidden override page |

Without the first two, the plugin runs Free for everyone. That's the safe default — Sprint 1 ships with monetization dormant; Casper enables it when his Freemius account is ready.

## Merchant analytics

For v1, **use Freemius's own dashboard** at freemius.com — installs, trials, conversions, MRR, churn, refunds, EU VAT collection. A separate `talenttrack-ops` plugin/site for richer custom analytics is the v2 option once Freemius's gaps are concrete.
