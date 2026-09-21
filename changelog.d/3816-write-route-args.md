# The activity, people and role-assignment writes say what they take (#3816)

Bump: minor

`POST` / `PUT /activities`, `POST` / `PUT /people/{id}` and
`POST /functional-roles/assignments` read a fixed set of fields and ignored
everything else, so a misspelled field name answered 200 over a value nothing
had stored. Each now declares the whole body it accepts and refuses a key
outside it with `400 unknown_field`, naming the key and listing what the route
does take — before anything is written.

The fields a write cannot do without are named too, so `POST /activities` with
an empty body answers `missing_fields` carrying `title` and `session_date`, and
`POST /functional-roles/assignments` names all three ids at once instead of a
message that named none of them.

One data fix travelled with it: `PUT /people/{id}` wrote both name columns on
every call, defaulted to empty, so a request carrying only a phone number
erased the person's name. An omitted field is left alone now, on both update
routes.
