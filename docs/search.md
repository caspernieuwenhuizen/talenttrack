---
title: Search
group: basics
summary: The search box in the top bar and the results page behind it, for finding a player, team, activity, person, setting or help topic from any screen, for staff the evaluations, goals and notes they may read, and for parents and players their own records.
audience: [user, admin, player, parent]
views: [search]
order: 22
---

# Search

Find a player, team, activity, person, setting or help topic from any screen,
without going back to the dashboard first.

## The search box

The box is in the top bar, in both navigation layouts, on every screen. Type
in it and the results appear underneath, grouped:

1. Players
2. Teams
3. Activities
4. Evaluations & goals (for staff)
5. Notes (for staff)
6. Evaluations, goals and messages (for parents and players)
7. People
8. Settings & modules
9. Sections
10. Help

Each group shows its best four players or three of the rest. When there are
more, an *All* link at the foot of the group opens the full list on the
results page. The letters you typed are shown in bold.

- **A player** shows the photo, or the initials when there is none, the shirt
  number and the primary position when the player has them, and the team. Up
  to two quick actions sit beside the name, such as *New evaluation* or
  *Goals*. You only see an action you are allowed to use.
- **A setting** shows where it lives in full, for example
  *Configuration › Academy › Appearance*.
- **An activity** shows its date and team.
- **An evaluation, a goal or a note** shows the player, the date and a short
  excerpt of what was written, when you may read it.

An empty box shows what you opened last: the players, teams and activities
whose page you visited, newest first. A record you can no longer open drops off
that list by itself. Until you have opened anything, the empty box lists the
sections you can reach.

### With the keyboard

| Key | What it does |
| --- | --- |
| **Ctrl+K** (**⌘K** on a Mac) | Jump to the box from anywhere |
| **↓** / **↑** | Move through the results |
| **Enter** | Open the selected result; with nothing selected, open the results page |
| **Escape** | Close the list; press again to clear the box |

### On a phone

The top bar has a search button instead of the box. Tap it and the search
opens over the whole screen with the box ready to type in. The close button,
Escape and your phone's Back button all close it, and you are back where you
were.

## What you can type

Accents and capitals don't matter: *jose* finds José. Every word you type has
to match, so *den b* finds Daan den Besten. A few shortcuts are understood, and
the box says so above the results:

- **#7** finds the players with shirt number 7;
- **O14** finds the O14 teams and their players (**MO14** the girls' teams only);
- **12-10** finds the activities on 12 October this season;
- a weekday such as **zaterdag** or **saturday** finds the activities on last
  and next Saturday.

## The results page

Press Enter in the box, or follow an *All* link, to open the full results.

- **Kind**: a row of chips, one per kind of result, each with its count. Tap a
  chip to see only that kind; *All* shows everything again. On a phone the row
  scrolls sideways.
- **Team**: only the records of one team. The list holds the teams you can see.
- **Season**: only the activities of one season.
- Results come twenty to a page, with *Previous* and *Next* below them.

The page address carries what you searched and every filter, so you can
bookmark it or send it to a colleague. They see only what they may open
themselves.

When nothing matches, the page suggests what to try instead.

## What you can find

Only what you can already open. Search does not widen what you may reach; it
makes reaching it faster. A record you may not open is not shown and not
counted, so a search for it reads exactly like a search for a name that does
not exist.

## For staff: evaluations, goals and notes

Coaches, the head of development and everyone else who reads a player's
development record find it here too, for the players they may read it for: a
coach for the players of their own teams, the head of development across the
academy, a scout for the players they are linked to.

- **Evaluations & goals** holds both. An evaluation is found by the player's
  name, the type of evaluation in your own language, the opponent, the date
  and the coach's notes. A goal is found by the player's name, its title, its
  description and its due date.
- **Notes** holds the notes on a player's file. A note is found by the
  player's name, its text and the day it was written, and opens the player's
  *Notes* tab. A note marked staff-only is found only by someone who may see
  it.

The coach's notes on an evaluation are only searched, and only shown as an
excerpt, where you may read them. The read-only observer, for example, finds
an evaluation by the player, the type and the date, never by a word in its
notes, and sees no excerpt. The observer gets no *Notes* group at all.

On the results page the *Evaluations & goals* chip shows both kinds at once.

## Switched-off modules

An administrator who may switch modules on also finds the modules that are
switched off, and their settings, under *Settings & modules*. Such a row is
marked *Off* and has a *Turn on* action, which opens the Modules page at that
module's switch. Nobody else sees a switched-off module in search.

## For parents

A coach texts you that a session is "in TalentTrack now". This is how you find
it: the same search box, and the same results page.

You search four kinds of record, for your own children and nobody else's:

- **Activities**: trainings, matches and other sessions your child is on,
  including ones still to come.
- **Evaluations**: what a coach wrote about your child. The type of
  evaluation is found in your own language, so *wedstrijdevaluatie* finds a
  match evaluation.
- **Goals**: what your child is working on.
- **Messages**: what the academy sent you.

Type a name or a title (*training*, *Ajax*), or a date: *3 nov*,
*3 november*, *2026-11-03* or *12-10* all work. A date finds the activities on
that day, the evaluations recorded on it, the goals due on it and the messages
sent on it. Two characters is the shortest search that finds anything.

With more than one child, the search covers all of them at once. An
evaluation or a goal says which child it is about, and each result opens on
the right child. A section your child has chosen to keep
private is not searched.

The search never reaches another family, and never tells you that another
family's record exists: a search that would match somebody else's child
returns exactly what a search for a made-up name returns. Parents get no quick
actions, and no settings.

If no child is linked to your account yet, the page says so: ask the academy
to link you.

**Seeing everything that is coming up.** Your child's activities page opens on
what has already happened. **Show upcoming** at the top of the list flips it to
everything still to come, soonest first. The **Coming up** block above the
list always shows the next few.

## For players

You find:

- **yourself**, opening your own development page;
- **your teammates**: the players your *My team* page lists, by name,
  opening their teammate card;
- **your own team's activities**;
- **your own evaluations and goals**.

You never find another player's evaluations or goals, notes, or settings, and
you get no quick actions.

## For developers

The box and the page read the same REST routes: `GET /talenttrack/v1/search`
with `page` for the grouped and paged results, `GET /talenttrack/v1/me/recent`
for the recent list, and `POST /talenttrack/v1/me/recent` to note an opened
record. The staff kinds are `staff_evaluation`, `staff_goal` and `note`;
items may carry `shirt_number`, `position`, `excerpt`, `path` and `disabled`.
`GET /talenttrack/v1/me/search` stays for its existing callers: it
answers in its old shape (`children`, `results`, `total`), built from the same
engine. The old help topic for the parent search opens this one. Opening a player, team or activity page notes it on the server, so
every front end feeds the same list. See [REST API](rest-api.md).
