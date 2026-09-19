# A team manager can open the screens their role already lets them read (#3643)

A Staff account holding the **Manager** functional role could read its squad,
its players and its schedule through the API, and met *"You do not have access
to this surface"* on Teams, Players and Activities — the three screens the job
is actually done on. The dashboard decides whether to offer a surface from a
separate tile-visibility entity, and no functional role held one. Manager and
Kit manager now do, and Physio is offered the squad list their injury and
measurement access hangs off. The screens render read-only: creating, editing
and archiving still need a coach's rights, and nobody sees a team they hold no
role on.

The register follows. A manager may take attendance but not edit the activity,
so the **Attendance grid** shortcut on an activity — which asked for activity
editing while the grid itself asked the attendance question — now asks the same
question the grid does, and appears for the people who may use it.
