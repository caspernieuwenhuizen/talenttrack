# Five modules now show their real names on the Modules page (#2599)

Bump: patch

Strava, Training plans, Measurements & testing, Data browser and Knowledge library were listed on the Modules page under a slugified class
name instead of a proper label and description. They now read like the other modules do.

The rest of this change is a build-time check with no visible effect: TalentTrack now refuses to ship a module or a screen that an academy cannot
switch off, unless somebody has written down why it must always be on. The switching itself has always worked — what was missing was anything that
noticed when a new one arrived without a toggle.
