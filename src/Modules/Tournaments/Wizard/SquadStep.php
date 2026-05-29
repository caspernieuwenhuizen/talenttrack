<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Squad. Multi-pick of players from the anchor team's
 * active + trial roster, with a per-player eligible-positions chip
 * row driving auto-balance on the planner.
 *
 * Specific position codes (#975 decision 2026-05-28): the chip strip
 * is `GK · CB · LB · RB · DM · CM · AM · LW · RW · ST` — matches the
 * blueprint editor vocabulary. The auto-balance algorithm reads the
 * code's first letter to bucket into formation slots.
 *
 * Trial players appear in the list with a `Trial` badge, unchecked by
 * default; the coach has to actively tick them.
 *
 * Cross-team adds are a follow-up affordance; v1 wizard scope is the
 * anchor team's roster (active + trial).
 */
final class SquadStep implements WizardStepInterface {

    public function slug(): string { return 'squad'; }
    public function label(): string { return __( 'Squad', 'talenttrack' ); }

    /**
     * Specific position codes (locked-in decision #975). Mirrors the
     * blueprint editor's vocabulary so coaches see the same chip set
     * across surfaces.
     *
     * @return array<string,string>
     */
    public static function positionCodes(): array {
        return [
            'GK' => __( 'Goalkeeper', 'talenttrack' ),
            'CB' => __( 'Centre back', 'talenttrack' ),
            'LB' => __( 'Left back', 'talenttrack' ),
            'RB' => __( 'Right back', 'talenttrack' ),
            'DM' => __( 'Defensive mid', 'talenttrack' ),
            'CM' => __( 'Centre mid', 'talenttrack' ),
            'AM' => __( 'Attacking mid', 'talenttrack' ),
            'LW' => __( 'Left wing', 'talenttrack' ),
            'RW' => __( 'Right wing', 'talenttrack' ),
            'ST' => __( 'Striker', 'talenttrack' ),
        ];
    }

    public function render( array $state ): void {
        WizardAssets::enqueue();

        $team_id = (int) ( $state['team_id'] ?? 0 );
        if ( $team_id <= 0 ) {
            echo '<div class="tt-tournament-wizard"><p class="tt-notice">' . esc_html__( 'No anchor team picked. Go back to the Basics step.', 'talenttrack' ) . '</p></div>';
            return;
        }

        global $wpdb; $p = $wpdb->prefix;
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, preferred_positions, status
               FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
           ORDER BY last_name ASC, first_name ASC",
            $team_id, CurrentClub::id()
        ) ) ?: [];

        if ( ! $players ) {
            echo '<div class="tt-tournament-wizard"><p class="tt-notice">' . esc_html__( 'The chosen team has no active players. Add players to the team first.', 'talenttrack' ) . '</p></div>';
            return;
        }

        $squad_state = is_array( $state['squad'] ?? null ) ? $state['squad'] : [];
        $is_first_visit = empty( $squad_state );
        $by_id = [];
        foreach ( $squad_state as $row ) {
            $pid = (int) ( $row['player_id'] ?? 0 );
            if ( $pid > 0 ) $by_id[ $pid ] = $row;
        }

        $position_codes = self::positionCodes();

        echo '<div class="tt-tournament-wizard">';
        echo '<p class="ttw-step-desc">' . esc_html__( 'Tick the players in the squad and mark which specific positions each can play. Trial players are unchecked by default — tick them only if they are joining.', 'talenttrack' ) . '</p>';
        echo '<div class="ttw-card" data-ttw-squad>';

        // Toolbar — search + count + Mark-all-present.
        echo '<div class="ttw-squad-toolbar">';
        echo '<div class="ttw-search">';
        echo '<label class="screen-reader-text" for="ttw-squad-search">' . esc_html__( 'Filter by name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="ttw-squad-search" data-ttw-squad-search placeholder="' . esc_attr__( 'Filter by name…', 'talenttrack' ) . '" inputmode="search">';
        echo '</div>';
        echo '<span class="ttw-count" data-ttw-squad-count><strong>0</strong> ' . esc_html__( 'in squad', 'talenttrack' ) . '</span>';
        echo '<button type="button" class="tt-button tt-button-secondary" data-ttw-mark-all>' . esc_html__( 'Mark all present', 'talenttrack' ) . '</button>';
        echo '</div>';

        echo '<ul class="ttw-squad-list">';
        foreach ( $players as $pl ) {
            $pid   = (int) $pl->id;
            $name  = trim( ( (string) $pl->first_name ) . ' ' . ( (string) $pl->last_name ) );
            $row   = $by_id[ $pid ] ?? null;
            $is_trial = ( (string) $pl->status === PlayerStatus::TRIAL );
            // First-visit default: active players checked, trial unchecked.
            $checked = $row !== null
                ? true
                : ( $is_first_visit && ! $is_trial );
            $positions = is_array( $row['eligible_positions'] ?? null )
                ? $row['eligible_positions']
                : self::defaultsFor( (string) $pl->preferred_positions );

            $name_for_filter = esc_attr( $name );
            $trial_attr = $is_trial ? ' data-trial="1"' : '';
            echo '<li class="ttw-squad-row" data-name="' . $name_for_filter . '"' . $trial_attr . '>';

            echo '<span class="ttw-row-check">';
            echo '<input type="checkbox" id="ttw-squad-in-' . esc_attr( (string) $pid ) . '" name="squad_in[]" value="' . esc_attr( (string) $pid ) . '" ' . checked( $checked, true, false ) . '>';
            echo '</span>';

            echo '<div class="ttw-name-block">';
            echo '<label class="ttw-name" for="ttw-squad-in-' . esc_attr( (string) $pid ) . '">' . esc_html( $name ) . '</label>';
            if ( $is_trial ) {
                echo ' <span class="ttw-trial-badge">' . esc_html__( 'Trial', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="ttw-positions" role="group" aria-label="' . esc_attr__( 'Eligible positions', 'talenttrack' ) . '">';
            foreach ( $position_codes as $code => $code_label ) {
                $is_pos = in_array( $code, $positions, true );
                $cb_id  = 'ttw-squad-pos-' . $pid . '-' . strtolower( $code );
                echo '<input type="checkbox" id="' . esc_attr( $cb_id ) . '" name="squad_positions[' . esc_attr( (string) $pid ) . '][]" value="' . esc_attr( $code ) . '" ' . checked( $is_pos, true, false ) . ' hidden>';
                echo '<span class="ttw-pos-chip" data-target="' . esc_attr( $cb_id ) . '" title="' . esc_attr( $code_label ) . '">' . esc_html( $code ) . '</span>';
            }
            echo '</div>';

            echo '</li>';
        }
        echo '</ul>';
        echo '</div>'; // card
        echo '</div>'; // tournament-wizard
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

        $allowed = array_keys( self::positionCodes() );
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
     * Derive default eligible positions from the player's
     * `preferred_positions` JSON column. Maps loose codes (CDM → DM,
     * CAM → AM, LWB → LB, etc.) to the canonical set so the chip row
     * starts with sensible defaults the coach can tweak.
     *
     * @return array<int,string>
     */
    private static function defaultsFor( string $preferred_positions_json ): array {
        $decoded = json_decode( $preferred_positions_json, true );
        if ( ! is_array( $decoded ) ) return [];
        $map = [
            'GK' => 'GK',
            'CB' => 'CB', 'RCB' => 'CB', 'LCB' => 'CB',
            'RB' => 'RB', 'RWB' => 'RB',
            'LB' => 'LB', 'LWB' => 'LB',
            'CDM' => 'DM', 'DM' => 'DM',
            'CM' => 'CM', 'RCM' => 'CM', 'LCM' => 'CM',
            'CAM' => 'AM', 'AM' => 'AM',
            'LM' => 'LW', 'LW' => 'LW',
            'RM' => 'RW', 'RW' => 'RW',
            'ST' => 'ST', 'CF' => 'ST', 'RST' => 'ST', 'LST' => 'ST',
        ];
        $out = [];
        foreach ( $decoded as $pos ) {
            $up = strtoupper( (string) $pos );
            $mapped = $map[ $up ] ?? '';
            if ( $mapped !== '' && ! in_array( $mapped, $out, true ) ) $out[] = $mapped;
        }
        return $out;
    }
}
