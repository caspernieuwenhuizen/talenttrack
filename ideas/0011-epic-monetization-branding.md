<!-- type: epic -->

# Monetize TalentTrack — pricing model, trial, licensing enforcement, payments, and branding

Raw idea:

Generate an idea on monetizing the app. Consider one-time payment vs yearly vs monthly subscription and also based on usage. Think about a trial version based on time or features or usage. Think about how to monitor/check/enforce technically. Think about payment options, ideally no setup costs upfront. Also as part of the idea, perhaps a separate sprint think about a marketing/branding story for TalentTrack.

## Why this is an epic

Pricing model, trial mechanics, licensing infrastructure, payment processor integration, license enforcement on the plugin side, a separate branding sprint, and probably a marketing site. Minimum 4–5 sprints, and it touches every part of the plugin because every feature boundary becomes a potential plan boundary.

## The big decision: build licensing yourself or use Freemius

Before anything else in this epic, this is the fork that changes every downstream choice:

- **Freemius (managed).** Third-party SaaS built specifically for WordPress plugin licensing. Handles checkout, Stripe/PayPal, license keys, software updates (replaces `plugin-update-checker`), trial flows, EU VAT, subscriptions, sandbox testing. Revenue-share model — roughly 7% of revenue, nothing upfront. Genuine "no setup cost" in the way you asked. Integration is a documented SDK drop-in; a `@fs_premium_only` comment tag can hide premium files from a free build automatically.
- **Self-hosted (DIY).** Keep `plugin-update-checker`, add a license server (WordPress + custom endpoints, or a small Laravel app), wire Stripe/Mollie directly, build trial-and-enforcement logic, handle EU VAT yourself (this is the nastiest part — OSS + Moss registration or a service like Quaderno). No revenue share. Significantly more work — call it a full sprint of just plumbing before a single paid feature ships.

Recommendation: **start with Freemius**, get to revenue fast, reconsider self-hosted only if revenue share becomes meaningful (roughly >€50k/year). Everything below in this spec works under either choice; the differences are flagged inline.

## Pricing model

Four monetization axes are in the raw idea. They're not mutually exclusive — the real question is which one is the *primary* axis and which become modifiers.

### Option A — Annual subscription, per-site, by tier (recommended primary)

Classic WP plugin model. Customer pays yearly for a license that unlocks pro features + updates. When the subscription lapses, the plugin keeps working but stops getting updates and premium features lock.

Tiers (straw proposal — numbers to tune during shaping):

| Tier | €/year | Sites | Fit |
| --- | --- | --- | --- |
| Free | €0 | unlimited | 1 team, up to N players, core evaluations, no reports, no PDF export |
| Club | €149 | 1 | unlimited teams + players, reports, PDF export, goals, custom fields |
| Academy | €399 | 1 | everything in Club + comparison, rate cards, bulk actions, branding |
| Multi-site | €899 | 5 | everything in Academy + aggregate reporting across sites |

Why annual as primary: predictable revenue, standard for the category (every serious WP plugin works this way), and every licensing platform is optimized for it.

### Option B — Monthly subscription

Offered alongside annual at ~20% premium (standard SaaS pattern — €15/mo vs €149/yr). Lowers the commitment barrier; captures customers who'd balk at a full year upfront. Higher churn, more chargebacks, more processing fees per euro. Worth having but not the flagship.

### Option C — One-time payment (lifetime license)

Popular in some WP niches, bad for sustainability — no ongoing revenue to fund maintenance, support, or the hosting/licensing costs. If offered at all, price it at ~4× annual (so €599 for Club-equivalent) and cap it to a limited window / limited quantity as a launch promotion. Not recommended as a permanent option.

### Option D — Usage-based

Charge by number of players tracked, or evaluations stored, or sessions logged. Philosophically matches the product (a small club and a big academy use the plugin very differently). But it creates weird incentives — coaches rate fewer players to save money, which fights the product's own goal. Also harder to enforce locally (needs either regular phone-home or a server-side data store, which this plugin doesn't have). Keep this as a modifier on tiers ("Club tier includes up to 100 players, then €0.50/month per extra") rather than the primary model.

### Proposed shape

**Annual subscription as the primary axis, per-site, three paid tiers plus free.** Monthly variant at a premium. Usage only as a soft cap within tiers (X players included, overage billing optional). No lifetime license as a permanent product — maybe a limited launch promo.

## Trial

Three options, can be combined:

- **Time-based trial** — full Academy-tier access for 14 or 30 days, then reverts to Free or asks for a license. Most common, easiest to implement, well understood by users.
- **Feature-gated free tier** — no time limit, just a limited subset of the plugin. Already described above as "Free" tier. Acts as a permanent trial.
- **Usage-gated trial** — free up to N players/teams/evaluations, then upgrade required. Another way to express the free tier.

Recommended combination: **Free tier (feature + usage gated, no expiry) + a 14-day Academy-tier trial** that any free user can activate once to try the top stuff. The free tier generates long-tail word of mouth (a small club can genuinely use it forever without paying, which is good for awareness), and the 14-day upgrade trial converts the ones with real needs.

Trial mechanics worth nailing:

- No credit card required to start trial (reduces friction, standard modern practice).
- Trial auto-ends into the free tier on day 15 — data and settings preserved, premium features just re-lock. Nothing gets deleted.
- One trial per site (hashed site URL). Multiple trials per account if they run multiple sites.
- Admin notice countdown starting day 10 of trial, linking to checkout.

## License enforcement — technical

### The honest framing

License enforcement on self-hosted WordPress is always soft. Anyone sufficiently motivated can strip the check — the plugin runs on their server and they control the code. The goal isn't DRM; it's to make paying easier than cracking, and to stop honest users from accidentally using premium features without a valid license. This is the industry norm and is fine commercially.

### Mechanism

- Each install generates a stable site fingerprint (`site_url` + plugin install timestamp, hashed).
- On license activation, the plugin calls the licensing server (Freemius or our own) with the key + fingerprint. Server returns a signed token with expiry, plan, features enabled.
- Plugin stores the token in `wp_options` as `tt_license_token`. Tokens expire in 7 days; plugin phones home to refresh in the background.
- If the refresh fails (network, server down, expired), plugin keeps working for a grace period (14 days) using the cached token, then reverts to Free tier. This avoids the worst-case "customer paid, our license server had an outage, their plugin stopped working" scenario.
- Every premium feature check goes through a single `TT\License::can('feature_name')` gate so we have one place to enforce and test.
- The existing `plugin-update-checker` stays (in self-hosted mode) or is replaced by Freemius's own updater (in Freemius mode) — either way, paid releases don't download to expired licenses.

### What gets enforced where

- **Feature flags.** Premium features check `License::can()`. Cheap to add, cheap to test.
- **Player/team caps on Free tier.** Check on create in the relevant REST endpoint and admin form. Existing records grandfathered if a license downgrades.
- **Updates.** Expired licenses stop receiving plugin updates.
- **PDF export, bulk actions, reports.** Entire menu items hidden + endpoints 403 for Free tier.

### What we do NOT do

- No obfuscation of PHP code. It's GPL-adjacent, people can read it, that's fine.
- No aggressive phone-home on every page load (performance + privacy).
- No "brick the site" behaviour on expiry. Ever. That kills trust.

## Payment options

Primary processors to support (all have zero setup cost, transaction-only fees):

- **Stripe** — 2.9% + €0.25 typical, global reach, best developer experience. Default.
- **Mollie** — 1.8% + €0.25 on EU cards, iDEAL at €0.29 flat (no percentage), strong on European local methods (Bancontact, SOFORT, giropay). Given the plugin's Dutch origin and current `nl_NL` user base, **Mollie should absolutely be offered alongside Stripe** — iDEAL is how most Dutch customers expect to pay, and losing the percentage fee on iDEAL is material at scale.
- **PayPal** — still requested by some customers, higher fees (~3.4% + €0.35), worth including as a secondary option to avoid losing sales.

Via Freemius, Stripe + PayPal are built in; Mollie isn't natively supported and would require a bit of thinking. In self-hosted mode, all three wire up directly.

Recommendation for day-one: **Stripe + Mollie**. Add PayPal later if customer feedback demands it.

## VAT and tax compliance — the bit people forget

If you sell to EU consumers from the EU, you owe VAT at the customer's rate, reported via OSS. Handling this yourself is significantly more work than building the whole rest of this epic. Two clean options:

- Freemius is Merchant of Record — they handle VAT entirely, you just get net payouts. Biggest operational benefit of using Freemius.
- Self-hosted: use a service like Quaderno or Paddle-as-MoR (note: Paddle itself is a Merchant-of-Record alternative to Stripe that handles tax, but it *does* have higher fees — ~5%, not zero-setup but no monthly either).

This single consideration is the strongest argument for starting with Freemius.

## Marketing and branding sprint (parallel track)

This is separate enough to treat as its own sprint running in parallel.

### Positioning question to answer first

Is TalentTrack for:

- **(a)** Small amateur clubs — the dad coaching U12s who wants a better spreadsheet? Or
- **(b)** Semi-pro academies — assistant coaches at BVO youth departments, regional talent programs? Or
- **(c)** Both, with different tiers?

The answer drives every marketing decision: tone, pricing anchors, where you advertise, whether the copy is in Dutch, English, or both. The plugin's current feature set (evaluations, rate cards, head-of-development role, player comparison, aggregate reporting) skews toward **(b) academy-grade**, so my bet is that's the sharp end. But the Free tier should serve (a) to generate word of mouth up the chain.

### Brand deliverables for the sprint

- **Name + tagline review.** "TalentTrack" is fine but generic — 40+ products with similar names. Worth a trademark check, and possibly a distinctive wordmark so the logo does the differentiating work.
- **Logo + color system.** Professional enough to sit next to club crests.
- **Marketing site.** One page, maybe two. Hero + three-feature-strip + social proof + pricing + FAQ + checkout link. Built as static HTML or a simple framework, not inside the plugin.
- **Screenshots + demo video.** 60-90 second walkthrough showing the rating flow, the coach dashboard, the PDF export. Biggest conversion lever for WP plugin sales.
- **Content pillars.** Blog/LinkedIn posts on player development, evaluation frameworks, the "why rate-cards" philosophy. SEO + credibility.
- **Customer stories.** Three clubs using the plugin today, written up with quotes and screenshots. Ask early for permission.

### Launch channels to consider

- Dutch football coaching communities (KNVB, regional academy networks, Coaches Betaald Voetbal). Warm ground given the plugin's origin.
- LinkedIn posts targeting head coaches and heads of development at clubs and academies.
- A Product Hunt launch is possible but expect ~0 football-coach audience there — optimize elsewhere.
- Paid ads: Google Search against terms like "player evaluation software" and "football player tracking plugin" — low volume, high intent. Start tiny, €5/day, see what converts.

## Open questions

- **Free tier caps: what numbers?** "Up to 1 team, up to 25 players, up to 50 evaluations" is a starting point. Needs tuning — generous enough to be useful and showable, tight enough that real academies hit the wall fast.
- **Currency.** EUR primary. Offer USD + GBP if Stripe/Freemius handles FX seamlessly (they do).
- **Existing users.** Is there an installed base already? If yes, they need a grandfather path — either lifetime Free tier at current usage levels, or a discounted first-year upgrade to Club.
- **Pilot customers.** Before public launch, give 3–5 real clubs an Academy license free for 6 months in exchange for case studies + feedback. They become the marketing site's social proof.
- **License key UX.** Where does the user enter their key? Standard pattern: a TalentTrack → License submenu in wp-admin, with status indicator in the main dashboard.
- **Refund policy.** 14-day money back is industry standard. Matches Paid Memberships Pro's 100-day which is generous but rare.
- **Freemius lock-in.** If we start with Freemius and migrate off later, the licensing data migrates but the customer checkout flow changes (from Freemius-hosted to our own). Plan for the possibility from day one by keeping the in-plugin license-gate abstract (`TT\License::can()`) so swapping the backend is a single-module change.

## Decomposition / rough sprint plan

1. **Sprint 1 — decisions + Freemius integration.** Lock pricing tiers, pick Freemius, integrate SDK, set up pricing page on their dashboard, wire `TT\License::can()` abstraction, gate 2–3 pilot features behind it.
2. **Sprint 2 — trial + free tier caps.** Implement 14-day trial flow, free tier player/team caps, upgrade nudges, account menu in wp-admin.
3. **Sprint 3 — feature audit + gating.** Walk every existing feature and decide Free / Club / Academy. Implement the gates. This is grindy but critical.
4. **Sprint 4 — branding + marketing site.** Parallel to the above; runs as its own track. Logo, site, copy, screenshots, demo video.
5. **Sprint 5 — pilot + launch.** Pilot customer onboarding, case studies, public launch, monitor conversion funnel.

Mollie integration is a small sprint-6 (or earlier if Freemius doesn't get us there and we go self-hosted).

## Touches

New module: `src/Modules/License/` — `License::can()`, activation UI, trial state, Freemius SDK hooks
New admin page: TalentTrack → License
Changes across most existing modules: every premium feature gets a `License::can()` gate
Replaces or augments `plugin-update-checker` (Freemius has its own updater)
New marketing site: separate repo, not in this plugin
Config constants in `wp-config.php`: `TT_LICENSE_BACKEND` (freemius / self / none — for dev), `TT_LICENSE_SERVER_URL` if self-hosted
DEVOPS.md — document build pipeline for free vs premium builds (the `@fs_premium_only` mechanism if using Freemius)
