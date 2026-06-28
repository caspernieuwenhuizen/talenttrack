# Team detail: hide the chemistry teaser when the feature is off (#2033)

Bump: patch

The "Team chemistry" card and its *Open the chemistry board* link on the
team detail view no longer appear when the `team_chemistry` sub-feature is
switched off, or for personas without chemistry read authority. The teaser
now uses the same access gate as the chemistry board itself, instead of
rendering whenever the module class is loaded.
