<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\SpondRestController;

/**
 * SpondModule (#0031) — read-only Spond → TalentTrack iCal sync.
 *
 * Owns:
 *   - Schema (migration 0041): tt_teams.spond_* + tt_activities.external_id.
 *   - Cron: hourly polling of every team with a non-empty spond_ical_url.
 *   - REST: POST /teams/{id}/spond/sync (manager-only).
 *   - WP-CLI: `wp tt spond sync [--team=<id>]`.
 *   - Lazy refresh: when an admin opens the team edit form and the last
 *     sync is more than 2x the configured interval old, kick off a sync.
 *
 * Spond stays the source of truth for schedule + RSVPs; TalentTrack
 * stays the source of truth for evaluations + goals + attendance.
 * Coaches see one timeline either way.
 */
final class SpondModule implements ModuleInterface {

    public const CRON_HOOK = 'tt_spond_hourly_sync';

    public function getName(): string { return 'spond'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        SpondRestController::init();

        add_filter( 'cron_schedules', [ self::class, 'registerCronSchedule' ] );
        add_action( 'wp', [ self::class, 'ensureCronScheduled' ] );
        add_action( self::CRON_HOOK, [ self::class, 'runScheduledSync' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'tt spond sync', [ SpondCli::class, 'sync' ] );
        }
    }

    /**
     * @param array<string,mixed> $schedules
     * @return array<string,mixed>
     */
    public static function registerCronSchedule( array $schedules ): array {
        $schedules['tt_spond_hourly'] = [
            'interval' => 3600,
            'display'  => __( 'TalentTrack — Spond hourly sync', 'talenttrack' ),
        ];
        return $schedules;
    }

    public static function ensureCronScheduled(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) === false ) {
            wp_schedule_event( time() + 60, 'tt_spond_hourly', self::CRON_HOOK );
        }
    }

    public static function runScheduledSync(): void {
        SpondSync::syncAll();
    }
}
