# Linking a WordPress account that is already taken says so (#4019)

A WordPress account can be linked to one active person at a time. Trying to
link one that somebody else already holds was refused correctly — and then
reported as `500 db_error`, "The person could not be created", with nothing
in `details` and no database error behind it. An admin linking a coach's
login was sent looking for a fault that wasn't there, and during a demo the
person holding the account is hidden from the People list, so searching for
them found nothing either.

The refusal now answers `409 wp_user_already_linked` and names the person
holding the account in `details.person_id`, so you can go and free it. The
wp-admin People form says the same thing in place of "Something went
wrong". A genuine write failure is still a 500, and now carries the
database error in `details` instead of hiding it — read before the failure is
logged, because writing the log entry clears the database's own last error.
