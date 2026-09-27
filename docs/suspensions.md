---
title: Suspensions
group: performance
summary: Record a suspension once, for a number of matches, and every planning screen flags the player for exactly those matches.
audience: [user]
views: [suspensions]
module: TT\Modules\Journey\JourneyModule
feature: player_suspensions
order: 86
---

# Suspensions

A **suspension** is recorded once, as a number of matches, and TalentTrack works out the rest: which matches it covers, that the player cannot be picked for them, and when it has been served. Nobody has to remember to mark the player Suspended in every match preparation.

A suspension is an official sanction, not a private note. It appears on the player's journey, and the player and their parents can read it, reason included.

## Who can do what

| | See suspensions | Record or edit one | Remove one |
| --- | --- | --- | --- |
| Head coach | Own teams | Own teams | No |
| Assistant coach | Own teams | Own teams | No |
| Head of development | Every team | Every team | No |
| Academy admin | Every team | Every team | Yes |
| Player | Their own | No | No |
| Parent | Their own child's | No | No |

A coach of another team does not see a player's suspensions at all. Unlike an injury, a suspension is not medical information, so the assistant coach records it just as the head coach does.

## Recording a suspension

1. Open the player and go to the **Suspensions** tab, then choose **Record suspension**. (Or open the **Suspensions** tile, which asks for the team and the player first.)
2. **Suspension** — the reason, the number of matches, and the day it counts from. Add a note if it helps; the player and their parents can read it.
3. **Confirm** — the screen lists the matches the suspension will cover, taken from the team's calendar, so you can see a wrong start date before saving.

Saving puts a *Suspension started* entry on the player's journey.

If step-by-step forms are switched off for your academy, the same four fields appear on one page with **Cancel** and **Record suspension**.

The reasons (yellow-card accumulation, red card, club decision) can be renamed or extended under **Configuration → Lookups → Suspension reasons**.

## Which matches it covers

- **Matches only.** A suspension never makes a player unavailable for a training.
- **Every match counts**: league, cup and friendly alike.
- It covers the team's matches **on or after the day it counts from**, in date order. A 3-match suspension covers the next three.
- A **cancelled** match does not count, so it does not use up the suspension.
- On a **tournament day**, each fixture is one match. The tournament day itself is not.
- Matches that are not on the calendar yet are covered as soon as they are added.

## When it has been served

A suspension is served the moment the **last match it covers is completed** — when the coach finishes the match, closes the register, or completes it any other way. From the next match on, the player is available again. A *Suspension served* entry appears on the journey with the date of that last match.

Nothing has to be done by hand, and there is no nightly job: completing the match is what serves it.

**Reopen** that last match and the suspension is running again: it is no longer served, *Suspension served* leaves the journey, and the player is unavailable for that match once more. Complete the match again and it is served again, with one journey entry. Reopening an earlier match of the ban changes nothing while the last one stays completed.

Once a suspension has been served, its start date and number of matches are fixed. The reason and the note can still be corrected.

## Where it shows

- **Match prep** — when you start a match preparation, a suspended player is pre-marked **Suspended**, with a note such as "Suspended, match 2 of 3". The availability drawer does the same for a player you have not marked yet. Your own choice always wins: you can change the mark.
- **Planned squad** — the activity page and the planned-attendance list show the player as **Unavailable**, the same single word used for an injured player. It never says why.
- **The player's journey** — *Suspension started* and *Suspension served*, visible to the player and their parents.
- **The Suspensions tile** — who is still to serve a suspension across the teams you can see, with a filter for served ones.

## Removing a suspension

Only an academy admin can remove a suspension. It goes to the recycle bin like any archived record. To correct a mistake, edit it instead.

## Switching it off

Suspensions can be switched off under **Modules**, as a feature of the Journey module. The tile, the page and the profile tab disappear, and planning screens stop flagging suspended players. The **Suspended** attendance status stays available to set by hand.
