# Spond Test shows what would actually sync (#3247)

Bump: patch

**Test** on a team's Spond connection proved the password and stopped there — which is not the question someone has after linking a group. They want to know whether the right calendar is behind it.

Test now logs in and then runs the dry-run preview that already existed on the Spond monitor, and reports it inline: how many events would be new, how many would update an existing activity, how many stored activities would be archived, plus the first few events with their dates. Nothing is written, and the panel says so. A link goes through to the monitor for the field-by-field comparison.

A login that works against a team with **no group linked yet** now says exactly that instead of reading as a failure — it is the normal state halfway through setup. A failed login still stops at the login error.

The two screens that enqueue the Spond script each carried their own copy of its string bag, and the copies had already drifted: the group-picker strings existed on one and not the other, so the same control fell back to English on the club-wide page. Both now read one shared bag, with a test that fails if the script ever reads a key the bag does not provide.
