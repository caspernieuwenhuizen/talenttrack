<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\IdeaType as CanonicalIdeaType;

/**
 * IdeaType — the four type values stored on `tt_dev_ideas.type`.
 *
 * Maps directly to the type marker that goes into the promoted
 * GitHub file (`<!-- type: feat -->` etc.) and the `<type>` segment
 * of the assigned filename.
 *
 * v4.12.9 (#988 PR-set 7) — the canonical idea type values moved into
 * `TT\Domain\Vocabularies\Lookups\IdeaType`. The constants below alias
 * the new vocabulary for one release per #988's locked plan, and will
 * be removed in the next minor; new code should reference
 * `TT\Domain\Vocabularies\Lookups\IdeaType::*` directly. The
 * module-local `label()` / `isValid()` / `all()` helpers stay in
 * place — they encode rendering rules that aren't part of the
 * vocabulary contract.
 */
final class IdeaType {

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaType::FEAT}; removed in next minor. */
    public const FEAT         = CanonicalIdeaType::FEAT;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaType::BUG}; removed in next minor. */
    public const BUG          = CanonicalIdeaType::BUG;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaType::EPIC}; removed in next minor. */
    public const EPIC         = CanonicalIdeaType::EPIC;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaType::NEEDS_TRIAGE}; removed in next minor. */
    public const NEEDS_TRIAGE = CanonicalIdeaType::NEEDS_TRIAGE;

    /** @return list<string> */
    public static function all(): array {
        return [ self::FEAT, self::BUG, self::EPIC, self::NEEDS_TRIAGE ];
    }

    /**
     * Operator-editable label. Resolves through `tt_translations` via
     * `LookupTranslator::byTypeAndName('idea_type', $value)`;
     * pre-migration installs fall back to the canonical English label.
     */
    public static function label( string $type ): string {
        if ( $type === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'idea_type', $type );
            if ( $label !== '' && $label !== $type ) return $label;
        }
        switch ( $type ) {
            case self::FEAT:         return __( 'Feature', 'talenttrack' );
            case self::BUG:          return __( 'Bug', 'talenttrack' );
            case self::EPIC:         return __( 'Epic', 'talenttrack' );
            case self::NEEDS_TRIAGE: return __( 'Needs triage', 'talenttrack' );
        }
        return $type;
    }

    public static function isValid( string $type ): bool {
        return in_array( $type, self::all(), true );
    }
}
