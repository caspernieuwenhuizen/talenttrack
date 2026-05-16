<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Squad. Multi-pick of players from the anchor team's
 * active roster, with a per-player eligible-positions multi-select.
 *
 * Eligibility uses position TYPES (GK/DEF/MID/FWD), not slot codes,
 * per the spec shaping decision — keeps the picker to 4 chips per
 * player rather than 11 slot codes.
 *
 * Cross-team adds are a follow-up affordance; v1 wizard scope is the
 * anchor team's roster + an explicit cross-team picker would belong
 * here when added.
 */
final class SquadStep implements WizardStepInterface {

    public function slug(): string { return 'squad'; }
    public function label(): string { return __( 'Squad', 'talenttrack' ); }

    public function render( array $state ): void {
        $team_id = (int) ( $state['team_id'] ?? 0 );
        if ( $team_id <= 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'No anchor team picked. Go back to the Basics step.', 'talenttrack' ) . '</p>';
            return;
        }

        global $wpdb; $p = $wpdb->prefix;
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, preferred_positions
               FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
           ORDER BY last_name ASC, first_name ASC",
            $team_id, CurrentClub::id()
        ) ) ?: [];

        if ( ! $players ) {
            echo '<p class="tt-notice">' . esc_html__( 'The chosen team has no active players. Add players to the team first.', 'talenttrack' ) . '</p>';
            return;
        }

        $squad_state = is_array( $state['squad'] ?? null ) ? $state['squad'] : [];
        // Index existing state by player_id for quick lookup.
        $by_id = [];
        foreach ( $squad_state as $row ) {
            $pid = (int) ( $row['player_id'] ?? 0 );
            if ( $pid > 0 ) $by_id[ $pid ] = $row;
        }

        $position_types = [
            'GK'  => __( 'Goalkeeper', 'talenttrack' ),
            'DEF' => __( 'Defender',   'talenttrack' ),
            'MID' => __( 'Midfielder', 'talenttrack' ),
            'FWD' => __( 'Forward',    'talenttrack' ),
        ];

        echo '<p>' . esc_html__( 'Tick the players in the squad and mark which position types each can play.', 'talenttrack' ) . '</p>';
        echo '<ul class="tt-wizard-squad-list" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px;">';
        foreach ( $players as $pl ) {
            $pid   = (int) $pl->id;
            $name  = trim( ( (string) $pl->first_name ) . ' ' . ( (string) $pl->last_name ) );
            $row   = $by_id[ $pid ] ?? null;
            $checked = $row !== null;
            $positions = is_array( $row['eligible_positions'] ?? null ) ? $row['eligible_positions'] : self::defaultsFor( (string) $pl->preferred_positions );

            echo '<li class="tt-wizard-squad-row" style="padding:8px;border:1px solid var(--tt-line, #e2e8f0);border-radius:6px;">';
            echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;">';
            echo '<input type="checkbox" name="squad_in[]" value="' . esc_attr( (string) $pid ) . '" ' . checked( $checked, true, false ) . '> ';
            echo esc_html( $name );
            echo '</label>';
            echo '<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:10px;font-size:13px;">';
            foreach ( $position_types as $code => $label ) {
                $is_pos = in_array( $code, $positions, true );
                echo '<label style="display:inline-flex;align-items:center;gap:4px;">';
                echo '<input type="checkbox" name="squad_positions[' . esc_attr( (string) $pid ) . '][]" value="' . esc_attr( $code ) . '" ' . checked( $is_pos, true, false ) . '> ';
                echo esc_html( $label );
                echo '</label>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    public function validate( array $post, array $state ) {
        $picked = isset( $post['squad_in'] ) && is_array( $post['squad_in'] )
            ? array_map( 'absint', $post['squad_in'] )
            : [];
        if ( ! $picked ) {
            return new \WP_Error( 'squad_empty', __( 'Pick at least one player for the squad.', 'talenttrack' ) );
        }
        $positions_post = isset( $post['squad_positions'] ) && is_array( $post['squad_positions'] )
            ? $post['squad_positions']
            : [];

        $allowed = [ 'GK', 'DEF', 'MID', 'FWD' ];
        $squad = [];
        foreach ( $picked as $pid ) {
            if ( $pid <= 0 ) continue;
            $raw = $positions_post[ (string) $pid ] ?? [];
            $positions = [];
            if ( is_array( $raw ) ) {
                foreach ( $raw as $code ) {
                    $code = strtoupper( sanitize_key( (string) $code ) );
                    if ( in_array( $code, $allowed, true ) ) $positions[] = $code;
                }
                $positions = array_values( array_unique( $positions ) );
            }
            $squad[] = [
                'player_id'          => $pid,
                'eligible_positions' => $positions,
            ];
        }
        return [ 'squad' => $squad ];
    }

    public function nextStep( array $state ): ?string { return 'matches'; }
    public function submit( array $state ) { return null; }

    /**
     * Derive default eligible-position TYPES from the player's
     * `preferred_positions` JSON column. Heuristic mapping; a coach
     * can always trim or expand in the checkbox grid.
     */
    private static function defaultsFor( string $preferred_positions_json ): array {
        $decoded = json_decode( $preferred_positions_json, true );
        if ( ! is_array( $decoded ) ) return [];
        $map = [
            'GK' => 'GK',
            'CB' => 'DEF', 'RB' => 'DEF', 'LB' => 'DEF', 'RCB' => 'DEF', 'LCB' => 'DEF', 'RWB' => 'DEF', 'LWB' => 'DEF',
            'CM' => 'MID', 'CDM' => 'MID', 'DM' => 'MID', 'CAM' => 'MID', 'AM' => 'MID',
            'RCM' => 'MID', 'LCM' => 'MID', 'RDM' => 'MID', 'LDM' => 'MID', 'RAM' => 'MID', 'LAM' => 'MID',
            'RM' => 'MID', 'LM' => 'MID',
            'ST' => 'FWD', 'RST' => 'FWD', 'LST' => 'FWD', 'CF' => 'FWD',
            'RW' => 'FWD', 'LW' => 'FWD',
        ];
        $out = [];
        foreach ( $decoded as $pos ) {
            $up = strtoupper( (string) $pos );
            $type = $map[ $up ] ?? '';
            if ( $type !== '' && ! in_array( $type, $out, true ) ) $out[] = $type;
        }
        return $out;
    }
}
