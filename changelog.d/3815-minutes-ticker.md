# The tournament minutes ticker keeps played and planned minutes apart (#3815)

The ticker added the minutes a player was still planned for to the minutes they
had actually played and printed the total on its own, so a squad that had not
kicked off yet read as though everybody had already had their game. Each card
now names the two separately — "0 played + 40 planned / 35 min" — and the bar
draws the played part solid with the planned part faded behind it. The green /
amber / red state still follows played plus planned, deliberately: before the
first whistle that is the only thing that tells a coach whether the plan covers
the whole squad. The ticker's own labels, which had been written into the script
in English, now come from the translation catalogue and read in Dutch.
