# Photos and video now load, count and land where you expect (#2715, #2716, #2717)

Bump: patch

Three defects found in the first live test of the media library, all fixed
together.

**Photos and video would not display (#2715).** Every thumbnail rendered as a
broken image. The files themselves were fine — stored, thumbnailed and stripped
of EXIF exactly as intended — but the browser was turned away at the door.
An `<img>` tag cannot send the `X-WP-Nonce` header the REST API expects, so
WordPress treated the request as coming from nobody at all and answered 401.
Media URLs now carry the nonce in the query string, which WordPress accepts as
equivalent. The session cookie is still required: a URL copied out of a page and
opened elsewhere is refused, so this does not turn a player's photo into a link
anyone can follow.

**Finishing the wizard dropped you on the site's front page (#2716).** The
"Add photos or video" wizard built its closing redirect on the site root rather
than the page that hosts the dashboard, so on any install where the dashboard is
not the front page the coach landed on the theme's homepage instead of the player
they had just added a photo to. The same three lines also pointed activity media
at a view that does not exist. Both now route through the shared link helper.

**The Media tab never showed a count (#2717).** Goals, Evaluations, Activities
and the rest all carry a number; Media was added to the tab strip without being
added to the counter behind it, so a player with photos showed a bare tab. The
badge now counts the same media the tab lists — club-scoped, archived items
excluded — so the two cannot disagree.

One limitation worth knowing: a nonce is valid for roughly a day. A gallery left
open in a tab longer than that will show broken thumbnails until the page is
reloaded.
