<!-- type: feat -->

# #0024 — Setup Wizard for new installs (stub spec)

## Status

**Stub.** Not Ready. The April 2026 idea-funnel pass surfaced 7 open questions ([`ideas/0024-feat-setup-wizard-and-onboarding.md`](../ideas/0024-feat-setup-wizard-and-onboarding.md)) that need decisions before this becomes a buildable spec.

## Problem (locked from idea)

A first-time admin lands in wp-admin TalentTrack with empty Players, empty Teams, no obvious starting point. Activation rate determines retention; bad activation kills both perpetual-free use and (eventually) trial-to-paid conversion under #0011 monetization.

## Open shaping questions

See the idea file. Summary:

| # | Question | Why it matters |
| - | - | - |
| Q1 | Mandatory / optional / optional-with-persistent-re-entry | Drives forcefulness of the activation experience |
| Q2 | Full-screen takeover vs inline admin page | Visual polish vs build cost vs localization complexity |
| Q3 | Tier 1 / Tier 1+2 / Tier 1 inline + Tier 2 as "next steps" | Drives wizard length and depth |
| Q4 | Stateful resume vs stateless | Mid-wizard browser-close behaviour |
| Q5 | Sprint placement (before #0011 vs after) | Activation-first vs monetization-first |
| Q6 | Interaction with #0011 / #0013 / #0023 / #0020 | Composition of related onboarding moments |
| Q7 | Localization approach for marketing-style copy | First impressions in Dutch matter |

## Recommended next step

When Casper is ready, surface Q1–Q7 inline (per the inline-questions feedback memory) with bolded recommendations. Once locked, this stub becomes a real spec along the lines of #0019 Sprint 1 (foundation).

## Estimated effort once shaped

| Scope | Effort |
| - | - |
| Tier 1 only (5 steps, optional + inline) | ~10-12h |
| Tier 1 only (mandatory + full-screen) | ~14-18h |
| Tier 1 + Tier 2 (full) | ~25-30h |

## Sequence position (proposed)

Insert ahead of **#0011** in SEQUENCE.md. Activation is the most leveraged thing for every monetization metric once #0011 ships.

## Touches (preview, not authoritative until shaped)

- New module `src/Modules/Onboarding/`
- New REST endpoints under `/onboarding/`
- `wp_options` keys for state persistence
- Hooks into #0011 trial start, #0013 backup wizard, #0023 theme inheritance, #0020 demo generator
- Substantial `.po` strings (write copy in concert with #0012 anti-AI-fingerprint pass)

## Depends on

- The shaping decisions in Q1-Q7.
- #0019 (Frontend-first migration) — already complete; provides the frontend admin scaffolding the wizard sits on top of.
