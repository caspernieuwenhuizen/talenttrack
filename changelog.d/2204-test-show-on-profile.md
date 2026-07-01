# Per test: choose whether its results show on the player profile (#2204)

Bump: minor

Each measurement test now has a **Show on the player profile** checkbox
(on by default) in the Manage-tests editor. Clear it to keep a test out of
the player-profile measurements view while it still records results and
appears in the results browser, reports and exports — handy for internal or
experimental tests. A new migration adds the `show_on_profile` column with a
default of 1, so every existing test stays visible on upgrade.
