<!-- type: epic -->

# #0030 — Branding and go-to-market (stub spec)

## Status

**Stub.** Carved out of [#0011](0011-epic-monetization-branding.md) on 2026-04-25 once the monetization code shipped in v3.17.0. Not Ready — see [`ideas/0030-epic-branding-and-go-to-market.md`](../ideas/0030-epic-branding-and-go-to-market.md) for the eight open shaping questions (brand voice, logo sourcing, marketing site tech, domain, pricing-page copy direction, screenshot strategy, pilot recruitment, launch channel).

## Problem (locked from idea)

The plugin code can charge for itself. What's missing is everything *around* it that turns a downloadable .zip into a product an academy will pay for: name + visual identity, landing page, pricing page, screenshots, findable docs, real case studies. Without these, even motivated trial users bounce because they can't explain to their HoD what they're trialing.

## Open shaping questions

See the idea file. Summary:

| # | Question | Why it matters |
| - | - | - |
| Q1 | Brand voice (football-clinical / modern-SaaS / warm-academic) | Drives every line of copy on the marketing site + plugin chrome |
| Q2 | Logo sourcing (self / Fiverr / agency) | Cost vs polish trade-off; I think the buyer cares more about screenshots than logo |
| Q3 | Marketing site tech (Astro static / WP / framework) | My pick: Astro static — fast, free hosting, simple |
| Q4 | Domain (.app / .io / .football / get-/use- / custom) | Brand identity question |
| Q5 | Pricing page copy (matrix-forward / use-case-forward) | My pick: use-case-forward at top, matrix below |
| Q6 | Screenshot strategy | Real demo data, clean shots, dashboard hero + 4 feature shots |
| Q7 | Pilot recruitment (count, channel, value, duration) | Where to find the first 3-5 academies + what to offer |
| Q8 | Launch channel (soft / ProductHunt / both) | My pick: soft launch first; ProductHunt only with the right angle |

## Recommended next step

When Casper is ready, surface Q1-Q8 inline (per the inline-questions feedback memory) with bolded recommendations. Once locked, this stub becomes a real spec along the lines of #0024 (well-shaped, ~4-sprint epic).

## Estimated effort once shaped

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Brand identity (logo + palette + voice + plugin chrome) | ~10-15h |
| 2 | Marketing site (landing + pricing + docs index) | ~20-25h |
| 3 | Pilot recruitment + onboarding (3-5 academies) | ~10-15h calendar-driven |
| 4 | Public launch + first-month monitoring | ~5-10h |

**Total: ~45-65 hours**, mostly calendar-time rather than focused-coding-time.

## Sequence position (proposed)

After #0011 (now Done). Can run in parallel with other code work since the skill sets don't overlap. Worth shipping before the install base grows past ~50 to avoid retrofitting brand identity onto a customer base.

## Touches (preview, not authoritative until shaped)

- New marketing site (separate repo or `marketing/` subfolder)
- Brand assets at `assets/brand/`
- `readme.txt` banner + plugin header updates
- Freemius dashboard customization (logo, colors, plan descriptions)
- Pilot onboarding playbook (private doc)
- Launch checklist + monitoring plan

## Depends on

- #0011 (monetization code) — **shipped in v3.17.0**, satisfied
- #0012 Part A (anti-AI-fingerprint copy pass) — useful as input for marketing copy; not a hard dep
