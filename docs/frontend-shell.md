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

**Two screens hide the bar, on purpose.** Running a live match and running a
training session both put their own controls along the bottom of the screen,
because that is where your thumb is when you are holding the phone one-handed at
the side of a pitch. Showing the navigation bar underneath those controls would
cost about 190px on a 640px screen — roughly half of what you were reading.

On those two screens the bar steps aside. The breadcrumb trail at the top is
still your way out, the slide-out menu is untouched, and on a tablet or laptop
nothing changes at all — the bar only exists on phone-width screens in the first
place.

*Developers:* the list is `config/focus_surfaces.php`, one slug per entry with
the reason written down, read through `FocusSurfaces::claims()`. Before adding a
slug, check the view renders the breadcrumb chain on every path — that chain is
what makes suppressing the bar safe rather than a dead end.

### The academy crest goes home

The crest and academy name in the top-left corner are a link back to the
dashboard, in both layouts. In the app shell the crest at the head of the
sidebar does the same, including when the sidebar is collapsed to icons. If no
logo is configured, the gold initials mark stands in and behaves identically.

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

Type straight into the search box in the top bar — no window opens first.
Matching sections, players, teams and activities appear underneath as you type.
Clicking into the box shows the sections you can reach before you type anything,
so it works as a launcher too.

Arrow keys move through the results, Enter opens the highlighted one, and Escape
closes the list while leaving your cursor in the box. **⌘K** / **Ctrl+K** jumps
to the box; the shortcut is only ever a shortcut, since the box is on screen and
clickable without it.

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

## Moving between screens

Every click is a normal page load — the back button, bookmarks, refresh and
open-in-new-tab all behave exactly as you expect. Two things make that feel
quicker than it used to. Hovering a link starts loading that page in the
background, so the click usually lands on something already fetched; and where
the browser supports it, screens cross-fade instead of blanking, with the
sidebar and header holding still.

Prefetching stands down when your device asks for reduced data or is on a slow
connection, and it never runs ahead of a link that changes something. A page
fetched in advance is **not** counted as a visit in the usage statistics.

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

The identity strip emits nothing under `classic`, so adopting it cannot change
that shell. In-page tabs are the one exception — see below.

**On tabs.** Tabs suit surfaces whose sections are genuinely alternative views of
one record — a per-surface product call, not something to impose from a shared
component. Team detail's sections are individually toggleable per user
(`TeamDetailSections::forUser()`), so converting them to tabs would quietly
override a feature people already rely on.

There are two kinds, chosen by which key a tab entry carries:

| Key | Renders | Behaviour |
| --- | --- | --- |
| `url` | `<a href>` | navigating — the destination is a page load |
| `panel` | `<button role="tab">` | in-page — switches a panel already on the page |

A strip is one kind or the other. The first entry carrying `panel` makes the
whole strip in-page, and any remaining `url` entries are skipped. They do not
mix because the keyboard contracts differ: arrow keys move between in-page tabs
and select as they go, while a row of links is walked with Tab.

**In-page tabs render under `classic` too.** The identity strip is shell chrome
and stays app-only; a section switcher is not — it is the only route to half a
view's content, so a surface whose sections vanished with the shell would be
broken rather than degraded. `FrontendTrainingPlansView` shows what the old
behaviour cost: it carries a duplicate Edit / Done header action purely because
its navigating tabs disappear under `classic`.

**Panels belong to the caller.** The component does not create them. Render each
panel yourself and pass its element id; the tab's own id is derived from it, so
you only ever name one of the pair.

```php
RecordSpine::render( [
    'name' => $player->name,
    'tabs' => [
        [ 'label' => 'Squad', 'panel' => 'tt-panel-squad', 'active' => true ],
        [ 'label' => 'Pitch', 'panel' => 'tt-panel-pitch' ],
    ],
] );
```

```html
<div id="tt-panel-squad" role="tabpanel" aria-labelledby="tt-tab-tt-panel-squad">…</div>
<div id="tt-panel-pitch" role="tabpanel" aria-labelledby="tt-tab-tt-panel-pitch" hidden>…</div>
```

The markup is already correct without JavaScript: the active tab is selected and
its panel is the one not carrying `hidden`. `record-spine-tabs.js` adds the
switching and the arrow-key handling on top, so a page that fails to load it
shows the default panel rather than nothing.

### The bottom bar's slots

`\TT\Shared\Frontend\Components\FrontendAppBottomBar::slots()` returns the four
destinations, in three passes:

1. **Configured** — club-scoped `tt_config` key `tt_shell_mobile_slots`, a JSON
   object of `persona key => [ slug, … ]`. A `*` key applies to any persona with
   no entry of its own. Absent or empty falls through.
2. **Shipped per-persona default** — `FrontendAppBottomBar::DEFAULT_SLOTS` (#2810).
3. **Derived** — the first four `kind: 'work'` tiles from
   `FrontendAppNav::groups()`, i.e. already capability-filtered, persona-labelled
   and in `groupOrder()` sequence. Setup tiles are excluded.

| persona | 1 | 2 | 3 | 4 |
| --- | --- | --- | --- | --- |
| head_coach | activities | players | teams | my-tasks |
| assistant_coach | activities | players | teams | my-tasks |
| scout | onboarding-pipeline | scouting-visits | players | my-tasks |
| head_of_development | my-tasks | players | trials | evaluations |
| team_manager | activities | teams | players | my-tasks |
| player | my-tasks | my-journey | overview | my-team |
| parent | my-activities | overview | my-evaluations | my-pdp |
| academy_admin | — no bar — |

Each pass only fills what the previous one left, so a stale or partial config
degrades rather than emptying the bar. A configured slug that no longer exists,
is hidden for the persona, or fails the capability check is **skipped**.

**The academy admin gets no bar.** Not a bar of setup tiles, and not the derived
default — the bar excludes setup surfaces by design and setup is that persona's
entire dashboard, so any bar rendered for them is either misleading or a
different thing wearing the same chrome. `render()` emits nothing at all rather
than hiding it in CSS: hidden markup still ships and still holds a place in the
keyboard tab order. Note this is a different state from "no slots configured",
which still falls through to the derived default.

**`readonly_observer` has no shipped default**, deliberately: it has no numbered
persona actions to trace slots to, so it derives rather than guesses.

**Every slot must be `native`, `viewable` or `read_only`.** A `desktop_only` slug
a thumb-tap away is the desktop-prompt page a thumb-tap away — navigation that is
actually a wall. `ThumbBarSlotsTest` asserts this against
`config/mobile_surfaces.php`, which matters because reclassifying a surface is a
one-line edit in a different file with nothing else connecting it to the bar.

There is deliberately no operator picker yet; the key is readable and writable
through the config layer.

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
