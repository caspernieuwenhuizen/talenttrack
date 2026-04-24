# Developer: start here

If you're picking up TalentTrack to contribute code, this is your orientation. ~3 minutes to read. Everything you need afterwards is in the other files.

## The model

- **`ideas/`** — shaped ideas. Historical reasoning records. **Do not work from these.**
- **`specs/`** — dev-ready specs. **Pick your next task from here.** Each spec has Problem / Proposal / Scope / Out of scope / Acceptance criteria / Notes.
- **`SEQUENCE.md`** — tells you what order to pick specs in. Phases 0 through 5.
- **`AGENTS.md`** — how to drive Claude Code effectively. Single-agent default, 2-agent parallel when truly independent.

## The flow

1. Open `SEQUENCE.md`. Find the next unchecked item.
2. Open its spec in `specs/`. Read top to bottom.
3. Start work. Open a PR. Get it merged. Mark complete.
4. Repeat.

## Spec structure you'll find in every file

- **Problem** — what's broken or missing and who feels it.
- **Proposal** — what we're doing about it.
- **Scope** — what's in this unit of work.
- **Out of scope** — what's deliberately excluded.
- **Acceptance criteria** — checkable gates for "done."
- **Notes** — architectural callouts and dependencies.

If anything's ambiguous inside a spec, the source idea file in `ideas/` has the full shaping conversation. The idea file is longer and messier; the spec is the clean execution doc.

## What's in `specs/` (38 files as of last update)

**Bugs:**
- `0008` — GitHub Actions Node 20 deprecation (hard deadline 2026-09-16)
- `0015` — Fatal bug on FrontendMyProfileView (demo-critical)

**Small features:**
- `0003` — Player evaluations view polish
- `0004` — My card tile polish
- `0020` — Demo data generator (demo-critical for May 4 2026)

**Epics with overview + multiple sprint specs:**
- `0006` — Team planning module (1 overview + 1 combined-sprints file)
- `0014` — Player profile + report generator (1 overview + 4 sprint specs)
- `0017` — Trial player module (1 overview + 6 sprint specs)
- `0018` — Team development / chemistry (1 overview + 1 combined-sprints file)
- `0019` — Frontend-first admin migration (1 overview + 7 sprint specs)
- `0022` — Workflow & Tasks Engine (1 overview + 1 combined Phase 1 sprints file)

**Single-file epics (long specs, not decomposed into sprints):**
- `0009` — Development management / staged ideas → GitHub
- `0010` — Multi-language (French / German / Spanish)
- `0011` — Monetization + branding
- `0012` — Professionalize + remove AI fingerprints
- `0013` — Backup + disaster recovery
- `0016` — Photo-to-session capture

## Cross-epic dependencies — watch these

Some specs can't start until others ship:

- **#0003, #0004, #0014 Part A** — wait for #0019 Sprint 1 (uses the new CSS scaffold + shared components).
- **#0017 entire epic** — waits for both #0014 Sprint 3 (uses the generalized `PlayerReportRenderer`) AND #0022 Phase 1 (Sprint 3 of #0017 consumes the workflow engine).
- **#0022 Phase 2** — depends on #0017 being implemented (migrates trial-input into a workflow template, deprecating `tt_trial_case_staff_inputs`).
- **#0016** — waits for #0006 Sprint 1 (uses the Principles concept).
- **#0011 Sprint 4 branding track** — benefits from #0012 Part A shipping first (anti-AI-fingerprint copy pass).
- **#0017 Sprint 4 (trial letters)** — uses both #0014 Sprint 3's renderer AND #0014 Sprint 5's `tt_player_reports` table.
- **#0019 Sprint 7 PWA** — enables browser push for #0022's notification bell.

Each spec's "Depends on" section is explicit. Read it before starting.

## When in doubt

- `SEQUENCE.md` tells you **what to work on**.
- `AGENTS.md` tells you **how to work on it** with Claude Code.
- Specs tell you **what the work is**.
- Idea files tell you **why the work is that way**.

## One thing to never do

Do not work from an `ideas/` file. They're historical records. The actionable version is always in `specs/`.
