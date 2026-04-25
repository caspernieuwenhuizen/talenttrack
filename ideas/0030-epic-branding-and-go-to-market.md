<!-- type: epic -->

# Branding + go-to-market

Origin: 2026-04-25 late evening. Carved out of [#0011](0011-epic-monetization-branding.md) once the monetization code shipped in v3.17.0. The original spec coupled both workstreams under one epic; in practice they're a different skill set (design + marketing + sales) on a different cadence (calendar time, not driver-time), so they get their own epic.

## Why this matters

The plugin code is ready to charge for. What's missing is everything *around* it that turns a downloadable .zip into a product an academy actually pays for:

- A name and visual identity that doesn't look like a side project.
- A landing page that explains what TalentTrack does in a sentence and who it's for.
- A pricing page that maps to the v3.17.0 tier matrix.
- Screenshots that show the product in its best state.
- Documentation that's findable without already knowing what to look for.
- Real case studies from academies who use it.

Without these, even motivated trial users bounce — they can't explain to their HoD what they're trialing.

## Open questions to resolve before shaping

These need answers before this becomes Ready.

### Q1 — Brand voice

Three plausible directions:
- **Football-clinical**: terse, expert-tone, data-forward. Resonates with academy directors who've evaluated 5 other products.
- **Modern-SaaS**: friendly, benefit-led, lots of whitespace. Resonates with anyone who's bought Notion / Linear.
- **Warm-academic**: development-focused, talks about "the player as a person." Resonates with progressive academies + parents.

These aren't mutually exclusive but the dominant register has to be one of the three. To decide: who's the buyer? HoD signing the contract, or a coach championing the tool internally?

### Q2 — Logo + visual identity sourcing

Three options:
- **Casper draws it himself** — fastest, lowest cost, design-quality risk.
- **Fiverr / 99designs** — €100-500, mixed quality, faster than agency.
- **Local agency** — €1.5k-5k, best quality, slowest.

To decide: how much of the buyer's first impression rides on logo polish vs landing-page copy + product-screenshot quality? My read is the buyer cares more about screenshots than logo. Cheap logo + great screenshots > expensive logo + bad screenshots.

### Q3 — Marketing site tech

Three options:
- **Static (Hugo / Astro)** — fast, free hosting, harder to extend with dynamic features (eg. signup forms beyond Freemius).
- **WordPress** — eats own dogfood, easy to extend, slower to load, more attack surface.
- **Framework (Next.js / Remix)** — most flexible, highest dev cost, overkill for a marketing site.

My recommendation: Astro static. Free Cloudflare Pages hosting, fast load, simple to maintain, integrates with Freemius via a one-line embed.

### Q4 — Domain

Currently the plugin is at github.com/caspernieuwenhuizen/talenttrack. Marketing site needs a domain. Options:
- `talenttrack.app`
- `talenttrack.io`
- `talenttrack.football`
- `getalenttrack.com`
- `usetalenttrack.com`
- Something custom (`youthtalent.tools`?)

To decide: brand identity question. `.app` and `.io` are conventional; `.football` is on-the-nose; the `get-` / `use-` prefixes are 2010s SaaS clichés.

### Q5 — Pricing page copy direction

Two routes:
- **Feature-matrix forward** — big table comparing Free/Standard/Pro line by line. Honest, dense.
- **Use-case forward** — three columns each describing "this is the academy you are if you pick this tier." Less data, more emotional resonance.

My recommendation: **use-case forward at the top, full feature matrix below.** Buyers self-sort emotionally first, then verify with the matrix.

This question ties into [#0012](0012-epic-professionalize-and-remove-ai-fingerprints.md) Part A's anti-AI-fingerprint pass — both shape the same voice.

### Q6 — Screenshots

The product screenshot strategy matters a lot. To decide:
- **Real demo data** (from #0020 demo generator) or **stylized fake data** (curated to look prettier in a marketing context)?
- **Light theme or dark theme** for the screenshots?
- **Annotated callouts** on key features, or clean shots?
- **Dashboard hero** as the single key shot, or **multiple feature-specific shots** showing breadth?

My recommendation: real demo data (it's already realistic), clean shots without annotations on the marketing site (annotations belong on the docs), one dashboard hero + 4 feature-specific shots in a carousel.

### Q7 — Pilot recruitment

To decide:
- **How many** pilots? Original spec said 3-5; that's reasonable.
- **Which channel?** Casper's own network in Dutch youth football, cold outreach to academies, posts in football-coach communities, or paid?
- **Pilot value exchange?** Original spec said "free Pro for 6 months in exchange for a case study + feedback." Could also offer founding-customer perpetual discount as a stronger hook.
- **Pilot duration?** 6 months is generous; 3 months is quicker iteration.

### Q8 — Launch channel

To decide:
- **Soft launch** to existing networks first (LinkedIn, Twitter, Dutch football coaches' WhatsApp groups), then scale?
- **Public launch** on ProductHunt + IndieHackers + r/wordpress + football communities?
- Both?

ProductHunt has a niche-product penalty — TalentTrack isn't a horizontal SaaS. Dutch football communities are smaller but more aligned. My recommendation: soft launch to existing networks first; ProductHunt only if/when there's a "version 4.0 with photo-AI" angle that fits PH's audience.

## Touches (when shaped)

- New marketing site (separate repo or `marketing/` subfolder)
- Brand assets in `assets/brand/` — logo (SVG + PNG variants), color palette, typography choices
- `readme.txt` banner update
- Plugin header + admin assets refreshed with the brand
- Freemius dashboard customization (logo, colors, plan descriptions)
- Pricing page, feature matrix page, docs index, blog scaffolding
- Pilot onboarding playbook (private doc)
- Launch checklist + day-one monitoring plan

## Estimated effort

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Brand identity (logo + palette + voice) | ~10-15h |
| 2 | Marketing site (landing + pricing + docs index) | ~20-25h |
| 3 | Pilot recruitment + onboarding (3-5 academies) | ~10-15h calendar-driven |
| 4 | Public launch + first-month monitoring | ~5-10h |

**Total: ~45-65 hours**, mostly calendar-time rather than focused-coding-time.

## Sequence position

Inserted in SEQUENCE.md as a separate epic after #0011 (now Done). Can run in parallel with other code work since the skill sets don't overlap.
