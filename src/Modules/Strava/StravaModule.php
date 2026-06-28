<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\StravaRestController;

/**
 * StravaModule (#2056, epic #2002) — per-player Strava activity
 * integration via OAuth 2.0 + webhooks.
 *
 * Owns:
 *   - Schema (migration 0188): tt_player_strava_connections (encrypted
 *     per-player tokens) + tt_player_activities (imported, non-HR).
 *   - OAuth connect flow: authorize-URL builder, signed-state callback,
 *     encrypted token store (this child, #2056).
 *   - Token refresh (#2057), webhook subscriber (#2059), activity
 *     ingest (#2058) land on top.
 *
 * Strava is connected per athlete (account linking), never an identity
 * provider — the capability model stays TalentTrack's, so minors who
 * cannot hold a Strava account are never locked out of the plugin.
 */
final class StravaModule implements ModuleInterface {

    public function getName(): string { return 'strava'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        StravaRestController::init();
        TokenRefreshService::init();
    }
}
