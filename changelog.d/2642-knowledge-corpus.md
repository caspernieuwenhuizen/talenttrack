# Knowledge library: courses ship with the plugin (#2642)

Bump: minor

TalentTrack develops players; it did not develop the people who develop
players. The knowledge library is where coach education now lives.

This first ship is the content spine. A course is a folder under `courses/`
whose `course.md` carries a front-matter block: title, summary, lesson order,
the capability and licence tier it needs, the methodology principles it
teaches, and whether its lessons unlock in sequence. Lesson order comes from
the manifest rather than from filenames, so retiring a lesson does not mean
renumbering the folder. A course also declares the language it was written in,
which is what lets a Dutch-first course sit beside an English-first
documentation corpus without either pretending to be the other.

The registry is a projection of the folder, never a list beside it — dropping
a course in registers it, deleting it unregisters it. A course the registry
cannot parse is skipped rather than fatal, so a half-written course in a branch
never breaks a reader's page; a new `course-lint` CI gate turns that silence
into a build failure, checking that every declared lesson exists, that no file
is left out of the manifest, that prerequisites resolve, and that every quiz
payload is answerable.

Shipping with it: *Periodiseren in voetbaltaal*, a Dutch trainerscursus on
football periodisation — eleven lessons, ten quizzes and a twelve-week final
assignment, built on the methodology from Raymond Verheijen's *Football
Periodisation* (World Football Academy, 2014).

Nothing is readable in the app yet: the reader, progress tracking, gating and
completion statistics land in #2643 through #2650. The module and its
`knowledge_courses` sub-feature can both be switched off.
