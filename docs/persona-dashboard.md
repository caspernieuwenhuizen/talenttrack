<!-- audience: user, admin -->

# Persona dashboards

Every academy user lands on a dashboard built for their persona — Player, Parent, Head Coach, Assistant Coach, Team Manager, Head of Development, Scout, Academy Admin, or Read-only Observer. The dashboard answers, in the first viewport, the question that matters most for that user: *Where am I now? What's new? What's next?*

This page covers what you can expect to see, how to switch between personas if you wear two hats, and (for academy admins) how each persona's defaults are organised.

## What lands on each persona

| Persona | Hero | Primary work band | What stays after |
| - | - | - | - |
| **Player** | FIFA-style rate card with your overall + position | Note from your coach (when there is one) | My journey, My card, My team, My evaluations, My activities, My goals, My PDP, My profile |
| **Parent** | Child switcher + "since you last visited" recap | PDP awaiting your acknowledgement | My child's card, evaluations, activities, PDP |
| **Head coach / Assistant coach** | Today / Up next with attendance + evaluation buttons | Workflow tasks list + recent evaluations rail | Activities, evaluations, goals, players, teams, PDP, methodology, my tasks |
| **Team manager** | Today / Up next | (none by default) | Activities, my teams, players, my tasks |
| **Head of Development** | KPI strip (active players, evaluations this month, attendance %, open trials, PDP verdicts pending, goal completion) | Trials needing decision (table) | Trials, PDP, players, methodology, tasks dashboard, evaluations, rate cards, compare |
| **Scout** | Assigned players grid (your primary work surface) | Recent reports | My reports, my assigned players |
| **Academy admin** | System health strip (backup, invitations, license, modules) | Recent audit events (table) | Configuration, authorization, usage stats, audit log, invitations, migrations, help, methodology |
| **Read-only observer** | KPI strip in read-only mode | (none) | (no edit actions; methodology + KPIs only) |

## Switching personas

If you have more than one role at the academy — say you're a Head Coach and a parent of a player — a small **Viewing as** pill bar appears at the top of your dashboard. Tap a different pill to switch the landing template. The choice persists across sessions; you can always switch back from the same pill bar.

The pill only shows up when more than one persona resolves for your account. Most users see a single landing and don't need to switch.

## What's a "widget"?

Each block on the dashboard is a widget. There are 14 widget types:

| Widget | Used for |
| - | - |
| Navigation tile | Linking to a section (e.g., My evaluations) |
| KPI card | A single number with a trend arrow + sparkline |
| KPI strip | A row of 4–6 KPI cards (HoD / Observer hero) |
| Action card | A single CTA button (+ Evaluation, + Goal, etc.) |
| Quick actions panel | A 2×2 grid of action cards (Coach side panel) |
| Info card | A read-only summary block (coach nudge, pending PDP ack, license status) |
| Task list panel | Preview of your open workflow tasks |
| Data table | Compact table with up to 5 rows + see-all link |
| Mini player list | Horizontal rail of player cards (podium, top movers, recent evaluations) |
| Rate card hero | Player landing's identity hero |
| Today / Up next hero | Coach landing hero with action buttons |
| Child switcher with recap | Parent landing's hero with "since you last visited" |
| System health strip | Admin hero (backup / invitations / license / modules) |
| Assigned players grid | Scout landing's primary surface |

Widgets come in four sizes — Small, Medium, Large, Extra-large — and snap to a 12-column grid on desktop, 6 columns on tablet, and a single mobile-priority-sorted column on phone.

## Customising a persona's dashboard

Open *TalentTrack → Dashboard layouts* in wp-admin. The page is gated by the `tt_edit_persona_templates` capability — granted to administrators and Academy Admins by default, opt-in for Head of Development.

The editor has three panes:

- **Left — Palette.** Two tabs: *Widgets* (the 14 shipped widget types) and *KPIs* (the 25 KPIs grouped by Academy / Coach / Player & parent). Drag a palette item onto the canvas, or focus it and press Enter.
- **Centre — Canvas.** A 12-column bento grid plus a hero band and a task band above it. Each placed widget shows its label, data source, size badge, and a remove (×) button. Click a widget to select it; click again or use Tab/Enter to move focus.
- **Right — Properties.** When a widget is selected, you can change its size (S/M/L/XL — only sizes the widget supports are enabled), its data source (KPI picker for KPI cards, free-text key for tiles), the persona-label override, mobile priority, and whether the widget is shown on mobile.

Top-bar controls:

- **Persona dropdown** — switches the canvas to another persona's layout. If you have unsaved changes you'll be asked to confirm.
- **Undo / Redo** — up to 50 steps. `Ctrl+Z` / `Ctrl+Shift+Z` work too.
- **Mobile preview** — collapses the canvas into a 360 px frame in priority order so you can see how the layout stacks on a phone.
- **Reset to default** — replaces the layout with the TalentTrack ship default. Confirmation required.
- **Save draft** — persists your in-flight layout without making it live.
- **Publish** — promotes the current layout to live for everyone matching that persona on their next page load. The confirmation modal shows the count of users it will affect.

### Keyboard support

The editor is fully keyboard-accessible:

- Tab through palette items, canvas widgets, and toolbar buttons.
- On a canvas widget, press **Space** to grab it, then **arrow keys** to move it (Left/Right move three columns, Up/Down move one row), and **Space** again to drop it. **Escape** cancels the move.
- **Delete** or **Backspace** on a focused widget removes it.
- All moves are announced via the live status region so screen readers narrate placement.

### Audit trail

Every save and publish writes an entry to the audit log (action `persona_template_published`, `persona_template_draft`, or `persona_template_reset`) so you can trace who changed which persona's layout and when.

### What the editor doesn't do (yet)

- **Per-user override.** A user can't customise their own dashboard — only academy admins set the layout per persona. Per-user customisation may land in a later epic if customers ask for it.
- **Custom KPI authoring.** The 25-KPI catalog is closed; you can drop any of them on a layout, but you can't write a new query.
- **Mobile authoring canvas.** The mobile preview is read-only — you set the priority + visibility on each widget; the collapse order is computed from those values.

## Per-persona override (testing tool)

The default dashboard chooser (*Configuration → Default dashboard*) toggles **Persona dashboard** vs. **Classic tile grid** for the whole site. Below it, a *Per-persona overrides* table lets an academy admin force a specific dashboard for a single persona while leaving the rest on the global default. Each persona row offers three options:

- **Inherit (use global default)** — the persona follows the site-wide setting.
- **Persona dashboard** — force the persona-specific layout for this persona only.
- **Classic tile grid** — force the legacy tile grid for this persona only.

Useful for rolling out a redesigned persona dashboard one persona at a time on a real install, or for previewing the legacy grid without flipping the whole site.

## Sources of data

Each KPI is computed live from your academy's data. KPIs that depend on features still in development (e.g., the player-status traffic light from `#0057`, PDP planning windows from `#0054`) render a placeholder dash (`—`) until those land.

## REST API

The resolved layout for any persona is exposed as JSON for future SaaS clients:

```
GET    /wp-json/talenttrack/v1/personas/{slug}/template          read
PUT    /wp-json/talenttrack/v1/personas/{slug}/template          save draft
DELETE /wp-json/talenttrack/v1/personas/{slug}/template          reset to default
POST   /wp-json/talenttrack/v1/personas/{slug}/template/publish  promote draft to live
POST   /wp-json/talenttrack/v1/me/active-persona                 set active persona lens
DELETE /wp-json/talenttrack/v1/me/active-persona                 clear active persona lens
```

A logged-in user can read templates for personas they qualify for; the write endpoints require `tt_edit_persona_templates`.

## Where to next

- Switching roles in the user menu: [Access control](?page=tt-docs&topic=access-control)
- The full tile catalog: [Coach dashboard](?page=tt-docs&topic=coach-dashboard)
