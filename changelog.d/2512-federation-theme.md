Bump: minor

**Visual themes.** The frontend's colours, corners and heading type are now a
setting. Alongside the shipped green-and-gold **Default**, there is
**Federation** — a navy chrome with a gold marker on the active section,
squarer corners and a condensed heading face. The academy picks a default
under Configuration → Appearance, and each person can pin their own under My
settings → Theme.

A theme changes appearance only — no permission, field or button changes with
it. While a theme is active it supplies the whole colour scheme, so the colour
and font settings under Appearance do not apply; your logo and academy name
still do, and the Colours panel says so rather than letting you pick a colour
that does nothing. Setting the theme back to Default is a complete rollback:
the theme's stylesheet is not loaded, no theme class is written into the page,
and your colours return exactly as you left them.

**Fixed:** with the app shell on, the navigation sidebar listed only three
destinations while the tile overview on the same screen showed thirty. Every
section is now reachable from the sidebar (#2505).
