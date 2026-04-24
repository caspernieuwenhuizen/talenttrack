<!-- type: feat -->

# Roles overview page in Access Control

**This idea has been merged into #0019 Sprint 5.** See `specs/0019-sprint-5-admin-tier-surfaces.md` under the "Roles + Capabilities" section.

The idea is preserved below as historical record of the original raw question that motivated the feature.

---

Raw idea:

Where does the observer role live? I do not see it as functional role. Can I assign it to people?

## Answer (recorded during shaping)

The Read-Only Observer lives as a WordPress role (`tt_readonly_observer`), not a Functional Role.

- WordPress roles control global caps: what can be seen/edited across the whole plugin. Set via WP Users → Edit user → Role.
- Functional Roles describe jobs on a team: head coach, assistant coach, physio, manager, other. Set via TalentTrack → Functional Roles and assigned per-team.

A person can hold both (e.g. a head coach whose WP role is tt_coach).

Note: as of shaping, `tt_readonly_observer` is claimed in the readme since v2.21.0 but was never actually registered in `Activator.php`. That bug is fixed in #0014 Sprint 5 (scout flow), which registers both `tt_readonly_observer` and the new `tt_scout` role properly.

## What was being asked for

A page in the TT admin that lists the WordPress roles, their effective caps, and where to assign them. Admins should have a canonical place to look up "what does this role do and how do I give it to someone?"

## Where this now lives

Absorbed into #0019 Sprint 5's `FrontendRolesView` → Role reference panel. The intent is preserved, the scope is larger (it's one part of a broader admin-tier frontend migration), and the ship date is tied to Sprint 5's schedule rather than independent.
