# One page per squad saying whose file is still incomplete (#3805)

The office used to chase missing paperwork one player at a time: the
players list shows name, foot and shirt number, so the state of sixteen
files meant opening sixteen records. **Dossier completeness** is a new
tile that answers it for a whole team at once — guardian name, e-mail and
phone, whether a parent account is linked, whether photo and video consent
is on record and when, and which players have pictures on file that nobody
consented to. Every name links to that player, and the back link brings you
straight back to the list you were working through.

A linked parent account and the guardian contact fields are reported
separately, because they are two different facts: an account is how a
parent reads their child's record, and the phone number is how somebody
telephones a family on a Saturday morning.

The page says a field is empty; it never says what is in it when it is
filled, and there is no club-wide version. A checklist that printed every
family's contact details would be an export with a friendlier heading.

A new alert, **Pictures on file with no consent**, raises its hand for an
active player who has photos or videos on file and no consent on record. It
goes to the team's head coach and to whoever can edit players, and clears
itself the moment consent is recorded or the last item is archived. Like
everywhere else consent appears, it hides nothing: it tells a human to go
and ask.

The same answer is on the API at `GET /teams/{id}/dossier-completeness`.
