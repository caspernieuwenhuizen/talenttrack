# Football actions are visible under every methodology set (#2620)

Bump: patch

The Voetbalhandelingen tab came up empty on any academy whose active methodology
was not the one the plugin shipped first. The catalogue's 18 actions had been
stamped to a single set when selectable methodologies landed, and the second
shipped set was never given its own — so the Methodology library's Football
actions tab, the goal → football-action picker and the printed reference card all
showed nothing.

The catalogue is now shared across every set. A football action — "passen onder
druk" — is vocabulary of the game rather than of one club's play style, so
switching the active methodology no longer changes which actions exist. Principles,
phases, vision and formations stay per-set as before.

An action a coach adds now joins the shared catalogue instead of being visible only
under whichever set happened to be active when they wrote it, and a goal linked to
an action keeps resolving to the same action under either set.
