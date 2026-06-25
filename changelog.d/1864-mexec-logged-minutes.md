# Match execution shows each player's logged minutes (#1864)

The match-execution screen now shows a per-player minutes chip once a match
has been ended, reading the same persisted minutes the minutes report uses, so
the two always agree. Before the match is ended there are no minutes yet and no
chip is shown. Tracked players and bench players who came on both display their
logged minutes.
