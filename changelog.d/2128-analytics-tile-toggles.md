# Analytics: switch Evaluation coverage and Cohort decision board off individually (#2128)

Bump: minor

The two Head-of-Development analytics surfaces — **Evaluation coverage**
and **Cohort decision board** — can now be hidden independently from
**Modules → Analytics**, without disabling the whole Analytics module or
touching the shared `tt_view_analytics` permission. Each is a per-tile
feature toggle: turning one off hides its tile and blocks its
`?tt_view=` route, while the central Analytics surface, the standard
reports and the analytics engine keep working.

Note for existing installs: both toggles ship **off by default**, so the
two tiles disappear on upgrade until an admin re-enables them under
Modules → Analytics. This is a deliberate change — academies that want
the surfaces switch them back on there.
