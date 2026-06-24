# New-evaluation player picker: team-scoped dropdown instead of blank search (#1731)

Bump: patch

The player-first new-evaluation wizard's Player step no longer hides every
player behind a type-to-search box. It now shows a team-scoped native
dropdown: pick a team, then choose the player from the list. A coach who
manages exactly one team lands with that team pre-selected and its players
already listed, so no typing is needed. The team filter repopulates the
player list on change, and Head of Development / Academy Admin keep an
"All teams" option for cross-team reach. The change is opt-in via a new
`style => 'dropdown'` arg on `PlayerSearchPickerComponent`; the ~6 other
surfaces that use the picker keep the existing search behaviour unchanged.
