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
 *
 * TT_PLUGIN_URL is a placeholder — nothing resolves a URL. TT_PLUGIN_DIR is
 * the real checkout root, because `src` builds `require` paths out of it and
 * PHPStan 2.x folds the constant before checking that the required file
 * exists. A placeholder there reports every one of those requires as a
 * missing file.
 *
 * Keep it in step with `talenttrack.php` when a constant is added there.
 */

if ( ! defined( 'TT_PLUGIN_URL' ) ) {
    define( 'TT_PLUGIN_URL', 'https://example.test/wp-content/plugins/talenttrack/' );
}
if ( ! defined( 'TT_PLUGIN_DIR' ) ) {
    define( 'TT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
