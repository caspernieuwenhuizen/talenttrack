<?php
/**
 * ScheduledReportFrequency — typed constants for the three values stored
 * in `tt_scheduled_reports.frequency`. Backs the
 * `scheduled_report_frequency` lookup (operator-editable) with per-locale
 * labels resolved through `tt_translations`.
 *
 * Strings (rather than integers) so a future per-club calendar can extend
 * the vocabulary without a schema migration. Migration 0075 documents the
 * three v1 values; the cron in
 * `ScheduledReportsRepository::computeNextRun()` is the canonical
 * scheduling reference.
 *
 * The repository-side `ScheduledReportsRepository::FREQUENCY_*` constants
 * are the existing internal contract; this Vocabulary class mirrors them
 * as the canonical reference for any PHP site comparing a stored frequency
 * value outside the repository.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $schedule['frequency'] === ScheduledReportFrequency::WEEKLY_MONDAY ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ScheduledReportFrequency {

    public const WEEKLY_MONDAY = 'weekly_monday';
    public const MONTHLY_FIRST = 'monthly_first';
    public const SEASON_END    = 'season_end';

    /** @var list<string> */
    public const ALL = [
        self::WEEKLY_MONDAY,
        self::MONTHLY_FIRST,
        self::SEASON_END,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
