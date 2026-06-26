# Six new per-academy feature toggles (#1538)

Bump: minor

The Modules page gains six more sub-feature switches, so academies can turn off
heavy, cost- or privacy-sensitive behaviour without disabling a whole module. All
default on, so nothing changes until you toggle one:

- **SMS channel** (Comms) — offer SMS as a messaging channel.
- **Scheduled messaging** (Comms) — the daily reminder cron.
- **Medical events on timeline** (Journey) — show medical events to permitted staff; an academy-wide privacy brake when off.
- **PDP calendar integration** (PDP) — write scheduled conversations to the calendar feed.
- **Dashboard layout editor** (Persona Dashboard) — the drag-and-drop layout builder.
- **Match prep PDF export** (Match Prep) — the A4 print / export-to-PDF actions.

(The seventh candidate, the Team planner calendar toggle, already shipped separately.)
