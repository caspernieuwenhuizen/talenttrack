<?php
/**
 * CompetitionType — typed constants for the values stored in the
 * `competition_type` lookup (operator-editable). Seeded by migration
 * 0013 with the original two values (`League`, `Cup`) and extended via
 * `LookupCanonicalSeeds::canonicalMap()` to the canonical set
 * `League`, `Cup`, `Tournament`, `Friendly`, `Indoor`. Used by the
 * evaluation form's Competition dropdown and the match-pickers; the
 * column is VARCHAR + admins can extend the vocabulary from the
 * lookups admin.
 *
 * Stored with TitleCase, intentionally — the original `competition-type`
 * lookup used display labels as the stored name; downstream code
 * resolves locale labels through `LabelTranslator` /
 * `LookupTranslator`. The constants below are the canonical English
 * stored values, not the per-locale display label.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $row->competition_type === CompetitionType::CUP ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CompetitionType {

    public const LEAGUE      = 'League';
    public const CUP         = 'Cup';
    public const TOURNAMENT  = 'Tournament';
    public const FRIENDLY    = 'Friendly';
    public const INDOOR      = 'Indoor';

    /** @var list<string> */
    public const ALL = [
        self::LEAGUE,
        self::CUP,
        self::TOURNAMENT,
        self::FRIENDLY,
        self::INDOOR,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
