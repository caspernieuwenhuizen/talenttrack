# The player record's write routes say what they take (#3817)

Creating and updating a player, a team, an evaluation or a goal now
declares the whole body it accepts. A key outside that list is refused by
name, listing what the route does take, before anything is written —
where a misspelled field used to be read by nothing and answered "saved".
A create that is missing what it needs names the fields rather than
describing them in a sentence.

Two things this found and fixed along the way. Saving a team through the
API with only some of its fields cleared the rest: a request carrying a
new name blanked the age group and the notes. It is now a partial save
like every other update, and a field left out keeps its value. And the
evaluation form's **Minutes played** box has stored nothing since match
minutes moved to the attendance screen; it is gone, rather than going on
collecting a number that was dropped on save. Minutes are entered on the
match's attendance screen, which is what the minutes reports read.
