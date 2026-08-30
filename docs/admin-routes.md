---
title: Admin route inventory
group: developer
summary: wp tt admin-routes — which wp-admin pages have a frontend equivalent, asked of a booted install.
audience: [dev]
order: 95
---

# Admin route inventory

`wp tt admin-routes` lists every wp-admin page the running install registers
and says whether it has a frontend route.

```
wp tt admin-routes
wp tt admin-routes --unrouted
wp tt admin-routes --format=json
```

## Why it is a command and not a grep

A static scan cannot answer this question. `AdminMenuRegistry::register()` is
called from module `boot()`, guarded by `ModuleRegistry::isEnabled()`, so
several groups — the methodology editors, `tt-spond`, `tt-football-actions` —
exist only when their module is on. A generator written against the source
found 32 slugs where a live install registers 48.

The hand-maintained alternative failed the other way. An audit listed seven
pages as needing a port when they had been routable since #1451, #1481, #1936
and #2654. Two inventories, two different wrong answers, both because nobody
could ask the running install.

## Columns

| Column | Meaning |
| --- | --- |
| `admin_slug` | The `?page=` slug |
| `title` | Menu title as registered |
| `cap` | Capability gating the page |
| `module` | Owning module, or `(core)` |
| `enabled` | Whether that module is on in this install |
| `frontend_slug` | The matching `?tt_view=` slug, when there is one |
| `status` | `routed`, `unrouted`, or `diagnostic` |

`--unrouted` filters to the rows that represent actual work: no frontend route
and not deliberately admin-only.

## How the frontend match is made

Two steps: derive the routable set, then pair an admin slug with one of them.

**The routable set comes from `tools/lib/routable-slugs.php`** — the canonical
deriver the docs gate (#2551), the mobile-class gate (#2812) and the tile-route
gate (#2885) also read. Four consumers, one deriver.

This command originally had a regex of its own, `case '<slug>':`, which is the
trap that helper exists to close. It cannot see a **constant arm** —
`case FrontendCategoryWeightsView::SLUG:`, where the literal lives in the view
class — or a **pre-auth route**, handled by `$tt_view_param === …` above the
dispatch chain so it works for a logged-out visitor. On the current tree that is
ten live routes the regex could not see, including two of the three pages #2874
commissioned ports for. `tools/` ships inside the plugin zip, so the deriver
resolves on a real install and not only in a checkout.

Anything in the dispatcher that looks like a route but cannot be followed
statically is printed as a warning above the table rather than dropped — an
unfollowable arm is *unknown*, not absent, and the difference is the reason this
command exists.

**The pairing** is the admin slug minus its `tt-` prefix, which is the
convention most of the plugin follows — checked against
`config/admin_frontend_slug_map.php` first, which records the pages whose port
renamed or merged the slug:

| wp-admin page | frontend slug | renamed by |
| --- | --- | --- |
| `tt-category-weights` | `eval-category-weights` | #2977 |
| `tt-persona-dashboard-editor` | `persona-templates` | #2978 |
| 8 × `tt-methodology-*-edit` | `methodology-vocabulary` | #2976 |

The methodology row is the one no prefix rule can express: eight admin pages
collapse into a single frontend surface, which is the point of #2976.

The recorded slug is still validated against the real routable set, so a stale
or mistyped map entry reads as `unrouted` rather than as a false green. A row in
that file is a decision — the question is whether the port genuinely landed
under a different name, not whether you would like the tool to stop
complaining.

## Deliberately admin-only pages

`config/admin_only_surfaces.php` maps an admin slug to a sentence saying why it
has no frontend route. Those pages report as `diagnostic` rather than
`unrouted`, and the same file is read by `AdminOnlyNotice`, which renders the
reason at the top of the page an operator lands on.

Reasons are written for an academy admin, not a developer: *"this screen stays
in the WordPress admin, it traces how a person gets their permissions"*, not
*"AuthChain introspection"*.

Adding a page there is a decision. The question is not "is this hard to port?"
but "would porting it make the product worse, or make recovery impossible when
the frontend is broken?" The recovery cases — the permissions matrix, the
migrations page, the error log — all have frontend equivalents already; the
wp-admin copies exist for the moment the frontend cannot be reached.

## Not a CI gate

Deliberately. The inventory needs to be trustworthy before an unrouted page
should fail a build, and `--format=json` is there so a gate can consume it once
somebody has read the output a few times. Turning it into a gate first is how
you get a gate people disable.
