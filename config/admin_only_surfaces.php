<?php
/**
 * wp-admin pages that deliberately have NO frontend route (#2980, epic #2874).
 *
 * Most of what an academy admin needs has been ported to the frontend, and
 * every remaining trip to wp-admin is one accidental click from the WordPress
 * plugin, user and settings screens — surfaces the capability model does not
 * describe and the product does not want them in.
 *
 * The pages below stay put on purpose. That reasoning was sound and written
 * nowhere, which is why #2874 spent an audit re-deriving it, and why that audit
 * then listed seven pages as needing a port when they had been routable since
 * #1451, #1481, #1936 and #2654. Two audits, two different wrong answers, both
 * because the decision lived in nobody's head twice.
 *
 * So this file is the decision, in the repo, greppable. It is read by:
 *
 *   - `wp tt admin-routes`, which excludes these from its unrouted list so
 *     diagnostics do not read as gaps (#2981);
 *   - the notice rendered at the top of each listed page, so an operator who
 *     lands on one is told why it is not in the app.
 *
 * Reasons are OPERATOR language, not developer language: "diagnose access
 * problems", not "AuthChain introspection". The person reading it is an academy
 * admin wondering whether they took a wrong turn.
 *
 * Adding a page here is a decision, not a formality. The question to answer is
 * not "is this hard to port?" but "would porting it make the product worse, or
 * make recovery impossible when the frontend is broken?"
 *
 * @return array<string, string> admin page slug => why it stays in wp-admin
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    // ── Diagnostics ────────────────────────────────────────────────────
    // These explain why the app is behaving as it is. Putting them inside the
    // app would mean asking a broken system to describe its own breakage.
    'tt-auth-chain-debug'    => 'This screen stays in the WordPress admin. It traces how a person gets their permissions, which is what you need when the app itself is showing someone the wrong thing.',
    'tt-roles-debug'         => 'This screen stays in the WordPress admin. It shows the roles behind a person\'s access, for when the app is hiding or showing something unexpectedly.',
    'tt-user-compare'        => 'This screen stays in the WordPress admin. It compares two people\'s access side by side, to answer "why can they see this and I cannot?"',
    'tt-matrix-preview'      => 'This screen stays in the WordPress admin. It previews a permission change before you apply it, so you can check the effect without anyone losing access first.',

    // ── Recovery ───────────────────────────────────────────────────────
    // Each of these has a frontend equivalent. The wp-admin copy is kept as the
    // way back in when the frontend cannot be reached — which is precisely the
    // moment you need it.
    'tt-matrix'              => 'The permissions matrix is also in the app. This copy stays in the WordPress admin as the way back in if a permission change ever locks everyone out of the app itself.',
    'tt-migrations'          => 'Database updates are also visible in the app. This copy stays in the WordPress admin because if an update fails, the app may not load — and this is where you would look.',
    'tt-error-log'           => 'This screen stays in the WordPress admin. If the app is not loading, the error log has to be reachable somewhere that does not depend on the app loading.',

    // ── Setup and support ──────────────────────────────────────────────
    'tt-demo-data'           => 'This screen stays in the WordPress admin. It fills a fresh install with example data, which is a one-off setup job rather than something you do while running an academy.',
    'tt-demo-review'         => 'This screen stays in the WordPress admin. It reviews the example data before you clear it, part of the same one-off setup.',
    'tt-seed-review'         => 'This screen stays in the WordPress admin. It checks the starting vocabularies a new install was seeded with, during setup.',
    'tt-welcome'             => 'This screen stays in the WordPress admin. It is the first-run introduction and has nothing to do once your academy is set up.',
    'tt-impersonate'         => 'This screen stays in the WordPress admin. Viewing the app as someone else is a support tool, and keeping it outside the app makes it obvious when it is in use.',

    // ── Developer instrumentation ──────────────────────────────────────
    'tt-module-completeness' => 'This screen stays in the WordPress admin. It reports how complete each module is against its own checklist, which is development instrumentation rather than academy work.',
];
