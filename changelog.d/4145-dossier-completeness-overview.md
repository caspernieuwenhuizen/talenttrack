# Dossier completeness: a who-is-missing-what overview, and check cards that open closed (#4145)

The Dossier completeness page listed every player with a gap on every check
card, so a full squad was one long scroll of repeated names. It now opens
with an overview: one line per player, most gaps first. On a tablet or
desktop that is a grid of the six checks with a tick or cross per cell and
a gap count; on a phone each player gets chips naming only what is missing.
Players with a complete file fold into one line. The check cards below are
now collapsible and open closed, with the check that has the most missing
first and complete checks at the bottom; "Open all" and "Close all" work
on every card at once. The REST route returns the same per-player view
(`players`) and a `completion` percentage per check, and orders the checks
the same way.
