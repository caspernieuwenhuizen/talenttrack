---
title: Roadmap
group: developer
summary: The one roadmap for TalentTrack — now, next and later, one line per epic, each with the value it delivers to an academy.
audience: [dev]
order: 19
---

# Roadmap

This is the only roadmap. Themes are the owner's; the product owner
proposes them and keeps this file true. One line per epic, linked to its
tracker issue, with the value an academy gets from it. An epic moves to
"shipped" when every child has shipped; a line that has been "later" for two
cycles is either moved up or removed.

Last reviewed: 2026-10-30.

## Direction

A real academy pilot, run well: Leon Hutten on `sto.mediamaniacs.nl` and the
academy on `jg4it.mediamaniacs.nl` use TalentTrack to onboard players and
follow their development, with the Academy HQ as the quality gate that
finds what escaped before a coach does.

## How the queue reads

`ready-for-dev` holds what ships this cycle: bugs a user hits, review-gate
findings and the children of a running epic. Latent findings, developer
tooling and tidy-ups carry `backlog` instead, so the queue count means
"waiting for a developer" (owner decision, 2026-10-30). Several developer
lanes run in parallel, each capped and file-disjoint.

## Now

| Epic | Value to the academy | State |
| --- | --- | --- |
| Academy HQ findings, round of October 2026 (`from-hq` queue) | Every persona's daily routes answer correctly; the HQ stops re-finding the same defect | Draining in parallel lanes: tournaments and minutes, family and alerts, and a small independent set |
| Injuries: one medical record, one availability answer ([#4282](https://github.com/caspernieuwenhuizen/talenttrack/issues/4282)) | A coach records a return, match prep and attendance know who is fit, and medical detail stays with the roles that may see it | Most children merged: one derived injury status, the squad overview and its audit, editing from the player's tab, the neutral Unavailable flag in match prep, the workflow deadline engine. In review: the "Check availability" marker for overdue and incomplete records. Follow-ups queued on reminders and the tournament squad |
| Match prep fits every football form ([#4280](https://github.com/caspernieuwenhuizen/talenttrack/issues/4280)) | A 6v6 or 8v8 coach prepares, prints and plays a match without eleven-a-side assumptions | Starter target, live tile and footer, line-up wording and the phone picker merged; phone layout and minutes for positions a formation hides in review |
| Prospect and consent pipeline ([#4250](https://github.com/caspernieuwenhuizen/talenttrack/issues/4250), [#4267](https://github.com/caspernieuwenhuizen/talenttrack/issues/4267), [#4251](https://github.com/caspernieuwenhuizen/talenttrack/issues/4251), [#4268](https://github.com/caspernieuwenhuizen/talenttrack/issues/4268), [#4257](https://github.com/caspernieuwenhuizen/talenttrack/issues/4257)) | A family's consent and a prospect's state are always visible and correct | In progress, one lane, in that order |

## Next

| Epic | Value to the academy | State |
| --- | --- | --- |
| Release of the work merged since v4.141.6 | The pilot academies get the injuries, match-prep and permissions fixes on their installs | Awaiting the owner's release approval once the running gates clear |

## Later

| Epic | Value to the academy | State |
| --- | --- | --- |
| Support grants from the Admin Center (#3501) | The owner can help an academy without an account on its install | Held on one question |
| Player report round three | A coach brings one document to a talk that covers the whole player | Awaiting pilot feedback |

## Decided this cycle

| Decision | What it means for the academy |
| --- | --- |
| Creating teams and players follows the authorization matrix | The head of development and the academy admin add teams and players; a head coach edits their own squad and opens a trial for a prospect |
| The availability flag is one state for injury or suspension | A role without injury access learns that a player cannot play, never why; the coach's own status and reason are the coach's record |
| Touch targets: 48 px under a touch pointer, 36 px floor with a mouse | Phones get thumb-sized controls; desktop screens keep their density |

## Shipped recently

| Epic | Released |
| --- | --- |
| PDP planning follows the current season and the active roster ([#4244](https://github.com/caspernieuwenhuizen/talenttrack/issues/4244), [#4248](https://github.com/caspernieuwenhuizen/talenttrack/issues/4248), [#4258](https://github.com/caspernieuwenhuizen/talenttrack/issues/4258), [#4266](https://github.com/caspernieuwenhuizen/talenttrack/issues/4266), [#4293](https://github.com/caspernieuwenhuizen/talenttrack/issues/4293)) | v4.141.6, the rest merged for the next release |
| Player report ([#3871](https://github.com/caspernieuwenhuizen/talenttrack/issues/3871)) | v4.141 line |
| Mobile list views, core children ([#4197](https://github.com/caspernieuwenhuizen/talenttrack/issues/4197)) | v4.141.0 to v4.141.5 |
| Team statistics tab ([#3519](https://github.com/caspernieuwenhuizen/talenttrack/issues/3519)) | v4.126.0 |
| Monthly team report ([#3457](https://github.com/caspernieuwenhuizen/talenttrack/issues/3457)) | v4.124.0 |
