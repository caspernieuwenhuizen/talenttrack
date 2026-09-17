# The test environment runs a pinned WordPress version (#3525)

Internal only — no effect on the plugin.

`.wp-env.json` tracked whatever WordPress called latest, resolving the version
from WordPress.org and then cloning that tag from the WordPress git mirror. The
two do not move together: .org was serving 7.1.1 while the mirror had only
reached 7.1, so every test job in the repository failed to start, on branches
that had touched nothing near CI. The version is now pinned and bumped
deliberately, so a compatibility break is attributable to the bump.
