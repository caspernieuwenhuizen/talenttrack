<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryOverviewScope;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\Services\PlayerAvailability;

/**
 * #4496 — players on the week's teams an injury makes unavailable today,
 * and when they are expected back.
 *
 * Who gets the line: a team counts only when the viewer may read its
 * injuries (`InjuryOverviewScope`) or change its activities
 * (`WeekContext::canEditTeam()`), the people who plan with that squad.
 * Scouts and read-only observers get the line only when an academy
 * switches it on for them (`optInFor()`), as with the availability counts.
 *
 * What it says, with the rule an existing surface already uses:
 * - **The marker** follows the planning screens (`PlayerAvailability`,
 *   #4005 decision 3): `unavailable`, the neutral word, and nothing else.
 * - **The return** follows the injury overview: only on a team whose
 *   injuries the viewer may read is the marker refined to `returning`
 *   (expected back within the window) or given an expected return beyond
 *   it (`back`). Showing that date to a named player is an injury read, so
 *   it is audited through `InjuryOverviewScope::recordRead()`. The bare
 *   marker is not.
 *
 * The injury type, body part, severity and notes never leave this class.
 */
final class InjuriesBlock extends AttentionBlock {

    public const UNAVAILABLE = 'unavailable';
    public const RETURNING   = 'returning';

    /** Personas who get the line only when an academy switches it on. */
    private const OPT_IN = [ 'scout', 'readonly_observer' ];

    public function key(): string { return 'injuries'; }
    public function label(): string { return __( 'Unavailable and returning this week', 'talenttrack' ); }
    public function help(): string { return __( 'Players an injury keeps out today, and when they are expected back. Never the injury itself.', 'talenttrack' ); }

    public function optInFor( string $persona ): bool {
        return in_array( $persona, self::OPT_IN, true );
    }

    public function defaultFor( string $persona, string $device ): bool {
        return ! $this->optInFor( $persona ) && parent::defaultFor( $persona, $device );
    }

    protected function field(): string { return 'attention_injuries'; }

    public function panelFields( WeekContext $week ): array {
        $out = parent::panelFields( $week );

        // The expected return of a named player is injury detail read here.
        $line    = $out[ $this->field() ] ?? [];
        $players = is_array( $line ) && is_array( $line['players'] ?? null ) ? $line['players'] : [];
        $read    = [];
        foreach ( $players as $item ) {
            $item = (array) $item;
            if ( ( $item['state'] ?? '' ) === self::RETURNING || (string) ( $item['back'] ?? '' ) !== '' ) {
                $read[] = (object) [ 'player_id' => (int) ( $item['player_id'] ?? 0 ) ];
            }
        }
        InjuryOverviewScope::recordRead( 'coach_week', $read );

        return $out;
    }

    protected function items( WeekContext $week ): array {
        $roster = self::players( $week );
        if ( $roster === [] ) return [];

        // The teams whose injuries the viewer may read, and the teams the
        // line covers at all: those, plus the teams they plan for.
        $readable = [];
        foreach ( InjuryOverviewScope::teams( $week->userId, user_can( $week->userId, 'tt_edit_settings' ) ) as $team ) {
            $tid = (int) ( ( (array) $team )['id'] ?? 0 );
            if ( $week->canSeeTeam( $tid ) ) $readable[ $tid ] = true;
        }
        $covered = $readable;
        foreach ( array_keys( $week->teams ) as $tid ) {
            if ( $week->canEditTeam( (int) $tid ) ) $covered[ (int) $tid ] = true;
        }

        $eligible = array_filter( $roster, static fn( array $p ): bool => isset( $covered[ $p['team_id'] ] ) );

        $out = [];
        foreach ( PlayerAvailability::unavailableSet( array_keys( $eligible ), $week->today ) as $pid => $_ ) {
            if ( ! isset( $eligible[ $pid ] ) ) continue;
            $out[ $pid ] = [
                'player_id' => $pid,
                'name'      => self::fullName( $eligible[ $pid ]['first_name'], $eligible[ $pid ]['last_name'] ),
                'state'     => self::UNAVAILABLE,
                'back'      => '',
            ];
        }

        // Read whether or not anyone is out today, so the week costs the
        // same queries whatever its data (CoachWeekTest counts them).
        if ( $readable !== [] ) {
            $rows = ( new InjuryRepository() )->listForTeams( array_keys( $readable ), [ 'status' => 'open', 'to' => $week->today ] );
            foreach ( $rows as $row ) {
                $r   = (array) $row;
                $pid = (int) ( $r['player_id'] ?? 0 );
                if ( ! isset( $out[ $pid ] ) || ! isset( $readable[ $eligible[ $pid ]['team_id'] ] ) ) continue;

                $back = substr( (string) ( $r['expected_return'] ?? '' ), 0, 10 );
                if ( $back === '' || $back < $week->today ) continue;
                if ( $back <= $week->end ) {
                    // Another open record for the same player may still run
                    // past the window; the later return wins.
                    if ( $out[ $pid ]['back'] === '' ) $out[ $pid ]['state'] = self::RETURNING;
                } else {
                    $out[ $pid ]['state'] = self::UNAVAILABLE;
                    $out[ $pid ]['back']  = max( $out[ $pid ]['back'], $back );
                }
            }
        }
        return array_values( $out );
    }
}
