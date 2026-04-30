<!-- type: feat -->

# #0034 — Custom icon system (replace dashicons + emoji)

## Problem

Two inconsistent icon vocabularies sit side-by-side in the UI:

- **Admin menus + dashboard tiles** use WordPress **dashicons** as string identifiers (`'icon' => 'dashicons-shield'`), declared inline in [Menu.php](../src/Shared/Admin/Menu.php).
- **Frontend tiles** ([FrontendTileGrid.php:204](../src/Shared/Frontend/FrontendTileGrid.php#L204)) use **emoji characters** (`'emoji' => '🛡'`).

Result: the same concept ("teams") ships as a dashicon shield in wp-admin and an emoji shield (rendered with the user's OS font, so look varies wildly) on the frontend. The two never match, and emoji rendering on Windows / Android / iOS produces three visually distinct shields. A reference sheet of solid dark-blue silhouette icons covering the application's surfaces is the canonical look we want everywhere.

## Proposal

Ship a small set of hand-authored SVG icons stored in `assets/icons/<name>.svg`, plus a single `IconRenderer` helper that inlines them into HTML. Both admin and frontend tile/menu definitions reference icons by name (e.g. `'icon' => 'teams'`). Icons author with `fill="currentColor"` so the existing per-tile accent color (`#1d7874`, `#2271b1`, etc.) drives their color via CSS.

Decisions locked during shaping (26 April 2026):

- **Both surfaces switch.** Admin menus + dashboard tiles drop dashicons; frontend tiles drop emoji. Worth the sweep to keep the visual language consistent across wp-admin and the frontend shortcode.
- **Individual SVG files, inlined at render.** `assets/icons/<name>.svg`, read once per request and cached in a static array. No SVG sprite (a sprite needs build tooling we don't have); no `<img>` tag (loses `currentColor` re-coloring).
- **`fill="currentColor"` on every path.** CSS `color` on the wrapping element drives the icon's color. Existing tile colors keep working; admin menus inherit WP admin chrome color where appropriate.
- **Solid silhouette style, hand-authored.** Reference sheet (PNG) is a style guide, not a vector source. Each SVG is hand-written, 24×24 viewBox, single path or path group, no strokes. Recognizable beats pixel-perfect.
- **Names map by feature, not by visual.** `players`, `teams`, `evaluations`, `goals`, etc. — not `shield-with-people`. Lets us swap a glyph later without renaming call sites.
- **No `tt_lookups` table.** Icon names are internal identifiers, not user-facing values. Plain code is the right granularity.
- **Coverage = currently shipped surfaces only.** Icons in the reference sheet that map to unbuilt features (AI Insights, Progress Timeline, Notifications, Health, Tasks separate from Goals, Notes, Messages, Documents, Video Analysis, Match Analysis, Performance Tracking, Development Plans, Talent Identification, Scouting Reports, Rankings, Skills) are deferred — those features add their icon at the time they ship.
- **Dashicons are not removed from the codebase.** WP-admin core surfaces outside our control still use them. We only swap our own menu/tile arrays.

## Scope

### Asset directory

New `assets/icons/` containing one SVG per icon name. Initial set (covers every icon currently rendered by [Menu.php](../src/Shared/Admin/Menu.php) and [FrontendTileGrid.php](../src/Shared/Frontend/FrontendTileGrid.php)):

| Name | Replaces | Used by |
| --- | --- | --- |
| `dashboard` | `dashicons-groups` (root menu), tile group icon | Admin menu root, dashboard hero |
| `players` | `dashicons-admin-users`, `👥` | Players surfaces (admin + tile) |
| `teams` | `dashicons-shield`, `🛡` | Teams surfaces |
| `people` | `dashicons-groups`, `🧑‍💼` | People (staff/parents/scouts) |
| `evaluations` | `dashicons-chart-bar`, `📝` `📊` | Evaluations surfaces |
| `sessions` | `dashicons-calendar-alt`, `🗓` | Sessions / calendar surfaces |
| `goals` | `dashicons-flag`, `🎯` | Goals surfaces |
| `reports` | `dashicons-media-document` | Reports surface |
| `rate-card` | `dashicons-id-alt`, `📇`, `🪪` | Rate cards + "My card" tile |
| `compare` | `dashicons-randomize`, `⚖` | Player comparison |
| `usage-stats` | `dashicons-chart-line`, `📈` | Usage statistics |
| `settings` | `dashicons-admin-settings` | Configuration |
| `custom-fields` | `dashicons-editor-table` | Custom fields admin |
| `categories` | `dashicons-category` | Evaluation categories |
| `weights` | `dashicons-chart-pie` | Category weights |
| `roles` | `dashicons-lock` | Roles & permissions |
| `functional-roles` | `dashicons-businessperson`, `🛠` | Functional roles |
| `permission-debug` | `dashicons-search` | Permission debug |
| `docs` | `dashicons-book` | Help & docs |
| `methodology` | (frontend only) `📘` | Methodology surfaces |
| `podium` | (frontend only) `🏆` | Podium tile |
| `profile` | `👤` | My profile tile |
| `import` | `⬆` | Import players tile |
| `migrations` | `🗄` | Migrations tile (frontend admin) |
| `external-link` | `↗` | "Open wp-admin" tile (frontend admin) |

25 icons. (`migrations` + `external-link` added during the FrontendTileGrid sweep — the admin-tile group ships two more surfaces than the initial inventory captured.)

### `IconRenderer` helper

```php
namespace TT\Shared\Icons;

class IconRenderer {
    /**
     * Inline an icon SVG with optional CSS classes and inline width/height.
     * Returns empty string if the icon doesn't exist (callers gracefully render no icon).
     */
    public static function render( string $name, array $attrs = [] ): string;

    /** Whether an icon with this name exists in assets/icons/. */
    public static function exists( string $name ): bool;

    /** Path to assets/icons/. */
    public static function dir(): string;
}
```

Behavior:

- Reads `assets/icons/{$name}.svg` once per request, cached in a static array.
- Validates `$name` matches `/^[a-z0-9-]+$/` (defense against accidental path traversal — the icon set is internal, but the helper is single-source-of-truth).
- Wraps the loaded SVG with extra attributes from `$attrs` (e.g. `class`, `width`, `height`, `aria-hidden`). Default: `class="tt-icon"`, `width="24"`, `height="24"`, `aria-hidden="true"`.
- Returns empty string on cache miss + logs a single `error_log` per missing name per request.

### Menu.php update

Each tile's `'icon'` value flips from a dashicons class to an icon name resolvable by `IconRenderer`:

```php
// before
[ 'label' => __('Teams', ...), 'icon' => 'dashicons-shield', ... ],
// after
[ 'label' => __('Teams', ...), 'icon' => 'teams', ... ],
```

The tile rendering switches from `<span class="dashicons {$icon}"></span>` to `IconRenderer::render($icon, ['class' => 'tt-tile-icon-svg'])`. Stat-card icons (top of dashboard) get the same treatment.

WP `add_menu_page()`'s root menu icon argument continues to use `dashicons-groups` — that argument expects a dashicon URL or `data:` URI, and feeding it an SVG would need a separate `data:image/svg+xml;base64,...` encoding. Out of scope for v1. Header icon stays as a dashicon; tiles inside the dashboard switch.

### FrontendTileGrid.php update

Each tile's `'emoji'` key is replaced with `'icon'`:

```php
// before
[ 'label' => __('Teams', ...), 'emoji' => '🛡', 'color' => '#2271b1', ... ],
// after
[ 'label' => __('Teams', ...), 'icon'  => 'teams', 'color' => '#2271b1', ... ],
```

Render path swaps from `<?php echo esc_html($tile['emoji']); ?>` to `<?php echo IconRenderer::render($tile['icon'], [...]); ?>`. The wrapping `.tt-ftile-icon` keeps its background-color rule; the SVG inside picks up white via `color: #fff;` already on the wrapper, which `currentColor` honors automatically.

### CSS

Two tiny additions:

- `.tt-icon { display: inline-block; vertical-align: middle; }` — base reset.
- The existing `.tt-ftile-icon`, `.tt-dash-tile-icon`, `.tt-dash-stat-icon` already set `color: #fff;` on the wrapper; the inlined SVGs pick that up automatically. No per-icon styling needed.

### Loader registration

`IconRenderer` registers itself in `talenttrack.php`'s autoloader chain (PSR-4 already in place under `TT\` namespace). No new bootstrapping required.

## Out of scope (v1)

- **Dashicon replacement in WordPress core surfaces** (post-list-table action icons, etc.). Out of our control.
- **Root menu icon (`add_menu_page` 6th argument).** Stays as `dashicons-groups`. Swapping needs SVG → data-URI encoding; defer until we feel the inconsistency.
- **Icons for unshipped features** — see "Coverage" decision above.
- **Dynamic icon variants** (filled / outline / size variants per icon). Single-style ships.
- **Icon picker in admin UI** for users to choose icons on custom dashboard tiles. No surface needs this today.
- **SVG sprite optimization.** Inline-per-call works fine at our icon count and request volume.
- **Theme-specific icon sets.** Single set ships with the plugin.

## Acceptance criteria

- [ ] `assets/icons/` exists with 25 SVG files covering the table above. Each is 24×24 viewBox, uses `fill="currentColor"` on every path, has no inline `<style>` block, no `<script>`, no external references.
- [ ] `src/Shared/Icons/IconRenderer.php` exists with the API described above. Reads cached, name-validated, returns empty string + logs once per missing name.
- [ ] [Menu.php](../src/Shared/Admin/Menu.php) — every tile in the People / Performance / Analytics / Configuration / Roles / Help groups uses an `IconRenderer`-resolvable name in its `'icon'` field. Stat cards too. Root `add_menu_page` keeps `dashicons-groups` (documented as deferred).
- [ ] [FrontendTileGrid.php](../src/Shared/Frontend/FrontendTileGrid.php) — every tile uses `'icon'` instead of `'emoji'`. Render path inlines SVG.
- [ ] Visual check: every dashboard / tile surface still renders the same labeled tiles, with consistent silhouette icons in place of the prior dashicons / emoji.
- [ ] No new translatable strings introduced (icon names are internal); `.po` file unchanged.
- [ ] PHPStan passes at the existing baseline (no new errors introduced).
- [ ] PHP syntax check passes on every modified file.
- [ ] [SEQUENCE.md](../SEQUENCE.md) updated in the release commit moving #0034 to "Done".

## Notes

### Why hand-authored SVGs and not Lucide / Heroicons

Considered using Lucide (`@lucide/icons`, ISC) or Heroicons (MIT) directly. Two reasons against:

1. **Style mismatch.** Lucide is stroke-based with 2px lines; Heroicons solid is closer but still a different visual register from the dark-blue silhouettes the reference sheet specifies. Picking one and restyling half of them would produce a worse-than-handmade outcome.
2. **Footprint.** We need 23 specific glyphs, not 1300. Vendoring an icon library to use 1.7% of it is wasted bytes shipped to every WP install.

Hand-authoring 23 simple solid silhouettes is ~3 hours of work, single source of truth, no vendor licence to track, no upgrade cadence to manage. The "build on Lucide" option in the inline shaping discussion was a reasonable default but loses out once we commit to the solid-fill style.

### Why `currentColor` everywhere

The existing tile palette is meaningful — coaches recognize teams by color (`#2271b1` blue), goals by red (`#b32d2e`), etc. Hardcoding the reference's dark-blue would break that mental model. `currentColor` lets the same SVG render in any palette via CSS `color` on the wrapper.

For wp-admin chrome where icons sit on the WP dashboard's white background (rather than colored tile chips), wrapping CSS sets the icon color to match the surrounding text color (`#1d2327` typical).

### Why no path-traversal protection beyond a regex

`IconRenderer` is only ever called with literal strings authored in `Menu.php` and `FrontendTileGrid.php`. No user input flows in. The `/^[a-z0-9-]+$/` check is defense-in-depth, not a real attack-surface concern. If a future caller passes user input, the regex catches it; otherwise the regex is invariant.

### Why we keep dashicons declared next to the new icon name (not yet)

Considered shipping both `'icon' => 'teams'` and `'dashicons_fallback' => 'dashicons-shield'` for two-phase rollout. Rejected: the icon set covers every shipped surface, so there is nothing to fall back to. A missing icon is a bug, not a graceful-degradation case. The empty-string return + `error_log` from `IconRenderer` makes that bug loud and findable.

### Why the root menu icon stays as dashicons-groups

`add_menu_page()`'s 6th argument expects a dashicon class string OR a data-URI. Inlining via the helper doesn't apply — WP renders that icon itself in the admin sidebar, not us. Switching to a data-URI encoded SVG works but introduces a base64 blob in the menu registration call that's noisy to maintain. Deferred until we want the visual consistency badly enough to accept the noise.

### Touches

New:
- `assets/icons/*.svg` — 25 files.
- `src/Shared/Icons/IconRenderer.php` — the helper.

Existing:
- `src/Shared/Admin/Menu.php` — swap `'icon' => 'dashicons-X'` to icon names; update tile/stat render paths to call `IconRenderer::render()`.
- `src/Shared/Frontend/FrontendTileGrid.php` — swap `'emoji' => '...'` to `'icon' => '...'`; update render path.
- `SEQUENCE.md` — move #0034 to Done in the release commit.

### Depends on / blocks

- **No dependencies.** Cosmetic refactor; runs on top of existing menu + tile structure.
- **Does not block** any current backlog item, but pre-empts piecemeal icon decisions in #0018 (team development), #0028 (conversational goals), #0014 (player profile rebuild) — when those land they author one new SVG instead of inventing an icon system.

### Sequence position

Independent of #0025, #0018, #0026, #0027, #0029. Can ship anytime.

### Sizing

~6 hours:

| Work | Hours |
| --- | --- |
| `IconRenderer` helper + static cache + name regex | 0.5 |
| Author 25 SVGs (24×24, `currentColor`, hand-drawn solid silhouettes) | 3.0 |
| Sweep [Menu.php](../src/Shared/Admin/Menu.php) — swap dashicons + update render paths | 1.0 |
| Sweep [FrontendTileGrid.php](../src/Shared/Frontend/FrontendTileGrid.php) — swap emoji + update render paths | 0.75 |
| Visual check across admin dashboard + every frontend tile group | 0.5 |
| PHPStan + syntax check + commit | 0.25 |
| **Total** | **~6h** |
