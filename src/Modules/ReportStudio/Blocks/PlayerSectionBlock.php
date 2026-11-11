<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Visibility\ReaderSet;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerSectionRights;
use TT\Modules\Analytics\Reports\PlayerReportComposers\EvidenceComposer;
use TT\Modules\Analytics\Reports\PlayerReportComposers\PlayerReportContext;
use TT\Modules\Analytics\Reports\PlayerReportComposers\TalkingPointsComposer;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\ReportStudio\Domain\CutsForReader;
use TT\Modules\ReportStudio\Domain\RendersDocument;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * One section of the player report as a Studio block (#4961): the section's
 * composer payload, composed through the player report's own composers over
 * one evidence packet per player, period and audience, and cut to the
 * audience by `PlayerReportAudience::apply()`, as the report cuts it.
 *
 * Who may receive it is the report's allowlists (`audiencesFor()`), never a
 * list of its own: status, talking points, the development plan, the matches,
 * the journey, injuries, behaviour and potential are for staff only. The
 * packet gates each section for the reader as it does on the report: injuries
 * on the medical rung, the journey on the reader's visibility levels.
 *
 * It prints with the player report's section renderer and is measured with
 * that report's heights. A section a family may receive is a
 * `PlayerFamilySectionBlock`, which names its section.
 */
class PlayerSectionBlock implements ReportBlock, RendersDocument, CutsForReader {

    /** The Studio key of each section this block covers. */
    public const KEYS = [
        PlayerReportBlock::STATUS         => 'player_status',
        PlayerReportBlock::TALKING_POINTS => 'player_talking_points',
        PlayerReportBlock::PDP            => 'player_pdp',
        PlayerReportBlock::MATCHES        => 'player_matches',
        PlayerReportBlock::TESTS          => 'player_tests',
        PlayerReportBlock::JOURNEY        => 'player_journey',
        PlayerReportBlock::INJURIES       => 'player_injuries',
        PlayerReportBlock::BEHAVIOUR      => 'player_behaviour',
        PlayerReportBlock::POTENTIAL      => 'player_potential',
    ];

    final public function __construct( protected readonly string $section ) {
        if ( ! isset( self::KEYS[ $section ] ) ) {
            throw new \InvalidArgumentException( 'Not a player section block: ' . $section );
        }
    }

    /** The block for a section: one that names its section when a family may receive it. */
    public static function for( string $section ): self {
        return in_array( PlayerReportAudience::FAMILY, PlayerReportAudience::audiencesFor( $section ), true )
            ? new PlayerFamilySectionBlock( $section )
            : new self( $section );
    }

    /** The player report section this block is. */
    public function section(): string {
        return $this->section;
    }

    public function key(): string {
        return self::KEYS[ $this->section ];
    }

    public function label(): string {
        return PlayerReportBlock::title( $this->section );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER ];
    }

    public function audiences(): array {
        return PlayerReportAudience::audiencesFor( $this->section );
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_PLAYER;
    }

    /**
     * A section an external audience may receive is a list of entries, already
     * cut to that audience when it was composed.
     */
    public function externalKeys(): array {
        return count( $this->audiences() ) > 1 ? [ 'items' ] : [];
    }

    public function render( ReportContext $context, array $options ): array {
        $sections = SharedReads::remember( 'player_report_sections:' . $context->audience, $context, static function () use ( $context ): array {
            $ctx = PlayerReportContext::make( $context->playerId, $context->from, $context->to, $context->userId, $context->audience );
            if ( $ctx === null ) return [];
            $out = [ PlayerReportBlock::TALKING_POINTS => TalkingPointsComposer::compose( $ctx ) ];
            foreach ( EvidenceComposer::BLOCKS as $block ) $out[ $block ] = EvidenceComposer::compose( $block, $ctx );
            return $out;
        } );
        if ( ! is_array( $sections[ $this->section ] ?? null ) ) return [];
        $payload = [];
        foreach ( $sections[ $this->section ] as $k => $v ) $payload[ (string) $k ] = $v;

        $cut = PlayerReportAudience::apply( [
            'player_id' => $context->playerId,
            'from'      => $context->from,
            'to'        => $context->to,
            'blocks'    => [ $this->section ],
            'data'      => [ $this->section => $payload ],
        ], $context->audience );
        return $cut['data'][ $this->section ] ?? [];
    }

    /**
     * The frozen section as this reader may read it now, by the rights the
     * evidence packet asks when it composes the section live
     * (`PlayerSectionRights`): injuries on the medical rung, staff notes on
     * the thread and its private notes, the development plan on the file,
     * evaluation notes on the sensitive-field registry, journey entries and
     * tests on the reader's levels. Talking points drawn from injuries, the
     * journey or the tests go with what they were drawn from.
     */
    public function forReader( array $data, int $reader, int $player_id ): array {
        $readers = ReaderSet::of( [ $reader ] );
        $items   = static fn( array $d ): array => is_array( $d['items'] ?? null ) ? array_values( $d['items'] ) : [];

        switch ( $this->section ) {
            case PlayerReportBlock::INJURIES:
                return PlayerSectionRights::seesInjuries( $readers ) ? $data : [ 'items' => [] ] + $data;

            case PlayerReportBlock::THREAD_NOTES:
                if ( ! PlayerSectionRights::readsThread( $readers, $player_id ) ) return [ 'items' => [] ] + $data;
                if ( PlayerSectionRights::seesPrivateNotes( $readers, $player_id ) ) return $data;
                $data['items'] = array_values( array_filter(
                    $items( $data ),
                    static fn( $n ): bool => is_array( $n ) && ( $n['visibility'] ?? '' ) === PlayerSectionRights::publicNotes()
                ) );
                return $data;

            case PlayerReportBlock::PDP:
                return PlayerSectionRights::seesPdpFile( $readers, $player_id ) || empty( $data['available'] ) ? $data : PlayerSectionRights::PDP_NONE;

            case PlayerReportBlock::RATINGS:
                if ( PlayerSectionRights::seesEvaluationNotes( $readers, $player_id ) || ! is_array( $data['evaluations'] ?? null ) ) return $data;
                $data['evaluations'] = PlayerSectionRights::withoutEvaluationNotes( array_values( array_filter( $data['evaluations'], 'is_array' ) ) );
                return $data;

            case PlayerReportBlock::JOURNEY:
                $ids  = array_map( static fn( $e ): int => is_array( $e ) ? (int) ( $e['id'] ?? 0 ) : 0, $items( $data ) );
                $seen = PlayerSectionRights::readableJourneyIds( $readers, $ids, $player_id );
                $data['items'] = array_values( array_filter( $items( $data ), static fn( $e ): bool => is_array( $e ) && isset( $seen[ (int) ( $e['id'] ?? 0 ) ] ) ) );
                return $data;

            case PlayerReportBlock::TESTS:
                $tests = PlayerSectionRights::readableTests( PlayerSectionRights::testLevels( $readers ) );
                $data['items'] = array_values( array_filter( $items( $data ), static fn( $t ): bool => is_array( $t ) && isset( $tests[ (int) ( $t['definition_id'] ?? 0 ) ] ) ) );
                return $data;

            case PlayerReportBlock::TALKING_POINTS:
                // A point carries no record id, so one drawn from a record a
                // reader may not see goes unless they hold every level of it.
                $medical = PlayerSectionRights::seesInjuries( $readers );
                $all     = [ RecordVisibility::LEVEL_PUBLIC, RecordVisibility::LEVEL_COACHING_STAFF, RecordVisibility::LEVEL_MEDICAL, RecordVisibility::LEVEL_SAFEGUARDING ];
                $journey = array_diff( $all, PlayerSectionRights::journeyLevels( $readers ) ) === [];
                $tests   = array_diff( RecordVisibility::measurementLevels(), PlayerSectionRights::testLevels( $readers ) ) === [];
                $data['items'] = array_values( array_filter( $items( $data ), static function ( $p ) use ( $medical, $journey, $tests ): bool {
                    $key = is_array( $p ) ? (string) ( $p['key'] ?? '' ) : '';
                    if ( $key === 'returned_from_injury' ) return $medical;
                    if ( $key === 'transition' ) return $journey;
                    if ( $key === 'tests_down' ) return $tests;
                    return is_array( $p );
                } ) );
                return $data;
        }
        return $data;
    }

    public function document( array $data ): string {
        return '<div class="tt-pr-doc tt-sd-player">' . PlayerReportPdfDocument::sectionHtml( $this->section, $data ) . '</div>';
    }

    public function documentHeight( array $data ): float {
        return PlayerReportLayout::printedHeight( $this->section, $data );
    }
}
