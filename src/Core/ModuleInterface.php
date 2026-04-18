<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ModuleInterface — every TalentTrack module implements this.
 *
 * - register(): declare services, routes, CPTs, etc. to the container/WP.
 *               Called early, before WP is fully loaded.
 * - boot():     run behavior that depends on WP being loaded
 *               (admin menus, hooks, shortcodes, etc.).
 * - getName():  unique module identifier used in config and logs.
 */
interface ModuleInterface {
    public function register( Container $container ): void;
    public function boot( Container $container ): void;
    public function getName(): string;
}
