<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;

/**
 * "What changed" (#4590): the squad's journey events in the window, as the
 * reader may see them, and how many players are injured now.
 */
final class ChangesComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        // An unattended caller (viewer 0) gets the conservative set: public
        // and coaching-staff events only, never medical or safeguarding ones.
        $visibilities = $ctx->viewer_user_id > 0
            ? PlayerEventsRepository::visibilitiesForUser( $ctx->viewer_user_id )
            : [ 'public', 'coaching_staff' ];

        $events = ( new PlayerEventsRepository() )->forTeamBetween(
            $ctx->team_id, $ctx->from, $ctx->to, TeamMonthlyReport::CHANGE_EVENT_TYPES, $visibilities
        );

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

        return [
            'events'        => $items,
            'open_injuries' => count( ( new InjuryRepository() )->listForTeams( [ $ctx->team_id ], [ 'status' => 'open' ] ) ),
        ];
    }
}
