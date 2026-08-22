---
title: Alerts
group: performance
summary: Conditions in your data that need attention — unmarked activities, missing attendance, unstaffed training — surfaced automatically and cleared by fixing them.
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

| Alert | What it means |
| --- | --- |
| **Past activity still planned** | The activity's date has passed but nobody marked it completed or cancelled. Until they do, its attendance and minutes are missing from every report. |
| **Attendance not recorded** | The activity is marked completed but nobody recorded who was there. It looks finished everywhere except in the reports. |
| **Upcoming activity has no coach** | An activity in the next week has nobody assigned to run it. Unstaffed activities tend to get cancelled late, which costs every player in the squad a training slot. |

More alerts, covering evaluations, goals, PDP cycles and player records, arrive in later releases.

## Who receives an alert

The people who can actually fix it: the coach assigned to the activity, and the team's head coach.

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
