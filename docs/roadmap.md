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

Last reviewed: 2026-11-10.

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
| Report Studio: one builder for reports and dashboards ([#4564](https://github.com/caspernieuwenhuizen/talenttrack/issues/4564)) | Coaches and the head of development build the report they need from the same numbers the standard reports use, and share it with the right people | The player and monthly reports as templates, grouping players by position, and scheduling a report (with a preview of who is left out) shipped in v4.150.0. Next: more blocks that can show a whole group, then the parity check before the old builders retire |
| The read-only observer reads everything, with sensitive fields masked ([#4687](https://github.com/caspernieuwenhuizen/talenttrack/issues/4687)) | A director or board member follows every player's journey, while a minor's private details stay with the people who work with them | Prospects, potential (band masked) and every scouting visit (notes masked) shipped in v4.150.0. Trials, read-only with the panel's inputs kept with the panel, are being finished; then the walk of every screen as the observer |

## Next

| Epic | Value to the academy | State |
| --- | --- | --- |
| Next release | The landing dashboard and measurement levels following the chosen theme; trials for the observer; the owner's open theme calls (theme versus the visual editor, the Kristalblauw header mark) | Merging. Scouts editing only their own visits (#5123) follows the owner's answer |
| The dashboard shows daily work only ([#4674](https://github.com/caspernieuwenhuizen/talenttrack/issues/4674)) | Staff reach their player screens without wading through setup tiles | Ready now that the search screen has shipped |

## Later

| Epic | Value to the academy | State |
| --- | --- | --- |
| Search, the remaining record types ([#4668](https://github.com/caspernieuwenhuizen/talenttrack/issues/4668)) | Goals, PDP, match analysis, reports, media, prospects and messages become findable for the people who may read them | Evaluations, notes, staff and the library (exercises, training plans, methodology, courses) are searchable since v4.150.0. The remaining types are filed one group at a time, each with its own read check |
| Player report round three | A coach brings one document to a talk that covers the whole player | Awaiting pilot feedback |

## Decided this cycle

| Decision | What it means for the academy |
| --- | --- |
| The JG4IT PDP forms are fixed forms stored as data, chosen by position; trainer scores are also an evaluation, the player's own scores stay theirs, and agreed points become goals | The talk the academy already holds on paper runs online, and its outcome lands in the player's evaluations and goals |
| A PDP form is fixed at the player's first save; the player's part locks at the talk or at sign-off; sign-off needs a complete form, but a missing preparation does not block it | A player's preparation cannot be swapped under them, and a talk can always be finished |
| A scheduled monthly report holds only the sections every recipient may read | A shared PDF never carries a section one of its readers could not open |
| The PDP talk page names the player, shows the form in the tab bar, splits into two parts each with its own save state, drops notes and agreed actions on a form talk, lets the trainer clear a score, and gets a conversation mode for the TV | The trainer leads the talk, not the screen; what is saved is never in doubt |
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
| The PDP talk, simple and clear ([#5078](https://github.com/caspernieuwenhuizen/talenttrack/issues/5078)): the player in the header, Achtergrond, two parts with their own save state, clearing a score, clearly collapsing sections and a conversation mode for the TV; the JG4IT form printed as the academy's A4 form; 14 new visual themes with bundled fonts, and Leon Hutten renamed Kristalblauw; readable status and accent text on every theme; Report Studio templates, grouping by position and scheduling; one rule for "played", one minutes squad and one open-goal rule on every report; library and staff search; prospects in the recycle bin; reports that log who read injury details: 54 changes | v4.150.0 |
| JG4IT PDP forms online ([#4975](https://github.com/caspernieuwenhuizen/talenttrack/issues/4975)): the player prepares on the academy's own form, the trainer scores and agrees points in the talk, the points become goals, and player and parent confirm the signed talk online, each with one "Ik heb het gelezen" (the A4 printout follows in the next release); Report Studio's second wave of blocks, grid layout and dashboards; coach KPIs that open staff screens | v4.149.0 |
| Report Studio's first wave of blocks, the search box in every layout with parent and player search, settings and search phase 1 complete ([#4669](https://github.com/caspernieuwenhuizen/talenttrack/issues/4669), [#4670](https://github.com/caspernieuwenhuizen/talenttrack/issues/4670), [#4671](https://github.com/caspernieuwenhuizen/talenttrack/issues/4671), [#4672](https://github.com/caspernieuwenhuizen/talenttrack/issues/4672), [#4673](https://github.com/caspernieuwenhuizen/talenttrack/issues/4673)), two-step login re-setup and one setup per account, the match prep share link with absence reasons, one rule for "played", and the observer on the evaluations list | v4.148.0 |
| The Report Studio builder canvas and viewer, the Configuratie page grouped by task, the read-only observer's masking and wider read rights, journey entries that follow each record's read right, phone tables that stack, 48 px dashboard links, reports that refuse unknown fields, and the mobile gate in Dutch: 117 changes | v4.147.0 |
| Report Studio storage, keyed tile groups, guardian contact following the staff rule, match preparation under one access rule, tournament squads that follow the planner, 48 px grid targets, report fixes | v4.146.0 |
| Report Studio foundation: block registry, measure engine and shared composers ([#4568](https://github.com/caspernieuwenhuizen/talenttrack/issues/4568), [#4590](https://github.com/caspernieuwenhuizen/talenttrack/issues/4590)) | v4.145.0 |
| Quality sweep: Dutch on every screen and an i18n gate, 48 px touch targets, Spond keeps a coach's corrections, minutes checks per match, goals and assists capped at the score | v4.144.0 |
| The coach's week on the landing page ([#4379](https://github.com/caspernieuwenhuizen/talenttrack/issues/4379)) | v4.143.0 |
| Prospect and consent pipeline ([#4250](https://github.com/caspernieuwenhuizen/talenttrack/issues/4250), [#4267](https://github.com/caspernieuwenhuizen/talenttrack/issues/4267), [#4251](https://github.com/caspernieuwenhuizen/talenttrack/issues/4251), [#4268](https://github.com/caspernieuwenhuizen/talenttrack/issues/4268), [#4257](https://github.com/caspernieuwenhuizen/talenttrack/issues/4257), [#4403](https://github.com/caspernieuwenhuizen/talenttrack/issues/4403)) | v4.143.0 |
| Injuries: one medical record, one availability answer ([#4282](https://github.com/caspernieuwenhuizen/talenttrack/issues/4282)) | v4.142.0 and v4.143.0 |
| Academy HQ findings, round of October 2026 (`from-hq` queue) | v4.142.0 to v4.144.0 |
| Match prep fits every football form ([#4280](https://github.com/caspernieuwenhuizen/talenttrack/issues/4280), [#4285](https://github.com/caspernieuwenhuizen/talenttrack/issues/4285), [#4284](https://github.com/caspernieuwenhuizen/talenttrack/issues/4284), [#4318](https://github.com/caspernieuwenhuizen/talenttrack/issues/4318), [#4319](https://github.com/caspernieuwenhuizen/talenttrack/issues/4319), [#4346](https://github.com/caspernieuwenhuizen/talenttrack/issues/4346), [#4263](https://github.com/caspernieuwenhuizen/talenttrack/issues/4263), [#4352](https://github.com/caspernieuwenhuizen/talenttrack/issues/4352), [#4353](https://github.com/caspernieuwenhuizen/talenttrack/issues/4353), [#4372](https://github.com/caspernieuwenhuizen/talenttrack/issues/4372)) | v4.142.0 |
| PDP planning follows the current season and the active roster ([#4244](https://github.com/caspernieuwenhuizen/talenttrack/issues/4244), [#4248](https://github.com/caspernieuwenhuizen/talenttrack/issues/4248), [#4258](https://github.com/caspernieuwenhuizen/talenttrack/issues/4258), [#4266](https://github.com/caspernieuwenhuizen/talenttrack/issues/4266), [#4293](https://github.com/caspernieuwenhuizen/talenttrack/issues/4293)) | v4.141.6 and v4.142.0 |
| Player report ([#3871](https://github.com/caspernieuwenhuizen/talenttrack/issues/3871)) | v4.141 line |
