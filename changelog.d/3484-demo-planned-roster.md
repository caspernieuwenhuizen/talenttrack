# Demo academies now contain planned squads, not just registers (#3484)

Attendance is two things: the squad a coach planned, and the register they took
afterwards. A generated demo academy contained only the second — thirteen
thousand registers and not one planned squad — so half of what the attendance
model holds did not exist locally.

That is a bigger gap than it sounds. Most of the attendance defects found
recently are a planned squad being read as a register, or a write landing on
the wrong one. None of them could be reproduced on a demo install, because
there was nothing there to confuse; they were found by reading code or reported
against a real academy's data. The fixes have the same problem in reverse —
until now there was no way to demonstrate locally that they work.

A run now plans every squad before registering it, and produces all three
states: past activities with both a plan and a register, future activities with
a plan and no register, and a minority of past activities nobody ever
registered — the case the empty-register confirm, the completeness counts and
the *attendance not recorded* alert exist for. Planned squads use the real plan
vocabulary rather than marking everybody as coming.

Existing demo data is unaffected until the next generation.
