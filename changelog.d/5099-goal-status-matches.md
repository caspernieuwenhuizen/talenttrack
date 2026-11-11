# Achieved and missed goals count in every stored spelling (#5099)

Places that counted or filtered a single goal status only matched the exact stored spelling. A goal stored as "Completed", the spelling the status list is seeded with and demo data uses, was missed. Every one of them now matches any spelling, as the open-goal count already does.

These numbers can go up on installs with TitleCase-stored statuses:
- the Completed column of the goals-by-principle table;
- the KPI export's completed goals;
- the goal completion percentage on the dashboard;
- the goals analytics fact's completed count and completion rate;
- the player's completed-goals figure;
- the Goals list's Achieved and Missed filters (`GET /goals?status=achieved|missed`, and a raw status code);
- the PDP goals block's Completed filter;
- the goals CSV export's status filter.
