<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\TeamDevelopment\ChemistryAggregator;
use TT\Modules\TeamDevelopment\CompatibilityEngine;
use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTeamChemistryView — coach-facing formation board (#0018
 * sprints 3-4).
 *
 *   ?tt_view=team-chemistry                     — team picker
 *   ?tt_view=team-chemistry&team_id=<int>       — full board for one team
 *
 * The board renders an isometric-tilted SVG pitch with the suggested
 * XI auto-filled from the CompatibilityEngine. Every slot carries a
 * data-attributed rationale for hover tooltips. Below the pitch:
 *
 *   - Chemistry composite + 4-part breakdown (formation/style/paired/depth)
 *   - Depth chart per slot (top-3, suggested starter highlighted)
 *   - Coach-marked pairings list + add form (gated by manage cap)
 *
 * No drag-drop in v1 — per the locked decision the board surfaces
 * "suggested position" highlights rather than reshuffling the lineup.
 * Sprint 5's player profile uses the same engine to render a
 * "best-fit" panel from the player's perspective.
 */
class FrontendTeamChemistryView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id <= 0 ) {
            self::renderTeamPicker( $user_id, $is_admin );
            return;
        }

        $team = QueryHelpers::get_team( $team_id );
        if ( ! $team ) {
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That team no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! $is_admin && ! self::userCoachesTeam( $user_id, $team_id ) ) {
            self::renderHeader( __( 'Access denied', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not coach this team.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderBoard( $team, $user_id );
    }

    private static function renderTeamPicker( int $user_id, bool $is_admin ): void {
        self::renderHeader( __( 'Team chemistry', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams to show. Coaches see chemistry boards for teams they head-coach.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<p style="color:#5b6e75; margin-bottom:12px;">' . esc_html__( 'Pick a team to open the formation board with auto-suggested XI, depth chart, and chemistry breakdown.', 'talenttrack' ) . '</p>';
        $base_url = remove_query_arg( [ 'team_id' ] );
        echo '<div class="tt-card-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:12px;">';
        foreach ( $teams as $t ) {
            $url = add_query_arg( [ 'tt_view' => 'team-chemistry', 'team_id' => (int) $t->id ], $base_url );
            echo '<a class="tt-card" href="' . esc_url( $url ) . '" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px 16px; text-decoration:none; color:#1a1d21;">';
            echo '<strong style="display:block; margin-bottom:4px;">' . esc_html( (string) $t->name ) . '</strong>';
            echo '<span style="color:#5b6e75; font-size:13px;">' . esc_html__( 'Open chemistry board →', 'talenttrack' ) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function renderBoard( object $team, int $user_id ): void {
        self::renderHeader( sprintf(
            /* translators: %s = team name */
            __( 'Team chemistry — %s', 'talenttrack' ),
            (string) $team->name
        ) );

        $base_url = remove_query_arg( [ 'team_id' ] );
        echo '<p style="margin-bottom:16px;"><a class="tt-btn tt-btn-secondary" href="'
            . esc_url( add_query_arg( [ 'tt_view' => 'team-chemistry' ], $base_url ) ) . '">'
            . esc_html__( '← Back to team picker', 'talenttrack' ) . '</a></p>';

        global $wpdb; $p = $wpdb->prefix;
        $template_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT formation_template_id FROM {$p}tt_team_formations WHERE team_id = %d",
            (int) $team->id
        ) );
        if ( $template_id <= 0 ) {
            $template_id = (int) $wpdb->get_var(
                "SELECT id FROM {$p}tt_formation_templates WHERE is_seeded = 1 AND archived_at IS NULL ORDER BY id ASC LIMIT 1"
            );
        }
        if ( $template_id <= 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'No formation template configured. Configure one in Settings → Team development.', 'talenttrack' ) . '</p>';
            return;
        }

        $template = $wpdb->get_row( $wpdb->prepare(
            "SELECT name, slots_json FROM {$p}tt_formation_templates WHERE id = %d",
            $template_id
        ) );
        $slots = is_array( $decoded = json_decode( (string) ( $template->slots_json ?? '[]' ), true ) ) ? $decoded : [];

        $style = $wpdb->get_row( $wpdb->prepare(
            "SELECT possession_weight, counter_weight, press_weight FROM {$p}tt_team_playing_styles WHERE team_id = %d",
            (int) $team->id
        ) );
        $poss = $style ? (int) $style->possession_weight : 33;
        $cntr = $style ? (int) $style->counter_weight    : 33;
        $prss = $style ? (int) $style->press_weight      : 34;

        $chem = ( new ChemistryAggregator() )->teamChemistry(
            (int) $team->id,
            $template_id,
            $poss, $cntr, $prss
        );

        echo '<p style="color:#5b6e75; margin-bottom:8px;">'
            . esc_html( sprintf(
                /* translators: %s = formation name */
                __( 'Formation: %s', 'talenttrack' ),
                (string) ( $template->name ?? '' )
            ) )
            . ' · '
            . esc_html( sprintf(
                /* translators: 1: possession 2: counter 3: press */
                __( 'Style: possession %1$d / counter %2$d / press %3$d', 'talenttrack' ),
                $poss, $cntr, $prss
            ) )
            . '</p>';

        self::renderPitch( $slots, $chem['suggested_xi'] );
        self::renderChemistryBreakdown( $chem );
        self::renderDepthChart( $chem['depth'] );
        self::renderPairings( (int) $team->id, $user_id );
    }

    /**
     * Render the isometric pitch as an SVG with positioned slot
     * markers. CSS perspective on the wrapper gives the tilted look;
     * the SVG itself is plain 2D.
     *
     * @param list<array<string,mixed>> $slots
     * @param array<string, array{player_id:int, player_name:string, score:float}> $suggested
     */
    private static function renderPitch( array $slots, array $suggested ): void {
        ?>
        <style>
            .tt-pitch-wrap {
                perspective: 1100px;
                margin: 16px 0 24px;
                width: 100%;
                max-width: 760px;
            }
            .tt-pitch {
                transform: rotateX(28deg);
                transform-origin: 50% 100%;
                background: linear-gradient(180deg, #4ea35f 0%, #3c8a4d 50%, #2c7d3d 100%);
                border-radius: 16px;
                aspect-ratio: 4 / 5;
                position: relative;
                box-shadow: 0 24px 48px rgba(0,0,0,0.15), inset 0 0 0 2px rgba(255,255,255,0.18);
                overflow: hidden;
            }
            .tt-pitch::before, .tt-pitch::after {
                content: ''; position: absolute; left: 8%; right: 8%; border: 1.5px solid rgba(255,255,255,0.55);
                pointer-events: none;
            }
            .tt-pitch::before { top: 50%; height: 0; }
            .tt-pitch::after { top: 50%; height: 22%; transform: translateY(-50%); border-radius: 0; }
            .tt-pitch-slot {
                position: absolute;
                transform: translate(-50%, -50%) rotateX(-28deg);
                transform-origin: 50% 50%;
                background: #fff;
                border: 2px solid #1a1d21;
                border-radius: 50%;
                width: 64px; height: 64px;
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                font-size: 11px; line-height: 1.1;
                color: #1a1d21;
                text-align: center;
                box-shadow: 0 4px 8px rgba(0,0,0,0.18);
                cursor: help;
            }
            .tt-pitch-slot strong { font-size: 11px; }
            .tt-pitch-slot .tt-slot-score {
                background: #1d7874; color: #fff; border-radius: 10px;
                padding: 1px 6px; font-size: 10px; margin-top: 2px;
            }
            .tt-pitch-slot.tt-fit-low .tt-slot-score { background: #b32d2e; }
            .tt-pitch-slot.tt-fit-mid .tt-slot-score { background: #c9962a; }
        </style>
        <div class="tt-pitch-wrap">
            <div class="tt-pitch">
                <?php foreach ( $slots as $slot ) :
                    $label = (string) ( $slot['label'] ?? '' );
                    $x = (float) ( $slot['pos']['x'] ?? 0.5 );
                    $y = (float) ( $slot['pos']['y'] ?? 0.5 );
                    $assign = $suggested[ $label ] ?? null;
                    $score = $assign ? (float) $assign['score'] : 0.0;
                    $name  = $assign ? (string) $assign['player_name'] : '—';
                    $first_name = $assign ? explode( ' ', $name )[0] : '';
                    $fit_class = $score >= 4.0 ? '' : ( $score >= 3.0 ? 'tt-fit-mid' : 'tt-fit-low' );
                    $tip = $assign
                        ? sprintf(
                            /* translators: 1: player, 2: slot, 3: score */
                            __( '%1$s — best fit at %2$s (%3$.2f)', 'talenttrack' ),
                            $name, $label, $score
                        )
                        : sprintf( /* translators: %s slot label */ __( 'No suggested player for %s', 'talenttrack' ), $label );
                    ?>
                    <div class="tt-pitch-slot <?php echo esc_attr( $fit_class ); ?>"
                         style="left:<?php echo esc_attr( (string) ( $x * 100 ) ); ?>%; top:<?php echo esc_attr( (string) ( $y * 100 ) ); ?>%;"
                         title="<?php echo esc_attr( $tip ); ?>">
                        <strong><?php echo esc_html( $label ); ?></strong>
                        <?php if ( $first_name !== '' ) : ?>
                            <span style="font-size:9px; color:#5b6e75;"><?php echo esc_html( $first_name ); ?></span>
                            <span class="tt-slot-score"><?php echo esc_html( number_format_i18n( $score, 2 ) ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /** @param array<string, mixed> $chem */
    private static function renderChemistryBreakdown( array $chem ): void {
        $composite = (float) $chem['composite'];
        $parts = [
            [ 'key' => 'formation_fit',    'label' => __( 'Formation fit', 'talenttrack' ),    'value' => $chem['formation_fit'] ],
            [ 'key' => 'style_fit',        'label' => __( 'Style fit', 'talenttrack' ),        'value' => $chem['style_fit'] ],
            [ 'key' => 'depth_score',      'label' => __( 'Depth', 'talenttrack' ),            'value' => $chem['depth_score'] ],
            [ 'key' => 'paired_chemistry', 'label' => __( 'Paired bonus', 'talenttrack' ),     'value' => $chem['paired_chemistry'] ],
        ];
        ?>
        <div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px; margin-bottom:16px;">
            <h2 style="margin:0 0 8px; font-size:18px;"><?php
                echo esc_html( sprintf(
                    /* translators: %s = composite score */
                    __( 'Team chemistry: %s / 5', 'talenttrack' ),
                    number_format_i18n( $composite, 2 )
                ) );
            ?></h2>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-top:8px;">
                <?php foreach ( $parts as $part ) : ?>
                    <div style="background:#fafbfc; padding:10px; border-radius:6px;">
                        <div style="font-size:12px; color:#5b6e75;"><?php echo esc_html( (string) $part['label'] ); ?></div>
                        <div style="font-size:18px; font-weight:600;"><?php echo esc_html( number_format_i18n( (float) $part['value'], 2 ) ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /** @param array<string, list<array{player_id:int, player_name:string, score:float}>> $depth */
    private static function renderDepthChart( array $depth ): void {
        if ( empty( $depth ) ) return;
        ?>
        <h2 style="font-size:16px; margin:20px 0 8px;"><?php esc_html_e( 'Depth chart', 'talenttrack' ); ?></h2>
        <table class="tt-list-table-table">
            <thead><tr>
                <th><?php esc_html_e( 'Slot', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( '1st choice', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( '2nd choice', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( '3rd choice', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $depth as $label => $rows ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $label ); ?></strong></td>
                        <?php for ( $i = 0; $i < 3; $i++ ) :
                            $cell = $rows[ $i ] ?? null;
                            ?>
                            <td>
                                <?php if ( $cell ) : ?>
                                    <?php echo esc_html( (string) $cell['player_name'] ); ?>
                                    <span style="color:#5b6e75; font-size:12px;">(<?php echo esc_html( number_format_i18n( (float) $cell['score'], 2 ) ); ?>)</span>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderPairings( int $team_id, int $user_id ): void {
        $can_manage = current_user_can( 'tt_manage_team_chemistry' );
        $pairings = ( new PairingsRepository() )->listForTeam( $team_id );

        echo '<h2 style="font-size:16px; margin:24px 0 8px;">' . esc_html__( 'Coach-marked pairings', 'talenttrack' ) . '</h2>';
        if ( empty( $pairings ) ) {
            echo '<p style="color:#5b6e75;"><em>' . esc_html__( 'No pairings yet. Mark "always start these two together" pairs to factor into the chemistry score.', 'talenttrack' ) . '</em></p>';
        } else {
            echo '<table class="tt-list-table-table"><thead><tr>';
            echo '<th>' . esc_html__( 'Player A', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Player B', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Note', 'talenttrack' ) . '</th>';
            if ( $can_manage ) echo '<th></th>';
            echo '</tr></thead><tbody>';
            foreach ( $pairings as $p ) {
                $a = QueryHelpers::get_player( (int) $p['player_a_id'] );
                $b = QueryHelpers::get_player( (int) $p['player_b_id'] );
                echo '<tr>';
                echo '<td>' . esc_html( $a ? QueryHelpers::player_display_name( $a ) : '—' ) . '</td>';
                echo '<td>' . esc_html( $b ? QueryHelpers::player_display_name( $b ) : '—' ) . '</td>';
                echo '<td>' . esc_html( (string) ( $p['note'] ?? '' ) ) . '</td>';
                if ( $can_manage ) {
                    $rest_path = 'pairings/' . (int) $p['id'];
                    echo '<td><button class="tt-btn tt-btn-secondary tt-btn-sm tt-rest-action" data-rest-path="' . esc_attr( $rest_path ) . '" data-rest-method="DELETE" data-confirm="' . esc_attr__( 'Remove this pairing?', 'talenttrack' ) . '">' . esc_html__( 'Remove', 'talenttrack' ) . '</button></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if ( $can_manage ) {
            $players = QueryHelpers::get_players( $team_id );
            ?>
            <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( 'teams/' . $team_id . '/pairings' ); ?>" data-rest-method="POST" data-redirect-after-save="1" style="margin-top:12px;">
                <div class="tt-grid tt-grid-3" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                    <select name="player_a_id" class="tt-input" required>
                        <option value=""><?php esc_html_e( '— Player A —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) : ?>
                            <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="player_b_id" class="tt-input" required>
                        <option value=""><?php esc_html_e( '— Player B —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) : ?>
                            <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="note" class="tt-input" placeholder="<?php esc_attr_e( 'Optional note', 'talenttrack' ); ?>" />
                </div>
                <div class="tt-form-actions" style="margin-top:8px;">
                    <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm"><?php esc_html_e( 'Add pairing', 'talenttrack' ); ?></button>
                </div>
                <div class="tt-form-msg"></div>
            </form>
            <?php
        }
    }

    private static function userCoachesTeam( int $user_id, int $team_id ): bool {
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            if ( (int) $t->id === $team_id ) return true;
        }
        return false;
    }
}
