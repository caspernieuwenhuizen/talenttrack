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

The drag-and-drop editor for academy admins ships in **Sprint 2** of this epic. Sprint 1 ships the catalog + the seven default templates.

When the editor lands, an admin will be able to:
- Re-order the widgets a persona sees.
- Resize widgets between Small / Medium / Large / Extra-large.
- Add a KPI card from the catalog of 25 shipped KPIs.
- Hide a widget that doesn't apply to your academy.
- Override the displayed label for a tile (e.g., "My card" → "Mijn pas").
- Reset a persona to ship defaults.

Until then, every academy gets the ship-default templates.

## Sources of data

Each KPI is computed live from your academy's data. KPIs that depend on features still in development (e.g., the player-status traffic light from `#0057`, PDP planning windows from `#0054`) render a placeholder dash (`—`) until those land.

## REST API

The resolved layout for any persona is exposed as JSON for future SaaS clients:

```
GET /wp-json/talenttrack/v1/personas/{slug}/template
```

A logged-in user can read templates for personas they qualify for; users with the `tt_edit_persona_templates` capability can read every persona's template (used by the editor's Preview-as-persona feature).

## Where to next

- Switching roles in the user menu: [Access control](?page=tt-docs&topic=access-control)
- The full tile catalog: [Coach dashboard](?page=tt-docs&topic=coach-dashboard)
