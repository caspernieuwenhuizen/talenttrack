<?php
namespace TT\Modules\MatchExecution\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchPrep\Services\FormationLayoutResolver;

/**
 * PitchLayoutService (#1713) — maps a match-prep lineup to positioned
 * slots for the vertical pitch on the live surface.
 *
 * The lineup stores `slot_number` + `player_id` per half. The
 * `slot_number` matches the `num` field of the layout
 * `FormationLayoutResolver` resolves, which carries the position label +
 * x/y percentages (0..100, left→right / top→bottom). One resolver keeps
 * the pitch, the prep view, and the printable team-sheet aligned (#3574).
 *
 * Pure mapping — no eligibility or scoring decisions — so it stays
 * SaaS-portable (CLAUDE.md §4).
 */
final class PitchLayoutService {

    /**
     * Positioned starting XI for the first half.
     *
     * @param int                                          $formation_template_id The prep's bound formation template (0 = none).
     * @param array<int,int>                               $slot_to_player        slot_number => player_id for half 1.
     * @param array<int, array{name:string, jersey:?int}>  $player_meta           player_id => display data.
     * @param int                                          $team_id               The fixture's team, for its football form when nothing is bound.
     *
     * @return list<array{
     *   slot:int,
     *   label:string,
     *   x:float,
     *   y:float,
     *   player_id:int,
     *   player_name:string,
     *   short_name:string,
     *   jersey:?int
     * }>
     */
    public function positionedXi( int $formation_template_id, array $slot_to_player, array $player_meta, int $team_id = 0 ): array {
        // #3574 — the layout every line-up surface resolves the same way:
        // the template's own geometry, its shape, the team's football form,
        // then 4-3-3. An 8v8 team is no longer drawn on eleven slots.
        $layout = FormationLayoutResolver::layoutFor( $formation_template_id, $team_id );

        $out = [];
        foreach ( $layout as $slot ) {
            $num  = (int) ( $slot['num'] ?? 0 );
            $pid  = (int) ( $slot_to_player[ $num ] ?? 0 );
            $meta = $player_meta[ $pid ] ?? null;
            $name = ( $pid > 0 && $meta !== null ) ? (string) $meta['name'] : '';
            $out[] = [
                'slot'        => $num,
                'label'       => (string) ( $slot['label'] ?? '' ),
                'x'           => (float) ( $slot['x'] ?? 50 ),
                'y'           => (float) ( $slot['y'] ?? 50 ),
                'player_id'   => $pid,
                'player_name' => $name,
                // #3554 — the label the pitch prints, so a client redrawing
                // it after a substitution prints exactly what the server did.
                'short_name'  => self::shortName( $name ),
                'jersey'      => ( $pid > 0 && $meta !== null ) ? $meta['jersey'] : null,
            ];
        }
        return $out;
    }

    /**
     * #3554 — the line-up as it stands now: each substitution puts the
     * player coming on in the slot of the player going off, applied in the
     * order they happened. A sub whose outgoing player holds no slot (the
     * data disagrees with itself) changes nothing rather than guessing.
     *
     * @param array<int,int> $slot_to_player slot_number => player_id at kickoff.
     * @param iterable<object> $subs         non-reversed substitutions, chronological
     *                                       (->player_off_id, ->player_on_id).
     * @return array<int,int>
     */
    public static function applySubstitutions( array $slot_to_player, iterable $subs ): array {
        foreach ( $subs as $sub ) {
            $off  = (int) ( $sub->player_off_id ?? 0 );
            $on   = (int) ( $sub->player_on_id ?? 0 );
            $slot = array_search( $off, $slot_to_player, true );
            if ( $off > 0 && $on > 0 && $slot !== false ) {
                $slot_to_player[ (int) $slot ] = $on;
            }
        }
        return $slot_to_player;
    }

    /**
     * #2223 — the pitch labels a player by first name + last initial
     * ("Daan P."), the way a coach names them from the sideline. A
     * single-word name is shown as-is; empty stays empty.
     */
    public static function shortName( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return '';
        }
        $parts = preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) {
            return $name;
        }
        $first = (string) $parts[0];
        if ( count( $parts ) === 1 ) {
            return $first;
        }
        $last    = (string) $parts[ count( $parts ) - 1 ];
        $initial = function_exists( 'mb_substr' )
            ? mb_strtoupper( mb_substr( $last, 0, 1, 'UTF-8' ), 'UTF-8' )
            : strtoupper( substr( $last, 0, 1 ) );
        if ( $initial === '' ) {
            return $first;
        }
        return $first . ' ' . $initial . '.';
    }
}
