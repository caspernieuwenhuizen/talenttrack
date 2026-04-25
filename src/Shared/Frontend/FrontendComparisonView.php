<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;

/**
 * FrontendComparisonView — the "Player comparison" tile destination
 * (analytics group).
 *
 * v3.0.0 slice 5. Streamlined mobile-first version of the admin
 * PlayerComparisonPage. Slot pickers (up to 4), FIFA card row, basic
 * facts table, headline numbers table, main-category breakdown.
 *
 * Skips the radar overlay and trend overlay charts that live in the
 * admin version — those require Chart.js with a custom multi-dataset
 * config that's significant code and primarily useful for deep-dive
 * sessions (where opening the admin view makes sense anyway). The
 * frontend version focuses on "compare these 4 at a glance".
 *
 * Permission gate: tt_view_reports. Observer role has this cap.
 */
class FrontendComparisonView extends FrontendViewBase {

    public static function render(): void {
        self::enqueueAssets();
        \TT\Modules\Stats\Admin\PlayerCardView::enqueueStyles();

        self::renderHeader( __( 'Player comparison', 'talenttrack' ) );

        // #0011 — feature gate. Player comparison is a Standard-tier
        // feature; on Free, surface an upgrade nudge instead of the
        // analytics body.
        if ( class_exists( '\TT\Modules\License\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::can( 'player_comparison' )
        ) {
            echo \TT\Modules\License\Admin\UpgradeNudge::inline(
                __( 'Player comparison', 'talenttrack' ),
                'standard'
            );
            return;
        }

        // Pick up to 4 players from ?p1, ?p2, ?p3, ?p4
        $picked = [];
        for ( $i = 1; $i <= 4; $i++ ) {
            $pid = isset( $_GET[ "p{$i}" ] ) ? absint( $_GET[ "p{$i}" ] ) : 0;
            if ( $pid > 0 ) $picked[] = $pid;
        }
        $picked = array_values( array_unique( $picked ) );
        $picked = array_slice( $picked, 0, 4 );

        $filters = PlayerStatsService::sanitizeFilters( $_GET );

        // Resolve picked players
        $players = [];
        foreach ( $picked as $pid ) {
            $pl = QueryHelpers::get_player( $pid );
            if ( $pl ) $players[] = $pl;
        }

        // All players for slot selectors (cross-club — observer's scope).
        // Demo-mode scope applied so demo runs only show demo-tagged players.
        global $wpdb;
        $p = $wpdb->prefix;
        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $all_players = $wpdb->get_results(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.team_id, t.name AS team_name, t.age_group
             FROM {$p}tt_players pl
             LEFT JOIN {$p}tt_teams t ON pl.team_id = t.id
             WHERE pl.status = 'active' AND pl.archived_at IS NULL {$player_scope}
             ORDER BY pl.last_name, pl.first_name ASC"
        );

        ?>
        <p style="color:#666; max-width:760px; margin:0 0 16px;">
            <?php esc_html_e( 'Compare up to 4 players side-by-side. Cross-team is supported — pick any players from any team or age group.', 'talenttrack' ); ?>
        </p>

        <form method="get" action="" style="background:#fff; border:1px solid #e5e7ea; border-radius:10px; padding:16px 20px; margin:16px 0;">
            <?php
            // Preserve tt_view + any other non-filter args
            foreach ( $_GET as $k => $v ) {
                if ( preg_match( '/^p[1-4]$/', (string) $k ) ) continue;
                if ( in_array( $k, [ 'date_from', 'date_to', 'eval_type_id' ], true ) ) continue;
                if ( is_string( $v ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( wp_unslash( $v ) ) . '" />';
                }
            }
            ?>
            <h3 style="margin:0 0 10px; font-size:15px;"><?php esc_html_e( 'Select players', 'talenttrack' ); ?></h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
                <?php for ( $i = 1; $i <= 4; $i++ ) :
                    $current = $picked[ $i - 1 ] ?? 0;
                    echo PlayerSearchPickerComponent::render( [
                        'name'        => 'p' . $i,
                        'label'       => sprintf( /* translators: %d is slot number */ __( 'Slot %d', 'talenttrack' ), $i ),
                        'players'     => $all_players,
                        'selected'    => (int) $current,
                        'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
                    ] );
                endfor; ?>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-top:12px;">
                <div>
                    <label style="font-size:12px; color:#555; display:block;"><?php esc_html_e( 'Date from', 'talenttrack' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>" style="width:100%; padding:6px;" />
                </div>
                <div>
                    <label style="font-size:12px; color:#555; display:block;"><?php esc_html_e( 'Date to', 'talenttrack' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>" style="width:100%; padding:6px;" />
                </div>
                <div>
                    <label style="font-size:12px; color:#555; display:block;"><?php esc_html_e( 'Evaluation Type', 'talenttrack' ); ?></label>
                    <select name="eval_type_id" style="width:100%; padding:6px;">
                        <option value="0"><?php esc_html_e( 'All types', 'talenttrack' ); ?></option>
                        <?php foreach ( QueryHelpers::get_eval_types() as $t ) : ?>
                            <option value="<?php echo (int) $t->id; ?>" <?php selected( (int) ( $filters['eval_type_id'] ?? 0 ), (int) $t->id ); ?>>
                                <?php echo esc_html( (string) $t->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p style="margin:14px 0 0;">
                <button type="submit" class="tt-btn tt-btn-primary" style="padding:8px 16px;"><?php esc_html_e( 'Compare', 'talenttrack' ); ?></button>
            </p>
        </form>

        <?php
        if ( empty( $players ) ) {
            echo '<p style="color:#666;"><em>' . esc_html__( 'Pick at least one player above and click Compare.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $service = new PlayerStatsService();
        $headlines = [];
        $mains = [];
        $age_groups = [];
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $team = $pl->team_id ? QueryHelpers::get_team( (int) $pl->team_id ) : null;
            $age_groups[ $pid ] = $team && ! empty( $team->age_group ) ? $team->age_group : '';
            $headlines[ $pid ] = $service->getHeadlineNumbers( $pid, $filters, 5 );
            $mains[ $pid ] = $service->getMainCategoryBreakdown( $pid, $filters );
        }
        $unique_ages = array_values( array_unique( array_filter( $age_groups ) ) );
        $mixed_ages = count( $unique_ages ) > 1;
        ?>

        <?php if ( $mixed_ages ) : ?>
            <div style="background:#e7f0f9; border-left:4px solid #2271b1; padding:10px 14px; margin:16px 0; font-size:13px;">
                <?php esc_html_e( 'Mixed age groups in this comparison. Overall ratings use age-group-specific category weights, so the numbers below are not perfectly apples-to-apples — they reflect what each player\'s own coaching staff uses.', 'talenttrack' ); ?>
            </div>
        <?php endif; ?>

        <!-- FIFA card row -->
        <h3 style="margin:24px 0 10px; font-size:15px;"><?php esc_html_e( 'Cards', 'talenttrack' ); ?></h3>
        <div style="display:flex; gap:14px; flex-wrap:wrap; overflow-x:auto; padding-bottom:8px;">
            <?php foreach ( $players as $pl ) : ?>
                <div style="flex:0 0 auto;">
                    <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $pl->id, 'sm', true ); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Basic facts -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Basic facts', 'talenttrack' ); ?></h3>
        <div style="overflow-x:auto;">
            <table class="tt-table" style="width:100%; background:#fff;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Attribute', 'talenttrack' ); ?></th>
                        <?php foreach ( $players as $pl ) : ?>
                            <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = [
                        [ __( 'Team', 'talenttrack' ),         function( $pl ) { $t = $pl->team_id ? QueryHelpers::get_team( (int) $pl->team_id ) : null; return $t ? (string) $t->name : '—'; } ],
                        [ __( 'Age group', 'talenttrack' ),    function( $pl ) use ( $age_groups ) { return (string) ( $age_groups[ (int) $pl->id ] ?: '—' ); } ],
                        [ __( 'Position(s)', 'talenttrack' ),  function( $pl ) { $pos = json_decode( (string) $pl->preferred_positions, true ); return is_array( $pos ) ? implode( ', ', $pos ) : '—'; } ],
                        [ __( 'Foot', 'talenttrack' ),         function( $pl ) { return (string) ( $pl->preferred_foot ?: '—' ); } ],
                        [ __( 'Jersey', 'talenttrack' ),       function( $pl ) { return $pl->jersey_number ? '#' . (int) $pl->jersey_number : '—'; } ],
                        [ __( 'Height', 'talenttrack' ),       function( $pl ) { return $pl->height_cm ? ( (int) $pl->height_cm . ' cm' ) : '—'; } ],
                    ];
                    foreach ( $rows as [ $label, $fn ] ) : ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( $label ); ?></td>
                            <?php foreach ( $players as $pl ) : ?>
                                <td><?php echo esc_html( (string) $fn( $pl ) ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Headline numbers -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Headline numbers', 'talenttrack' ); ?></h3>
        <div style="overflow-x:auto;">
            <table class="tt-table" style="width:100%; background:#fff;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Metric', 'talenttrack' ); ?></th>
                        <?php foreach ( $players as $pl ) : ?>
                            <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $metrics = [
                        [ __( 'Most recent', 'talenttrack' ), 'recent' ],
                        [ __( 'Rolling (last 5)', 'talenttrack' ), 'rolling' ],
                        [ __( 'All-time', 'talenttrack' ), 'alltime' ],
                        [ __( 'Evaluations', 'talenttrack' ), 'count' ],
                    ];
                    foreach ( $metrics as [ $label, $key ] ) : ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( $label ); ?></td>
                            <?php foreach ( $players as $pl ) :
                                $pid = (int) $pl->id;
                                $val = $headlines[ $pid ][ $key ] ?? null;
                                $display = $val === null ? '—' : ( is_numeric( $val ) ? (string) $val : esc_html( (string) $val ) );
                                ?>
                                <td style="font-variant-numeric:tabular-nums; font-weight:600;"><?php echo $display; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Main category breakdown -->
        <h3 style="margin:28px 0 10px; font-size:15px;"><?php esc_html_e( 'Main category averages', 'talenttrack' ); ?></h3>
        <?php self::renderMainBreakdown( $players, $mains ); ?>

        <p style="color:#888; font-size:12px; margin:24px 0 0; font-style:italic;">
            <?php esc_html_e( 'For radar overlay and trend charts, use the admin Player Comparison page.', 'talenttrack' ); ?>
        </p>
        <?php
    }

    /**
     * Main-category breakdown table. Collect all unique main-category
     * labels across the 4 players, then render one row per category
     * with per-player averages.
     */
    private static function renderMainBreakdown( array $players, array $mains ): void {
        // Collect unique main category labels across all players
        $all_cats = [];
        foreach ( $mains as $pid => $data ) {
            foreach ( $data as $row ) {
                $key = (string) ( $row->main_label ?? $row->label ?? '' );
                if ( $key !== '' ) $all_cats[ $key ] = $key;
            }
        }

        if ( empty( $all_cats ) ) {
            echo '<p><em>' . esc_html__( 'No category data yet for these filters.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <div style="overflow-x:auto;">
            <table class="tt-table" style="width:100%; background:#fff;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                        <?php foreach ( $players as $pl ) : ?>
                            <th><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( array_keys( $all_cats ) as $cat_label ) : ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $cat_label ) ); ?></td>
                            <?php foreach ( $players as $pl ) :
                                $pid = (int) $pl->id;
                                $val = '—';
                                foreach ( ( $mains[ $pid ] ?? [] ) as $row ) {
                                    $key = (string) ( $row->main_label ?? $row->label ?? '' );
                                    if ( $key === $cat_label ) {
                                        $avg = $row->avg ?? $row->average ?? null;
                                        if ( $avg !== null ) $val = number_format( (float) $avg, 2 );
                                        break;
                                    }
                                }
                                ?>
                                <td style="font-variant-numeric:tabular-nums;"><?php echo esc_html( $val ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
