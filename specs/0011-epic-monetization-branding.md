<!-- type: epic -->

# #0011 — Monetization + branding

## Problem

TalentTrack is a capable, focused plugin with a real user problem to solve. To sustain development beyond one maintainer, it needs to generate revenue. Today it's free, self-installed via GitHub release zips, with no pricing infrastructure, no branding, no marketing site, no license management.

Two distinct problems under one epic:

1. **Monetization** — pricing, tiers, licensing, payment, feature gating.
2. **Branding** — logo, website, marketing copy, screenshots, positioning.

These are separable workstreams. Each can progress without the other, but both should be done before any public launch.

## Proposal

Five sprints combining payment/licensing infrastructure, feature gating pass, and the parallel branding/marketing work. Locked during shaping:

- **Freemius-first** licensing (handles EU VAT, 7–20% revenue share). Migration path to self-hosted documented from day one via the `TT\License::can()` abstraction.
- **Tight free tier**: 1 team / 15 players / 30 evaluations. Designed for extended-demo rather than indefinite free use.
- **Tiers**: Free / Club (€149/yr) / Academy (€399/yr) / Multi-site (€899/yr) per idea file.
- **14-day Academy trial** on top of the feature-limited free tier.
- **Freemius-default currencies** (EUR / USD / GBP).
- **No existing-user migration** — clean slate ship.

## Scope

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Decisions lock + Freemius integration + `TT\License::can()` abstraction | ~14–18h |
| 2 | Free tier caps + 14-day trial + account menu | ~12–15h |
| 3 | Feature audit + gate implementation across modules | ~18–22h |
| 4 | Branding + marketing site (parallel track) | ~30–40h |
| 5 | Pilot + launch | ~10–15h |

**Total: ~84–110 hours** across two tracks (code + branding/marketing).

### Sprint 1 — Freemius integration + License abstraction

**`TT\License::can()` abstraction** — central gatekeeper:
```php
namespace TT\License;

class LicenseGate {
    public static function can(string $feature): bool;
    public static function capsExceeded(string $cap_type): bool;  // 'teams', 'players', 'evaluations'
    public static function tier(): string;  // 'free' | 'club' | 'academy' | 'multisite'
    public static function isInTrial(): bool;
    public static function trialDaysRemaining(): int;
}
```

Every future gate call goes through `TT\License::can('feature_name')`. Swapping Freemius for self-hosted later is a single-module change.

**Freemius SDK integration**:
- Install SDK, configure with product ID + API key.
- Pricing page on Freemius dashboard: tiers, prices, trial config.
- Feature toggles: 2–3 pilot features gated behind the abstraction to prove the wiring (e.g., CSV import, advanced reports from #0014).
- License key entry: TalentTrack → License submenu in wp-admin, status indicator on main dashboard tile grid.

**Events**: track license activation, trial start, trial expiry, upgrade, cancellation in `tt_usage_events` for analytics.

### Sprint 2 — Free tier caps + trial + account

**Free tier cap enforcement**:
- 1 team max (creating a 2nd shows upgrade prompt).
- 15 players max across the club.
- 30 evaluations max total.
- Cap check on every relevant create/import action.

**Soft enforcement**:
- Hitting a cap doesn't error — it shows an "upgrade to continue" modal with the tier options.
- Existing data above-cap is never truncated or blocked. A user who drops from Club → Free (rare) keeps their data, just can't add more until they upgrade back.

**14-day Academy trial**:
- Auto-available on activation OR explicit "start trial" action — decide during implementation.
- Full Academy tier features during trial.
- Reminder emails at T-7, T-3, T-0 via WP mail.
- On expiry: gracefully downgrades to Free; data preserved; upgrade nudge.

**Account menu** in wp-admin:
- TalentTrack → Account: shows current tier, license status, trial days remaining, upgrade/manage links to Freemius.
- Usage summary: teams / players / evaluations used vs. cap.

### Sprint 3 — Feature audit + gating

The grindy sprint.

**Feature audit**: walk every existing feature and assign to tier.

Starting categorization (adjustable in implementation):

| Feature | Free | Club | Academy | Multi-site |
| --- | --- | --- | --- | --- |
| Core players/teams/sessions/goals | ✓ | ✓ | ✓ | ✓ |
| Basic evaluations | ✓ | ✓ | ✓ | ✓ |
| Rate cards / FIFA cards | ✓ | ✓ | ✓ | ✓ |
| Functional Roles (#0019 Sprint 4) | — | ✓ | ✓ | ✓ |
| CSV bulk import (#0019 Sprint 3) | — | ✓ | ✓ | ✓ |
| Advanced reports (#0014 Parts B) | — | ✓ | ✓ | ✓ |
| Scout access (#0014 Sprint 5) | — | — | ✓ | ✓ |
| Trial module (#0017) | — | — | ✓ | ✓ |
| Team chemistry (#0018) | — | — | ✓ | ✓ |
| Photo-to-session (#0016) | — | — | ✓ | ✓ |
| Backup + DR (#0013) | — | ✓ | ✓ | ✓ |
| Multi-site licensing | — | — | — | ✓ |

**Gate implementation**: each listed feature gets a `TT\License::can('feature_key')` call at the appropriate entry point (view rendering, REST endpoint, admin menu registration).

**Upgrade nudges**: features gated out for the current tier show a small "Upgrade to unlock" overlay with the tier that enables them.

### Sprint 4 — Branding + marketing site (parallel track)

Runs as its own workstream, can start in Sprint 1 if delegable.

**Branding**:
- Logo (wordmark + icon variants).
- Color palette.
- Typography choices.
- Apply across: plugin header in wp-admin, readme.txt banner, marketing site, any Freemius-customizable surfaces.

**Marketing site** (separate from the plugin):
- Landing page (hero, pricing tiers, feature matrix).
- Documentation (largely re-uses existing `docs/`).
- Blog / updates.
- Case studies (populated by Sprint 5 pilots).

Tech choice: static site generator (Hugo/Astro) or WordPress itself. Claude Code can draft either; aesthetics matter most.

**Copy**: positioning, tier descriptions, social proof. Written in concert with #0012 Part A's anti-AI-fingerprint pass — both work shapes the same voice.

### Sprint 5 — Pilot + launch

**Pilot**: 3–5 real academies get free Academy tier for 6 months in exchange for:
- Case study (logo + quote + one-paragraph story).
- Feedback on rough edges.
- Reference permission.

**Launch**:
- Public announcement (social + relevant forums).
- Freemius listing goes live.
- Monitor conversion funnel: trial starts, trial-to-paid conversion, cap hits → upgrade conversion.
- First-month metrics review: tune pricing / caps / trial length based on data.

## Out of scope

- **Grandfather migration for existing users** — shipping fresh (no paid users today).
- **Mollie / iDEAL in the first launch** — Freemius default is card-only. Add iDEAL later if Dutch conversion is poor.
- **Team plans beyond Multi-site** — enterprise/custom pricing handled case-by-case.
- **Affiliate program**. Future.
- **Refund handling custom logic** — Freemius handles via their dashboard.
- **Tax handling** — Freemius is Merchant of Record; they handle EU VAT etc.

## Acceptance criteria

- [ ] `TT\License::can()` abstraction centralizes every gating check.
- [ ] Freemius integrated; trial and purchase flows work end-to-end.
- [ ] Free tier caps enforced softly with upgrade nudges.
- [ ] 14-day Academy trial works with email reminders and graceful downgrade.
- [ ] Every feature is categorized to a tier; gates in place.
- [ ] Branding consistently applied across plugin + marketing site.
- [ ] Marketing site live with pricing, docs, case studies.
- [ ] 3–5 pilot academies running; initial case studies published.
- [ ] Launch executed; first-month metrics captured.

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

Late in SEQUENCE.md — after product maturity achieved via #0019, #0014, #0017. Specifically: don't launch with a weak product. The branding/marketing track (Sprint 4) can start earlier if you want to run it in parallel; the code track (Sprints 1–3, 5) should wait until the product justifies the price.

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
