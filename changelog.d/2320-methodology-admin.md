# Manage methodology sets + per-team selection (#2320)

Bump: minor

Academies can now manage their methodology sets from the frontend. A new **Speelwijzen** tab leads the methodology manage surface: it lists every set (with Actief and Shipped badges), creates and renames sets through a flat multilingual form, makes any set the install-wide active one with a single "Maak actief" action, and archives sets it no longer needs — refusing to touch shipped reference sets or strand the install with zero methodologies. Each team can override which set it uses through a new "Methodology set" dropdown on the team edit form, defaulting to the install-wide active set. The same operations are exposed over REST at `/methodology/sets`, including `PUT /methodology/sets/{id}/default`, so a future SaaS front end gets identical answers.

Wizard plan: exemption — methodology set is a single-record named container, analogous to a lookup/vocabulary edit (§3 exemption a).
