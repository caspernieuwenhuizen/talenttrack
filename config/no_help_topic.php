<?php
/**
 * Routable `?tt_view=` slugs that deliberately have no help topic (#2547).
 *
 * `DashboardShortcode::viewToTopicMap()` is a projection of the corpus: a
 * doc claims the screens it serves in its `views:` front matter, and that
 * inversion is the map. A slug nothing claims opens the help drawer on the
 * default topic — which is the right behaviour, but only when somebody
 * decided it should.
 *
 * That was the whole failure this replaced. The old map was a
 * hand-maintained 27-entry array against a dispatcher routing 144 slugs,
 * so ~117 screens opened "Getting started" silently, with nothing anywhere
 * recording that a mapping was missing.
 *
 * So there are two states and no third: a slug is claimed by a `views:`
 * entry, or it is listed here with a reason. The docs lint (#2551) fails a
 * PR that adds a route in neither state, which is what keeps context-aware
 * help at 100% as new views land rather than decaying again.
 *
 * @return array<string, string> view slug => why it has no topic
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    'docs'        => 'The help surface itself. A topic pointing at the page you are already reading is a loop, not help.',
    'my-settings' => 'Personal account settings — notification preferences, password, locale. Nothing here is academy behaviour to document; the mobile and notification setup topics cover the parts that need explaining.',
];
