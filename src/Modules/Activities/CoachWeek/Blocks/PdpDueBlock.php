<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Pdp\PdpModule;
use TT\Modules\Pdp\Services\PdpPlanningService;

/**
 * #4497 — "PDP conversations due": the talks still to hold in a PDP block
 * whose window closes inside the panel's window, on the panel's teams.
 *
 * Read from `PdpPlanningService::buildMatrix()`, the PDP planning view's own
 * cells: per team and block, the roster less the talks held and the talks
 * marked not held, counted where the block's `window_end` falls in the
 * window. No second window rule. Links to PDP planning.
 */
final class PdpDueBlock extends FollowUpBlock {

    public function key(): string { return 'pdp'; }

    public function label(): string { return __( 'PDP conversations due', 'talenttrack' ); }

    public function help(): string { return __( 'PDP talks still to hold in a block that closes this week. Opens PDP planning.', 'talenttrack' ); }

    protected function defaults(): array {
        return [
            'head_coach'          => [ true, true ],
            'head_of_development' => [ true, false ],
        ];
    }

    public function available(): bool {
        return ModuleRegistry::isEnabled( PdpModule::class );
    }

    public function panelDefaults(): array { return [ 'pdp_due' => null ]; }

    public function panelFields( WeekContext $week ): array {
        $uid = $week->userId;
        if ( ! user_can( $uid, 'tt_view_pdp_planning' ) || ! self::surfaceOpen( 'pdp-planning', $uid ) ) return [];

        // The panel's teams that PDP planning also shows this user.
        $allowed = PdpPlanningService::accessibleTeamIds( $uid );
        $teams   = [];
        foreach ( array_keys( $week->teams ) as $team_id ) {
            $team_id = (int) $team_id;
            if ( ! $week->canSeeTeam( $team_id ) ) continue;
            if ( $allowed !== null && ! in_array( $team_id, $allowed, true ) ) continue;
            $teams[] = $team_id;
        }
        if ( $teams === [] ) return [];

        $season = PdpPlanningService::currentSeasonId();
        if ( $season <= 0 ) return [];
        $matrix = $week->once( 'pdp_matrix', static fn(): array => PdpPlanningService::buildMatrix( $season, $teams ) );

        $count  = 0;
        $closes = '';
        foreach ( $matrix['teams'] as $team ) {
            foreach ( $team['blocks'] as $cell ) {
                $end = substr( (string) ( $cell['window_end'] ?? '' ), 0, 10 );
                if ( $end === '' || $end < $week->start || $end > $week->end ) continue;
                $open = max( 0, (int) ( $cell['expected'] ?? 0 ) - (int) ( $cell['conducted'] ?? 0 ) - (int) ( $cell['not_held'] ?? 0 ) );
                if ( $open === 0 ) continue;
                $count += $open;
                if ( $closes === '' || $end < $closes ) $closes = $end;
            }
        }
        return $count > 0 ? [ 'pdp_due' => [ 'count' => $count, 'closes' => $closes ] ] : [];
    }
}
