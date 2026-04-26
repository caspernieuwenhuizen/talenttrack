<!-- audience: dev -->

# Theme integration

How a host theme overrides TalentTrack's frontend look without forking. Aimed at theme developers and site builders comfortable in CSS.

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

Components (`tt-btn`, `tt-card`, `tt-input`, `tt-attendance`, …) reference these tokens. The list above is the v3.x stable contract — adding tokens is allowed in minor releases; renaming or removing requires a major release note.

## The `body.tt-theme-inherit` switch (#0023)

By default the plugin defines its own token values. When the body element carries the class `tt-theme-inherit`, TalentTrack treats the host theme as the source of truth and skips its own `:root` declarations — the theme's tokens win.

To opt in from a theme:

```php
// functions.php
add_filter( 'body_class', function ( $classes ) {
    if ( has_shortcode( get_post()->post_content ?? '', 'talenttrack_dashboard' ) ) {
        $classes[] = 'tt-theme-inherit';
    }
    return $classes;
} );
```

Then declare the tokens you want to override on `:root` in your theme's stylesheet. Tokens you don't override fall back to TalentTrack's defaults.

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

If token-level overrides aren't enough, your theme can dequeue the plugin's stylesheet and ship a replacement:

```php
add_action( 'wp_enqueue_scripts', function () {
    wp_dequeue_style( 'tt-frontend' );
    wp_dequeue_style( 'tt-frontend-mobile' );
    wp_enqueue_style( 'mytheme-tt-replacement', get_stylesheet_directory_uri() . '/talenttrack-overrides.css' );
}, 20 );
```

This is escape-hatch territory — you're now responsible for keeping up with component additions. Token overrides are the supported path.

## Print styles

The "Print report" affordance on the player overview opens the page in a new tab with `?tt_print=<player_id>`. The plugin ships a print-tuned stylesheet that strips chrome and sets a paper-friendly layout. Themes that want to customise the print view can register their own stylesheet against `media="print"` after the plugin's enqueues.

## Versioning expectations

- Tokens listed above are stable across v3.x.
- Component classnames are stable across v3.x; new variants may be added.
- Internal classnames (`tt-overview-grid`, `tt-form-msg`, …) are not part of the public theme contract; expect churn.
- Keep an eye on `CHANGES.md` for any v4.x breaking changes to this contract.
