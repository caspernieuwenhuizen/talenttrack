<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportBlockOptions;
use TT\Modules\Analytics\Reports\PlayerReportComposers\PlayerReportContext;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Analytics\Reports\PlayerSectionRights;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\ReportStudio\Domain\CarriesSensitiveReads;
use TT\Modules\ReportStudio\Domain\CutsForReader;
use TT\Modules\ReportStudio\Domain\RendersDocument;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SectionOptions;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * One section of the player report as a Studio block (#4961): the section's
 * composer payload, composed through the player report's own dispatch
 * (`PlayerReport::block()`) over one evidence packet per player, period and
 * audience, and cut to the audience by `PlayerReportAudience::apply()`, as
 * the report cuts it.
 *
 * Who may receive it is the report's allowlists (`audiencesFor()`), never a
 * list of its own: status, talking points, the development plan, the notes
 * area, the matches, the journey, injuries, behaviour, potential and the
 * staff notes are for staff only. The packet gates each section for the
 * reader as it does on the report: injuries on the medical rung, the journey
 * on the reader's visibility levels, the staff notes on the thread's rules.
 *
 * It prints with the player report's section renderer and is measured with
 * that report's heights. A section a family may receive is a
 * `PlayerFamilySectionBlock`, which names its section.
 *
 * #5022 — every section of the report is one, so the player report template
 * is built from these blocks alone. The notes area (`player_notes`) prints
 * as the report's ruled lines; what is said in the talk is a snapshot's note
 * on that block, never a value in the definition.
 */
class PlayerSectionBlock implements ReportBlock, RendersDocument, CutsForReader, CarriesSensitiveReads {

    /** The Studio key of each section this block covers. */
    public const KEYS = [
        PlayerReportBlock::STATUS         => 'player_status',
        PlayerReportBlock::TALKING_POINTS => 'player_talking_points',
        PlayerReportBlock::RATINGS        => 'player_ratings',
        PlayerReportBlock::ATTENDANCE     => 'player_attendance',
        PlayerReportBlock::MINUTES        => 'player_minutes',
        PlayerReportBlock::GOALS          => 'player_goals',
        PlayerReportBlock::PDP            => 'player_pdp',
        PlayerReportBlock::NOTES          => 'player_notes',
        PlayerReportBlock::MATCHES        => 'player_matches',
        PlayerReportBlock::TESTS          => 'player_tests',
        PlayerReportBlock::JOURNEY        => 'player_journey',
        PlayerReportBlock::INJURIES       => 'player_injuries',
        PlayerReportBlock::BEHAVIOUR      => 'player_behaviour',
        PlayerReportBlock::POTENTIAL      => 'player_potential',
        PlayerReportBlock::THREAD_NOTES   => 'player_thread_notes',
    ];

    /**
     * The payload keys of each section an external audience may receive, as
     * its composer returns them. The report's own audience cut has already
     * run, so these are every key the section still holds for that audience.
     */
    private const EXTERNAL_KEYS = [
        PlayerReportBlock::RATINGS    => [ 'evaluation_count', 'latest', 'latest_date', 'average', 'series', 'categories', 'evaluations', 'has_subcategories', 'detail' ],
        PlayerReportBlock::ATTENDANCE => [ 'activities', 'present', 'late', 'absent', 'excused', 'injured', 'attended', 'missed', 'rate' ],
        PlayerReportBlock::MINUTES    => [ 'apps', 'minutes', 'matches', 'share' ],
        PlayerReportBlock::GOALS      => [ 'items' ],
        PlayerReportBlock::TESTS      => [ 'items' ],
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

    /** The Studio key of a player report section, or null for the letterhead. */
    public static function keyFor( string $section ): ?string {
        return self::KEYS[ $section ] ?? null;
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
        return SectionOptions::of( PlayerReportBlockOptions::ownerOf( $this->section ) );
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_PLAYER;
    }

    public function externalKeys(): array {
        return count( $this->audiences() ) > 1 ? ( self::EXTERNAL_KEYS[ $this->section ] ?? [] ) : [];
    }

    public function render( ReportContext $context, array $options ): array {
        $section = $this->section;
        $payload = SharedReads::remember(
            'player_report_section:' . $context->audience . ':' . $section . ':' . md5( (string) wp_json_encode( $options ) ),
            $context,
            static function () use ( $context, $section, $options ): array {
                $ctx = $options === []
                    ? self::sharedContext( $context )
                    : PlayerReportContext::make( $context->playerId, $context->from, $context->to, $context->userId, $context->audience, [ $section => $options ] );
                return $ctx !== null ? PlayerReport::block( $section, $ctx ) : [];
            }
        );
        $keyed = [];
        foreach ( $payload as $k => $v ) $keyed[ (string) $k ] = $v;

        $cut = PlayerReportAudience::apply( [
            'player_id' => $context->playerId,
            'from'      => $context->from,
            'to'        => $context->to,
            'blocks'    => [ $section ],
            'data'      => [ $section => $keyed ],
        ], $context->audience );
        $shown = $cut['data'][ $section ] ?? [];

        // The injuries block as composed for its reader is a read of them.
        $items = $section === PlayerReportBlock::INJURIES && is_array( $shown['items'] ?? null ) ? $shown['items'] : [];
        if ( $items !== [] ) {
            \TT\Infrastructure\Journey\InjuryReads::record(
                \TT\Infrastructure\Journey\InjuryReads::INJURIES,
                'report_studio',
                [ $context->playerId => count( $items ) ],
                [ $context->userId ]
            );
        }
        // And the journey block's medical and safeguarding entries.
        if ( $section === PlayerReportBlock::JOURNEY ) {
            \TT\Infrastructure\Journey\InjuryReads::recordJourney( 'report_studio', $context->playerId, $shown, [ $context->userId ] );
        }
        return $shown;
    }

    /**
     * #4963 — the injuries section's entries, and the journey section's
     * medical and safeguarding ones, for the document's player.
     */
    public function sensitiveReads( array $data, array $context, int $reader ): array {
        $player = is_numeric( $context['player_id'] ?? null ) ? (int) $context['player_id'] : 0;
        $items  = is_array( $data['items'] ?? null ) ? $data['items'] : [];
        if ( $player <= 0 || $items === [] ) return [];
        if ( $this->section === PlayerReportBlock::INJURIES ) {
            return [ \TT\Infrastructure\Journey\InjuryReads::INJURIES => [ $player => count( $items ) ] ];
        }
        if ( $this->section === PlayerReportBlock::JOURNEY ) {
            $ids = [];
            foreach ( $items as $item ) {
                if ( is_array( $item ) ) $ids[] = (int) ( $item['id'] ?? 0 );
            }
            $n = \TT\Infrastructure\Journey\InjuryReads::sensitiveCount( $player, $ids );
            return $n > 0 ? [ \TT\Infrastructure\Journey\InjuryReads::SENSITIVE => [ $player => $n ] ] : [];
        }
        return [];
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

    /**
     * One context, so one evidence packet, per player, period, audience and
     * reader, for every section block of the report: held in the request's
     * shared reads, and dropped with them.
     */
    private static function sharedContext( ReportContext $context ): ?PlayerReportContext {
        $held = SharedReads::remember( 'player_report_context:' . $context->audience, $context, static fn(): array => [
            'context' => PlayerReportContext::make( $context->playerId, $context->from, $context->to, $context->userId, $context->audience ),
        ] );
        return ( $held['context'] ?? null ) instanceof PlayerReportContext ? $held['context'] : null;
    }
}
