# Frontend authoring for set pieces (#2228)

Bump: minor

Academy editors can now author **set pieces** from the frontend, no wp-admin
required. The methodology "Manage" surface gains a **Spelhervattingen** tab:
list, create, edit and delete club-authored set pieces with a slug, a
kind (corner, free kick, penalty, throw-in) and side, side-by-side Dutch +
English inputs for the title, a Dutch and English coaching-point list (one
bullet per line) and an optional diagram-overlay JSON blob. Shipped reference
set pieces stay read-only, and saved set pieces show up in the read view's
Set pieces tab.

The same data is exposed over REST at
`/wp-json/talenttrack/v1/methodology/set-pieces` (GET/POST/PUT/DELETE),
club-scoped and gated on `tt_edit_methodology`, so a future SaaS front end
gets identical answers. Built on the #2225 tab-registry + REST-base scaffold.
