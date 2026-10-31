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

Last reviewed: 2026-10-31.

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
| Academy HQ findings, round of October 2026 (`from-hq` queue) | Every persona's daily routes answer correctly; the HQ stops re-finding the same defect | Draining in parallel lanes; the tournaments-and-minutes, family-and-alerts and small-items lanes have each finished their set, with a few items still at the review gates |
| Injuries: one medical record, one availability answer ([#4282](https://github.com/caspernieuwenhuizen/talenttrack/issues/4282)) | A coach records a return, match prep and attendance know who is fit, and medical detail stays with the roles that may see it | Nearly complete; most of it released in v4.142.0: one derived injury status, the squad overview and its audit, editing from the player's tab, the neutral Unavailable flag in match prep, the "Check availability" marker in planning, match prep and the tournament squad, and recovery reminders that follow every edit. Left: reminders in step with the recycle bin (in review), then applying the coach's "extend" answer ([#4289](https://github.com/caspernieuwenhuizen/talenttrack/issues/4289), re-shaped) |
| Prospect and consent pipeline ([#4250](https://github.com/caspernieuwenhuizen/talenttrack/issues/4250), [#4267](https://github.com/caspernieuwenhuizen/talenttrack/issues/4267), [#4251](https://github.com/caspernieuwenhuizen/talenttrack/issues/4251), [#4268](https://github.com/caspernieuwenhuizen/talenttrack/issues/4268), [#4257](https://github.com/caspernieuwenhuizen/talenttrack/issues/4257), [#4403](https://github.com/caspernieuwenhuizen/talenttrack/issues/4403)) | A family's consent and a prospect's state are always visible and correct, on the board and on the dashboard | In progress, one lane: the consent date, trial status and consent-log fixes are in the next release batch; the dashboard strip and the consent wizard follow |
| The coach's week on the landing page ([#4379](https://github.com/caspernieuwenhuizen/talenttrack/issues/4379)) | A coach sees every activity of the week and what each still needs, the moment they open TalentTrack | Built and reviewed, in the next release batch; which personas see it is an academy setting |

## Next

| Epic | Value to the academy | State |
| --- | --- | --- |
| Release v4.142.1 | The pilot academies get the coach's week, the prospect fixes and the small HQ fixes | Batch being integrated |

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
| Match prep fits every football form ([#4280](https://github.com/caspernieuwenhuizen/talenttrack/issues/4280), [#4285](https://github.com/caspernieuwenhuizen/talenttrack/issues/4285), [#4284](https://github.com/caspernieuwenhuizen/talenttrack/issues/4284), [#4318](https://github.com/caspernieuwenhuizen/talenttrack/issues/4318), [#4319](https://github.com/caspernieuwenhuizen/talenttrack/issues/4319), [#4346](https://github.com/caspernieuwenhuizen/talenttrack/issues/4346), [#4263](https://github.com/caspernieuwenhuizen/talenttrack/issues/4263), [#4352](https://github.com/caspernieuwenhuizen/talenttrack/issues/4352), [#4353](https://github.com/caspernieuwenhuizen/talenttrack/issues/4353), [#4372](https://github.com/caspernieuwenhuizen/talenttrack/issues/4372)) | v4.142.0 |
| Access checks tightened | v4.142.0 |
| PDP planning follows the current season and the active roster ([#4244](https://github.com/caspernieuwenhuizen/talenttrack/issues/4244), [#4248](https://github.com/caspernieuwenhuizen/talenttrack/issues/4248), [#4258](https://github.com/caspernieuwenhuizen/talenttrack/issues/4258), [#4266](https://github.com/caspernieuwenhuizen/talenttrack/issues/4266), [#4293](https://github.com/caspernieuwenhuizen/talenttrack/issues/4293)) | v4.141.6 and v4.142.0 |
| Player report ([#3871](https://github.com/caspernieuwenhuizen/talenttrack/issues/3871)) | v4.141 line |
| Mobile list views, core children ([#4197](https://github.com/caspernieuwenhuizen/talenttrack/issues/4197)) | v4.141.0 to v4.141.5 |
| Team statistics tab ([#3519](https://github.com/caspernieuwenhuizen/talenttrack/issues/3519)) | v4.126.0 |
| Monthly team report ([#3457](https://github.com/caspernieuwenhuizen/talenttrack/issues/3457)) | v4.124.0 |
