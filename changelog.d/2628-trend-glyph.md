# Test trends: a trend you can see without colour, and names you can click (#2628)

Bump: patch

The Test trends report showed whether a player improved or fell back in green
versus red and nothing else. That is invisible to a red/green colour-blind
reader — roughly one man in twelve — and it disappears entirely when the report
is printed in black and white, which is how it reaches most touchlines.

Every change now carries a glyph as well as a colour: green ▲ improved, red ▼
fallen back, grey ▬ unchanged. The word itself is still there on hover and for
a screen reader, so the separate Verdict column is gone and the table is one
column narrower on a phone.

Height and weight tests gained an indicator they never had: a grey ▲ or ▼ that
says which way the value moved and passes no judgement, because a taller player
is not a better one.

Player names in the tables and in the Most improved / Fallen back lists are now
proper record links — they match the colour of the text around them, and hovering
one shows the player summary card, the same as everywhere else in the app.

Both test reports now draw the indicator from one shared component, so they
cannot disagree about the same player's trend.
