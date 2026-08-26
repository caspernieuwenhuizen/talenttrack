# Attendance statuses stop showing half in Dutch and half in English (#2863)

Bump: patch

One column on the player profile showed *Aanwezig* on some rows and *present* on
others. Two parts of the plugin write attendance status with different
capitalisation — the register writes `Present`, the planned squad writes
`present` — and only the first matched the configured vocabulary. The second
found no match and printed the stored value as-is.

Translation lookups now recognise a value regardless of its capitalisation or
whether it uses spaces, hyphens or underscores, so a status resolves to its
configured label wherever it appears. Status pills already worked this way; the
rest of the plugin now does too, so which screen you are on no longer decides
whether a value is translated.

This corrects the display. Making the two writers agree on one spelling changes
stored data and follows separately.
