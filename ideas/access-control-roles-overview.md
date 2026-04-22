<!-- type: feature -->

# Roles overview page in Access Control

Raw idea:

Where does the observer role live? I do not see it as functional role. Can I assign it to people?

## Answer (not an idea — Claude Code should record this and move on)

The Read-Only Observer lives as a WordPress role (`tt_readonly_observer`), not a Functional Role.

- WordPress roles control global caps: what can be seen/edited across the whole plugin. Set via WP Users → Edit user → Role.
- Functional Roles describe jobs on a team: head coach, assistant coach, physio, manager, other. Set via TalentTrack → Functional Roles and assigned per-team.

A person can hold both (e.g. a head coach whose WP role is tt_coach).

## The actual feature hiding in the question

There is no page in the TT admin that lists the WordPress roles, their effective caps, and where to assign them. Admins have to infer this. Add an "Access Control → WordPress Roles" page that shows:

- All 8 TT WordPress roles (Head of Development / Club Admin / Coach / Scout / Staff / Player / Parent / Read-Only Observer)
- For each: short description, the caps that role holds (collapsible detail), a "users with this role" count with a link to a filtered WP Users list, a "how to assign this role" note.

Could sit as a tab on the existing Roles & Permissions page or as a new sibling page. Observer in particular gets a prominent card since it's the one most admins won't find otherwise.
