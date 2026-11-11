# Five new visual themes with bundled fonts: Clubrood, Oranje, Tactiekbord, Bordeaux and Polder (#5089)

Bump: minor

Configuration → Appearance → Visual theme and My settings → Theme offer five
new looks: Clubrood (heritage red, black and muted gold), Oranje (navy and
Dutch orange), Tactiekbord (slate and chalk mint), Bordeaux (wine and sand)
and Polder (deep teal and coral). Each puts its deepest colour on the header
and the navigation rail and marks the section you are on with its accent;
where an accent is too light to read as text on white, words in that colour
use a darker shade of it. Reports follow the theme's colour. Their fonts ship
with the plugin as OFL woff2 files under `assets/fonts/`, Latin subset and
only the weights in use, loaded with `font-display: swap` only while the theme
is on, so a page makes no request to Google Fonts for them. Federation now
uses the bundled Barlow Condensed for its headings, and its keyboard focus
ring on the evaluation form is navy instead of a gold that was hard to see on
white. The player card loads Oswald and Manrope from the plugin instead of
from Google Fonts. A contrast test checks every theme. Default stays a
complete rollback.
