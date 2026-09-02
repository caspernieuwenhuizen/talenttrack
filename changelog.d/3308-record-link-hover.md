# Record links look like links, and keyboard focus is visible again (#3308)

Player names and other record links across the app were styled to look exactly
like body text until you hovered them — which on a phone means never. Twelve
names in a PDP block read as a paragraph rather than as the list of people you
can open.

They now carry a little more weight at rest, so they read as tappable without
turning every name in the product a link colour.

Tabbing to one shows a proper focus ring again. The old rule removed the
outline and offered a barely-visible shadow instead, which is not a focus
indicator.

Hovering tints the name rather than lifting it on a shadow. The lift was a card
treatment applied to a single word: it nudged the text off its baseline, so a
table rippled as the pointer crossed it, and the shadow hugged the letters.
Nothing moves now, and the tint follows your theme instead of being fixed blue.
