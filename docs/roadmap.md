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

Last reviewed: 2026-11-02.

## Direction

A real academy pilot, run well: Leon Hutten on `sto.mediamaniacs.nl` and the
academy on `jg4it.mediamaniacs.nl` use TalentTrack to onboard players and
follow their development, with the Academy HQ as the quality gate that
finds what escaped before a coach does.

The longer view, beyond this roadmap, is the [product vision](vision.md):
six bets on what an academy needs that no product offers yet.

## How the queue reads

`ready-for-dev` holds what ships this cycle: bugs a user hits, review-gate
findings and the children of a running epic. Latent findings, developer
tooling and tidy-ups carry `backlog` instead, so the queue count means
"waiting for a developer" (owner decision, 2026-10-30). Several developer
lanes run in parallel, each capped and file-disjoint; four run now.

## Now

| Epic | Value to the academy | State |
| --- | --- | --- |
| Report Studio: one builder for reports and dashboards ([#4564](https://github.com/caspernieuwenhuizen/talenttrack/issues/4564)) | Coaches and the head of development build the report they need from the same numbers the standard reports use, and share it with the right people | Foundation released in v4.145.0: the block registry, the shared measure engine and the composers taken from the monthly and player reports. Storage and versions are merged for the next release; the start wizard is in review; the builder canvas, the first blocks and the "Shared with me" viewer follow |
| Search and settings grouped by task ([#4668](https://github.com/caspernieuwenhuizen/talenttrack/issues/4668)) | Anyone finds a player, team, match, help topic or setting from one box, and an admin never loses a setting again | Designed and decided on 2 November. The keyed tile groups and the new Configuratie page (ten task sections, each setting once) have started; the search engine's design is settled (one folded search column per table, so search reuses each record's own access rule) and queued |
| The read-only observer reads everything, with sensitive fields masked ([#4687](https://github.com/caspernieuwenhuizen/talenttrack/issues/4687)) | A director or board member follows every player's journey, while a minor's private details stay with the people who work with them | Decided and filed on 2 November. The staff rule is being built; one shared masking point and the screen-by-screen masking follow, and the wider read rights come last, so nothing opens before its masking |
| Correct numbers and access on every report and grid | A coach reads the same, correct answer on every screen, and sees only their own teams' data | In review: match preparation under one access rule, the grids reporting a refused save, scheduled reports reaching only readers who may see them, the minutes audit counting planned minutes as not recorded, and the analytics facts applying the attendance rules |

## Next

| Epic | Value to the academy | State |
| --- | --- | --- |
| Release v4.146.0 | The pilot academies get the Report Studio storage, the match preparation access rule, tournament squads that follow the planner, 48 px touch targets on the grids, and the report fixes | Thirty changes merged on main, more passing the review gates; the release comes to the owner for a yes |
| Configuratie by task with search, phase 1 ([#4669](https://github.com/caspernieuwenhuizen/talenttrack/issues/4669), [#4670](https://github.com/caspernieuwenhuizen/talenttrack/issues/4670), [#4671](https://github.com/caspernieuwenhuizen/talenttrack/issues/4671), [#4672](https://github.com/caspernieuwenhuizen/talenttrack/issues/4672), [#4673](https://github.com/caspernieuwenhuizen/talenttrack/issues/4673)) | Settings in ten task sections, each once; one search box in every layout and on the phone, over players, teams, staff, activities, help and settings, for staff, parents and players | Ships as one release (owner decision) |

## Later

| Epic | Value to the academy | State |
| --- | --- | --- |
| The dashboard shows daily work only ([#4674](https://github.com/caspernieuwenhuizen/talenttrack/issues/4674)) | Staff reach their player screens without wading through setup tiles | Ready; ships in the release after the settings page and search |
| Search, phases 2 and 3 ([#4668](https://github.com/caspernieuwenhuizen/talenttrack/issues/4668)) | Evaluation text, goals, PDP and notes become findable for staff who may read them; then the exercise library, reports and media | Filed when phase 1 has shipped |
| Player report round three | A coach brings one document to a talk that covers the whole player | Awaiting pilot feedback |

## Decided this cycle

| Decision | What it means for the academy |
| --- | --- |
| Report Studio is the one builder; the old builders retire only when it replaces all they do | Nothing an academy uses today disappears before its replacement works |
| Report Studio: head of development and admin build for anyone, coaches for their own team; the player and monthly reports become templates on the same engine | One engine, so a number is the same in every report |
| Report Studio starts with choices: kind, scope, subject, audience, period, layout and sharing, with the grid layout as an option | A report that cannot work (a family report about a whole team) cannot be started |
| Search reaches settings and modules, players get a limited search, and evaluation text and notes are searchable only for staff who may read them | Settings are findable by what they do; a child's private notes never reach the wrong reader |
| Configuratie is grouped by task, and the dashboard shows daily work only | Ten clear settings sections instead of twenty module sections; no setting in two places |
| The read-only observer reads everything except injuries, safeguarding, notes, messages, parent accounts and PDP; guardian contact, absence reasons, evaluation comments, full date of birth and trial or scouting judgements show as "Verborgen"; no exports, no Report Studio | An observer follows every player's journey, and a minor's private details stay with the people who work with them |
| Coach attendance reports follow attendance access | A coach sees attendance for the teams they may record it for |
| Minutes planned but not recorded count as not recorded | The minutes audit shows what a coach still has to enter |
| Team blueprints are a desktop screen | The phone keeps the screens a coach uses at the pitch |
| Open goals means goals being worked on (pending, in progress, on hold, approved) | The open-goals count matches what a coach is actually working on |
| Creating teams and players follows the authorization matrix | The head of development and the academy admin add teams and players; a head coach edits their own squad and opens a trial for a prospect |

## Shipped recently

| Epic | Released |
| --- | --- |
| Report Studio foundation: block registry, measure engine and shared composers ([#4568](https://github.com/caspernieuwenhuizen/talenttrack/issues/4568), [#4590](https://github.com/caspernieuwenhuizen/talenttrack/issues/4590)) | v4.145.0 |
| Quality sweep: Dutch on every screen and an i18n gate, 48 px touch targets, Spond keeps a coach's corrections, minutes checks per match, goals and assists capped at the score | v4.144.0 |
| The coach's week on the landing page ([#4379](https://github.com/caspernieuwenhuizen/talenttrack/issues/4379)) | v4.143.0 |
| Prospect and consent pipeline ([#4250](https://github.com/caspernieuwenhuizen/talenttrack/issues/4250), [#4267](https://github.com/caspernieuwenhuizen/talenttrack/issues/4267), [#4251](https://github.com/caspernieuwenhuizen/talenttrack/issues/4251), [#4268](https://github.com/caspernieuwenhuizen/talenttrack/issues/4268), [#4257](https://github.com/caspernieuwenhuizen/talenttrack/issues/4257), [#4403](https://github.com/caspernieuwenhuizen/talenttrack/issues/4403)) | v4.143.0 |
| Injuries: one medical record, one availability answer ([#4282](https://github.com/caspernieuwenhuizen/talenttrack/issues/4282)) | v4.142.0 and v4.143.0 |
| Academy HQ findings, round of October 2026 (`from-hq` queue) | v4.142.0 to v4.144.0 |
| Match prep fits every football form ([#4280](https://github.com/caspernieuwenhuizen/talenttrack/issues/4280), [#4285](https://github.com/caspernieuwenhuizen/talenttrack/issues/4285), [#4284](https://github.com/caspernieuwenhuizen/talenttrack/issues/4284), [#4318](https://github.com/caspernieuwenhuizen/talenttrack/issues/4318), [#4319](https://github.com/caspernieuwenhuizen/talenttrack/issues/4319), [#4346](https://github.com/caspernieuwenhuizen/talenttrack/issues/4346), [#4263](https://github.com/caspernieuwenhuizen/talenttrack/issues/4263), [#4352](https://github.com/caspernieuwenhuizen/talenttrack/issues/4352), [#4353](https://github.com/caspernieuwenhuizen/talenttrack/issues/4353), [#4372](https://github.com/caspernieuwenhuizen/talenttrack/issues/4372)) | v4.142.0 |
| PDP planning follows the current season and the active roster ([#4244](https://github.com/caspernieuwenhuizen/talenttrack/issues/4244), [#4248](https://github.com/caspernieuwenhuizen/talenttrack/issues/4248), [#4258](https://github.com/caspernieuwenhuizen/talenttrack/issues/4258), [#4266](https://github.com/caspernieuwenhuizen/talenttrack/issues/4266), [#4293](https://github.com/caspernieuwenhuizen/talenttrack/issues/4293)) | v4.141.6 and v4.142.0 |
| Player report ([#3871](https://github.com/caspernieuwenhuizen/talenttrack/issues/3871)) | v4.141 line |
