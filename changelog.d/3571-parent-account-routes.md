# REST: find, link and read back a player's parent accounts (#3571)

Linking a parent over the REST API needed a WordPress user id that no route
could look up, and nothing showed which parents were already linked. Three
changes:

- **`GET parent-accounts/eligible?search=`** finds accounts that may be linked as a parent, by name or email. It needs at least two characters and returns at most 20 accounts.
- **`GET players/{id}/parents`** lists a player's linked parent accounts, primary first.
- **`POST players/{id}/parents`** now declares its parameters. A missing `wp_user_id` names the field. Re-linking an existing parent answers `already_linked` with a message, instead of the unexplained `noop`.

All three need the parent-accounts management permission.
