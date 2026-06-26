# Local architecture maps

Interactive per-module graphs that surface every code surface touching a domain
entity — tables, repositories, REST controllers, views, wizards, caps, events,
exporters, lookups — plus polish overlays that highlight readiness debt
(inline SQL in views, raw `current_user_can`, missing label hydration, etc.).

Use these to scope "polish this module to production" work without grepping
the whole repo by hand. Open the `index.html` directly in a browser — no build
step, no dev server. Cytoscape.js is loaded from a CDN (cached after first
load, so the file works offline once primed).

## Workflow

1. **Pick a module** to polish (activities, players, evaluations, pdp, …).
2. **Open `<module>/index.html`** in a browser. Use the type filters to peel
   layers (e.g. hide migrations + lookups to see business code only). Use the
   polish-overlay checkboxes to highlight every node with a given debt flag.
3. **Click a node** to see its file:line, connections, and polish notes in
   the right panel. Edges in the panel are clickable — chase the graph.
4. **File issues** for the polish flags you want to fix; label `ready-for-dev`
   per CLAUDE.md §7 so the executor picks them up.
5. **Update the map** when a module ships a polish PR — the map data is
   inline in `index.html` between the `MAP_DATA` markers. Bump the
   `meta.repo_version` so the map stays self-dating.

## Directory convention

```
.local-architecture-maps/
  README.md                 ← this file
  <module-slug>/
    index.html              ← viewer + data, single self-contained file
    notes.md                ← optional — open questions, scope decisions
```

## Adding a new module

Cheapest path: copy `activities/index.html` to `<module>/index.html`, then
replace the `MAP_DATA = { nodes: [...], edges: [...] }` block. Run an
explorer pass to gather the inventory first (see
`activities/notes.md` for the prompt template used).

The `TYPE_DEFS` / `OVERLAY_DEFS` blocks at the top of `index.html` are
shared vocabulary — extend if a module legitimately introduces a new
node kind, but resist adding one-offs.

## Node-type palette (shared across modules)

| Type | Shape | Color |
|---|---|---|
| table | rectangle | indigo |
| migration | round-rectangle | purple |
| repository | ellipse | sky |
| service | diamond | cyan |
| rest | pentagon | green |
| view-read | octagon | amber |
| view-write | octagon | orange |
| wizard | star | violet |
| cap | hexagon | red |
| event | tag | yellow |
| cron | barrel | brown |
| exporter | vee | teal |
| lookup | round-tag | lime |
| analytics | triangle | pink |

## Polish overlay vocabulary

| Overlay | Why it's polish debt |
|---|---|
| `inline-sql-in-view` | View calls `$wpdb->*` directly — should go via a repository so REST + view share queries. CLAUDE.md §4. |
| `cap-raw-current_user_can` | Skips `AuthorizationService::userCanOrMatrix` — matrix-only operators get silently denied. |
| `i18n-risk` | Missing `__()` / English hardcoded / labels not in `tt_translations`. |
| `silent-fail` | `$wpdb` error swallowed without log or surface. |
| `missing-hydration` | Surface emits `*_key` raw — must go through `LookupTranslator` (#806). |
| `missing-uuid` | Root-entity table lacks `uuid CHAR(36) UNIQUE` — SaaS-migration debt. CLAUDE.md §4. |
| `no-rest-endpoint` | PHP-rendered feature with no matching REST route — couples to WordPress. |
