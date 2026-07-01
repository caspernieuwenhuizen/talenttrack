# Line-up bench: clean position codes instead of raw JSON (#2196)

Bump: patch

The match-day line-up card's Bench row now shows a reserve player's
position as the clean short code (`LW`, `CDM`) instead of the raw stored
JSON array (`["LW"]`). Multi-position players join cleanly (`LW, CDM`) and
an empty position renders nothing. Starting XI was already clean; only the
bench fallback needed decoding.
