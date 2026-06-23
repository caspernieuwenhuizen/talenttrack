<!-- audience: dev -->

# Theme integration

How TalentTrack relates to the active WordPress theme. Aimed at theme developers and site builders comfortable in CSS.

## Total visual isolation (canvas mode)

As of v4.45.26 (#1728) the dashboard renders in **full canvas mode with no opt-out**. The plugin takes over the page template for any singular post that embeds the `[talenttrack_dashboard]` shortcode and, before `wp_head()` prints, **dequeues every stylesheet that isn't TalentTrack's own**. The active theme's `style.css` — and any other plugin's CSS — never reaches the document, so it cannot override TalentTrack's palette, typography, or layout.

The only non-TalentTrack stylesheets that survive in canvas mode are:

- the WP admin bar (`admin-bar`, `dashicons`) so staff keep their toolbar;
- operator-chosen Google Fonts (`tt-brand-fonts` and any `fonts.googleapis.com` / `fonts.gstatic.com` request).

There is no `tt-theme-inherit` switch any more, and a host theme cannot style TalentTrack's buttons, links, or headings — that is by design. To re-brand TalentTrack, use **Configuration → Appearance** (palette, logo, fonts) or **Custom CSS**, not the theme.

## Design tokens

TalentTrack's frontend stylesheets define a shared set of CSS custom properties on `:root`:

```css
:root {
    --tt-color-primary: #1a4a8a;
    --tt-color-secondary: #5b6470;
    --tt-color-success: #00a32a;
    --tt-color-warning: #dba617;
    --tt-color-danger: #b32d2e;
    --tt-color-info: #2271b1;
    --tt-bg-card: #ffffff;
    --tt-bg-page: #f6f7f9;
    --tt-text: #1a1d21;
    --tt-text-muted: #5b6470;
    --tt-border: #e0e2e7;
    --tt-radius-sm: 4px;
    --tt-radius-md: 6px;
    --tt-radius-lg: 8px;
    --tt-sp-1: 4px;
    --tt-sp-2: 8px;
    --tt-sp-3: 12px;
    --tt-sp-4: 16px;
}
```

Components (`tt-btn`, `tt-card`, `tt-input`, `tt-attendance`, …) reference these tokens. The list above is the v3.x stable contract — adding tokens is allowed in minor releases; renaming or removing requires a major release note. Operators override the palette through **Configuration → Appearance**, which injects the chosen colours as `:root` custom properties; the theme has no say.

## Component classnames

The frontend uses these stable class roots — change at your own risk if you're styling them directly:

| Class                          | Use                                                  |
| ---                            | ---                                                  |
| `.tt-btn`, `.tt-btn-primary`   | Action buttons                                       |
| `.tt-input`, `.tt-textarea`    | Form fields                                          |
| `.tt-card`                     | Tile / panel surface                                 |
| `.tt-table`                    | Data tables                                          |
| `.tt-tabs`, `.tt-tab`          | Sub-navigation in player / coach views               |
| `.tt-rating-pill`              | Compact rating badge                                 |
| `.tt-attendance-row`, `.tt-attendance-row--guest` | Session attendance rows           |
| `.tt-guest-badge`              | Marker on guest attendance rows (#0026)              |
| `.tt-radar-wrap`               | Container for the radar chart SVG                    |

## When a deeper override is needed

Because canvas mode strips non-TalentTrack stylesheets, a theme cannot ship overrides for the dashboard — anything the theme enqueues is dequeued before it paints. The supported path for per-club styling beyond the palette/font config is **Configuration → Custom CSS**, which is enqueued from within TalentTrack and therefore survives isolation. Token-level overrides via the Appearance palette remain the lightest-touch option.

## Print styles

The "Print report" affordance on the player overview opens the page in a new tab with `?tt_print=<player_id>`. The plugin ships a print-tuned stylesheet that strips chrome and sets a paper-friendly layout. Themes that want to customise the print view can register their own stylesheet against `media="print"` after the plugin's enqueues.

## Versioning expectations

- Tokens listed above are stable across v3.x.
- Component classnames are stable across v3.x; new variants may be added.
- Internal classnames (`tt-overview-grid`, `tt-form-msg`, …) are not part of the public theme contract; expect churn.
- Keep an eye on `CHANGES.md` for any v4.x breaking changes to this contract.
