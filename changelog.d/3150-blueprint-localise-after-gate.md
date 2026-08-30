# Blueprint editor no longer publishes a roster before checking who is looking (#3150)

Opening a team blueprint you do not coach printed "Access denied" in the
page while the page source already carried the names, preferred positions
and ages of every player on that team. The editor's data payload was built
by the asset-enqueue helper, which read the `?id=` out of the request for
itself and ran a thousand lines before the ownership check.

The payload is now built by the editor only, with the blueprint id that
check has already approved. The public share link, which is reachable
without signing in, no longer builds one at all — that page is rendered
entirely on the server and never used it. Player lookups behind the editor
are scoped to the club that is asking.
