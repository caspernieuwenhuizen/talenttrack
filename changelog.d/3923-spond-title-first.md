# Spond import: the title decides the activity type, not the description (#3923)

A Spond training whose description mentioned the weekend's fixture
imported as a fixture. The classifier concatenated the event's title and
description into one haystack and searched both with equal weight, so
"Training JO14-1" with the note *"laatste training voor de wedstrijd van
zaterdag"* landed as a match — with a match roster expected, the minutes
grid open against it, and the team record and the minutes audit both
counting it. The type is written at import, so renaming the activity
afterwards did not revise it.

The title decides now. The description is consulted only when the title
contains no recognised keyword at all, so an event called just "JO14-1"
whose body reads "wedstrijd tegen Ajax" still classifies as a match. The
whole-word rules for `kamp` and `uit` apply in both fields.
