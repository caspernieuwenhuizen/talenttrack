<!-- audience: admin, developer -->

# Navigation layout (the frontend shell)

TalentTrack renders its frontend inside one of two application shells. The
choice is a setting, not a release: the academy picks a default and every
person can pick their own.

## The two layouts

**Classic** — the chrome TalentTrack has always had. A brand header, a
breadcrumb, and the page. There is no navigation bar; you return to the tile
overview to move between sections. This is the default, and it stays available
indefinitely.

**App shell** — navigation is always on screen:

| Screen width | What you see |
| --- | --- |
| 1280px and up | A grouped sidebar down the left |
| 1024px and up | The same sidebar, collapsible to a strip of icons |
| Below 1024px | A slide-out menu behind the ☰ button in the header |

Those are one navigation in different clothes, not four different menus — same
entries, same order, same permissions.

## Choosing a layout

**As an academy admin**, set the default under *Configuration → General →
Navigation layout*. It applies to everyone who has not chosen their own.

**As any user**, set your own under *My settings → Layout*. The options are:

- *Use the academy default* — follow whatever the academy has set, including
  when the admin changes it later. This is the default.
- *Classic* or *App shell* — pin that layout for yourself regardless of the
  academy default.

Changing either takes effect on the next page load.

### Why both levels exist

A layout change mid-season is disruptive if it is forced. The two levels mean an
academy can switch its default when it is ready, an individual coach can stay on
Classic until the season ends, and someone who wants the sidebar today does not
have to wait for the academy to flip it.

## What is in the navigation

Whatever you can already reach. The entries come from the same registry that
builds the tile overview, so:

- You see only the sections your role has access to.
- Section names follow your role — the same destination can be labelled
  differently for a coach and for a parent, exactly as on the tiles.
- Groups appear in the standard order: Performance, People, Planning & tactics,
  Development, Reference.

Nothing is reachable through the navigation that was not reachable before, and
nothing that was reachable has been hidden.

## Notes

- The sidebar's collapsed/expanded state is remembered in your browser, per
  device.
- With JavaScript disabled the navigation is still present and every entry is a
  normal link; only the slide-out toggle and the collapse button are inert.

---

## For developers

### Resolution

`\TT\Shared\Frontend\ShellPreference` is the only place the shell is decided:

```php
ShellPreference::resolve( $user_id );   // 'classic' | 'app'
ShellPreference::isApp( $user_id );     // bool
ShellPreference::rootClass( $user_id ); // 'tt-shell-classic' | 'tt-shell-app'
```

Order is **user override → club default → `classic`**. A stored value that is
not a known shell falls through to the next step rather than rendering nothing,
so a hand-edited config can never produce a chrome-less page.

| Level | Where | Values |
| --- | --- | --- |
| Club default | `tt_config` key `tt_frontend_shell`, club-scoped | `classic`, `app` |
| User override | user meta `tt_frontend_shell` | `classic`, `app`, or absent (= inherit) |

The club default lives in `tt_config` rather than `wp_options` per CLAUDE.md §4
— `wp_options` is global to the WP install and would leak across tenants. The
user override is a personal preference rather than tenant config, so user meta
is the right home; `inherit` deletes the meta rather than storing a resolved
value, which is what lets a later club change reach the user.

### Consuming it

- **PHP** — call `ShellPreference::isApp()`. Never read the config key directly;
  one resolver is what makes the SaaS migration a single replacement rather than
  a search across views.
- **CSS** — the resolved value is stamped on the dashboard wrapper as
  `.tt-shell-classic` / `.tt-shell-app`. Scope shell-specific rules under it.
- **JS** — read `window.TT.shell`. Per CLAUDE.md §4 the front end reads config
  from `window.TT.*`, never from PHP-rendered HTML.
- **REST** — `tt_frontend_shell` is writable through `POST /talenttrack/v1/config`
  like any other allowlisted config key, gated by the existing area-edit
  capability check.

### The navigation

`\TT\Shared\Frontend\Components\FrontendAppNav` renders it, reading
`TileRegistry::tilesForUserGrouped()` — which already applies the capability
check, the per-persona label map (including the `__hidden__` marker), module and
feature gating, and the `groupOrder()` sequence. **There is no second navigation
registry.** Adding a tile adds a nav entry.

`FrontendAppNav::groups()` is public and separate from `render()` so a second
presentation can consume the same resolved list unchanged.

Per CLAUDE.md §5b this is the *one* primary navigation and the shell renders it
once. A view must never emit module-level navigation of its own — see
[`back-navigation.md`](back-navigation.md).

### Keeping `classic` a real rollback

Under `classic` the shell wrapper, the nav, the stylesheet and the behaviour
script are all absent — not hidden. Nothing is in the DOM for a view to come to
depend on, which is what makes flipping the setting back a true rollback rather
than a visual approximation of one. **Do not write a view that requires the app
shell's DOM.**

### Layout contract for views

The app shell gives the content column `min-width: 0` inside a CSS grid, so wide
content behaves the same as it does today: a table wider than its container must
scroll inside its own `overflow-x: auto` wrapper rather than widen the page. That
was already the rule; the sidebar just makes breaking it visible sooner.

`position: fixed` elements — modals, bottom sheets, drag layers — are unaffected:
they resolve against the viewport and should continue to span it, including over
the sidebar. `position: sticky` inside the content column also behaves as before,
because the column is a normal-flow block in the page's scroll context.
