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

1. `assets/css/tokens.css` declares the neutral tokens on `.tt-root` (the body
   class).
2. The theme sheet redeclares a subset on `.tt-dashboard`, a closer ancestor,
   so every surface inside the dashboard inherits the themed value with no
   per-view rule. This is the same cascade argument `tokens.css` itself makes
   about `BrandStyles`.
3. Anything a token cannot express — the app shell's navy rail, which cannot
   come from re-pointing `--tt-paper` without darkening every card — is a small
   set of explicit rules against the shell's existing class vocabulary.

A theme **must** declare the brand tokens (`--tt-primary`,
`--tt-primary-rgb`, `--tt-secondary`, `--tt-secondary-rgb`, and the derived
`--tt-primary-deep` / `-ink` / `-hover`). `BrandStyles::injectVars()` returns
early while a theme is active, so nothing else supplies them — and roughly
forty surfaces read `var(--tt-primary, #0b3d2e)` with the shipped green as a
hardcoded fallback, which is what those rules would paint if the theme stayed
silent.

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

### Known gap

Federation's intended letter is **Barlow Condensed** for display and **Barlow**
for the rest. Neither ships with the plugin, so the theme falls back to the
condensed faces already on the operating system (`Arial Narrow`, then
`Segoe UI Condensed`) and finally to the body face. Bundling the webfonts is
tracked separately — it carries a licensing and payload decision that the theme
itself does not.
