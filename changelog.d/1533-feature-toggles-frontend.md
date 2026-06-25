# Configuration: Feature toggles no longer bounce into wp-admin (#1533)

Bump: patch

The Configuration page's **Feature toggles** tile no longer sends you into wp-admin — per-module enable/disable already lives on the frontend **Modules** view (`?tt_view=modules`), which is contributed into the Configuration grid. The redundant wp-admin tile is retired, so toggling modules stays on the modern frontend surface. First port of the "wp-admin Configuration surfaces → frontend" tracker (#1533); Translations, Backups, Audit log, Setup wizard and Spond are filed as follow-up children.
