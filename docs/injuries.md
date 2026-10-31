---
title: Injuries
group: performance
summary: Dated injury records on a player's journey, from onset to return to play.
audience: [user]
views: [injuries]
module: TT\Modules\Journey\JourneyModule
order: 85
---

# Injuries

An **injury** is a dated record on a player's journey: what happened, when it started, and when they came back. It sits alongside trials, signings and age-group moves as one of the transitions the academy tracks explicitly, rather than as a line in a note that nobody can search next season.

TalentTrack records the **development impact** of an injury, not the clinical treatment. There are no return-to-play phases, no treatment plans and no medical attachments. If your physio expects a medical system, set that expectation up front: this is not one.

## Who can do what

| | See injuries | Record one | Delete one |
| --- | --- | --- | --- |
| Head coach | Own teams | Own teams | No |
| Assistant coach | No | No | No |
| Team staff with the **Physio** functional role | Teams they are the physio of | Teams they are the physio of | No |
| Team staff with any other functional role | No | No | No |
| Head of development | Every team | Every team | Yes |
| Academy admin | Every team | Every team | Yes |
| Player / parent | Their own, on the journey | No | No |

The assistant coach is deliberately outside this. Injury records are medical data about minors, and the academy defaults to fewer people seeing them, not more. An assistant coach still marks a player **Blessure** on the attendance of a session and **Geblesseerd** in the match-day availability drawer — that is how availability is handled, and it is separate from the injury record.

**Injuries follow the functional role, not the account type.** Team manager, kit manager and physio are all the one Staff account type, so the account cannot tell them apart — the job somebody does on a squad can. Only the **Physio** role carries the injury log, and it carries it on the teams that role is held on and on no others. So a team manager who also does the first aid is given **Physio** as a second functional role on that team, under **People → Functional roles**: a person can hold more than one role on the same squad, and the two sit side by side. Nobody in this group may **delete** an injury, whichever roles they hold. The assignment has to be live today for the access to apply — when it ends, so does the access.

One exception, and it is temporary: a Staff account that holds **no** functional role anywhere keeps the injury access it had before functional roles existed, on the squads it is attached to. Giving that person the role that describes their job is what moves them onto the table above. See [Access control](access-control.md) for the full picture.

## Recording an injury

1. Open the player and go to the **Injuries** tab, then choose **Record injury**. The wizard already knows the player, so it opens on **What happened** with the player's name at the top. (Or open the **Injuries** tile and start from there, which asks for the player first.)
2. **What happened** — body part, type and severity. All three are optional: at the side of a pitch you know "hamstring" long before you know the grade, and an injury with just a date is worth more than one nobody recorded.
3. **When** — the date of injury is the only required field. Add an expected return if you have a sense of it; leave it empty if you don't.
4. Add a note. Write it as if the family will read it, because under a subject-access request they can.

Saving puts an *Injury started* entry on the player's journey automatically.

## Editing an injury

An injury recorded in a hurry on the pitch is often just a date and a note. Complete it once you know more: on the player's **Injuries** tab, or on a row of the **Injuries** overview, choose **Edit**. You can change the body part, the type, the severity, the expected return and the note. The date of injury stays as it was recorded.

The expected return cannot be before the date of injury. Emptying a field clears it. **Cancel** takes you back to where you came from without saving anything, and every saved edit is written to the audit log.

The **Edit** button only appears for people who may record injuries for that player; everyone else sees the injury read-only.

## Recording the return

This is the half that matters most, and the one that gets forgotten.

On the player's **Injuries** tab, an open injury carries a **Record return** button. Enter the date the player was back in training and save. Three things follow:

- an *Injury ended* entry lands on the player's journey;
- the injury stops counting as open, so the player drops out of the squad overview;
- the length of the absence becomes a real number rather than an open-ended one.

Until you do this the injury stays open forever, and the overview slowly fills with players who have been fit for months.

## Who is out right now

The **Injuries** tile opens the squad view: one row per open injury across the teams you can see, with the player, the team, the body part, the severity, how long they have been out, and when they were expected back.

A row is flagged **Overdue** when the expected return has passed and no actual return has been recorded. That is not a judgement about the player — it means the record needs a decision: either they are back and nobody said so, or the expectation has changed.

A row is flagged **Incomplete** when it has been open for 14 days without an expected return. The body part, type and severity can stay empty — a first-aider on the touchline often does not know them yet — but by then somebody should say when the player is expected back. An academy can change the number of days (configuration key `injury_incomplete_after_days`); the player's **Injuries** tab shows the same flag.

## Planning around an overdue or incomplete record

An overdue or incomplete record does not keep the player out of planning. Planned attendance and the match preparation show the player as available, with a neutral **Check availability** marker beside the name, so the coach confirms rather than guesses. The marker names no cause, so it is safe for every role that plans the squad, and it is never printed or stored in the match preparation. An injury that is still within its expected return, or open for less than the 14 days, keeps showing the player as **Unavailable**.

## The recovery reminder

When a record asks for a decision — on the expected return date, or on the day it becomes incomplete — the team's physio and head coach each get an **Injury recovery check** task, due that day. A team without a physio sends it to the head coach alone, and only people who may read the player's injuries receive it. Neither the task nor its e-mail carries any injury detail: the e-mail gives the task's name and deadline, and the task opens on the player.

There is one reminder per player at a time. Moving the expected return retires the old task, and a new one arrives on the new date; recording the return or archiving the record retires it too. Editing anything else leaves it as it is.

Filter by team, and switch between **Currently out**, **Recovered** and **All**.

The same list is available to other apps through the API as `GET /wp-json/talenttrack/v1/player-injuries`, with the same team and status filters, a page at a time. It follows the same rules as this page: you only get the teams whose injuries you may read here, and every read is written to the audit log.

**Record injury** sits at the top of the page, so you can log one without going through a player's file first — the wizard asks which team and which player as its first step. The button only appears for roles that may record an injury; everyone else sees the overview read-only.

## Privacy

Injuries are medical data about minors, so two things hold:

- Every time someone reads a player's injuries — the tab, the overview, or the API — it is written to the audit log with who, when and which player.
- Injury entries on the journey carry the **medical** visibility level, so a role without medical access sees the chronology with the entry withheld rather than silently missing.

Deleting an injury is restricted to the head of development and the academy admin, and archived records go to the recycle bin rather than disappearing.

## See also

- [Player journey](player-journey.md) — where injury entries appear in context.
- [Activities and attendance](activities.md) — the per-session **Blessure** status, which is availability, not an injury record.
- [Access control](access-control.md) — functional roles, and what each one grants on the team it is held on.
