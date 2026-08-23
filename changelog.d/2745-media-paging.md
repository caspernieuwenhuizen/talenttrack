# Long media galleries load in pages (#2745)

Bump: patch

A player, team or activity with a lot of photos rendered every single one
at once. On a phone after a full season that is a heavy page for no good
reason — nobody scrolls to August while looking for last weekend.

Galleries now show the 24 most recent items with a **Show more** button
underneath, adding the next 24 each time until there is nothing left and
the button disappears.

It is a button rather than loading more as you scroll, on purpose: the
oldest photo stays reachable, the browser's back button keeps working, and
there is nothing small to aim at with a thumb. Keyboard users land on the
first newly-loaded item rather than being thrown back to the top, and a
screen reader is told when more has arrived.

The count on the Media tab still reflects everything held for that player,
not what is currently on screen.
