---
title: Alerts
group: performance
summary: Conditions in your data that need attention — unmarked activities, missing attendance, unevaluated players — surfaced automatically and cleared by fixing them.
audience: [user, admin]
order: 35
---

# Alerts

An **alert** tells you about something in your data that is true right now and needs attention. A training last Tuesday still marked as planned. A completed training with nobody's attendance recorded. An activity on Thursday with no coach assigned.

You never mark an alert as done. You fix the thing it points at, and the alert disappears on its own.

## Alerts are not tasks

TalentTrack has two different things that can be waiting for you, and they behave differently on purpose:

| | Task | Alert |
| --- | --- | --- |
| What it is | work assigned to you | a condition that is currently true |
| How it ends | you complete it | it stops being true |
| Who gets it | one person | everyone who could fix it |
| Where you fix it | in the task itself | in the normal screen for that record |

A task is "write the post-match evaluation for Daan". An alert is "this activity is still marked as planned". If alerts were tasks, you would fix the activity in the activities list and then still have a task sitting in your inbox telling you to fix it.

## Where alerts appear

- **The bell**, top right. Its number now counts both your open tasks and your open alerts.
- **A banner** at the top of the dashboard, showing the ones that need attention most.

Each alert links straight to the record it is about, so fixing it is one click away.

## What TalentTrack currently alerts on

### Activities

| Alert | What it means |
| --- | --- |
| **Past activity still planned** | The activity's date has passed but nobody marked it completed or cancelled. Until they do, its attendance and minutes are missing from every report. |
| **Attendance not recorded** | The activity is marked completed but nobody recorded who was there. It looks finished everywhere except in the reports. |
| **Upcoming activity has no coach** | An activity in the next week has nobody assigned to run it. Unstaffed activities tend to get cancelled late, which costs every player in the squad a training slot. |

### Evaluations

| Alert | What it means | Which player question it answers |
| --- | --- | --- |
| **Player not evaluated recently** | Nobody has recorded an evaluation for a player for longer than your academy's threshold. | *Where is this player now?* An academy that has written nothing down about a player for two months cannot answer that — not for a selection meeting, not for a parent, not for the player. |
| **Evaluation window closing** | An evaluation window is about to close and players in your team have no evaluation in it. | *Where is this player now, according to the review the academy promised to do?* Once the window closes the gap is permanent: that period has no record for them. |
| **Evaluation not shared with the player** | An evaluation was recorded but nothing was written in the player-facing feedback field. | *What does this player need next?* An evaluation the player never sees cannot answer that for them. The ratings and internal notes stay with staff, so from the player's side nothing happened. |

### Goals and PDP

| Alert | What it means | Which player question it answers |
| --- | --- | --- |
| **Goal past its target date** | A development goal has passed the date it was aimed at and is still open. | *What does this player need next?* A goal past its date and still open is the one part of the record that claims to answer that and no longer does. Either the player got there and nobody said so, or the plan needs changing. |
| **No PDP conversation this cycle** | A player's PDP file for this season is open but no conversation has actually been held. | *Where is this player going?* The PDP cycle is where the academy commits to sitting down with the player and agreeing that. A cycle whose conversations were all scheduled and none held looks complete on every list and has told the player nothing. |

### People

| Alert | What it means | Which player question it answers |
| --- | --- | --- |
| **Player turns 18 soon** | A player's eighteenth birthday is within the notice period. | *Where is this player going?* Turning eighteen changes the paperwork rather than the football — parental consent stops being the basis for holding their data, a youth agreement may need to become a contract, and the parent account's access becomes a decision rather than a default. Easy a month early, awkward a month late. |
| **Parent invited but never activated** | A parent was invited, never created their account, and the player still has no parent linked at all. | *Where has this player come from, and who at home can see it?* A parent with no account cannot read the evaluations, cannot see the PDP conversations, and cannot answer the club about consent. |
| **Certificate expiring** | One of your own certificates is about to expire, or has just expired. | *What does this player need next?* — answered from the other side. Every player in the squad needs the person running their session to be qualified to run it. |

### Measurements

| Alert | What it means | Which player question it answers |
| --- | --- | --- |
| **No measurement this season** | A player has no measurement recorded in the current season. | *Where has this player come from?* — physically. Growth data is the only part of a player's record that is not somebody's opinion, and a season with no measurement leaves a permanent hole in the curve. You cannot fill it later: the player has already grown. |

### Data quality

| Alert | What it means | Which player question it answers |
| --- | --- | --- |
| **Player has no team** | An active player belongs to no team. | *Where is this player now?* — and the honest answer is that TalentTrack does not know. A player with no team has no attendance, no minutes, no coverage row, and no head coach receiving any of the other alerts about them. |
| **Team has no head coach** | A team with players has nobody assigned as head coach. | *What does this player need next?* — for the whole squad at once, because the person whose job it is to answer that does not exist. It is also why a team can go quiet: almost every other alert goes to the head coach. |

These two go to whoever looks after the records rather than to a coach, because there is no coach to send them to — that is the condition. They are quieter than the rest of the catalogue: **Player has no team** appears on the bell only, not as a banner.

### Onboarding

| Alert | What it means | Which player question it answers |
| --- | --- | --- |
| **Invitation never accepted** | A player or staff invitation was sent a fortnight ago and never accepted. | *Where has this player come from?* An invitation is the first step of a player's journey through the academy's own systems, and an unaccepted one is a journey that never started — no account, no sight of their own evaluations, no reading the feedback their coach wrote for them. |

Parent invitations have their own alert (**Parent invited but never activated**), because the question they raise is different: a parent invitation that was never accepted may still be fine if the family is linked another way. Splitting them keeps each message specific.

That completes the alert catalogue for now. They arrive one module at a time, and each release names the alerts it adds — see "New alerts arrive switched on" below.

### Settings that change when these alerts fire

These live in academy configuration, not in code, because academies genuinely differ about what "recently" means. The one whose threshold is wrong stops trusting the alert.

| Setting | Default | What it controls |
| --- | --- | --- |
| `alerts_eval_stale_weeks` | 8 weeks | How long a player may go without an evaluation before the alert appears. A player who has never been evaluated is measured from the day they joined, not from the beginning of time. |
| `alerts_eval_window_closing_days` | 3 days | How much warning you get before an evaluation window closes. |
| `alerts_eval_share_grace_days` | 7 days | How long after an evaluation is recorded before the "not shared" alert appears. |
| `alerts_eval_share_lookback_days` | 60 days | How far back the "not shared" alert still looks. Older evaluations are left alone: telling you in April what you should have written in September is a backlog, not an action. |
| `alerts_goal_overdue_grace_days` | 3 days | How long after a goal's target date before the alert appears. A goal reviewed on Monday for a Sunday deadline is normal practice. |
| `alerts_goal_overdue_lookback_days` | 365 days | How far past its date a goal is still worth chasing. Beyond that it is abandoned rather than overdue, and the fix is a tidy-up, not an alert. |
| `alerts_pdp_no_conversation_days` | 45 days | How far into a PDP cycle before "no conversation held" becomes an alert. |
| `alerts_player_turns_18_days` | 30 days | How much notice you get before a player's eighteenth birthday. The age itself is not a setting: it is a fact about the jurisdiction the academy operates in, not a preference. |
| `alerts_parent_invite_stale_days` | 14 days | How long a parent invitation may sit unused before the alert appears. |
| `alerts_staff_cert_expiring_days` | 60 days | The window around today for the certificate alert. It reaches both forwards and backwards: a certificate that lapsed last week is the most actionable case of all, and one that lapsed a year ago is not "expiring", it is a different conversation. |
| `alerts_measurement_grace_days` | 60 days | How far into a season before "no measurement yet" becomes an alert. In week one it would fire for every player in the academy at once, which is indistinguishable from saying nothing. |
| `alerts_player_without_team_grace_days` | 7 days | How long a newly added player may sit without a team before the alert appears. Assigning the squad is often the next step in the same sitting. |
| `alerts_invitation_stale_days` | 14 days | How long a player or staff invitation may sit unaccepted before the alert appears. |

## New alerts arrive switched on

When a release adds an alert, it takes effect immediately for everyone who can act on it — you do not have to turn it on. That is deliberate: an alert nobody enabled is an alert nobody sees.

The safeguard is that new alerts arrive **one module at a time**, never the whole catalogue at once, and every release's notes name the alerts it adds and say what they will surface. Two new alerts with an explanation is being informed; twelve with no explanation is being spammed by your own tooling.

## Who receives an alert

The people who can actually fix it: the team's head coach, plus whoever else is directly involved — the coach assigned to the activity, the coach who wrote the evaluation, whoever set the goal, whoever sent the invitation.

**Certificate alerts are the exception, and go only to the person whose certificate it is.** That is somebody's own professional record, not squad information. If a staff member has no account there is nobody to tell, and nothing is sent; the Head of Development's org-wide view of expiring certificates covers that case instead.

Heads of Development and academy admins do **not** receive an alert for every team. Twenty teams' worth of alerts arriving at the person with the least time to read them is how a system gets ignored. An overview for that role is coming in a later release.

You also only ever receive an alert about a record you have permission to see. This is re-checked every hour, so a coach who moves off a team stops receiving that team's alerts without anyone having to remember to remove them.

## When an alert disappears

Fix the thing an alert is about and the alert goes as soon as you save. Mark the activity completed, record the attendance, assign the head coach — the next screen you land on no longer shows it. You do not confirm anything and there is no "done" button; the alert was only ever a description of your data, and the description stopped being true.

A background check also runs once an hour. It is the safety net, and it is the only thing that can notice a condition that became true because time passed rather than because someone saved something — a certificate reaching its expiry date, an invitation going unanswered for a fortnight, a session slipping past the point where its attendance is overdue. Those appear within the hour rather than instantly.

The one case where you may still wait is a bulk change — importing players, rolling over a season — where TalentTrack deliberately leaves the recount to the hourly check rather than doing hundreds of them while you wait for the page.

## Choosing which alerts you see

**Account → Alert settings** lists every alert, grouped by the part of the system it comes from, with a tick per place it can appear:

- **In the bell** — counted in the number top right.
- **Banner on the dashboard** — a bar at the top of the page.

Untick everything for an alert and you stop seeing it. Alerts you cannot change appear greyed out with the reason — either your academy has decided for everyone, or the alert concerns a child's safety and is always on. They stay visible on purpose: a settings list that quietly hides what you cannot change makes you think the list is complete when it is not.

Messages the academy *sends* you — emails, push notifications — are separate, under **Account → Settings → Messages you receive**. Each screen links to the other.

### Snoozing one alert

If a single alert is not useful right now, snooze it for a day, a week or a month. It disappears and comes back when the snooze runs out, provided the thing is still unfixed.

### Dismissing one alert

Dismissing removes it for good — but **only that occurrence**. If the same problem is fixed and then happens again, you get a new alert, because it is genuinely new information. To stop a *kind* of alert permanently, untick it in Alert settings instead.

## For administrators

**Alert policy** (Settings → Alert policy) decides who controls each alert:

- **Each person chooses** — the default, and right for almost everything.
- **Always on for everyone** — nobody can mute it. Use it when an alert matters too much to be optional.
- **Off for the whole club** — nobody sees it and no records are kept. Use it for parts of the system your academy does not use. Alerts concerning a child's safety cannot be switched off.

Two settings only an administrator can set:

- **Require people to acknowledge this before continuing** — the alert blocks the page until the person confirms they have seen it. Reserve it for genuinely serious cases; an interruption people see every day gets clicked away without being read.
- **Turn into a task after (days)** — how long an ignored alert waits before becoming a real assigned task. Leave it empty to use the built-in default. This takes effect once task escalation ships.

Other notes:

- Alerts are re-checked when the underlying record is saved, and again on an hourly background job. If nothing ever appears, check that WordPress scheduled tasks are running on this site — the hourly job is what catches everything that becomes true through the passage of time.
- A fresh installation runs one check immediately on activation, so the dashboard shows a true picture without waiting an hour.
- Every alert is also available through the REST API at `/wp-json/talenttrack/v1/alerts`.

## Alerts on the record itself

An alert is now also shown **on the thing it is about**, as a small coloured chip: a number, a word, and a link.

- **The activities list** — a chip on any activity that has something open.
- **An activity's own page** — the same chip, in the header.
- **A team's page** — anything open about that team.
- **A player's page** — anything open about that player, at the top of the left-hand column.

The chip says how many and how urgent, in words. It is never colour alone, and it never needs a hover or a tooltip to be understood — which matters because on a phone there is no hover.

Two things about the chip are deliberate, and worth knowing:

**You cannot turn it off.** Every other alert surface — the bell, the banner, the digest — will be yours to control once the settings arrive. The chip is not, because it is not a notification. It is the record's own current state, drawn next to the record. Hiding it would mean hiding a row's real condition from the person looking straight at that row.

**It only ever shows what is still open.** Once you have fixed the thing, the chip goes. Nothing is kept. On a player's page in particular, alerts that have been dealt with are simply gone, and **nothing about an alert is ever written into the player's journey**. The journey is the record of what happened to the player; an alert is a record of what a grown-up did not get round to typing in. They are different things, and mixing them would put "attendance was never recorded" into a child's development history where it does not belong.

## The alerts list

Everything open, in one place, at **Alerts** (`?tt_view=alerts`). Filter by:

- **Area** — Activities, Evaluations, and so on as more arrive.
- **Severity** — urgent, needs attention, for information.
- **State** — open, unread, or recently resolved.

Clicking a chip brings you here already narrowed to that one record; "Show all alerts" widens it back out.

## The overview for Heads of Development and admins

This is the overview earlier releases promised. If you oversee more than one team, the top of the alerts list shows a per-team summary: *"4 teams have records that need attention"*, then a line per team with a count. Each team name opens that team.

You still do not receive an alert per team, and that is on purpose. Twenty teams' worth of alerts arriving at the person with the least time to read them is how a system gets ignored. What you get instead is the shape of the problem — which teams, how many, how urgent — and a way into whichever one you want to look at.

The summary only ever counts teams you already oversee. It counts each affected record once, even when two coaches were both told about it, so the number is "three unmarked activities", never "six".

## For administrators (alerts on records)

- Rendering chips on a list costs **one** database query for the whole page, regardless of how many rows carry a chip. Anything that surfaces alerts on a list must read them in one batch; a per-row read is a bug, not a slow version of the same thing.
- The per-team summary is a grouped read over the alerts that already exist. It creates nothing, which is what lets the "no alert per team for Heads of Development" rule hold.
- The same filters are available on the API: `GET /alerts?subject_type=activity&subject_id=12`, `GET /alerts?player_id=7`, and `GET /alerts/rollup` for the per-team summary.
- Switching an alert off for the club also clears the ones it has already raised, rather than leaving them stored where nobody can see them.
- Every alert is also available through the REST API at `/wp-json/talenttrack/v1/alerts`, along with `/alerts/preferences` and `/alerts/policy`.

## Getting alerts by email

If you do not open TalentTrack often, you can have your open alerts sent to you as a summary email.

It is **off until you turn it on**. Nobody is signed up automatically — the app will show you alerts in the bell and on the dashboard, but it will not email you until you ask.

To turn it on, tick **In the summary email** for the alerts you care about in **Account → Alert settings**.

What you can rely on:

- You are never sent the same alert twice. An alert stays open until the underlying thing is fixed, and without this you would get the same three items every morning.
- Nothing is emailed that you have already read, snoozed or dismissed in the app.
- If there is nothing to report, no email is sent at all.
- Each line links straight to the record that needs attention, not to a list.

## How long alerts are kept

Once an alert has cleared, it is kept for **90 days** and then deleted. Alerts that are still open are never deleted, however old they are — an alert nobody has dealt with for a year is worth seeing, not tidying away.

This means the alerts system cannot answer questions spanning more than about a quarter. For season-long patterns, use Reports, which reads the underlying records rather than the alerts about them.

## When an alert becomes a task

An alert that nobody deals with does not stay a nudge forever. If your academy has set a threshold for it (Settings → Alert policy → **Turn into a task after (days)**), the alert is turned into a real, assigned task once it has been open that long.

Two things to know about how that works:

- **It happens once.** An alert becomes a task one time, not once a day until somebody acts.
- **It is one-way.** Fixing the underlying thing clears the alert, but it does **not** close the task. A task has somebody's name on it and a record of what happened, and closing that behind their back would defeat the point. Close the task from the task itself.

The alerts list shows which alerts have become tasks, and links to them.

### Checking the alerts engine is working

The **Alert policy** screen opens with an **Engine health** panel.

It answers the one question the rest of the system cannot: a background job that has stopped produces exactly the same screens as an academy with nothing wrong — empty ones. If the panel says alerts have not been checked recently, WordPress scheduled tasks are not running on your site, and every alert screen is frozen at whenever they last did.

The table below it shows, for each alert, how many are open, how many were cleared, and what share of them people simply dismissed.

That last figure is the useful one. **An alert most people dismiss is not informing anyone — it is teaching them to dismiss alerts**, and the useful ones go with it. Anything above about 60% (over enough occurrences to mean something) is flagged for review. Nothing is switched off automatically: whether an alert earns its place is a judgement about your academy, not a calculation. A safeguarding alert being dismissed often is a training problem, not a definition to delete.
