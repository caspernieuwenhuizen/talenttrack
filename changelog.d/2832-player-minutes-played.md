# Player · Minutes played: only played matches, translated type, styled link (#2832)

The report listed fixtures that had not been played yet — a match kicking off
this evening appeared as a row with an em-dash for minutes and counted toward
"Matches in roster". A match now counts once its activity is marked completed;
activities recorded before the status field existed fall back to the calendar.
The rule lives in one place, shared with the team minutes report, so the two
cannot disagree about what "played" means.

Two smaller fixes in the same table: the Type column showed the raw storage key
("game", "tournament") instead of the translated activity type, and the match
name was a bare underlined link rather than the record-link treatment the rest
of the reports use.
