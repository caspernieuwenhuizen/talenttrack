<?php
/**
 * PdpVerdictDecision — typed constants for the four end-of-season verdict
 * decisions stored in `tt_pdp_verdicts.decision`.
 *
 * Backs the `pdp_verdict_decision` lookup (operator-editable, seeded by
 * migration 0112 with per-locale translations through `tt_translations`).
 * Pilot academies may surface academy-specific labels (e.g. *progressed*
 * / *signed* / *released* / *moved*) but the stored keys stay sacred —
 * `PdpVerdictsRepository::ALLOWED_DECISIONS` and the REST controller's
 * `ALLOWED_DECISIONS` gate every write against the four values below.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $verdict->decision === PdpVerdictDecision::PROMOTE ) { ... }
 *     in_array( $verdict->decision, PdpVerdictDecision::ALL, true );
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PdpVerdictDecision {

    public const PROMOTE  = 'promote';
    public const RETAIN   = 'retain';
    public const RELEASE  = 'release';
    public const TRANSFER = 'transfer';

    /** @var list<string> */
    public const ALL = [
        self::PROMOTE,
        self::RETAIN,
        self::RELEASE,
        self::TRANSFER,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
