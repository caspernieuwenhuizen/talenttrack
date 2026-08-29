# Lookup labels no longer borrow a meaning from the translation catalogue (#3082)

Bump: minor

A lookup value with no translation row used to be handed to the plugin's
translation catalogue as a plain phrase, and whatever matched was displayed.
That is how a player's preferred foot `Left` came out in Dutch as *Vertrokken*
— "departed" — because that was the only place the word appeared in the
catalogue. The quiet version of the same fault put adjectives and lowercase
mid-sentence words into status pills: `Technical` as *Technisch* rather than
*Techniek*, `overdue` as a lowercase *te laat*.

That resolution step is gone. A lookup with no translation row now shows its
English key, which is the deliberate trade: obviously untranslated English gets
reported by an operator, a real Dutch word meaning the wrong thing does not.
Curated labels belong in the seed list and reach the database through a
migration, where they are reviewable.

A repair migration runs once on update. It replaces a stored label only when
that label is character-for-character what the catalogue would have produced
for that value and is not the curated one — a label a club typed itself is
never touched. On installs whose catalogue already agreed with the seeds it
changes nothing; it matters on installs that first migrated against an older
`.po`.

Also fixed in the curated list: the Dutch label for goal priority `Medium`,
which read *Middel* ("means / remedy / waist") instead of *Gemiddeld*; and
missing labels for the `Pending Approval` goal status and the two observation
journey event types, which had been reading English on every non-Dutch locale.
