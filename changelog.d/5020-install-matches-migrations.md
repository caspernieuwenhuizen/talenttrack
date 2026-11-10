# Re-activating the plugin no longer undoes the player-account rule (#5020)

Re-activating the plugin, or running "Retry migrations now", ran the install
routine's table definitions over the migrated tables. For players that
restored the old `wp_user_id DEFAULT 0` and left out the one-account-per-player
key, so the second trial promoted to a player in a club failed without a
message. The install routine now declares the players table as the migrations
leave it. A fresh install also seeds the rating scale the rest of the plugin
falls back to, and no longer stores the club colours (no stored colour means
the theme's colour). A new test checks that a re-activation changes none of
the `tt_` tables the migrations built.
