<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Visibility\ReaderSet;
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

    /** The frozen section as this reader may read it now (`PlayerSectionRights::cut()`). */
    public function forReader( array $data, int $reader, array $context ): ?array {
        $player_id = is_numeric( $context['player_id'] ?? null ) ? (int) $context['player_id'] : 0;
        return PlayerSectionRights::cut( $this->section, $data, ReaderSet::of( [ $reader ] ), $player_id );
    }

    public function document( array $data ): string {
        return '<div class="tt-pr-doc tt-sd-player">' . PlayerReportPdfDocument::sectionHtml( $this->section, $data ) . '</div>';
    }

    public function documentHeight( array $data ): float {
        return PlayerReportLayout::printedHeight( $this->section, $data );
    }
}
