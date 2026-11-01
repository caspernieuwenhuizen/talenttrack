<?php
namespace TT\Modules\Analytics\Reports\PlayerReportComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Players\Services\PlayerPhoto;

/**
 * The player report's letterhead: who, which team, which window (#4590).
 * Reads nothing from the evidence packet.
 */
final class LetterheadComposer {

    /** @return array<string,mixed> */
    public static function compose( PlayerReportContext $ctx ): array {
        $player  = $ctx->player;
        $team_id = (int) ( $player->team_id ?? 0 );
        $team    = $team_id > 0 ? QueryHelpers::get_team( $team_id ) : null;
        $dob     = (string) ( $player->date_of_birth ?? '' );
        $jersey  = $player->jersey_number ?? null;

        return [
            'player_id'     => (int) ( $player->id ?? 0 ),
            'name'          => QueryHelpers::player_display_name( $player ),
            'photo_url'     => PlayerPhoto::url( $player ),
            'team_id'       => $team_id,
            'team_name'     => $team ? (string) ( $team->name ?? '' ) : '',
            'age_group'     => $team ? (string) ( $team->age_group ?? '' ) : '',
            'head_coach'    => $team_id > 0 ? ( new EvalCoverageService() )->headCoachNameForTeam( $team_id ) : '',
            'jersey_number' => $jersey !== null && $jersey !== '' ? (int) $jersey : null,
            'birth_year'    => $dob !== '' ? (int) substr( $dob, 0, 4 ) : null,
            'from'          => $ctx->from,
            'to'            => $ctx->to,
            'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
        ];
    }
}
