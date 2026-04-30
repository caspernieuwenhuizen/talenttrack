<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\SpondRestController;

/**
 * SpondModule (#0031, fetcher rewritten in #0062) — read-only Spond
 * → TalentTrack sync via the internal JSON API at api.spond.com.
 *
 * Owns:
 *   - Schema (migration 0041 + 0052): tt_teams.spond_* (group_id +
 *     last_sync_*) + tt_activities.external_id.
 *   - Per-club credentials: `Spond\CredentialsManager` (encrypted at
 *     rest in tt_config).
 *   - Cron: hourly polling of every team with a non-empty
 *     spond_group_id and a credentialed account.
 *   - REST: POST /teams/{id}/spond/sync (manager-only).
 *   - WP-CLI: `wp tt spond sync [--team=<id>]`.
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

        if ( is_admin() ) {
            Admin\SpondOverviewPage::init();
        }

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
