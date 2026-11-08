# Team access follows the account's active person record in this academy (#4955)

When the permission check decided whether someone works on a team, it read the first person record linked to their account. That could be a record from another academy, or one that is no longer active. It now reads the same record every other permission check reads: the account's active, newest person record in the current academy. For an account with a duplicate or inactive person record this can only narrow what it reaches, never widen it.
