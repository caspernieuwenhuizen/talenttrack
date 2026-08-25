<?php
/**
 * Plugin constants, declared for PHPStan only (#2831).
 *
 * `talenttrack.php` defines TT_PLUGIN_URL / TT_PLUGIN_DIR as
 * `plugin_dir_url( __FILE__ )` and `plugin_dir_path( __FILE__ )`. PHPStan
 * registers a `define()` only when its value is a literal it can evaluate,
 * so scanning the bootstrap picks up TT_VERSION and silently skips the other
 * two — which is why every enqueue in `src` sits in the baseline, and why any
 * NEW file that enqueues an asset failed the gate with "Constant
 * TT_PLUGIN_URL not found".
 *
 * This file is never loaded at runtime: it is listed in `phpstan.neon`'s
 * `scanFiles`, which collects symbols without executing or analysing them.
 * The values are placeholders — only the names and types matter.
 *
 * Keep it in step with `talenttrack.php` when a constant is added there.
 */

if ( ! defined( 'TT_PLUGIN_URL' ) ) {
    define( 'TT_PLUGIN_URL', 'https://example.test/wp-content/plugins/talenttrack/' );
}
if ( ! defined( 'TT_PLUGIN_DIR' ) ) {
    define( 'TT_PLUGIN_DIR', '/srv/www/wp-content/plugins/talenttrack/' );
}
