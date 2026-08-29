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

    // Pre-auth screens. Each of these renders before the login guard (or,
    // for the MFA prompt, before a half-authenticated session is allowed
    // through), returning its own minimal chrome rather than the dashboard
    // shell. There is no help button on the page, so a topic mapping here
    // would map a screen the drawer cannot open. They became visible to
    // this gate in #3022, when the three routable-slug derivers were
    // unified; they were always routes, just unparseable ones.
    'accept-invite'        => 'Invitation acceptance, rendered before the login guard because the token is the credential and the recipient may have no account yet. No shell, so no help drawer to open.',
    'match-analysis-share' => 'A signed share link to one match analysis, opened by a coach outside the academy. Pre-auth, single-purpose, and deliberately noindex — a help topic here would document the product to someone who is not using it.',
    'match-prep-share'     => 'The same arrangement for match preparation: a signed link for an assistant coach, analyst or keeper coach who may have no account here. Pre-auth, no shell.',
    'lost-password'        => 'The branded "email me a reset link" form. Renders before the login guard and carries its own instructions; the password-reset topic documents the flow for anyone reading the corpus.',
    'reset-password'       => 'The branded "choose a new password" form, reached from the emailed link. Pre-auth and self-explanatory, for the same reason as lost-password.',
    'mfa-prompt'           => 'The second-factor challenge. The session is half-authenticated, chrome is stripped to the prompt itself, and anything but entering the code is a distraction at this step.',
];
