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
    public const SECTION_GENERAL = 'general';

    /**
     * Set pieces, one section per side.
     *
     * They shipped merged, which cost two things: a coach's note about our
     * corners sat in the same box as one about defending their free kicks,
     * and the two match-prep set-piece goal boxes had to be joined into one
     * "Planned" line. Splitting them restores an exact 1:1 mapping with the
     * four goal boxes on the plan, so every planned line lands beside the
     * phase it was planned for.
     */
    public const SECTION_SET_PIECES_ATTACK = 'set_pieces_attack';
    public const SECTION_SET_PIECES_DEFEND = 'set_pieces_defend';

    /**
     * The legacy merged key, still readable so an analysis written before
     * the split keeps its text. Migration 0231 moves the rows; this
     * constant exists for the one release in which a stale row could still
     * be read from a cache or a queued request.
     *
     * @deprecated since v4.98.0 — use the two side-specific keys.
     */
    public const SECTION_SET_PIECES_LEGACY = 'set_pieces';

    public const RATING_WENT_WELL  = 'went_well';
    public const RATING_MIXED      = 'mixed';
    public const RATING_NEEDS_WORK = 'needs_work';

    public const MARKER_STOOD_OUT   = 'stood_out';
    public const MARKER_AS_EXPECTED = 'as_expected';
    public const MARKER_BELOW_PAR   = 'below_par';

    /**
     * The mark on a single note (#3091). Plus, minus, or nothing.
     *
     * Neutral is a first-class answer and is the default. "Speelde als
     * rechtsback" is an observation, not a judgement, and forcing a
     * good/bad call on it would repeat the mistake the section rating
     * already avoids by letting "nothing selected" be the resting state.
     */
    public const VALENCE_PLUS  = 'plus';
    public const VALENCE_MINUS = 'minus';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';

    /** The half of the game a phase belongs to. */
    public const CHAIN_POSSESSION = 'possession';
    public const CHAIN_PRESSING   = 'pressing';

    /**
     * Section keys in the order they are written and read: the overall
     * read, then the two chains.
     *
     * @return list<string>
     */
    public static function sectionKeys(): array {
        return array_merge(
            [ self::SECTION_GENERAL ],
            ...array_values( self::chains() )
        );
    }

    /**
     * The two chains of the game, each read top to bottom as a sequence.
     *
     * With the ball: what we do with it, what happens the instant we lose
     * it, and our own set pieces. Without it: what we do to get it back,
     * what happens the instant we win it, and theirs.
     *
     * This is the order a coach reviews in, and it is why the surface is
     * two columns rather than one list — a transition only means anything
     * next to the phase it comes out of.
     *
     * @return array<string, list<string>> chain key => ordered section keys
     */
    public static function chains(): array {
        return [
            self::CHAIN_POSSESSION => [
                MethodologyEnums::FUNCTION_AANVALLEN,
                MethodologyEnums::FUNCTION_OMSCHAKELEN_VERDEDIGEN,
                self::SECTION_SET_PIECES_ATTACK,
            ],
            self::CHAIN_PRESSING => [
                MethodologyEnums::FUNCTION_VERDEDIGEN,
                MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN,
                self::SECTION_SET_PIECES_DEFEND,
            ],
        ];
    }

    /** @return array<string,string> chain key => translated column heading */
    public static function chainLabels(): array {
        return [
            self::CHAIN_POSSESSION => __( 'With the ball', 'talenttrack' ),
            self::CHAIN_PRESSING   => __( 'Without the ball', 'talenttrack' ),
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

    /**
     * The legacy merged set-piece key is accepted on read so an analysis
     * written before the split still renders; it is never offered for
     * writing, and migration 0231 moves the stored rows.
     */
    public static function isSectionKey( string $key ): bool {
        return in_array( $key, self::sectionKeys(), true )
            || $key === self::SECTION_SET_PIECES_LEGACY;
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
            self::SECTION_SET_PIECES_ATTACK              => __( 'Set pieces — ours', 'talenttrack' ),
            self::SECTION_SET_PIECES_DEFEND              => __( 'Set pieces — theirs', 'talenttrack' ),
            self::SECTION_SET_PIECES_LEGACY              => __( 'Set pieces', 'talenttrack' ),
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
     * Four of the six map exactly onto the plan's four goal boxes now that
     * set pieces are split by side. The two transitions have no counterpart
     * at all — match prep never asked for them. Rendering an empty
     * "Planned:" line there would be worse than rendering none: it reads as
     * "we planned nothing" when the truth is "we were never asked".
     */
    public static function prepGoalColumnFor( string $section_key ): ?string {
        switch ( $section_key ) {
            case self::SECTION_GENERAL:
                return 'goals_general';
            case MethodologyEnums::FUNCTION_AANVALLEN:
                return 'goals_attack';
            case MethodologyEnums::FUNCTION_VERDEDIGEN:
                return 'goals_defend';
            case self::SECTION_SET_PIECES_ATTACK:
                return 'goals_attack_setpiece';
            case self::SECTION_SET_PIECES_DEFEND:
                return 'goals_defend_setpiece';
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

    /**
     * The two marks a note can carry (#3091).
     *
     * `_x` on both: "Good" and "Needs work" are short enough that Dutch
     * would otherwise inherit whichever sense happens to be first in the
     * catalogue, and here they qualify one sentence a coach wrote rather
     * than grading anything.
     *
     * @return array<string,string> valence key => label
     */
    public static function valences(): array {
        return [
            self::VALENCE_PLUS  => _x( 'Good', 'match analysis note', 'talenttrack' ),
            self::VALENCE_MINUS => _x( 'Needs work', 'match analysis note', 'talenttrack' ),
        ];
    }

    public static function isValence( string $value ): bool {
        return isset( self::valences()[ $value ] );
    }

    public static function valenceLabel( string $value ): string {
        $valences = self::valences();
        return $valences[ $value ] ?? '';
    }

    /**
     * The sign a marked note carries.
     *
     * Deliberately NOT the ▲ ● ▼ of the section rating and the player
     * marker. The page already has one visual language for "how did this
     * go" at phase and player level; a note is a different granularity, and
     * reusing the triangles would make a note-level mark and a
     * section-level rating look identical inside the same card. U+2212 for
     * the minus so it reads as a sign rather than as a hyphen a coach
     * typed.
     *
     * @return array<string,string>
     */
    public static function valenceGlyphs(): array {
        return [
            ''                  => '',
            self::VALENCE_PLUS  => '+',
            self::VALENCE_MINUS => "\u{2212}",
        ];
    }

    public static function valenceGlyph( string $value ): string {
        $glyphs = self::valenceGlyphs();
        return $glyphs[ $value ] ?? '';
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
        unset( $labels[ self::SECTION_GENERAL ], $labels[ self::SECTION_SET_PIECES_LEGACY ] );
        return $labels;
    }

    public static function isPlayerItemTag( string $value ): bool {
        return isset( self::playerItemTags()[ $value ] );
    }
}
