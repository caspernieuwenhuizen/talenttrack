# The team name on a player's profile now leads somewhere they can go (#3473)

Under the player's name on their profile sits their team, as a link. It pointed
at the staff team page — for everybody, including the player and their parents,
who have no access to it. Tapping the first thing on the screen that names your
child's team produced "Niet geautoriseerd".

Staff still reach the team record. A player and a parent now reach "My team"
instead, scoped to the right child, and if a reader can reach neither the name
is shown as plain text rather than as a link that goes nowhere.

This is the same mistake as the two fixed before it in this area — a destination
hard-coded at the point where the link is built, without asking who is reading.
It was the last one on this page.
