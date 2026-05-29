<?php
/**
 * ScheduledReportStatus — typed constants for the three values stored in
 * `tt_scheduled_reports.status`. Backs the `scheduled_report_status`
 * lookup (operator-editable) with per-locale labels resolved through
 * `tt_translations`.
 *
 * The cron consumer (`ScheduledReportsRepository::dueForRun()`) picks
 * rows with `status = ScheduledReportStatus::ACTIVE` and `next_run_at <=
 * NOW()`. The admin pause / resume / archive action handlers
 * (`ScheduledReportsActionHandlers`) flip rows between the three values.
 *
 * The repository-side `ScheduledReportsRepository::STATUS_*` constants
 * are the existing internal contract; this Vocabulary class mirrors them
 * as the canonical reference for any PHP site comparing a stored status
 * value outside the repository.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $schedule['status'] === ScheduledReportStatus::ARCHIVED ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ScheduledReportStatus {

    public const ACTIVE   = 'active';
    public const PAUSED   = 'paused';
    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const ALL = [
        self::ACTIVE,
        self::PAUSED,
        self::ARCHIVED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
