---
title: Injuries
group: performance
summary: Dated injury records on a player's journey, from onset to return to play.
audience: [user]
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
| Head of development | Every team | Every team | Yes |
| Academy admin | Every team | Every team | Yes |
| Player / parent | Their own, on the journey | No | No |

The assistant coach is deliberately outside this. Injury records are medical data about minors, and the academy defaults to fewer people seeing them, not more. An assistant coach still marks a player **Blessure** on the attendance of a session and **Geblesseerd** in the match-day availability drawer — that is how availability is handled, and it is separate from the injury record.

## Recording an injury

1. Open the player and go to the **Injuries** tab, then choose **Record injury**. (Or open the **Injuries** tile and start from there, which asks for the player first.)
2. **What happened** — body part, type and severity. All three are optional: at the side of a pitch you know "hamstring" long before you know the grade, and an injury with just a date is worth more than one nobody recorded.
3. **When** — the date of injury is the only required field. Add an expected return if you have a sense of it; leave it empty if you don't.
4. Add a note. Write it as if the family will read it, because under a subject-access request they can.

Saving puts an *Injury started* entry on the player's journey automatically.

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

Filter by team, and switch between **Currently out**, **Recovered** and **All**.

**Record injury** sits at the top of the page, so you can log one without going through a player's file first — the wizard asks which team and which player as its first step. The button only appears for roles that may record an injury; everyone else sees the overview read-only.

## Privacy

Injuries are medical data about minors, so two things hold:

- Every time someone reads a player's injuries — the tab, the overview, or the API — it is written to the audit log with who, when and which player.
- Injury entries on the journey carry the **medical** visibility level, so a role without medical access sees the chronology with the entry withheld rather than silently missing.

Deleting an injury is restricted to the head of development and the academy admin, and archived records go to the recycle bin rather than disappearing.

## See also

- [Player journey](player-journey.md) — where injury entries appear in context.
- [Activities and attendance](activities.md) — the per-session **Blessure** status, which is availability, not an injury record.
