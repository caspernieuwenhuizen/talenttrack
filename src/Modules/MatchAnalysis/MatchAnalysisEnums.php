<?php
namespace TT\Modules\MatchAnalysis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\MethodologyEnums;

/**
 * MatchAnalysisEnums — the closed vocabularies of a match analysis.
 *
 * Three of them: the sections an analysis is written into, the rating a
 * section can carry, and the marker a player item can carry.
 *
 * The section list is the methodology's own team functions
 * (`MethodologyEnums::teamFunctions()`) plus set pieces and a general
 * summary. That is deliberate and is the decision the epic locked: match
 * prep's five goal boxes (general / attack / defend / set piece attack /
 * set piece defend) have no transition section, and the transitions are
 * where youth matches are decided. Reusing the methodology taxonomy also
 * means an analysis reads back in the same words the club plans its
 * training in, and that a season of analyses aggregates against the
 * methodology rather than against a shape invented here.
 *
 * Like `MethodologyEnums` these are code-level, not `tt_lookups`: a club
 * whose framework is not "aanvallen / verdedigen / omschakelen" is using a
 * different methodology, and that should be a conscious change here rather
 * than silent drift in a lookup table.
 */
final class MatchAnalysisEnums {

    /** The overall read on the match — prose, not bullets. */
    public const SECTION_GENERAL    = 'general';
    /** Corners, free kicks, penalties, throw-ins — both sides of them. */
    public const SECTION_SET_PIECES = 'set_pieces';

    public const RATING_WENT_WELL  = 'went_well';
    public const RATING_MIXED      = 'mixed';
    public const RATING_NEEDS_WORK = 'needs_work';

    public const MARKER_STOOD_OUT   = 'stood_out';
    public const MARKER_AS_EXPECTED = 'as_expected';
    public const MARKER_BELOW_PAR   = 'below_par';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';

    /**
     * Section keys in the order they are written and read. General first
     * (the coach's overall read frames everything under it), then the four
     * team functions in methodology order, then set pieces.
     *
     * @return list<string>
     */
    public static function sectionKeys(): array {
        return [
            self::SECTION_GENERAL,
            MethodologyEnums::FUNCTION_AANVALLEN,
            MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN,
            MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN,
            MethodologyEnums::FUNCTION_VERDEDIGEN,
            self::SECTION_SET_PIECES,
        ];
    }

    /**
     * Section keys that carry a rating + bullets. `general` is excluded —
     * it is the summary paragraph, and rating "the match overall" invites
     * the single grade the whole surface is built to avoid.
     *
     * @return list<string>
     */
    public static function ratedSectionKeys(): array {
        return array_values( array_filter(
            self::sectionKeys(),
            static fn( string $key ): bool => $key !== self::SECTION_GENERAL
        ) );
    }

    public static function isSectionKey( string $key ): bool {
        return in_array( $key, self::sectionKeys(), true );
    }

    /**
     * Translated section labels. The four team functions resolve through
     * `MethodologyEnums` so the analysis and the methodology reference can
     * never disagree about what a phase is called.
     *
     * @return array<string,string> section key => label
     */
    public static function sectionLabels(): array {
        $functions = MethodologyEnums::teamFunctions();

        return [
            // `_x` because the bare msgid "Overall" already resolves to the
            // arithmetic sense elsewhere in the product ("Totaal"). Here it
            // heads the coach's general read of the match, which is
            // "Algemeen" — a distinction no gate can catch, only a reader.
            self::SECTION_GENERAL                        => _x( 'Overall', 'match analysis section', 'talenttrack' ),
            MethodologyEnums::FUNCTION_AANVALLEN         => $functions[ MethodologyEnums::FUNCTION_AANVALLEN ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN => $functions[ MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN ],
            MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN  => $functions[ MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN ],
            MethodologyEnums::FUNCTION_VERDEDIGEN        => $functions[ MethodologyEnums::FUNCTION_VERDEDIGEN ],
            self::SECTION_SET_PIECES                     => __( 'Set pieces', 'talenttrack' ),
        ];
    }

    public static function sectionLabel( string $key ): string {
        $labels = self::sectionLabels();
        return $labels[ $key ] ?? $key;
    }

    /**
     * The match-prep goal column whose text was the plan for a section, or
     * null where the plan has nothing to say.
     *
     * Two of the six map cleanly (`goals_attack`, `goals_defend`), one is a
     * merge (both set-piece boxes land on `set_pieces`, handled by the
     * caller), and the two transitions have no counterpart at all — match
     * prep never asked for them. Rendering an empty "Planned:" line there
     * would be worse than rendering none: it reads as "we planned nothing"
     * when the truth is "we were never asked".
     */
    public static function prepGoalColumnFor( string $section_key ): ?string {
        switch ( $section_key ) {
            case self::SECTION_GENERAL:
                return 'goals_general';
            case MethodologyEnums::FUNCTION_AANVALLEN:
                return 'goals_attack';
            case MethodologyEnums::FUNCTION_VERDEDIGEN:
                return 'goals_defend';
            default:
                return null;
        }
    }

    /** @return array<string,string> rating key => label */
    public static function ratings(): array {
        return [
            self::RATING_WENT_WELL  => __( 'Went well', 'talenttrack' ),
            // `_x` for the same reason as "Overall" above: the bare
            // "Mixed" is already translated in the mixed-group sense
            // ("Gemengd"), where a rating between good and poor is
            // "Wisselend".
            self::RATING_MIXED      => _x( 'Mixed', 'match analysis rating', 'talenttrack' ),
            self::RATING_NEEDS_WORK => __( 'Needs work', 'talenttrack' ),
        ];
    }

    public static function isRating( string $value ): bool {
        return isset( self::ratings()[ $value ] );
    }

    /** @return array<string,string> marker key => label */
    public static function markers(): array {
        return [
            self::MARKER_STOOD_OUT   => __( 'Stood out', 'talenttrack' ),
            self::MARKER_AS_EXPECTED => __( 'As expected', 'talenttrack' ),
            self::MARKER_BELOW_PAR   => __( 'Below par', 'talenttrack' ),
        ];
    }

    public static function isMarker( string $value ): bool {
        return isset( self::markers()[ $value ] );
    }

    public static function markerLabel( string $value ): string {
        $markers = self::markers();
        return $markers[ $value ] ?? '';
    }

    /**
     * Team functions a player item can be tagged with. The same four the
     * sections use plus set pieces — a note about a corner routine is about
     * set pieces, not about "aanvallen".
     *
     * @return array<string,string>
     */
    public static function playerItemTags(): array {
        $labels = self::sectionLabels();
        unset( $labels[ self::SECTION_GENERAL ] );
        return $labels;
    }

    public static function isPlayerItemTag( string $value ): bool {
        return isset( self::playerItemTags()[ $value ] );
    }
}
