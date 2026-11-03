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

**Leon Hutten** — the Leon Hutten Talenten Academie design system, built from
the club's own crest. The crest's black banner becomes the header and the
navigation rail; its diagonal cyan-to-navy stripe closes the header and the
brand block. Buttons and links take the deep blue, cyan marks the section
you are on, and the whole theme is set in Open Sans. Corners are the squarest
of the three (3px).

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
Branding inside the dashboard; it no longer declares any. `public.css` still
repeats a few `tokens.css` declarations on `.tt-root`, at the same values, so
they change nothing.

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
3. Add the config key to the allowlist in `ConfigRestController` only if you
   introduce a new key — `tt_frontend_theme` is already allowed.

The enqueue in `DashboardShortcode` picks up the shell stylesheet as a
dependency only when the app shell actually enqueued it, so a `classic`
install never registers a handle it does not load.

A theme that wants a webfont has to load it itself.
`BrandStyles::enqueueFonts()` also returns early while a theme is active — the
club's font picker does not apply, and skipping the request spares the page a
third-party fetch whose result nothing would use. `leon-hutten` loads Open
Sans with an `@import` at the top of its own sheet.

### Known gap

Federation's intended letter is **Barlow Condensed** for display and **Barlow**
for the rest. Neither ships with the plugin, so the theme falls back to the
condensed faces already on the operating system (`Arial Narrow`, then
`Segoe UI Condensed`) and finally to the body face. Bundling the webfonts is
tracked separately — it carries a licensing and payload decision that the theme
itself does not.
