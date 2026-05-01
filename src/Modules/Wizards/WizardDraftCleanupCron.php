<?php
namespace TT\Modules\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardState;

/**
 * WizardDraftCleanupCron (#0072) — daily prune of stale `tt_wizard_drafts`
 * rows. Default TTL is 14 days, configurable via the
 * `tt_wizard_draft_ttl_days` filter.
 *
 * 14 days balances "I started rating at home and want to finish at the
 * club tomorrow" against "the database isn't a graveyard".
 */
final class WizardDraftCleanupCron {

    public const HOOK = 'tt_wizard_drafts_cleanup_cron';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( 'init', [ self::class, 'ensureScheduled' ] );
    }

    public static function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 3600, 'daily', self::HOOK );
        }
    }

    public static function run(): void {
        WizardState::cleanupOldDrafts();
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }
}
