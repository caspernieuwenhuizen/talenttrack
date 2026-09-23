# Alerts: one count for "how many of our families can we reach?" (#4014)

A board member answering that question called the per-team dossier report
four times, read past every incomplete player's name on a phone, added the
guardian columns and the parent-account column up by hand and asked an
administrator to confirm the result before minuting it. The two columns also
read as though they disagreed: a squad with no guardian e-mail addresses and
three linked parent accounts shows "0 of 21" on one card and "3 of 21" on
another, both true, with nothing saying what they add up to.

The alerts page now carries the answer. A family counts as **reachable** when
the club has a guardian e-mail address, a guardian phone number or a linked
parent account, and the page shows the academy total with a count per team —
`GET /alerts/family-reachability` for a front end. The same line appears above
the six checks on each team's dossier completeness page.

Counts and team names only: no family is named academy-wide, and there is
still no club-wide dossier route, because a club-wide list of the families
nobody can reach would be an export of children's contact details. Whose file
is missing what stays on the per-team report behind the permissions it
already has. Reachable is an additional, derived reading and replaces none of
the six checks — a player whose parent has an account but whose guardian
phone number is empty is reachable and still nobody the club can telephone on
a Saturday morning, which is exactly why those checks stay separate.
