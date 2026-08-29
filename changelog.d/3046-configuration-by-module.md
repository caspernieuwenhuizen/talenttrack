# Configuration is grouped by module instead of by filing decision (#3046)

Bump: minor

Configuration used to present six sections — Appearance, Dashboard, Data &
vocabularies, Methodology & cycles, Integrations, System — that described the
kind of screen rather than the part of the academy it belonged to. Anything a
module owned could land in any of three of them depending on which felt closest
the day it was added, so finding "how are trials organised" meant already
knowing where each piece had been filed.

Configuration now groups by module. Trial tracks and trial letter templates are
under Trials. Workflow templates are under Workflow. Everything that belongs to
the whole install rather than to one module — appearance, date notation and
locale, lookups, seasons, backups, the audit log, the wp-admin menus, the
configuration export, the recycle bin — is together under Academy-wide, first
on the page.

The grouping is derived from what each module already declares, so a module's
new settings screen appears under its own heading with nothing to file, and
switching a module off takes its section with it. Every existing link and
bookmark still resolves; nothing moved to a new address.

On the dashboard, a group that mixes work with setup no longer drags the setup
half into "Today's work". The Trials group keeps its cases list up top and its
trial tracks in the collapsed setup section below.
