<!-- audience: admin, developer -->

# Visual theme

TalentTrack's colours, corners and heading type are a setting. The academy
picks a default and every person can pick their own — the same arrangement as
the [navigation layout](frontend-shell.md).

A theme changes **appearance only**. It never changes what anyone can see or
do: no permission, no field, no button appears or disappears with a theme.

## The themes

**Default** — the green and gold TalentTrack has always had. This is the
default, and it stays available indefinitely.

**Federation** — a navy chrome with a gold marker on the active section.
Squarer corners (4px instead of 8px), a condensed heading face, and a
navy-tinted depth so shadows read as part of the palette rather than as grey
haze over it.

**Kristalblauw** — deep blues and cyan, set in Open Sans. A black header and
navigation rail, closed by a diagonal cyan-to-navy stripe. Buttons and links
take the deep blue, cyan marks the section you are on. Corners are nearly
square (3px). The theme was renamed without changing anything else, so an
academy that had already chosen it keeps it, looking the same.

**Clubrood** — heritage red, black and muted gold, with Barlow Condensed for
the headings. Classic shirt colours; the condensed headings read like a
matchday programme.

**Oranje** — navy and Dutch orange, set in Archivo. National-team energy,
with the highest-contrast accent of the set.

**Tactiekbord** — slate and chalk mint, set in IBM Plex Sans. Cool and
analytical; it suits academies that lead with data and methodology.

**Bordeaux** — wine and sand, with Source Serif 4 for the headings and Source
Sans 3 for the rest. Editorial and calm; the serif headings give reports a
printed feel.

**Polder** — deep teal and coral, set in DM Sans. Warm and approachable; a
good fit for younger age groups and for what parents see.

**Geel-Zwart** — two-tone black and signal yellow, with Oswald for the
headings. Pure two-tone and the loudest on screen; the yellow carries every
highlight.

**Groen-Wit** — two-tone tonal green and mint white, set in Manrope. One hue,
light and dark; fresh and quiet for clubs in green and white.

**Paars-Wit** — two-tone tonal violet and lilac, set in Outfit. One hue, light
and dark; it stands apart from the usual football palettes.

**Wapenschild** — a black header, green actions and a yellow accent, set in
Montserrat. Bold and recognisable.

**Streepshirt** — two-tone black and bright green, set in Montserrat. The
striped-shirt two-tone: black carries the screens, green highlights.

**Wedstrijddag** — deep green with yellow buttons, with Barlow Condensed for
the headings and Montserrat for the rest. A matchday-programme feel.

**Middernacht** — two-tone midnight navy and electric blue, with Exo for the
headings and Poppins for the rest. Near-black navy with one bright blue; sleek
and modern.

**Neon** — midnight navy and neon lime, with Exo for the headings and Poppins
for the rest. Training-bib lime on midnight navy; energetic, with very high
contrast.

**Stadionlicht** — electric blue with cyan, with Exo for the headings and
Poppins for the rest. Blue leads and cyan accents; lighter and brighter than
Middernacht.

In all of these the header and the navigation rail take the theme's deepest
colour, and the accent marks the section you are on. An accent is for marks
and fills. Where it is too light to read as text, as with the yellow, lime and
cyan of Geel-Zwart, Neon and Stadionlicht, words in that colour use a darker
shade of it on white and a lighter one on the theme's colour.

### Fonts come with the plugin

The fonts the themes use are part of the plugin. A page never asks Google
Fonts or any other font service for them, and a font is only downloaded while
its theme is on. Until it arrives the text shows in a similar system font, so
nothing waits for it.

## A theme replaces your colour settings

While a theme is active it supplies the **whole** colour scheme — the brand
header, buttons, links, status colours and the heading font. The colour and
font fields under Configuration → Appearance have no effect until the theme is
set back to Default. The Colours panel says so when a theme is on.

This is deliberate. Letting a club colour through produced the problem it was
meant to avoid: a green brand header sitting above a navy sidebar, with two
palettes fighting over the same screen.

**Your identity still shows.** Your logo and academy name are markup, not
colours, and they render in every theme. Setting the theme back to Default
restores your colours exactly as you left them — nothing is overwritten, so
there is nothing to re-enter.

### Custom CSS is not affected

If you have written rules under Configuration → Custom CSS, they still apply
on top of a theme. That is an escape hatch you opted into by hand, so a theme
does not silently discard it — but it is also where a theme's look can be
broken, so check that page if a theme is not rendering the way you expect.

## Choosing one

**For the whole academy** — Configuration → Appearance → *Visual theme*.
Everyone who has not chosen their own follows this.

**For yourself** — My settings → *Theme*. Pick a theme to pin it, or *Use the
academy default* to follow the academy again, including when an administrator
changes it later.

Changes apply on the next page load.

## Going back

Setting the theme to **Default** is a complete rollback. The theme's
stylesheet is not loaded at all and no theme class is written into the page,
so every screen renders exactly as it did before the theme existed. There is
no cleanup and nothing to migrate.

---

## For developers

`ThemePreference` (`src/Shared/Frontend/ThemePreference.php`) resolves the
theme, mirroring `ShellPreference`:

| Concern | Where |
| --- | --- |
| Academy default | `tt_config` key `tt_frontend_theme` (club-scoped) |
| Personal override | user meta `tt_frontend_theme`; `inherit` follows the club |
| Resolution | override → club default → `default` |
| Root class | `ThemePreference::rootClass()`, `''` for the default theme |
| Stylesheet | `ThemePreference::styleFile()`, `''` for the default theme |

Read through the resolver, never the config key directly — that one chokepoint
is what makes a future SaaS migration a single replacement rather than a search
across views.

### How a theme is built

A theme is a **token layer**, not a second set of surfaces:

1. `assets/css/tokens.css` declares every default token on `.tt-root`: a
   wrapper the dashboard renders directly around `.tt-dashboard`, and the body
   class. The wrapper is always there, so the tokens resolve whatever the
   WordPress theme does and whether or not the custom CSS module is on.
2. The theme sheet redeclares a subset on `.tt-dashboard`, a closer ancestor,
   so every surface inside the dashboard inherits the themed value with no
   per-view rule.
3. Anything a token cannot express — the app shell's navy rail, which cannot
   come from re-pointing `--tt-paper` without darkening every card — is a small
   set of explicit rules against the shell's existing class vocabulary.

### Layer order

There are three design layers, and on any page at most two of them apply:

1. **`tokens.css`**: the one set of defaults, on `.tt-root`. Every token the
   product reads is declared here, brand colours and fonts included.
2. **A theme**, when one is chosen. It owns the complete visual design and
   redeclares its tokens on `.tt-dashboard`. Branding is not output under a
   theme.
3. **Branding**, on the default design only. The academy's chosen colours and
   fonts are attached to `tokens.css` itself (`BrandStyles::attachVars()`),
   as a `.tt-root` rule printed right after the defaults, so they win by
   order. A colour counts as chosen only when it differs from the Branding
   form's own default; a stored `#000000` for a status colour (danger,
   warning, success, info) counts as not chosen. A colour that is not chosen
   keeps its `tokens.css` value.

On top of those sits the academy's own **custom CSS** (the custom CSS module
and its visual editor), which writes `.tt-root { --tt-…: … }` rules of its
own and is meant to have the last word.

No surface stylesheet declares tokens of its own. `frontend-admin.css` used to
redeclare a second set on `.tt-dashboard`, which beat both `tokens.css` and
Branding inside the dashboard; it no longer declares any. Neither does
`public.css`, which used to repeat a few `tokens.css` defaults.

One token is declared on `.tt-dashboard` as well as `.tt-root`, in
`tokens.css`: the focus ring, `--tt-focus`. It is derived from `--tt-primary`,
and a theme sets its primary on `.tt-dashboard`, so the ring has to be
declared there too to follow it. Its colour is overridden through
`--tt-focus-color`. `BrandStyles` sets that only for a Branding primary too
light to reach 3:1 against the page.

A theme **must** declare the brand tokens (`--tt-primary`,
`--tt-primary-rgb`, `--tt-secondary`, `--tt-secondary-rgb`, and the derived
`--tt-primary-deep` / `-ink` / `-hover`). Otherwise the default design's
values from `tokens.css` show through.

Suppression lives in `BrandStyles` rather than in the theme sheet because that
method emits a dozen tokens, and which of them appear depends on which
Branding fields the operator filled in. One conditional at the single place
they are written beats out-specifying a moving target.

### Adding a theme

1. Add the value to `ThemePreference::themes()` and a label in `labels()`.
2. Add `assets/css/theme-<value>.css`. `styleFile()` derives the filename, so
   nothing else needs editing to enqueue it.
3. For a theme that is a palette only, add the value to
   `ThemePreference::sharedSheetThemes()` and keep its sheet to its
   `@font-face` rules and one token block. `assets/css/theme-shared.css` draws
   the header, the rail and the display type from those tokens, once, for
   every such theme; `DashboardShortcode` enqueues it before the theme sheet
   and the root carries `tt-theme-shared`. Federation and Kristalblauw predate
   it and keep their own rules.
4. Add the config key to the allowlist in `ConfigRestController` only if you
   introduce a new key — `tt_frontend_theme` is already allowed.

The enqueue in `DashboardShortcode` picks up the shell stylesheet as a
dependency only when the app shell actually enqueued it, so a `classic`
install never registers a handle it does not load.

A theme that wants a webfont has to load it itself.
`BrandStyles::enqueueFonts()` also returns early while a theme is active — the
club's font picker does not apply, and skipping the request spares the page a
third-party fetch whose result nothing would use.

### Bundled fonts

Webfonts are bundled under `assets/fonts/<family>/`: SIL OFL families only,
the Latin subset, only the weights a theme uses, each family's `OFL.txt` next
to its files. `assets/fonts/README.md` lists the family, version, source,
licence and payload. A theme declares its `@font-face` rules at the top of its
own sheet with `font-display: swap`, pointing at `../fonts/…`, so the font
loads only while that theme is active and the fallback stack holds the layout
while it does.

| Theme | Families |
| --- | --- |
| `federation` | Barlow Condensed (display) |
| `clubrood` | Barlow Condensed (display) |
| `oranje` | Archivo |
| `tactiekbord` | IBM Plex Sans |
| `bordeaux` | Source Serif 4 (display), Source Sans 3 (body) |
| `polder` | DM Sans |
| `leon-hutten` (Kristalblauw) | Open Sans |
| `geel-zwart` | Oswald (display) |
| `groen-wit` | Manrope |
| `paars-wit` | Outfit |
| `wapenschild`, `streepshirt` | Montserrat |
| `wedstrijddag` | Barlow Condensed (display), Montserrat (body) |
| `middernacht`, `neon`, `stadionlicht` | Exo (display), Poppins (body) |

No theme sheet imports from Google Fonts.

The value `leon-hutten` is historical: the theme is labelled Kristalblauw, and
the stored value and the sheet name stayed so that installs which chose it
keep their choice without a migration.

Federation's headings are set in the bundled Barlow Condensed. Its body text
stays in the system face: Barlow regular is not bundled. The player card
(`player-card.css`) loads its Oswald and Manrope from the same folder.

### Mapping a design palette

The fourteen palettes come from a design that names its colours after the design
system. Each sheet maps them the same way:

| Design | Product |
| --- | --- |
| `--tt-green-deep` | the header and rail (`--tt-theme-chrome`), `--tt-primary-deep` |
| `--tt-green` | `--tt-primary`, `--tt-primary-rgb`, `--tt-accent`, `--tt-link` |
| `--tt-green-soft` | `--tt-primary-ink` (primary button hover), `--tt-link-hover` |
| `--tt-gold` | `--tt-secondary`, `--tt-secondary-rgb`, `--tt-primary-hover`, the active marker |
| ink, muted, line, paper, paper-soft, status | `--tt-ink`, `--tt-muted`, `--tt-line`, `--tt-paper`, `--tt-bg-soft`, the status tokens |

The rail's hover, pressed, line and soft-ink steps are the chrome colour mixed
toward white. A design colour that misses a contrast floor in a text or focus
role keeps its value for fills, and that role takes the same hue stepped
toward the theme's deep colour (on light surfaces) or toward white (on dark
ones) until it clears. The focus ring follows `--tt-primary`; a theme does not
set `--tt-focus-ring`. `ReportBrandColour::THEME_PRIMARY` carries each theme's
primary, so reports follow the theme too.

### The secondary as text

`--tt-secondary` is a fill colour. A component that prints it as words or
numerals reads one of three tokens from `tokens.css` instead, by the surface
underneath:

| Token | Surface | Read by |
| --- | --- | --- |
| `--tt-secondary-text` | paper | the 404 emblem, the saved-view star, the podium team link on hover, the popularity percentage |
| `--tt-secondary-on-primary` | the primary band | the overview rating label, the today card's call to action, a teammate's initials |
| `--tt-secondary-on-chrome` | the header | the help icon on hover |

On the default design `--tt-secondary-text` is the shipped gold darkened to
`#84660e`, which reads at 5.4:1 on white; the gold itself stays on every fill.
A Branding secondary brings its own shade through `--tt-secondary-text-color`,
derived by `BrandStyles::accentTextColor()`: the colour itself when it reads at
4.5:1, otherwise the same hue darkened until it does. The other two follow
`--tt-secondary` unless a theme sets them. All three are declared on
`.tt-dashboard` as well as `.tt-root`, like `--tt-focus`, so they follow a
theme's secondary. A theme that declares a secondary declares at least
`--tt-secondary-text` itself.

### Status colours as text

The four status colours (`--tt-success`, `--tt-warning`, `--tt-danger`,
`--tt-info`) are fills too. Every `color:` rule that prints one reads its
`-text` variant instead, for example
`color: var(--tt-danger-text, var(--tt-danger, #b32d2e))`; fills, borders and
badges keep the status colour itself. Each `-text` clears 4.5:1 on paper, the
soft background and the status's own `-subtle` fill. On the default design it
is the status ink (`--tt-danger-ink` and its siblings). A theme that sets a
status colour sets its `-text` too. A Branding status colour feeds
`--tt-<status>-text-color`, derived by `BrandStyles::statusTextColor()`: the
colour itself when it reads at 4.5:1 on the page and on the subtle fill,
otherwise the same hue darkened until it does.

`tests/php/ThemeContrastTest.php` checks every registered theme against the
contrast floors, resolving each token as the browser does: ink, soft ink,
muted text and links on paper and the soft background, white on the primary
and its hover, ink on the button hover, the three secondary-as-text tokens,
the header's text, and the rail's text, soft text and brand mark at 4.5:1; the
focus ring, the header icon, and the rail's marker and focus ring against the
rail and the active row, at 3:1. Kristalblauw's cyan as text and on the
primary band is a named exception, held so that theme keeps its look; its
marker on the active row is one step lighter (`--tt-lh-cyan-marker`) and
passes like every other theme's.
`tests/php/ThemePreferenceTest.php` checks that every theme has its labels and
sheet, that `default` loads no sheet and stamps no class, and that bundled
fonts load from the plugin with `swap`.
