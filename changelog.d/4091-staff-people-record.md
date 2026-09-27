# Every staff login has a People record; `user_id` removed from the trial panel route (#4091)

A staff account that was never entered in People could only be put on a trial panel by its account id. Updating the plugin now gives every staff login in the club a People record (migration 0296: name from the account, role type from its staff role; accounts linked to a player or, as a parent, to a child are left alone), and any account that gets a staff role from now on (a new user, a role change, a staff invitation) gets its record at the same time. The staff directory (`GET staff`) therefore always returns a `person_id`.

**Removed:** `POST trial-cases/{id}/staff` no longer accepts `user_id`, as announced for one release. Send `person_id`; a request that still sends `user_id` gets a 400 that says so. The demo academy's administrator account now has a People record too.
