# Admin can create a new parent/player account directly (#1847)

Bump: minor

The **Parent accounts** view gains a *Create a new parent account* panel: an academy admin provisions a brand-new WP account (name + email), links it to the chosen player, and the person receives a standard **"set your password"** email — the admin never sees or sets a password. For the rare no-usable-email case, a *No usable email* toggle sets a temporary password instead (share it securely). Every direct-create is audit-logged. The same `directCreate` path exists on both `ParentAccountService` and `PlayerAccountService` and is reachable over REST (`POST /players/{id}/parents` / `…/account` with `create:true`), so a future front end gets the same behaviour (§4). Inviting remains the low-friction default; direct-create is the admin-convenience path. Follow-up to the Accounts & access epic (#1815, #1770). The player-accounts-view create UI is a fast-follow — its service + REST ship here.
