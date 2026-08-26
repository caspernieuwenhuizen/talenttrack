# The Activities tab badge counts what the tab shows (#2862)

Bump: patch

The number on a player's **Activities** tab counted only activities they had
already attended, while the list beneath it also showed what was coming up. A
badge reading 14 above nineteen rows is the kind of disagreement that makes a
coach doubt both numbers.

The badge now counts exactly what the tab renders, upcoming fixtures included.

Completes the fix started earlier in this release, which stopped the same list
showing every player twice.
