# TalentTrack v3.110.169 — Row-link standard for `FrontendListTable`: whole-row click → detail page (closes #758)

## Pilot ask

Chat 2026-05-18:

> The list of PDP files does not have an option to actually open the file. Suggest a best way that would work both on desktop as well as mobile. Would it make sense to make the whole row the link to detail page?

Yes — and worth establishing as a **standard** for every list view in the app, not a one-off for PDP. This ship lands the standard plus the first consumer (PDP files); other list views adopt the pattern in follow-up ships.

## The standard

A list preset can opt in by declaring:

```php
FrontendListTable::render( [
    // …
    'row_url_key' => 'detail_url',
] );
```

`row_url_key` names a key on every REST row that holds the row's detail-page URL. By convention, REST controllers prepare it as:

```php
$detail_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
    [ 'tt_view' => 'pdp', 'id' => $file_id ],
    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
) );
// …
return [ /* …other fields… */, 'detail_url' => $detail_url ];
```

The `BackLink::appendTo()` wrap means the destination view automatically renders the contextual "← Back to …" pill from CLAUDE.md §5 — the user can always retrace their step back to the list.

## What the JS does

`assets/js/components/frontend-list-table.js`:

**`renderRow()`** — when `config.row_url_key` is set and the row carries that key, the `<tr>` is stamped with:
- `data-row-href="<the URL>"`
- `class="is-row-link"`
- `role="link"`
- `tabindex="0"`

**`bindRowLinks(root)`** (new) — delegated handlers on the tbody:

| Interaction                    | Behaviour                                       |
| ---                            | ---                                             |
| Primary-button click           | `window.location.href = href`                   |
| Middle-click / auxclick btn=1  | `window.open(href, '_blank', 'noopener')`       |
| Cmd-click / Ctrl-click         | New tab                                         |
| Keyboard Enter / Space         | Activate (Cmd/Ctrl modifier → new tab)          |
| Click on interactive descendant| **Skip** — let the link / button do its thing  |
| Click during text selection    | **Skip** — selection > navigation               |

**Interactive descendants** are detected by walking up from the click target to the row, looking for `A`, `BUTTON`, `INPUT`, `SELECT`, `TEXTAREA`, `LABEL`, or `[role="button"]`. If any of those is on the ancestor path, the row navigation does NOT fire — so per-column cross-entity links (player name → player detail, team name → team detail) keep working exactly as before. The whole-row click only fires on "dead space" cells (status pill, ack icons, updated-at column, padding).

**Text selection** is preserved by checking `window.getSelection()` before navigating. If the user has actively selected text inside the row (e.g. copying a player name), the click on mouseup is suppressed.

## What the CSS does

`assets/css/frontend-admin.css`:

```css
.tt-dashboard .tt-list-table-table tbody tr.is-row-link { cursor: pointer; }
.tt-dashboard .tt-list-table-table tbody tr.is-row-link:hover { background: var(--tt-bg-soft); }
.tt-dashboard .tt-list-table-table tbody tr.is-row-link:focus-visible {
    outline: 2px solid var(--tt-primary);
    outline-offset: -2px;
    background: var(--tt-bg-soft);
}
```

Three signals — cursor, hover, focus — tell the user the row is clickable. The `:focus-visible` outline is keyboard-only (no mouse-click outline noise), and `outline-offset: -2px` keeps the ring inside the cell border so it doesn't bleed into adjacent rows.

## What the PHP does

`src/Shared/Frontend/Components/FrontendListTable.php`:

```php
$row_url_key = isset( $config['row_url_key'] )
    ? sanitize_key( (string) $config['row_url_key'] )
    : '';
// …
$js_config = [
    // …
    'row_url_key' => $row_url_key,
];
```

The PHP shell passes the config key through to the JS hydrator. No server-side row markup change — the PHP shell renders an empty `<tbody>` placeholder; the first row payload comes from the JS hydrator's REST fetch on hydrate.

## First consumer — PDP files list

`src/Modules/Pdp/Frontend/FrontendPdpManageView.php` line 175:

```php
'row_url_key' => 'detail_url',
```

`src/Modules/Pdp/Rest/PdpFilesRestController.php::format_list_row()` already emitted `detail_url` (since v3.110.110, when it was added for an earlier feature). No REST controller change needed for PDP — the field shape was already there.

## Try it

`?tt_view=pdp` on the pilot install:

- Click anywhere on a row (status pill, ack icons, the updated-at column) → lands on the PDP file detail page with a "← Back to Player Development Plans" pill above the breadcrumb.
- Click the player name → still routes to the player detail (cross-entity link cell preserved).
- Click the team name → still routes to the team detail.
- Middle-click anywhere on dead space → new tab with the PDP detail.
- Cmd-click (Mac) / Ctrl-click (Windows) → new tab.
- Tab to the row, press Enter → navigates.

## Follow-up

The pilot will roll the standard out to other list views themselves:
- Players list (`?tt_view=players`)
- Teams list (`?tt_view=teams`)
- Evaluations list (`?tt_view=evaluations`)
- Goals list (`?tt_view=goals`)
- Activities list (`?tt_view=activities`)
- People list (`?tt_view=people`)

For each, the recipe is two lines:
1. In the REST controller's `format_list_row()` (or equivalent), emit `'detail_url' => BackLink::appendTo( … )`.
2. In the view's `FrontendListTable::render()` call, add `'row_url_key' => 'detail_url'`.

## Files touched

- `assets/js/components/frontend-list-table.js` — `renderRow()` stamps `<tr>`; new `bindRowLinks()` function; `hydrate()` calls it.
- `src/Shared/Frontend/Components/FrontendListTable.php` — accept `row_url_key` config, pass through to JS.
- `src/Modules/Pdp/Frontend/FrontendPdpManageView.php` — `'row_url_key' => 'detail_url'` on the PDP list preset.
- `assets/css/frontend-admin.css` — `tr.is-row-link` rules.
- `talenttrack.php` — version 3.110.167 → 3.110.168.
- `readme.txt` — Stable tag + changelog entry.

No DB migration, no REST shape change (PDP controller's `detail_url` field already existed), no new i18n strings, no auth change.
