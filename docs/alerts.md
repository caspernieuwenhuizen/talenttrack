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

## Turning alerts off

Not yet. Per-person and per-club settings — choosing which alerts you see and where — arrive in a later release. Until then, everyone who can fix a thing is told about it.

## For administrators

- Alerts are re-checked on an hourly background job. If nothing ever appears, check that WordPress scheduled tasks are running on this site.
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
