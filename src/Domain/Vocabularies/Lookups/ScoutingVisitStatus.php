<?php
/**
 * ScoutingVisitStatus — typed constants for the three values stored in
 * `tt_scouting_plan_visits.status`. Backs the `scouting_visit_status`
 * lookup (operator-editable) with per-locale labels resolved through
 * `tt_translations`.
 *
 * The repository-side `ScoutingVisitsRepository::STATUS_*` constants are
 * the existing internal contract; this Vocabulary class mirrors them as
 * the canonical reference for any PHP site comparing a stored visit
 * status value outside the repository.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $visit->status === ScoutingVisitStatus::PLANNED ) { ... }
 *     in_array( $visit->status, [ ScoutingVisitStatus::COMPLETED, ScoutingVisitStatus::CANCELLED ], true );
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ScoutingVisitStatus {

    public const PLANNED   = 'planned';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::PLANNED,
        self::COMPLETED,
        self::CANCELLED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
