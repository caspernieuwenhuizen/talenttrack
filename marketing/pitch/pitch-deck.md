---
title: "TalentTrack"
subtitle: "Player development, on the rails."
author: "Casper Nieuwenhuizen — Media Maniacs"
date: "2026"
theme: "league"
revealjs-url: "https://unpkg.com/reveal.js@5/dist/"
---

# TalentTrack

**Player development, on the rails.**

A frontend-first talent management system for a single youth football club.

<small>Casper Nieuwenhuizen — casper@mediamaniacs.nl</small>

---

## The problem

Coaches manage player development in spreadsheets, WhatsApp groups and a coach's notebook.

- Evaluations happen twice a season — *if* someone remembers
- Goals are agreed in the parking lot and forgotten by Tuesday
- When a coach leaves, the institutional memory leaves with them
- Parents pay for "systematic development" and see... vibes

The data is there. The orchestration isn't.

---

## Who it is for

**Single-club youth academies.** 50 to 500 players. Dutch amateur leagues — KNVB structure, O8 through O19, one or two HJO-level coordinators.

Not for federations. Not for top-flight enterprise pipelines.

If you have one club, a head of development who knows everyone by name, and 10–25 coaches across age groups, this is built for you.

---

## What TalentTrack is

A WordPress plugin that runs on your own hosting. No SaaS lock-in.

**Frontend-first.** Coaches, players and HJO never touch wp-admin. Tile-based dashboard, mobile-friendly, role-aware.

**Three personas built in:**

- Players see *their* card, *their* goals, *their* sessions
- Coaches see only the teams they coach
- Head of development sees everything in scope

A read-only observer role exists for assistants, board members and scouts.

---

## Capability — Evaluations + radar

Hierarchical categories (Technical / Tactical / Physical / Mental), each with subcategories. 21 standard subcategories ship; clubs add their own.

Coaches rate at main level *or* drill into subcategories — either/or, per category.

Every evaluation gets a **weighted overall rating** — weights configurable per age group.

Trend lines and radar charts per player. FIFA-style player cards with tiered podiums per team.

---

## Capability — Sessions + attendance

Plan training sessions and matches per team.

Mark attendance pitch-side from a phone — present / absent / late.

Every session links back to its team and contributes to attendance patterns the HJO can spot at a glance.

Bulk-archive last season cleanly when the new one starts.

---

## Capability — Goals with status flow

One or more development goals per player. Priority + status (active / achieved / missed). Date-bounded.

Coaches and players see **the same board**. No more "what did we agree last quarter?"

Save / update / delete from the frontend, with optimistic UI on flaky pitch-side 4G.

---

## Capability — Role-aware access

Granular WordPress capabilities — every domain has split `view_*` and `edit_*` capabilities.

A read-only observer role works end-to-end: full view access, every write blocked at the controller, write controls hidden from the UI.

Functional roles (head_coach, assistant_coach, manager, physio) map to authorization roles, so "head coach who also has physio rights" is a tickbox, not a code change.

---

## Capability — Demo data + reports

One-click demo data generator. Tiny / Small / Medium / Large preset populates teams, players, evaluations, sessions and goals — Dutch-sounding names, plausible age curves, realistic attendance patterns.

Wipe with one click — your real data is untouched.

Printable A4 player report with club header, FIFA card, headline numbers, trend + radar charts, signature footer. Save as PDF straight from the browser.

---

## Tech stack

- **WordPress plugin.** Self-hosted on your own server. No vendor lock-in.
- **Full data ownership.** It's your database. Backups are yours, exports are yours.
- **GPL-2.0+ licensed.** Inspect, modify, audit.
- **Translatable.** Dutch ships in the box; framework supports any locale.
- **Lean dependencies.** No SPA build pipeline. Server-rendered HTML, sprinkled JS, Chart.js for graphs. Runs on any decent shared host.

---

## Deployment + pricing

- **Single-site licence.** One academy, one install.
- **Annual subscription** covers updates and support.
- **Self-hosted.** Your hosting, your domain, your data.

`{{pricing_tbd}}` per year — see the leave-behind for current numbers.

White-label option for clubs that want their own branding edge to edge.

---

## Roadmap teaser

What's shipping next, in priority order:

- **Frontend-first migration (#0019)** — every admin task moves to the frontend. wp-admin stays as fallback only.
- **Workflow & tasks engine (#0022)** — turn "we should evaluate after every match" into a scheduled, visible task. Bell + email notifications.
- **Photo-to-session capture (#0016)** — snap a photo on the pitch, attach to the session.
- **Trial player module (#0017)** — full case management for kids on trial, including parent-meeting mode.

Direction first, dates when they're real.

---

## Why TalentTrack vs. spreadsheets

| | Spreadsheets / WhatsApp | TalentTrack |
|---|---|---|
| Player history | Lost when coach leaves | Persists forever |
| Role separation | Everyone sees everything | Players see themselves, coaches see their teams |
| Mobile use | "Who can read this column on a phone?" | Designed touch-first |
| Reports | Manual copy-paste each season | One click, printable A4 |
| Compliance | "Where is that file again?" | Single source of truth |

---

## Talk to me

Casper Nieuwenhuizen
**casper@mediamaniacs.nl**
mediamaniacs.nl

I'd like to see the install live in your club. 30 minutes, on your laptop, with your age groups and your coaches' names.

If it doesn't fit, you've lost half an hour. If it does, your next season runs differently.

---
