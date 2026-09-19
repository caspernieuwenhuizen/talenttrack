# Players: a missing date of birth or join date is empty, not 0000-00-00 (#3590)

A player created or saved without a date of birth or join date stored the
database's zero date, `0000-00-00`. The API then returned it as if it were a
real date, which also gave age and age-group calculations a bogus date. Now:

- **A blank date is stored as empty.** This covers the API and the player CSV import.
- **Zero dates on existing records are cleared** by a one-off data repair.
- **A date that isn't a real calendar date is refused.**
- **An unlinked player's `wp_user_id` is `null` over the API**, not `0`.
