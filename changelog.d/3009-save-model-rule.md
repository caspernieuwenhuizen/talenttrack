# The save model is written down (#3009)

A new help topic, **How saving works**, sets out the three ways TalentTrack
commits work and which screens use each: the screens that save themselves and
offer undo and revert, the screens that keep a Save button with a real Cancel,
and the wizards that draft and then submit. It says how far undo reaches, why
revert belongs to one device and one sitting, and why the attendance, minutes
and ratings grids keep an explicit Save deliberately rather than by omission —
a coach rating a squad on a flaky connection gets one commit point, and a
half-finished commit is worse than a lost one.

Every screen that changed in this epic now points at it, in English and Dutch.
This is the slice that stops the next surface guessing: the epic's finding was
never only that autosave was inconsistent, it was that the rule was nowhere.

Documentation and repo standards only — no behaviour changes.
