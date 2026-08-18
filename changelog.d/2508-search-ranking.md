# Search: players and teams no longer pushed out by section matches (#2508)

Bump: patch

Typing the first letters of a player's name into the search box often showed
only sections — Activities, Team planner, Test coverage — and never the player.
Typing `er` matched nineteen players and showed none of them.

Sections were listed first and the whole list then cut to eight, so any search
matching eight or more sections had no room left for anything else. Since there
are around sixty sections to match against, two-letter fragments hit that limit
constantly — which is exactly when you are still typing a name.

Sections now take at most three places whenever records are also matching, and
the rest of the list goes to players, teams and activities. Nothing is lost the
other way: when a search matches no records, sections still fill the list, and a
name that matches no section gets the whole list to itself. The list also holds
ten results instead of eight.
