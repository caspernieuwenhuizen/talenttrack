<!-- audience: developer -->

# Mobile patterns

A small library of CSS components and JavaScript helpers for surfaces
classified `native` in `MobileSurfaceRegistry::register($slug, MobileSurfaceRegistry::CLASS_NATIVE)`.
Built on top of the #0056 mobile-first foundation (48 px tap targets,
`inputmode` attributes, `:focus-visible`, `touch-action`, safe-area
insets) and the `CLAUDE.md` §2 mobile-first authoring rule.

The library covers the four patterns that have a real consumer today.
New patterns get added when a surface needs them, not speculatively.

- [Bottom-sheet modal](#bottom-sheet-modal)
- [Fixed CTA bar](#fixed-cta-bar)
- [Segmented control](#segmented-control)
- [List item replacing table rows](#list-item-replacing-table-rows)

## Loading

The library lives in two files:

- `assets/css/mobile-patterns.css`
- `assets/js/mobile-helpers.js`

Both are enqueued conditionally by `DashboardShortcode::render()` only
on routes whose `mobile_class` is `native`. Surfaces classified
`viewable` or `desktop_only` never load them. No build step.

## Conventions on top of #0056

- **No `<table>` elements on `native` surfaces below 480 px.** Use
  `tt-mobile-list-item` instead.
- **No ad-hoc `position: fixed` elements.** The fixed-bottom CTA is
  the `tt-mobile-cta-bar` component; bottom-sheets are
  `tt-mobile-bottom-sheet`. Other fixed positioning is forbidden
  because it interacts badly with iOS Safari's URL bar collapse.

## Bottom-sheet modal

Slides up from the bottom of the viewport, drag-to-dismiss, max 80%
screen height. Use it for filters, confirmations, secondary actions,
the new-evaluation wizard's player picker.

```html
<div class="tt-mobile-bottom-sheet-backdrop"></div>
<div class="tt-mobile-bottom-sheet" role="dialog" aria-modal="true">
    <div class="tt-mobile-bottom-sheet-handle" aria-hidden="true"></div>
    <div class="tt-mobile-bottom-sheet-header">Pick a player</div>
    <div class="tt-mobile-bottom-sheet-body">
        <!-- content -->
    </div>
</div>
```

```js
var sheet = document.querySelector('.tt-mobile-bottom-sheet');
TT.Mobile.openBottomSheet(sheet);
// later:
TT.Mobile.closeBottomSheet(sheet);
```

The drag-to-dismiss listens on the `.tt-mobile-bottom-sheet-handle`
element. Pulls follow the finger. Release with > 80 px translation
(or flick velocity > 0.5 px/ms) closes; otherwise snaps back open.
Backdrop click also closes. Honours `prefers-reduced-motion`.

## Fixed CTA bar

Sticky bottom bar containing the primary submit button. Stays visible
while the user scrolls long forms. Replaces inline submit buttons that
would otherwise scroll off-screen. The immediate consumer is
`RateActorsStep`'s Submit on the new-evaluation wizard.

```html
<form>
    <!-- … long form fields … -->
    <div class="tt-mobile-cta-bar">
        <button type="submit" class="tt-btn tt-btn-primary">Save evaluations</button>
    </div>
</form>
```

The component honours `env(safe-area-inset-bottom)` so it clears the
iOS home indicator. The button minimum height is the v3.50.0 48 px
floor; font-size is 16 px to prevent iOS auto-zoom on focus.

## Segmented control

Replaces dropdowns when there are 2–4 options. Native iOS / Android
feeling segment picker. Backed by hidden radio inputs so it submits
in standard form payloads.

```html
<div class="tt-mobile-segmented-control" role="radiogroup" aria-label="Status">
    <input type="radio" id="seg-all" name="status" value="all" checked />
    <label for="seg-all">All</label>
    <input type="radio" id="seg-active" name="status" value="active" />
    <label for="seg-active">Active</label>
    <input type="radio" id="seg-archived" name="status" value="archived" />
    <label for="seg-archived">Archived</label>
</div>
```

For 5+ options, use a `<select>` instead — segmented controls become
unreadable above four segments.

## List item replacing table rows

Card-style two-line list row with chevron-right tap-to-detail
affordance. Use it on any `native` surface that would otherwise
render `<table>` rows on phones.

```html
<ul class="tt-mobile-list">
    <li>
        <a class="tt-mobile-list-item" href="/players?id=42">
            <div class="tt-mobile-list-item-leading">JD</div>
            <div class="tt-mobile-list-item-content">
                <div class="tt-mobile-list-item-primary">John Doe</div>
                <div class="tt-mobile-list-item-secondary">U16 · Striker</div>
            </div>
            <div class="tt-mobile-list-item-trailing">›</div>
        </a>
    </li>
    <!-- … -->
</ul>
```

The `*-leading` slot is for an avatar / icon / initials disc; the
`*-trailing` slot is for the chevron / status indicator. Both are
optional.

## Shared list table on a phone

Lists built on `FrontendListTable` don't need the list item above.
Below 768 px the table turns into compact rows on its own: a bold
title line, a muted subtitle line, badges at the top right, and a
label/value line for anything else. From 768 px up it is a normal
table again. The layout lives in `assets/css/components/list-table.css`,
which `DashboardShortcode::render()` loads on every view.

Each column says where it goes on a phone with a `mobile` key:

| Role | On a phone |
|---|---|
| `primary` | Title line: bold, left, no label. A second `primary` follows the first, muted, with its column label in front (`#7`). |
| `secondary` | Muted, small subtitle under the title. Several are joined with " · ". |
| `badge` | Pills and short values at the top right. A long title wraps inside its own column instead of pushing the badges onto a line of their own. A badge that only shows "—" is left out. |
| `detail` | A `LABEL — value` line. The default for a column that declares nothing. |
| `hide` | Not shown on a phone. Use it for columns that only matter at a desk: IDs, created-by, long notes. |

```php
'columns' => [
    'last_name'      => [ 'label' => __( 'Name', 'talenttrack' ), 'render' => 'html', 'value_key' => 'name_link_html', 'mobile' => 'primary' ],
    'team_name'      => [ 'label' => __( 'Team', 'talenttrack' ), 'render' => 'html', 'value_key' => 'team_link_html', 'mobile' => 'secondary' ],
    'jersey_number'  => [ 'label' => __( '#', 'talenttrack' ), 'mobile' => 'primary' ],
    'preferred_foot' => [ 'label' => __( 'Foot', 'talenttrack' ), 'render' => 'html', 'value_key' => 'preferred_foot_pill_html', 'mobile' => 'badge' ],
],
```

When a view declares no role on any column, the first column is
`primary`, the second `secondary` and the rest `detail`. Once one
column declares a role, every undeclared column is `detail`.

Other rules that hold on a phone:

- A cell with nothing in it takes no line, and neither does an empty
  row-actions cell. Row actions sit together at the right.
- When the list sets `row_url_key`, the whole row is the link (at
  least 48 px high, reachable with Tab, opened with Enter or Space,
  with a focus ring). The 48 px floor on record links inside the row
  is lifted there, because the row is already the target. A link in the
  title line does not catch the tap on a phone: tapping it opens the
  row. That lets a list lead with the player (the goals list) and still
  open the goal. The link stays reachable with Tab, and works again in
  the table from 768 px.
- The collapsed filter bar has no card around it. The card comes back
  from 768 px. Below 768 px it is at most two rows: the Filters button
  with the saved views beside it, then, only while a filter is applied,
  the filter chips with Clear as a text link at the end of the same line.
- A page head stays on one row. A secondary action that is desk work
  (such as importing from CSV) gets `'narrow_overflow' => true` in
  `pageActionsHtml()`. Below 768 px it then sits in the ⋯ menu, and from
  768 px up in the row.

## See also

- `docs/architecture-mobile-first.md` — the underlying conventions
  from #0056.
- `CLAUDE.md` §2 — the always-on mobile-first authoring rule.
