<?php
/**
 * TrialCaseStatus — typed constants for the values stored in
 * `tt_trial_cases.status`. Tracks the lifecycle of a trial: a coach opens
 * a case when a player starts the trial window, extends it once if
 * needed, marks it decided once the staff team votes admit / decline, and
 * archives it after the acceptance slip flow closes out.
 *
 * Backs the `trial_case_status` lookup (operator-editable, seeded by
 * migration 0116 with per-locale translations through `tt_translations`).
 * Heavy operator surface — trial workflow varies a lot by academy (#803
 * audit; #842) — but the four stored keys stay sacred.
 *
 * `TrialCasesRepository::STATUS_*` constants are the existing internal
 * contract; this Vocabulary class mirrors them as the canonical reference
 * for any other PHP site comparing a stored status value.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $case->status === TrialCaseStatus::OPEN ) { ... }
 *     in_array( $case->status, [ TrialCaseStatus::OPEN, TrialCaseStatus::EXTENDED ], true );
 *
 * SQL string literals (`status IN ('open','extended')`) stay as literals —
 * DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TrialCaseStatus {

    public const OPEN     = 'open';
    public const EXTENDED = 'extended';
    public const DECIDED  = 'decided';
    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const ALL = [
        self::OPEN,
        self::EXTENDED,
        self::DECIDED,
        self::ARCHIVED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
