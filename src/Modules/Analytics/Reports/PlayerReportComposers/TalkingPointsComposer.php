<?php
namespace TT\Modules\Analytics\Reports\PlayerReportComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerTalkingPoints;

/**
 * The talking points (#4590), derived from the evidence packet by
 * {@see PlayerTalkingPoints}.
 */
final class TalkingPointsComposer {

    /** @return array<string,mixed> */
    public static function compose( PlayerReportContext $ctx ): array {
        return [ 'items' => PlayerTalkingPoints::derive( $ctx->packet() ?? [], (int) ( $ctx->player->team_id ?? 0 ), $ctx->from, $ctx->to ) ];
    }
}
