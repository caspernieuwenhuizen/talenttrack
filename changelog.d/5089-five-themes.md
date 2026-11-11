# Five new visual themes with bundled fonts: Clubrood, Oranje, Tactiekbord, Bordeaux and Polder (#5089)

Bump: minor

Configuration → Appearance → Visual theme and My settings → Theme offer five
new looks: Clubrood (heritage red, black and muted gold), Oranje (navy and
Dutch orange), Tactiekbord (slate and chalk mint), Bordeaux (wine and sand)
and Polder (deep teal and coral). Their fonts ship with the plugin as OFL
woff2 files under `assets/fonts/`, Latin subset and only the weights in use,
loaded with `font-display: swap` only while the theme is on, so a page makes
no request to Google Fonts for them. Federation now uses the bundled Barlow
Condensed for its headings instead of falling back to a system face. A new
contrast test checks every theme for body text, the primary button, the focus
ring and the rail's active marker. Default stays a complete rollback.
