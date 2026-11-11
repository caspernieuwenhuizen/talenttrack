# Player report and team monthly snapshots follow each reader's section rights (#5060)

A saved player report or team monthly report now shows each reader what they
may read today, section by section, through the same rules the Report Studio
snapshots use (`PlayerSectionRights::cut()`, `TeamSectionRights::cut()`). The
REST route, the snapshot page and the PDF give the same answer. A player
report snapshot saved for staff opens only for a reader who reads as staff.
Notes on a team monthly snapshot are written by whoever may save that team's
snapshot, and each write is recorded in the audit log. A note write without a
body changes nothing (`400 missing_fields` on the REST route).
