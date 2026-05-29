<?php
/**
 * ReportAudienceType — typed constants for the eight values stored in
 * `tt_player_reports.audience`. Backs the `audience_type` lookup
 * (operator-editable) with per-locale labels resolved through
 * `tt_translations`.
 *
 * The renderer-side `TT\Modules\Reports\AudienceType` class is the
 * existing internal contract — it carries the canonical English label,
 * description, and `trialLetters()` helper. This Vocabulary class mirrors
 * the eight values as the canonical reference for any PHP site comparing
 * a stored audience value outside the Reports module.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->audience === ReportAudienceType::SCOUT ) { ... }
 *     in_array( $row->audience, [
 *         ReportAudienceType::TRIAL_ADMITTANCE,
 *         ReportAudienceType::TRIAL_DENIAL_FINAL,
 *         ReportAudienceType::TRIAL_DENIAL_ENCOURAGEMENT,
 *     ], true );
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ReportAudienceType {

    public const STANDARD                  = 'standard';
    public const PARENT_MONTHLY             = 'parent_monthly';
    public const INTERNAL_DETAILED          = 'internal_detailed';
    public const PLAYER_PERSONAL            = 'player_personal';
    public const SCOUT                      = 'scout';
    public const TRIAL_ADMITTANCE           = 'trial_admittance';
    public const TRIAL_DENIAL_FINAL         = 'trial_denial_final';
    public const TRIAL_DENIAL_ENCOURAGEMENT = 'trial_denial_encouragement';

    /** @var list<string> */
    public const ALL = [
        self::STANDARD,
        self::PARENT_MONTHLY,
        self::INTERNAL_DETAILED,
        self::PLAYER_PERSONAL,
        self::SCOUT,
        self::TRIAL_ADMITTANCE,
        self::TRIAL_DENIAL_FINAL,
        self::TRIAL_DENIAL_ENCOURAGEMENT,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
