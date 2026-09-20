# The coach of an activity is the coach, not whoever typed it in (#3745)

An activity's **Coach** used to be set to the logged-in user on every save
path, and `POST` / `PUT /activities` accepted a `coach_id`, answered 200 and
threw it away. An academy administrator entering a team's season schedule
therefore became the coach of every session in it and collected all of its
register reminders, while "upcoming activity has no coach" could never fire
because the column was never empty.

The activity form, the wp-admin form and the new-activity wizard now all show
a **Coach** picker. It offers the staff of the selected team and prefills the
team's head coach; a team with no head coach — or with two — is left on
*— No coach —* rather than guessed at, which is what lets the alert say so. An
assistant coach may name a colleague on a team they work with, and naming
staff from a team you cannot see is refused with a named error instead of
being silently replaced. The person who created the activity is still recorded,
in **Created by**. Existing activities are left exactly as they are.
