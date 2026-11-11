<?php
namespace TT\Modules\ReportStudio\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\PlayerSectionBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\ReportTemplate;
use TT\Modules\ReportStudio\Domain\ReportTemplateRegistry;

/**
 * The player report as Studio templates (#5022, epic #4564): one per
 * audience, each with the sections today's report opens on for that
 * audience, in its order (`PlayerReportBlock::normalise()` over
 * `PlayerReportAudience::blocksFor()`, the composition `PlayerReport` makes
 * for an empty request). Attendance and playing time stand side by side when
 * they follow each other, as the report prints them.
 *
 * A family or a scout receives the report only as a snapshot; the template
 * fixes the audience, and the builder chooses the rest.
 */
final class PlayerReportTemplate {

    public const KEYS = [
        PlayerReportAudience::INTERNAL => 'player_report',
        PlayerReportAudience::SCOUT    => 'player_report_scout',
        PlayerReportAudience::FAMILY   => 'player_report_family',
    ];

    public static function register(): void {
        foreach ( self::KEYS as $audience => $key ) {
            ReportTemplateRegistry::register( $key, static fn(): ReportTemplate => self::for( $audience ) );
        }
    }

    public static function for( string $audience ): ReportTemplate {
        return new ReportTemplate(
            self::KEYS[ $audience ] ?? self::KEYS[ PlayerReportAudience::INTERNAL ],
            self::label( $audience ),
            self::description( $audience ),
            [
                'kind'     => ReportStartRules::KIND_REPORT,
                'scope'    => ReportContext::SCOPE_PLAYER,
                'audience' => $audience,
                'layout'   => ReportStartRules::LAYOUT_ROWS,
            ],
            [ 'blocks' => self::blocks( $audience ) ]
        );
    }

    /**
     * The sections the report opens on for this audience, as blocks.
     *
     * @return list<array{id:string, key:string, options:array<string,mixed>, width:string}>
     */
    public static function blocks( string $audience ): array {
        $sections = PlayerReportBlock::normalise( PlayerReportAudience::blocksFor( $audience, [] ) );
        $paired   = PlayerReportBlock::pairsAttendance( $sections );

        $out = [];
        foreach ( $sections as $section ) {
            $key = $section === PlayerReportBlock::LETTERHEAD ? LetterheadBlock::KEY : PlayerSectionBlock::keyFor( $section );
            if ( $key === null ) continue;
            $half  = $paired && in_array( $section, [ PlayerReportBlock::ATTENDANCE, PlayerReportBlock::MINUTES ], true );
            $out[] = [
                'id'      => 'b' . ( count( $out ) + 1 ),
                'key'     => $key,
                'options' => [],
                'width'   => $half ? ReportDefinition::WIDTH_HALF : ReportDefinition::WIDTH_FULL,
            ];
        }
        return $out;
    }

    private static function label( string $audience ): string {
        switch ( $audience ) {
            case PlayerReportAudience::SCOUT:  return __( 'Player report for a scout', 'talenttrack' );
            case PlayerReportAudience::FAMILY: return __( 'Player report for the family', 'talenttrack' );
        }
        return __( 'Player report', 'talenttrack' );
    }

    private static function description( string $audience ): string {
        switch ( $audience ) {
            case PlayerReportAudience::SCOUT:
                return __( 'The player report as a scout receives it: evaluation scores without the coach\'s notes, attendance, playing time and tests at the public level.', 'talenttrack' );
            case PlayerReportAudience::FAMILY:
                return __( 'The player report as the player and their parents receive it, through a snapshot you share: evaluation scores without the coach\'s notes, attendance, playing time, goals and tests.', 'talenttrack' );
        }
        return __( 'The player report for the conversation with the player: status, talking points, evaluations, attendance, playing time, goals, the development plan and space for notes.', 'talenttrack' );
    }
}
