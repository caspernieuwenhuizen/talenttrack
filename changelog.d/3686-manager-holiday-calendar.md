# Team managers and kit managers can read the academy holiday calendar (#3686)

A staff account holding the Manager or Kit manager functional role on a team could read that team's schedule but was refused the academy holiday calendar, so a gap between two trainings looked like missing data instead of a planned break. Both roles now read the calendar, which is what puts the holiday banners on the team planner. Creating, editing, archiving and restoring holidays are unchanged, and neither role is offered the Holidays management screen.

Found alongside it and fixed here: saving an edited holiday failed with "not found". The edit route was registered under a method name that no request could ever match, so every save from the holiday edit screen was rejected whoever made it.
