---
title: Coach dashboard (frontend)
group: frontend
summary: What coaches see in the frontend tile grid.
audience: [user]
order: 20
---

# Coach dashboard

When you log in as a coach you land on a tile grid. Each tile opens one part of the app. Use the **← Back** link at the top of every page to return here.

## Layout on desktop

On a wide screen (1024px and up) the **Today's work** section splits into two columns. The work groups run down the left in a fixed order — **Performance → People → Planning & tactics → Development → Reference**, then any remaining groups (such as Tasks or Analytics). Your personal shortcuts gather on the right in a sticky **My work** panel: compact rows with an icon and a title, one per personal tile, that stay in view as you scroll the left column. On narrower screens everything collapses to a single column and the **My work** panel moves to the bottom. The collapsed **Setup & administration** section stays full-width below. Only setup screens appear there: a group that holds both — Trials, say, with its cases list and its trial tracks — now splits, so the cases list stays in Today's work and the tracks move down.

## This week

At the top of **Today's work** a **This week** panel lists every activity of the current week on the teams you coach, in date order. The week starts on the first day of the week your academy has set in General settings (Monday unless it is set to Sunday), the same week the team planner shows. Past days stay in the list, so nothing that still needs doing drops off on Wednesday. If you coach more than one team, each row also names its team. On a wide screen (1024px and up) the panel sits at the top of the right-hand column, above **My work**; on a phone or tablet it comes first, before the tile groups.

Each row shows the day, the type, the activity and its time and place, and below that what it still needs:

- **A training that has passed** shows *Attendance recorded* or *Attendance not recorded*. When it is not recorded, **Mark attendance** opens the attendance for that training straight away.
- **An upcoming training** shows how many players are expected out of the squad (when a planned squad was captured), and whether a training plan is attached: *Training plan ready* or *No training plan yet*. Tap the row to open the activity.
- **Today's training** shows the same, with today's date highlighted and a **Mark attendance** button.
- **A match** is marked with a coloured edge and shows the opponent, home or away, the time to be present, the kick-off and the place. Below that: how many players are available and how many are unavailable, and how far the line-up is (*Line-up 9 of 11*, or *Line-up complete*). **Match preparation** opens the preparation; on match day, once a preparation exists, the button reads **Start match**. **Activity** opens the activity itself. A match already marked completed shows like a past day instead: whether attendance is recorded, **Mark attendance** when it is not (unless the match is dated after today), and **Activity**, with no preparation, no start and no availability or line-up.

*Unavailable* is a count only. The panel never says why a player cannot play.

A week without a match says *No match this week.* under the list. A week with no activities on your teams shows a single line saying so instead of rows.

### Who sees the panel

Your academy chooses which roles see the panel, under **Configuration → Default dashboard → This week panel**. By default it is the head coach and the assistant coach. Tick or untick a role and save; the change shows the next time the dashboard is opened. Someone with several roles sees the panel while acting in a ticked role, and not while acting in another. A user who coaches no team never sees it, whatever their role.

## Tiles you'll use most

### Teams
The teams you coach. Each one shows its top-3 podium and the full roster. Tap any player card to see their detail page.

### My players
Players on the teams you coach. Admins see this tile labelled **Players** and get the full academy roster instead. Tap a card to open the player detail page. The list page also has an **Import from CSV** button.

### Evaluations
Form to record an evaluation. Pick a player, an evaluation type, a date, then rate them on every category. Save and you're done.

### Activities
Log a training, game or other event. Pick the team, the date, optional location and notes, then mark each player's attendance.

### Goals
Add development goals for a player and track their progress.

## Planning & tactics

The forward-looking planning and tactical tools sit in their own **Planning & tactics** group, just after Performance — separated from the day-to-day Evaluations / Activities / Goals work:

- **Team planner** — plan the training week, principle by principle.
- **VCT session designer** — build a training from the exercise library.
- **Team blueprint** — drag-and-drop line-ups with live chemistry.
- **Team chemistry** — formation board with a suggested XI.

## Analytics

### Podium
Top-3 of every team you coach, on one screen.

## Reference

The **Reference** group holds materials you read but don't fill in.

### Methodology
Your academy's principles, formations, positions and set pieces.

## On a phone

Tiles stack into one column on phones, two on tablets. Long tables scroll sideways instead of squeezing into narrow columns.
