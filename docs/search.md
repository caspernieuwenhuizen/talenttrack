---
title: Search
group: basics
summary: The search box in the top bar and the results page behind it, for finding a player, team, activity, person, setting or help topic from any screen.
audience: [user, admin]
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
4. People
5. Settings
6. Sections
7. Help

Each group shows its best four players or three of the rest. When there are
more, an *All* link at the foot of the group opens the full list on the
results page. The letters you typed are shown in bold.

- **A player** shows the photo, or the initials when there is none, and the
  team. Up to two quick actions sit beside the name, such as *New evaluation*
  or *Goals*. You only see an action you are allowed to use.
- **A setting** shows the section of the configuration it is in.
- **An activity** shows its date and team.

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

For a parent the results page searches their own children's records instead:
see [Finding something in your child's record](parent-search.md).

## For developers

The box and the page read the same REST routes: `GET /talenttrack/v1/search`
with `page` for the grouped and paged results, `GET /talenttrack/v1/me/recent`
for the recent list, and `POST /talenttrack/v1/me/recent` to note an opened
record. Opening a player, team or activity page notes it on the server, so
every front end feeds the same list. See [REST API](rest-api.md).
