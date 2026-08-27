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

The command reads the `case` labels out of `DashboardShortcode` and matches an
admin slug to a view slug by stripping the `tt-` prefix, which is the
convention every port has followed.

A port that chose a different name shows as `unrouted`. That is deliberate: the
codebase records no link between the two, so the honest answer is "nothing here
says these are the same page" rather than a guess. Fix it by naming the view
after the admin slug, or add the pair to the config below with a note.

A better end state is for the dispatcher to expose its routable set as an array
the `switch` also consumes, so this command and the tile-route gate (#2885) read
one list instead of both parsing the same file. That is a refactor of the
busiest file in the plugin and wants its own PR.

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
