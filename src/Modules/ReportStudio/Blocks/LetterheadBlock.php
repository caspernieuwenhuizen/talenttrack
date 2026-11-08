<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportComposers\LetterheadComposer as PlayerLetterhead;
use TT\Modules\Analytics\Reports\PlayerReportComposers\PlayerReportContext;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\LetterheadComposer as TeamLetterhead;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;

/**
 * Letterhead (#4572) — who the report is about and over which period, in the
 * club's colours. A team reads the team monthly report's letterhead composer,
 * a player the player report's, so the Studio's heading names the same team,
 * head coach and squad as those reports do. The colours are
 * `ReportBrandColour`'s, the ones a printed report carries.
 *
 * The player report's letterhead is on the scout and the family allowlists,
 * so a player's letterhead may reach both.
 */
final class LetterheadBlock implements ReportBlock {

    /** What a player letterhead carries, every one of them on the family allowlist. */
    private const PLAYER_KEYS = [
        'player_id', 'name', 'photo_url', 'team_id', 'team_name', 'age_group', 'head_coach',
        'jersey_number', 'birth_year', 'from', 'to', 'generated_at', 'subject', 'brand',
    ];

    public function key(): string {
        return 'letterhead';
    }

    public function label(): string {
        return _x( 'Letterhead', 'report studio block', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return in_array( $context->scope, $this->scopes(), true );
    }

    public function externalKeys(): array {
        return self::PLAYER_KEYS;
    }

    public function render( ReportContext $context, array $options ): array {
        $primary = ReportBrandColour::primary( $context->userId );
        $brand   = [
            'primary' => $primary,
            'tint'    => ReportBrandColour::tint( $primary ),
            'number'  => ReportBrandColour::numberColour( $primary ),
        ];

        if ( $context->scope === ReportContext::SCOPE_TEAM ) {
            $head = TeamLetterhead::compose( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) );
            return $head + [ 'subject' => 'team', 'brand' => $brand ];
        }

        $player = PlayerReportContext::make( $context->playerId, $context->from, $context->to, $context->userId, $context->audience );
        if ( $player === null ) return [ 'brand' => $brand ];
        return PlayerLetterhead::compose( $player ) + [ 'subject' => 'player', 'brand' => $brand ];
    }
}
