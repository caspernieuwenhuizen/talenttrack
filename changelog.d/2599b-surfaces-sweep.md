# Fixed: the Data browser tile stayed on the dashboard after switching the module off (#2599)

Bump: patch

Switching the **Data browser** module off hid what it does but left its tile on the dashboard, pointing at a screen that no longer answered. The
tile now disappears with the module, as every other module's does.

Behind that, the switchability check that shipped alongside it has been taught something it was missing: a screen belonging to a module you can
already switch off does not also need a separate feature toggle. That removed 47 entries from the list of screens marked as needing a decision —
they never needed one — and left six that genuinely must always be on, each with the reason written down.
