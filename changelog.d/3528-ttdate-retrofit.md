# The date notation setting now reaches every screen (#3528)

Picking a date notation in General settings changed some screens and silently
did nothing on others. A club that chose **Long — 31 December 2026** still met
`juni 30, 2026` on the measurements tab, in injuries, in generated PDFs, in
exported spreadsheets, in trial letters and in the emails TalentTrack sends —
fifty places that read WordPress's own setting directly instead of the
academy's.

Worst of all on one screen at a time: the player profile showed both notations
at once, because some of what it renders went through the setting and some did
not.

All fifty now resolve through the same helper, so the choice is honoured
everywhere a full date appears — including everything that leaves the screen,
where a printed report and the page it came from used to disagree.

**Nothing changes for an install that never touched the setting.** The System
default preset reproduces the WordPress format exactly, which is what the great
majority of installs are running.

Times are unaffected: the preset covers the date, and the clock still follows
WordPress's own Time format setting.
