# Branding colours apply on the default design, and the status colours change (#4797)

On installs that use the default design (no theme), the colours and fonts an academy sets under Branding now apply inside the dashboard. Until now a second set of built-in colours overrode them, so only the accent and the focus ring followed Branding.

The built-in colours are now a single set. As a result, the default design's status colours change, also for academies that never set them:

- Danger (errors, removals): `#b32d2e` → `#d8453b`.
- Warning: `#dba617` → `#e8902b`.
- Success: `#00a32a` → `#2f9e5e`.
- Info: `#2271b1` → `#2d6fb3`.

A Branding colour still set to the form's suggestion counts as not chosen. So does a status colour stored as black (`#000000`), which some installs hold for colours nobody picked. Either way, that colour keeps the built-in value instead of turning alerts black.

Installs that use the Leon Hutten or federation theme don't change; the theme still owns the whole design. A few screens outside the dashboard follow the dashboard's own neutral greys and spacing now: the wp-admin Help & Docs page, the wp-admin plan panel, the trial letter, the search preview panel and the team chemistry picker. The Help & Docs page's links and quotes there are now green instead of WordPress blue.
