# Record pages on a phone: 48 px breadcrumb and profile links, and a ⋯ menu that closes (#4231)

Three tap-target fixes on the player and team pages. Breadcrumb links were
48 px tall but could be narrower than that ("Teams" measured 40 px wide);
they now have a 48 px minimum width as well. On the player's Profile tab the
team link and a parent's phone and e-mail links were 40 px tall on a phone
and are now 48 px; on a desktop they are unchanged.

The ⋯ menu in the action row under the hero (player page, team page, trial
case) is now the same element as the ⋯ menu in page headers. It still opens
by tap, Enter and Space, and now closes on Escape, on a tap outside it and
when an item is chosen, with focus returning to the ⋯. The page-header menu
gains the same close-on-choice behaviour. The menu's items and who can see
them are unchanged.
