# Activity detail: grouped panel + compact stat strip (#2220)

Bump: patch

The activity detail page now reads as one cohesive record. The hero, a new
compact stat strip and the section cards sit inside a single softly-tinted
**grouping panel**, giving three tonal layers (page → tinted panel → white
cards) so the detail looks deliberate even when only a couple of sections
apply. The de-elevated hero is followed by a stat strip of the key numbers:
a match shows Present · Substitutes · Match length, a training shows
Present · Duration, each cell dropping out gracefully when its number is
unavailable. Every section card (Attendance, Line-up, Principles, Notes,
Tournament) keeps its titled header and only renders when it has content.
The line-up card's internal layout is unchanged here (its restyle is
tracked separately). Numbers are derived in the domain layer; the view only
composes them.
