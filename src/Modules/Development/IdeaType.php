<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * IdeaType — the four type values stored on `tt_dev_ideas.type`.
 *
 * Maps directly to the type marker that goes into the promoted
 * GitHub file (`<!-- type: feat -->` etc.) and the `<type>` segment
 * of the assigned filename.
 */
final class IdeaType {

    public const FEAT         = 'feat';
    public const BUG          = 'bug';
    public const EPIC         = 'epic';
    public const NEEDS_TRIAGE = 'needs-triage';

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
