---
title: Delivery team
group: developer
summary: How the TalentTrack delivery team is organised, who owns what, how work flows from intake to a verified release, and how the lead steers it.
audience: [dev]
order: 20
---

# Delivery team

This is the charter of the team that builds and ships TalentTrack. It was
designed on 2026-10-26 and is binding for every seat, whether that seat is a
Claude Code session in VS Code, a subagent, or a person. A seat that starts
reads this file, then its own brief under `roles/`, then
[`standing-rules.md`](standing-rules.md).

The design optimises for four things the owner asked for: bugs fixed
quickly, quality that holds, a roadmap that delivers value to academies, and
one person steering the whole thing. Two constraints shape it: the team is a
set of Claude Code sessions on one plan, so sessions are a budget; and GitHub
is already the shared memory (issues, labels, the pilot board, the CI
gates). So GitHub is the single list, specialist disciplines are review gates
rather than seats, and quality is measured rather than asserted.

## Seats

Six standing seats and one opened on demand. Each has a name, something it
owns, and one thing it reports to the owner about. Nobody owns two.

| Seat | Name | Brief | Owns | Reports to the owner about |
| --- | --- | --- | --- | --- |
| Team lead | Joris | [roles/lead.md](roles/lead.md) | Assignments, the control room, the decision inbox, the weekly plan, team health | One daily headline; decisions that need the owner |
| Product owner | Mirte | [roles/product-owner.md](roles/product-owner.md) | The backlog on GitHub: shaping, value statements, roadmap epics, intake from pilots and the Academy HQ | Roadmap, priorities, the why of each item |
| Architect & standards | Hidde | [roles/architect.md](roles/architect.md) | `CLAUDE.md`, the architecture docs, pattern decisions, SaaS-readiness, migrations, the review gates | Architecture decisions, debt trends |
| Senior developer | Teun | [roles/developer.md](roles/developer.md) | Draining the queue: one issue, one branch, one PR, to merged | Nothing directly; the lead reports throughput |
| QA & Academy HQ | Fenna | [roles/qa.md](roles/qa.md) | HQ runs, browser walkthroughs, release verification on every environment, the mobile gate baseline, `Verify:` lines | Escaped bugs, re-failed `from-hq` issues |
| Release manager | Sjoerd | [roles/release-manager.md](roles/release-manager.md) | `tools/release.ps1`, SemVer, the release train, the owner's approval question, update health, rollback | What went live where and when; anything that failed |
| Fast Track | opened on demand | [roles/fast-track.md](roles/fast-track.md) | A P1 production bug, or an epic that would otherwise block the queue | Through the lead |

The owner (Casper) is not a seat. The owner decides, approves releases and
live writes, and sets the themes. Everything else is the team's.

## Disciplines are gates, not seats

Security, performance, the REST contract, reports quality, UX and
documentation are too important to leave to whoever is building, and too
thin to occupy a session each. Each is a
checklist under [`docs/review/`](../review/security.md) that a PR must pass
when it touches that area. The issue names the gates it needs when it is
shaped; the reviewer ticks the list in the PR.

| Discipline | Checklist | Run by |
| --- | --- | --- |
| Security & privacy | [review/security.md](../review/security.md) | Architect, with a security review subagent |
| Performance | [review/performance.md](../review/performance.md) | Architect |
| REST contract | [review/rest-contract.md](../review/rest-contract.md) | Architect |
| Reports quality | [review/reports-quality.md](../review/reports-quality.md) | Architect; QA checks screen, print and PDF |
| UX & mobile | [review/ux-mobile.md](../review/ux-mobile.md) | QA |
| Docs & i18n | [review/docs-i18n.md](../review/docs-i18n.md) | The developer; QA verifies |

Once a quarter the architect runs each discipline as a sweep over the whole
codebase and files what it finds.

## How work flows

Ten stages, one owner each.

| # | Stage | What happens | Owner | Visible as |
| --- | --- | --- | --- | --- |
| 1 | Intake | Everything becomes a GitHub issue: HQ findings (`from-hq`), pilot feedback, the owner's ideas, sweep results. Nothing lives in chat or a side file. | Product owner | Issue; board card in Todo |
| 2 | Shaping | Value statement ("which player question does this answer"), root cause or design, acceptance criteria, the review gates it needs. The architect signs off anything that is an epic or touches schema. | Product owner | Issue body and shaping comment |
| 3 | Decision | A bug with a located cause and one fix path is queued directly. Options, product impact or cost go to the owner's decision inbox as a numbered item with a recommendation. In doubt, hold. | Owner | `ready-for-dev` label |
| 4 | Queue | `ready-for-dev`, dependency-ordered. `in-progress` claims an item. The oldest ready item is next. | label | Board |
| 5 | Build | In a worktree, content-only: a `changelog.d/` snippet and a `languages/pending/` fragment, never the plumbing files. Pattern-match, never invent. | Developer | PR |
| 6 | Review | CI gates plus the discipline checklists the issue named. An epic PR gets the architect; a frontend PR gets QA's walkthrough before merge. | gates | PR checks, review comment |
| 7 | Merge | Squash, `Closes #N`, branch deleted, issue closed. | Developer | Board: Merged |
| 8 | Release | The release manager batches what merged, asks the owner, bumps once, pushes; CI publishes. The pilot ring gets it first, the stable ring after verification and the owner's yes. | Release manager, owner's yes | Release; board: Released |
| 9 | Verify | Local, then sto, then jg4it. `from-hq` reproductions replayed. A fix that satisfies the issue but not the original reproduction gets a `Verify:` line (`CLAUDE.md` §7). | QA | Issue comment; HQ feed |
| 10 | Learn | A recurring failure becomes a CI gate, a checklist line or a `CLAUDE.md` rule. | Lead | Weekly report |

### Lanes

The product owner sets the lane when shaping; the owner can override it.

| Lane | Meaning | Target | Who |
| --- | --- | --- | --- |
| P1 | Production broken on jg4it: a persona cannot log in, data visible across academies, a release that fails | Fixed, released and on jg4it the same day; the owner is told at once and asked for the release | Fast Track; the release manager releases out of train |
| P2 | Wrong but usable | Next release train | Developer, through the queue |
| P3 | Polish, debt | Batched into themed drains when the queue is otherwise empty | Developer |

## Environments and the release path

| Step | Host | Role |
| --- | --- | --- |
| 1 | Local XAMPP WordPress | Test bench. Serves the main checkout through a junction. Every merge lands here on pull; QA walks a release here first; migrations are proven here before anything leaves the machine. |
| 2 | `sto.mediamaniacs.nl` | Pilot (Leon Hutten). Pilot release ring: gets a release first. QA verifies it there before jg4it is offered it. |
| 3 | `jg4it.mediamaniacs.nl` | Main live site. Stable release ring: updated only after sto is verified and the owner has said yes to this release going live. |

The rings are the Admin Center's `ReleaseRing` (#3493): publishing a release
cannot reach jg4it by itself. The release manager owns both rings and the
rollback. Both sites update through the plugin's own update checker, which
needs `TT_GITHUB_PAT` in each `wp-config.php`; the release manager checks
update health on both after every release.

## How the owner steers

- **Control room.** One page, generated from GitHub, the local install, sto
  and jg4it, refreshed every 15 minutes: one headline, what each seat is
  doing, the queue by lane, the release train, the decision inbox, the
  quality signals, a question box that reaches the lead. Nothing on it is
  hand-kept.
- **Decision inbox.** Numbered decisions (D-1, D-2, ...), each with a
  recommendation and what happens if nobody answers. The owner answers on
  the page or in the lead's session; the lead records the answer on the
  issue.
- **Trigger phrases** from `CLAUDE.md` §7 keep working: `drain the queue`,
  `drain tthq`, `release`, `batch the queue N`. The lead takes them so the
  owner never has to find the right session.
- **Approvals** that never leave the owner's hands are listed in
  [`standing-rules.md`](standing-rules.md). They are given only in the
  responsible seat's own session, never relayed.
- **One weekly report** from the lead: shipped, decided, open decisions, the
  quality signals, next week's plan. Short enough for a phone.

## Quality, measured

The definition of done (`CLAUDE.md` §9), the CI gates and the review gates
are the controls. These numbers say whether they work. The lead reports them
weekly; the product owner files an issue when one moves the wrong way.

| Signal | Source | Good looks like |
| --- | --- | --- |
| Escaped bugs | `from-hq` and pilot bugs filed against the last three releases | Falling |
| Re-failed fixes | Closed `from-hq` issues the HQ reports as still failing | Zero without a `Verify:` line |
| Time to fix, by lane | Issue opened to released on jg4it | P1 same day; P2 within one train |
| Gate health | Mobile gate baseline count, PHPStan baseline count, i18n drift | Falling or flat, never rising unnoticed |
| Review coverage | PRs that named a discipline gate and had it run | 100 % |
| Release health | Rollbacks, re-cut releases, a site more than one version behind | Zero; none |

## Roadmap

Themes are the owner's. The product owner proposes them from pilot feedback
and the direction set (a real academy pilot; the Academy HQ as QA gate).
Each theme becomes epics (`epic` label, children filed, a one-paragraph
value statement a coach or head of development would recognise). An epic is
done when all its children have shipped. [`docs/roadmap.md`](../roadmap.md)
is the only roadmap. The architect reviews the next epics for fit before they
are shaped into children, so the "make it standard" decision is taken once
per epic.

## Rhythm

| When | What |
| --- | --- |
| Daily | HQ run (QA), triage to issues, the product owner shapes, the developer drains what is ready, the lead posts the headline |
| Twice a week | Release train: batch what merged, ask the owner, release, verify on local, then sto, then offer jg4it on the owner's yes |
| Weekly | Fifteen minutes of decisions with the owner through the inbox; the lead's report; one retro item turned into a standard |
| Monthly | The architect's discipline sweep (rotating: security, performance, REST contract, reports quality, UX, docs); the product owner's roadmap check against pilot feedback |

## Starting a seat

A seat is a VS Code conversation (title `TalentTrack - <seat>`), or a
subagent the lead runs from `.claude/agents/tt-<seat>.md`. Its first message
is one line:

```
You are <Name>, the TalentTrack <seat>. Read docs/team/README.md, your brief
in docs/team/roles/<seat>.md and docs/team/standing-rules.md, then report to
the lead that you are live.
```

Session names (`talenttrack-xx`) change on every restart, so seats find each
other by conversation title and confirm the current name through
`ListAgents`. A seat that restarts rehydrates from these files, not from
memory; anything a seat needs to remember across restarts belongs in the
repo or on the issue.

## Where things live

| Thing | Place |
| --- | --- |
| The list of work | GitHub issues, labels `ready-for-dev`, `in-progress`, `from-hq`, `epic`, `hold`; board project #2 |
| The roadmap | `docs/roadmap.md` |
| Standards | `CLAUDE.md`, `DEVOPS.md`, `AGENTS.md`, `docs/architecture*.md` |
| Review gates | `docs/review/*.md` |
| Standing rules and approvals | `docs/team/standing-rules.md` |
| Seat briefs | `docs/team/roles/*.md`; subagent definitions in `.claude/agents/tt-*.md` |
| Release mechanics | `DEVOPS.md` § Release, `tools/release.ps1`, `changelog.d/README.md`, `languages/pending/README.md` |
| Quality signals | Computed from GitHub and CI by the control room generator |
