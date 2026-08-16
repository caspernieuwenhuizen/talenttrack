# Modules can be marked "under development", and the dashboard tile says so (#2409)

Bump: patch

The **Under development** marker now works at module level, not just per
feature: tick the checkbox on a module's card at *Modules* and every view that
module owns shows the informational pill. A core (always-on) module can be
flagged too — the marker gates nothing, so there is no reason to exempt it.

The marker also reaches the **dashboard tile** now. A tile shows a small amber
**Under development** badge when its own feature is flagged *or* when its
module is, so people see that a surface is still being built before they click
into it rather than after. The badge appears on the persona dashboard, the
classic tile grid, the "My work" rail and a parent's child tiles.

As before the flag is purely cosmetic — it never disables or hides anything,
and it is independent of the on/off switch, so a module can be live and flagged
at once. Only admins who can manage modules can set it; everyone sees the
result. It is stored per club on `tt_module_state` and is readable and settable
through the `/talenttrack/v1/modules` REST endpoint.
