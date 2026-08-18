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
| Below 768px | The slide-out menu, **plus** a bar along the bottom |

Those are one navigation in different clothes, not four different menus — same
entries, same order, same permissions.

The sidebar is **dark**, as in the design, against the light content area — the
selected entry carries a stripe in your academy's brand colour. The academy name
stays in the top bar and your account menu stays in its corner, so nothing moves
about signing out.

The app shell uses the **full width of the window**, with the sidebar against
the left edge — it is a workspace, not a centred document. The header stays put
while you scroll, so search, notifications and your account stay reachable from
anywhere on a long page. Classic keeps the centred, width-capped reading layout
it has always had.

### The bottom bar on phones

Four destinations plus **More**, which opens the full tile overview. It sits in
the thumb zone and clears the iOS home indicator, so what you reach for at the
side of a pitch is one tap away.

Which four you get is derived from your role: the first four *everyday* sections
you can access, in the standard group order. Setup and configuration sections
are never placed there — they are not what anyone reaches for one-handed.

The slide-out menu is still there and still carries everything, so the bar never
hides anything. It is a shortcut, not a filter.

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

## Search — jump to anything

The search box in the top bar, or **⌘K** / **Ctrl+K**, opens a jump-to overlay.
It finds sections, players, teams and activities, and opens showing the sections
you can reach — so it works as a launcher before you type anything.

Arrow keys move, Enter opens, Escape closes. The shortcut is only ever a
shortcut: the search box does the same thing, so nothing depends on knowing it.

You only see records you already have access to. Search does not widen what you
can reach; it just makes reaching it faster.

## Preview — look without leaving

On a laptop, following a link to a player, team or activity from somewhere else
opens a **preview panel** beside what you were reading instead of navigating
away. Check the detail, then either **Open** it properly or **Close** the panel
and carry on exactly where you were, scroll position intact.

Previews are read-only. On phones and tablets the link simply navigates, as it
always did — a panel covering most of a phone screen is just a page with extra
steps.

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
`FrontendAppBottomBar` is that second presentation.

### `RecordSpine` — pinned record identity

`\TT\Shared\Frontend\Components\RecordSpine` renders the slim strip that stays at
the top of a record page while its full hero scrolls away. Adopted by team,
activity and staff detail; player detail has its own equivalent from #2457.

```php
RecordSpine::render( [
    'name'      => 'Ajax JO15-1',   // required; blank renders nothing
    'meta'      => 'JO15',          // one line of context
    'status'    => 'active',        // drives the avatar ring
    'photo_url' => '',              // falls back to initials
    'tabs'      => [],              // optional; see below
] );
```

**It composes; it does not decide** (§4). Which chips a viewer may see, derived
status, permission filtering — all of that stays in the calling view and the
domain layer. If the component ever needs a repository, the design has gone
wrong.

It emits nothing under `classic`, so adopting it cannot change that shell.

**On tabs.** The `tabs` key is supported and deliberately unused by the initial
adopters. Team detail's sections are individually toggleable per user
(`TeamDetailSections::forUser()`), so converting them to tabs would quietly
override a feature people already rely on. Tabs suit surfaces whose sections are
genuinely alternative views of one record — a per-surface product call, not
something to impose from a shared component.

### The bottom bar's slots

`\TT\Shared\Frontend\Components\FrontendAppBottomBar::slots()` returns the four
destinations, config first and derived default second:

1. **Configured** — club-scoped `tt_config` key `tt_shell_mobile_slots`, a JSON
   object of `persona key => [ slug, … ]`. A `*` key applies to any persona with
   no entry of its own. Absent or empty means "derive", which is the ship state.
2. **Derived** — the first four `kind: 'work'` tiles from
   `FrontendAppNav::groups()`, i.e. already capability-filtered, persona-labelled
   and in `groupOrder()` sequence. Setup tiles are excluded.

A configured slug that no longer exists, is hidden for the persona, or fails the
capability check is **skipped**, and the derived default backfills the gap — so a
stale config degrades to a sensible bar rather than a broken or empty one. There
is deliberately no operator picker yet; the key is readable and writable through
the config layer, and the default is good enough to ship without one.

**Deciding the slots from real usage.** `tt_usage_events` (migration 0011)
already records `event_type = 'frontend_view'` with the view slug in
`event_target`, per `user_id`, `club_id`-scoped, 90-day retention — nothing new
needs instrumenting. After the shell has been live on mobile for a few weeks,
read top slugs per persona and write `tt_shell_mobile_slots`. Viewport is not
recorded; if the persona split proves too coarse, adding a viewport bucket to the
event is a separate small change.

Active state matches the slot's own view **and** its record views — `players`
lights up for `player` — because a bar that goes blank the moment you open a
record stops orienting you.

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
