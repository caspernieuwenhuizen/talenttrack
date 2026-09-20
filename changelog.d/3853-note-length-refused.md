# A match-analysis note that does not fit is refused, not cut (#3853)

A note is one row per bullet and its `body` column holds 255 characters. The
repository used to write `mb_substr( $body, 0, 255 )` and answer 200, so a note
sent in longer than that came back ending mid-word with nothing to say it had
been shortened — and the selection call that later quoted the observation was
quoting half a sentence.

The limit stays: a note is a bullet, not a paragraph, which is why every input
on the surface caps itself well under it. What changed is that going over is
said out loud. `PUT …/analysis`, `PUT …/analysis/sections/{key}` and
`PUT …/analysis/players/{player_id}` all answer `400` naming the offending item
and the maximum, and **nothing in that request is written** — not even the short
notes that travelled with the long one. A multi-line note is still split into
one bullet per line and each line measured on its own; an over-long line is
never split for the coach, because where a bullet ends is their judgement.
Rows already shortened by the old code are left exactly as they are.
