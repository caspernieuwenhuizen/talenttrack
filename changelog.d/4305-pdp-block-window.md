# PDP planning: the block detail shows the same window as its matrix cell (#4305)

The planning matrix showed a block's window as the earliest start to the
latest end over the team's talks, but the block detail behind the cell
showed the window of whichever player's surname sorted first, so the two
could disagree by weeks and the detail could read as closed while the cell
still read as open. Both now read one shared helper: the block's window runs
from the earliest start to the latest end, and the block counts as open
while any player's talk window is. Renaming a player no longer moves it.
