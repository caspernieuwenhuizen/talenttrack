# The Staff role can now do a physio's job, and can no longer do an admin's (#3232)

Bump: minor

**Staff can record measurements and injuries** for the players on their teams.
Until now the role could not: recording a height, a sprint time or a hamstring
strain was closed to it, which made "physio" a seat that could not do the two
things a physio is there for.

**Read this before handing the role out.** Staff is one role covering physios and
kit managers alike, so anyone with it can now see and record **injuries** —
medical information about minors — for their own teams. That is right for a
physio and more than a kit manager needs, and until the role is split there is no
way to give one without the other. If it is more than you want somebody to see,
attach them to the team without the Staff role. Neither injuries nor measurements
can be deleted by Staff; that stays with the head of development and the academy
admin.

**Staff no longer holds "manage players".** That capability was never about the
roster: it carries season rollover across the academy, creating login accounts
for players, editing install-wide custom-field definitions, and deleting player
records. On academies not yet using the permission matrix, a physio or kit
manager could reach all four. They can't now.

Nothing useful is lost with it. The one thing it legitimately reached — setting
up a test in the catalogue — now asks about the test catalogue instead, so the
heads of development and academy admins who had it still have it.
