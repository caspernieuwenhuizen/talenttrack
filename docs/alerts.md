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

More alerts, covering goals, PDP cycles, measurements and player records, arrive in later releases. They arrive one module at a time, and each release names the alerts it adds — see "New alerts arrive switched on" below.

### Settings that change when these alerts fire

These live in academy configuration, not in code, because academies genuinely differ about what "recently" means. The one whose threshold is wrong stops trusting the alert.

| Setting | Default | What it controls |
| --- | --- | --- |
| `alerts_eval_stale_weeks` | 8 weeks | How long a player may go without an evaluation before the alert appears. A player who has never been evaluated is measured from the day they joined, not from the beginning of time. |
| `alerts_eval_window_closing_days` | 3 days | How much warning you get before an evaluation window closes. |
| `alerts_eval_share_grace_days` | 7 days | How long after an evaluation is recorded before the "not shared" alert appears. |
| `alerts_eval_share_lookback_days` | 60 days | How far back the "not shared" alert still looks. Older evaluations are left alone: telling you in April what you should have written in September is a backlog, not an action. |

## New alerts arrive switched on

When a release adds an alert, it takes effect immediately for everyone who can act on it — you do not have to turn it on. That is deliberate: an alert nobody enabled is an alert nobody sees.

The safeguard is that new alerts arrive **one module at a time**, never the whole catalogue at once, and every release's notes name the alerts it adds and say what they will surface. Two new alerts with an explanation is being informed; twelve with no explanation is being spammed by your own tooling.

## Who receives an alert

The people who can actually fix it: the team's head coach, plus whoever else is directly involved — the coach assigned to the activity, or the coach who wrote the evaluation.

Heads of Development and academy admins do **not** receive an alert for every team. Twenty teams' worth of alerts arriving at the person with the least time to read them is how a system gets ignored. An overview for that role is coming in a later release.

You also only ever receive an alert about a record you have permission to see. This is re-checked every hour, so a coach who moves off a team stops receiving that team's alerts without anyone having to remember to remove them.

## Why an alert can take an hour to disappear

TalentTrack re-checks every condition once an hour, in the background. This is deliberate: checking them all while your dashboard loads would make signing in slower for everyone, and slower every time a new alert type is added.

So if you mark an activity completed at 10:15, the alert may still be there until the next check. It will clear itself. There is nothing you need to do, and nothing you can do to hurry it.

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

- Alerts are re-checked on an hourly background job. If nothing ever appears, check that WordPress scheduled tasks are running on this site.
- A fresh installation runs one check immediately on activation, so the dashboard shows a true picture without waiting an hour.
- Switching an alert off for the club also clears the ones it has already raised, rather than leaving them stored where nobody can see them.
- Every alert is also available through the REST API at `/wp-json/talenttrack/v1/alerts`, along with `/alerts/preferences` and `/alerts/policy`.
