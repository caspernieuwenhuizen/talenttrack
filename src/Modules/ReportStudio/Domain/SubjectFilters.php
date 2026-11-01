<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\Fact;

/**
 * SubjectFilters — the `FactQuery` filters that narrow a fact to a block's
 * subject and period (#4568).
 *
 * A fact without the dimension a subject needs (a player subject on a fact
 * with no player) cannot answer for that subject, and says so with null
 * rather than answering for everyone. The reader's own scope is applied by
 * `FactQuery` underneath, so these filters only ever narrow.
 */
final class SubjectFilters {

    /**
     * @return array<string,mixed>|null
     */
    public static function for( ReportContext $context, Fact $fact ): ?array {
        $filters = [];
        switch ( $context->scope ) {
            case ReportContext::SCOPE_PLAYER:
                if ( $fact->dimension( 'player_id' ) === null ) return null;
                if ( $context->playerId > 0 ) $filters['player_id_eq'] = $context->playerId;
                break;
            case ReportContext::SCOPE_GROUP:
                if ( $fact->dimension( 'player_id' ) === null ) return null;
                if ( $context->playerIds !== [] ) $filters['player_id_in'] = $context->playerIds;
                break;
            case ReportContext::SCOPE_TEAM:
                if ( $fact->dimension( 'team_id' ) === null ) return null;
                if ( $context->teamId > 0 ) $filters['team_id_eq'] = $context->teamId;
                break;
        }
        $filters['date_after']  = $context->from;
        $filters['date_before'] = $context->to;
        return $filters;
    }
}
