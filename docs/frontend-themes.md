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

## Your academy colours still apply

Your club colours are not a theme's business. The two colours you set in
**Appearance** are yours, and both themes keep using them for the brand
header, links and buttons. A theme owns the greys, the surfaces, the status
colours and the type around them.

Federation claims exactly one colour of its own: the gold that marks which
section you are in. That is deliberate — it has to stay legible whatever your
club colours are, including when they are close to navy.

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

A theme must **not** declare `--tt-primary` / `--tt-secondary`. Those are
emitted by `BrandStyles` and re-themed by the operator's colour editor.

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
