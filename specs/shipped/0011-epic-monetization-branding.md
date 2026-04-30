<!-- type: epic -->

# #0011 — Monetization (licensing + tiers + caps + trial)

> **Scope split (2026-04-25 late evening):** the original spec covered both monetization and branding/go-to-market. Branding + marketing site + pilot + launch were carved out into [#0030](0030-epic-branding-and-go-to-market.md). This epic now covers the **monetization code only**.

## Problem

TalentTrack is a capable, focused plugin with a real user problem to solve. To sustain development beyond one maintainer, it needs to generate revenue. Today it's free, self-installed via GitHub release zips, with no pricing infrastructure, no license management.

This epic covers: **pricing, tiers, licensing, payment, feature gating, trial flow, free-tier caps, developer override**.

Branding, marketing site, pilot recruitment, and public launch live in [#0030](0030-epic-branding-and-go-to-market.md).

## Status

**Done.** Shipped in v3.17.0 (single sprint). Q1-Q8 locked 2026-04-25.

## Locked decisions

| Q | Decision |
| - | - |
| Q1 | **30-day full trial → 14-day read-only grace → free tier**. 44 days from install to hard degrade. |
| Q2 | **1 team / 25 players / unlimited evaluations** for free; numeric caps prevent abuse, **feature gates** drive conversion. Gated behind paid: radar charts, player comparison, rate cards, CSV import, functional roles, partial restore + 14-day undo (#0013 Sprint 2). |
| Q3 | **Free / Standard / Pro at €0 / €399 / €699 per year.** Three tiers. Freemius-default currencies (EUR / USD / GBP). |
| Q4 | **3 sprints** (code / branding / pilot+launch) instead of the original 5. |
| Q5 | **Option A** — Standard = single-academy use; Pro = multi-academy + future epics (#0016 photo-to-session, #0017 trial module, scout flow, S3 backup destinations). |
| Q6 | **Tier→feature map editable via Freemius dashboard** at runtime; PHP `FeatureMap::DEFAULT_MAP` as fallback for offline/cold-start. **Customers cannot edit the matrix** — edits flow only from the Freemius merchant dashboard. |
| Q7 | **Merchant ops dashboard = Freemius dashboard alone for v1.** No merchant analytics inside the customer plugin. A separate `talenttrack-ops` plugin on Casper's own site is a v2 option once the Freemius dashboard's gaps are concrete. |
| Q8 | **Developer tier override** for demos: gated by `TT_DEV_OVERRIDE_SECRET` wp-config.php constant + per-session password. 24h transient timebox; "DEV OVERRIDE" pill in the admin bar while active. Override never reaches customer installs (constant absent → override path 404s). |

## Proposal

- **Freemius-first** licensing (handles EU VAT, 7–20% revenue share). Migration path to self-hosted documented from day one via the `TT\License\LicenseGate` abstraction.
- **Free tier**: 1 team / 25 players / unlimited evaluations. Feature gates drive conversion, not numeric scarcity.
- **Tiers**: Free (€0) / Standard (€399/yr) / Pro (€699/yr).
- **30+14 trial path** on top of the feature-limited free tier.
- **Tier→feature map editable via Freemius dashboard** with PHP fallback.
- **Developer override** for local demos.
- **No existing-user migration** — clean slate ship.

## Scope (single sprint, shipped in v3.17.0)

Freemius SDK adapter (dormant by default) + `LicenseGate` + `FeatureMap` (default + Freemius-override) + free-tier caps + 30+14 trial state machine + Account menu + dev override + 3 keystone feature gates (player comparison, rate cards full, CSV import). **Effort: ~30h.**

Deferred to a follow-up gate-sweep PR (separate from #0011 / #0030):
- Radar chart gating across the 3 render sites
- Functional roles tile gating
- Backup partial-restore + undo gating

### Sprint 1 — Code (foundation + caps + trial + gates + dev override)

The combined code sprint, replacing the original spec's Sprints 1+2+3.

**`TT\License\LicenseGate` abstraction** — central gatekeeper:
```php
namespace TT\License;

class LicenseGate {
    public static function can( string $feature ): bool;
    public static function capsExceeded( string $cap_type ): bool;  // 'teams' | 'players' | 'evaluations'
    public static function tier(): string;       // 'free' | 'standard' | 'pro'
    public static function isInTrial(): bool;
    public static function isInGrace(): bool;    // 14-day read-only grace after trial
    public static function trialDaysRemaining(): int;
    public static function graceDaysRemaining(): int;
}
```

Every gate call goes through `LicenseGate::can( 'feature_key' )`. Swapping Freemius for self-hosted later is a single-module change.

**`TT\License\FeatureMap`** — defaults + Freemius override:

```php
namespace TT\License;

class FeatureMap {
    public const DEFAULT_MAP = [
        'free'     => [ /* core_evaluations, basic_dashboard, ... */ ],
        'standard' => [ /* + radar_charts, player_comparison, rate_cards, csv_import, functional_roles, partial_restore, undo */ ],
        'pro'      => [ /* + multi_academy, photo_session, trial_module, scout_access, s3_backup */ ],
    ];

    public static function can( string $tier, string $feature ): bool;
    public static function syncFromFreemius( array $plan_features ): void; // called by SDK webhook
}
```

Inheritance: Pro inherits Standard inherits Free. The Freemius dashboard's plan-features matrix overrides the PHP defaults at runtime; if Freemius is unsynced/unavailable, fallback to defaults.

**Freemius SDK integration**:
- Install SDK, configure with product ID + API key (read from wp-config.php constants so credentials never enter the repo).
- Wire init **conditionally**: if `TT_FREEMIUS_PRODUCT_ID` isn't defined yet, the SDK is dormant and `LicenseGate::tier()` returns `free` (no monetization until Casper opens the Freemius account and defines the constants).
- Pricing page configured on the Freemius dashboard.
- 30-day trial + 14-day grace mapped onto Freemius's trial primitives + a custom grace state machine.

**Free-tier caps** (soft enforcement):
- 1 team max — creating a 2nd shows upgrade modal.
- 25 players max across the club.
- No evaluation cap.
- Hitting a cap shows an "upgrade to continue" modal with the tier options. Existing data above-cap is never blocked.

**30+14 trial state machine**:
- On install (with Freemius active): user sees a "Start 30-day Standard trial" CTA on the TalentTrack dashboard.
- During trial: Standard tier features unlocked. Reminder emails at T-7, T-3, T-0 via `wp_mail`.
- On day 30: enters 14-day **read-only grace**. Existing data accessible; no new writes to gated features. Persistent banner: "Trial ended. Upgrade to keep adding evaluations" + days-remaining counter.
- On day 44: hard degrade to Free. Data preserved; gated features hidden again.

**Feature gates across modules** (the grind):

| Feature | Free | Standard | Pro | Notes |
| --- | --- | --- | --- | --- |
| Core players / teams / sessions / goals / basic evaluations / rate-card view | ✓ | ✓ | ✓ | |
| Players above 25 / teams above 1 | — | ✓ | ✓ | |
| Radar charts | — | ✓ | ✓ | |
| Player comparison | — | ✓ | ✓ | |
| Rate cards (full analytics) | — | ✓ | ✓ | |
| CSV bulk import (#0019 Sprint 3) | — | ✓ | ✓ | |
| Functional Roles (#0019 Sprint 4) | — | ✓ | ✓ | |
| Backup partial restore + 14-day undo (#0013 Sprint 2) | — | ✓ | ✓ | |
| Backup local + email destinations (#0013 Sprint 1) | ✓ | ✓ | ✓ | Free (basic safety) |
| Backup S3 / Dropbox / GDrive | — | — | ✓ | (Future) |
| Multi-academy / federation | — | — | ✓ | |
| Photo-to-session (#0016) | — | — | ✓ | Per-photo cost; naturally Pro |
| Trial module (#0017) | — | — | ✓ | |
| Scout access (#0014 Sprint 5) | — | — | ✓ | |
| Team chemistry (#0018) | — | — | ✓ | |

Each gated feature gets a `LicenseGate::can()` check at the entry point (view rendering, REST endpoint, admin menu registration). Features that aren't shipped yet (#0016, #0017, etc.) just get the key reserved in `FeatureMap`; gates land when the feature ships.

**Upgrade nudges**: gated-out features show a small "Upgrade to unlock — Standard / Pro" overlay with a link to the Freemius checkout.

**Account menu** in wp-admin (`TalentTrack → Account`):
- Current tier + license status + trial/grace days remaining (or "Free")
- Upgrade / manage / open-customer-portal links to Freemius
- Usage summary: teams / players used vs. caps
- Visible "DEV OVERRIDE" notice if active

**Developer override (Q8)**:
- Hidden admin page `wp-admin/admin.php?page=tt-dev-license` registered ONLY if `TT_DEV_OVERRIDE_SECRET` is defined in wp-config.php
- Form requires a password whose bcrypt hash matches the constant
- On match: select tier (free / standard / pro / trial) → 24h transient set
- "DEV OVERRIDE" pill in the wp-admin top bar while active
- `LicenseGate::tier()` checks the override transient before Freemius

**Events**: track license activation, trial start, grace start, trial expiry, upgrade, cancellation, dev-override-toggle in `tt_usage_events`.

### Branding + marketing site + pilot + launch

→ Carved out into [#0030 — Branding and go-to-market](0030-epic-branding-and-go-to-market.md).

The original spec coupled them into Sprints 2 and 3 here, but they're a different skill set (design + marketing + sales) on a different cadence (calendar time, not driver-time). #0030 owns them as a separate epic with its own shaping pass.

## Out of scope

- **Grandfather migration for existing users** — shipping fresh (no paid users today).
- **Mollie / iDEAL in the first launch** — Freemius default is card-only. Add iDEAL later if Dutch conversion is poor.
- **Team plans beyond Multi-site** — enterprise/custom pricing handled case-by-case.
- **Affiliate program**. Future.
- **Refund handling custom logic** — Freemius handles via their dashboard.
- **Tax handling** — Freemius is Merchant of Record; they handle EU VAT etc.

## Acceptance criteria

- [x] `TT\License\LicenseGate::can()` abstraction centralizes every gating check.
- [x] Freemius SDK adapter wired; dormant until credentials defined; ready for end-to-end purchase flow once Casper opens the account.
- [x] Free tier caps enforced softly with upgrade nudges (1 team / 25 players).
- [x] 30-day trial + 14-day grace state machine; graceful downgrade to Free.
- [x] Three keystone features categorized to tier and gated (player comparison, rate cards full, CSV import). Remaining feature gates land in a separate sweep PR.
- [x] Tier→feature map editable via Freemius dashboard at runtime; PHP defaults as fallback.
- [x] Developer override mechanism for owner-only demos.

Acceptance items related to **branding consistency, marketing site, pilot academies, and launch execution** moved to [#0030](0030-epic-branding-and-go-to-market.md).

## Notes

### Why Freemius first

- Handles EU VAT as Merchant of Record (significant legal burden removed).
- Handles failed card retries, subscription renewal emails, invoice generation.
- Revenue share (7–20%) is the cost; worth it until volume justifies self-hosted.
- Community of WP plugin authors using them; lots of documented patterns.

### Self-hosted migration path

If/when self-hosted makes sense (typically at ~€50-100k ARR):
- `TT\License::can()` stays the same interface.
- Swap Freemius SDK for EDD or custom.
- Customer data migrates (Freemius exports it).
- Customer checkout URL changes — one-time email communication to existing subscribers.

### DPIA + legal

- Freemius handles GDPR for billing data.
- Customer-facing privacy statement on marketing site (covers: billing, analytics, support).
- In-plugin privacy (what the plugin itself does with academy data) is a separate document — already evolving across other epics (#0014, #0016).

### Sequence position

**Done** in v3.17.0. The branding + go-to-market work that historically sat under this epic is now [#0030](0030-epic-branding-and-go-to-market.md); sequencing for that lives there.

### Cross-epic interactions

- **#0012** — anti-AI-fingerprint pass on copy. Do #0012 Part A before Sprint 4's copy finalization. Marketing copy that reads like it was AI-generated is a sales anti-signal.
- **#0016** — photo-to-session API costs are Academy tier + per-photo overage. Feature gate sits here.
- **#0013** — backup is Club tier (per the table). Gate at activation.
- **#0017** — trial module is Academy tier.

### Touches

- `src/License/` — new module
- Freemius SDK install + config
- Every module that needs a gate call (~15-20 touch points after feature audit)
- `wp-admin` menus — Account, License submenus
- New marketing site in a separate repo or subfolder
- Branding assets — new `assets/brand/` folder
- `readme.txt` / plugin description — final pricing-aware copy

## Refinement needed before this becomes Ready

The April 2026 idea-funnel pass surfaced four conflicts between this spec and the funnel framing. They need decisions before Sprint 1 can start.

1. **Trial length.** This spec says **14 days**; the funnel argues **30 days** with a 14-day read-only grace + later hard degrade. 30 days is more humane for clubs with seasonal usage cycles; 14 days converts faster on average.
   → **Recommended for shaping**: 30-day full trial → 14-day read-only grace → free tier. Total 44 days from install to hard degrade. Adjust based on early conversion data.

2. **Free-tier limits.** This spec says **1 team / 15 players / 30 evaluations** (tight, designed for extended demo). Funnel suggests **1 team / 25 players / 2 staff / "Basic list view (no radar charts)"** (slightly more generous, also gates radar charts behind paid). The radar-chart gate is interesting because it's a high-perceived-value visual feature.
   → **Recommended for shaping**: 1 team / 25 players / no cap on evaluations but **radar charts gated behind Pro**. Charts are the obvious "wow this is useful" moment that converts.

3. **Pro vs Academy naming + tier count.** This spec proposes Free / **Club** (€149) / **Academy** (€399) / Multi-site (€899). Funnel uses Free / **Pro** / **Business** with prices as placeholders. Both are workable; "Pro" is more familiar to non-football SaaS buyers, "Club"/"Academy" matches the football vocabulary clubs use about themselves.
   → **Recommended for shaping**: keep this spec's **Club / Academy / Multi-site** naming — it speaks the customer's language. Confirm the prices.

4. **Sprint ordering vs the rest of the backlog.** This spec sits in Phase 5 of SEQUENCE.md (after most product work). Funnel argues the Setup Wizard (#0024, new) should ship before monetization for activation reasons. Acceptance: place #0024 between Phase 4 and Phase 5, ahead of #0011.

These four are inline-decidable; tee them up at the start of shaping rather than mid-Sprint-1.
