# Player dashboard: own work as tiles, no setup/functions tile (#1821)

Bump: patch

The Speler (player) dashboard now renders the player's work (My journey, My card, My team, My evaluations, My activities, My goals, My POP) as tiles under "Today's work" instead of a separate right-hand rail. The "Functional roles" setup tile is also gated correctly: it now requires the manage capability (`tt_manage_functional_roles`), so it no longer leaks into a player's "Setup" section via the loose view-people fallback. Other personas are unchanged, and the persona switcher is respected.
