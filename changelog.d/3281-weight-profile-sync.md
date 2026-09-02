# The profile weight follows the measurements, like the height already did (#3281)

Recording a height has updated the player's profile since the last release.
Weight did not, so an academy weighing its squad every cycle had a correct
dated series and a profile still showing whatever was typed at signup.

Now both follow the readings. Record a weight and the profile shows it as soon
as that reading is the player's most recent one, under the same rules height
uses: the most recent measurement wins rather than the last one you typed,
deleting the last reading leaves the profile value alone, and a clearly
mistyped number is not copied across.

The recognised test names are `Gewicht`, `Weight` and `Mass`, matched the same
way as the height names — capitals and spacing don't matter.
