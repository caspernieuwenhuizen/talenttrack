# Test results now show how much a player changed, not just that they did (#2586)

The Trend column showed only an arrow — you could see a player had improved,
but not by how much, which is the part that matters. It now shows the signed
change beside the arrow, e.g. "▲ −0,08 s" on a test where lower is better.

Players with only one measurement still show a dash rather than a made-up
zero. The number follows the site language, so a Dutch install reads −0,08.
