# Add an age profile, and a straight answer for the age groups that will never have one (#2601)

Bump: minor

The training generator only drafts for an age group that has an age profile —
the profile is what supplies the age-safe intensity ceiling, and that is not
something to guess at for children. Five shipped seeded, U10 to U14, and there
was no way to add a sixth. An academy fielding U15 to U19 had a generator that
refused every one of those teams and nowhere to go.

**You can now add and remove age profiles** under VCT configuration → Age
profiles, and through the API. Nothing is pre-filled: these numbers decide how
long and how hard children train, so a plausible-looking suggestion would be
worse than an empty field. Adding a profile also copies the session shape from
the closest age group that already has one — U15 inherits U14's blueprint — so
the generator works for those teams straight away rather than stopping one step
later for a different reason.

Removing a profile is refused while a team is still in that age group; those
teams would quietly stop getting drafted trainings. Trainings already planned are
never affected.

**And the youngest groups now get an answer instead of an apparent gap.** U7–U9
have no load model on purpose — training load is not planned in numbers at that
age. The generator used to report a missing profile there, which sent coaches
looking for a setting that does not exist. It now says structured load planning
does not apply at this age and the session is the coach's to shape. The line
between "not modelled by design" and "not set up yet" follows the profiles your
club actually has, so adding a younger profile moves it.

Seeded numbers for U15 and up are still a methodology decision and are not
included; an academy can now set its own.
