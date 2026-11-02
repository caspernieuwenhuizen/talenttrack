# Navigation groups: one keyed list, one fixed order, for the dashboard and the sidebar (#4669)

Bump: minor

The dashboard and the app-shell sidebar now show their groups in one fixed
order, read from a single list, whatever order the modules happen to load in.
Before, any group outside a hard-coded list of six landed wherever its module
booted, which is why Administration, Configuration, the idea pipeline and
About came out in a different order from install to install. Each tile now
names a group key from that list (Me, Players & teams, Activities & matches,
Development, Tactics, Analytics & reports, Intake, Communication, Product
ideas, Settings, then the ten Configuration sections), and an unknown group or
an unknown tile kind is refused instead of quietly creating a heading or
turning into everyday work. Today's tiles are mapped onto the nearest group:
Tests & measurements and Learning sit under Development, Trials under Intake,
Planning & tactics is split between Activities & matches and Tactics, and the
old Administration and About groups are spread over the Configuration
sections. Media retention and Import history are now setup tiles. No two
tiles in one group share an icon any more, and the gear is kept for the
Configuration tile alone.
