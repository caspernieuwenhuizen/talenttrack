# Chemistry settings page + tile hidden when team chemistry is off (#2071)

Bump: patch

With the Team chemistry feature switched off, the Chemistry settings view
(`?tt_view=chemistry-config`) and its dashboard tile stayed reachable while
the main formation board correctly hid. The `team_chemistry` feature now
claims the `chemistry-config` slug too, so the dispatcher renders the
standard module-disabled notice before the view loads — for administrators
as well as other roles — and the settings tile carries the feature tag so it
disappears from the dashboard when the feature is off. With the feature on,
the page and tile behave exactly as before.
