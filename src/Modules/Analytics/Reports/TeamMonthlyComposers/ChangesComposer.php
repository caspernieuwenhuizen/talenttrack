<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;

/**
 * "What changed" (#4590): the squad's journey events in the window, as the
 * readers may see them, and how many players are injured now.
 */
final class ChangesComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        // #4991 — the levels every reader holds; an unattended caller gets
        // the conservative set: public and coaching-staff events only, never
        // medical or safeguarding ones.
        $visibilities = $ctx->journeyVisibilities();

        // A PDP verdict entry follows the PDP verdict right, as on the
        // timeline, for every reader; an unattended caller holds none.
        $events = $visibilities === [] ? [] : $ctx->readableEntries( ( new PlayerEventsRepository() )->forTeamBetween(
            $ctx->team_id, $ctx->from, $ctx->to, TeamMonthlyReport::CHANGE_EVENT_TYPES, $visibilities
        ) );

        $items = [];
        foreach ( $events as $e ) {
            $items[] = [
                'player_id'  => (int) ( $e->player_id ?? 0 ),
                'name'       => TeamReportFigures::name( $e ),
                'event_type' => (string) ( $e->event_type ?? '' ),
                'date'       => substr( (string) ( $e->event_date ?? '' ), 0, 10 ),
                'summary'    => (string) ( $e->summary ?? '' ),
            ];
        }

        $out = [ 'events' => $items ];
        // #4991 — how many are injured is injury information: counted only
        // when every reader may read the squad's injury records, and left
        // out otherwise, so the section names no count at all.
        if ( $ctx->readsInjuries() ) {
            $out['open_injuries'] = count( ( new InjuryRepository() )->listForTeams( [ $ctx->team_id ], [ 'status' => 'open' ] ) );
        }
        return $out;
    }
}
