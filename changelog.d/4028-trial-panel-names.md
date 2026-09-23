# The trial panel comes back with names on it (#4028)

`GET /trial-cases/{id}/staff` returned the raw panel rows, so a caller got
`user_id` and nothing to read: a panel of numeric ids, which no screen can
render and no reviewer can check before a decision about a child. Every row
now carries `display_name`, and `person_id` where the account resolves to a
record in People — both null rather than missing when they do not resolve.

The names come from the batched lookup the inputs payload already uses, so
there is one name-resolution path and one extra query per request, not one per
row. `POST /trial-cases/{id}/staff` also says where its `user_id` comes from
instead of leaving a caller to guess an account id.
