<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\TeamDevelopment\BlueprintChemistryEngine;
use TT\Modules\TeamDevelopment\ChemistryAggregator;
use TT\Modules\TeamDevelopment\CompatibilityEngine;
use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * #0068 v2 (v3.92.0) — full chemistry view rebuild:
 *
 *   1. The "isometric SVG" pitch is now an actual SVG with all
 *      standard markings (touchlines, goal lines, halfway line, centre
 *      circle + spot, both penalty boxes + goal areas + penalty arcs,
 *      corner arcs). Aspect ratio matches FIFA's 105m × 68m. See
 *      `PitchSvg`. Flat by default; `?perspective=isometric` opts back
 *      into the v1 tilted view.
 *   2. Players with no evaluations show "?" instead of "0.00" — the
 *      composite score / formation fit / style fit / depth score all
 *      go null until ≥40% of the roster has rated main categories,
 *      and the view renders an empty-state banner explaining why.
 *   3. Empty XI slots render as "—" when the roster is smaller than
 *      the formation needs. v1 fell back to re-using the top-scoring
 *      player which produced the "only a few players keep showing
 *      up" complaint.
 *   4. Ships alongside three new formation shapes (4-4-2, 3-5-2,
 *      4-2-3-1) seeded by migration 0064 — picked from a per-team
 *      formation template dropdown, replacing the previous single
 *      "always 4-3-3" implicit pick.
 */

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
        $chem_label = __( 'Team chemistry', 'talenttrack' );

        // v3.85.5 — Team chemistry is Pro-tier per FeatureMap.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'team_chemistry' )
        ) {
            FrontendBreadcrumbs::fromDashboard( $chem_label );
            self::renderHeader( $chem_label );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( $chem_label, 'pro' );
            return;
        }

        self::enqueueAssets();

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( $chem_label );
            self::renderTeamPicker( $user_id, $is_admin );
            return;
        }

        $team = QueryHelpers::get_team( $team_id );
        if ( ! $team ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Team not found', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'team-chemistry', $chem_label ) ]
            );
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That team no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! $is_admin && ! self::userCoachesTeam( $user_id, $team_id ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Access denied', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'team-chemistry', $chem_label ) ]
            );
            self::renderHeader( __( 'Access denied', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not coach this team.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            (string) $team->name,
            [ FrontendBreadcrumbs::viewCrumb( 'team-chemistry', $chem_label ) ]
        );
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
        $help_url = add_query_arg(
            [ 'tt_view' => 'docs', 'topic' => 'team-chemistry' ],
            home_url( '/' )
        );
        echo '<p style="margin-bottom:16px;">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $help_url ) . '">'
            . esc_html__( 'How does this work?', 'talenttrack' ) . '</a>';
        echo '</p>';

        global $wpdb; $p = $wpdb->prefix;

        // v3.92.0 — the team's picked template comes from
        // `tt_team_formations` first; falls back to the lowest-id
        // seeded template (Neutral 4-3-3) if none picked. The user
        // can switch shape via the dropdown rendered above the pitch.
        $requested_template = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
        $stored_template_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT formation_template_id FROM {$p}tt_team_formations WHERE team_id = %d",
            (int) $team->id
        ) );
        $template_id = $requested_template > 0
            ? $requested_template
            : ( $stored_template_id > 0
                ? $stored_template_id
                : (int) $wpdb->get_var(
                    "SELECT id FROM {$p}tt_formation_templates WHERE is_seeded = 1 AND archived_at IS NULL ORDER BY id ASC LIMIT 1"
                ) );
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

        self::renderTemplatePicker( (int) $team->id, $template_id, (string) ( $template->name ?? '' ) );
        self::renderSandboxControls( (int) $team->id, $user_id );

        echo '<p style="color:#5b6e75; margin:0 0 8px;">'
            . esc_html( sprintf(
                /* translators: 1: possession 2: counter 3: press */
                __( 'Style: possession %1$d / counter %2$d / press %3$d', 'talenttrack' ),
                $poss, $cntr, $prss
            ) )
            . '</p>';

        if ( ! $chem['has_enough_data'] ) {
            self::renderEmptyStateBanner( $chem );
        }

        $perspective = isset( $_GET['perspective'] ) && $_GET['perspective'] === 'isometric'
            ? \TT\Modules\TeamDevelopment\Frontend\PitchSvg::MODE_ISOMETRIC
            : \TT\Modules\TeamDevelopment\Frontend\PitchSvg::MODE_FLAT;

        $blueprint = ( new BlueprintChemistryEngine() )->computeForSuggested(
            (int) $team->id, $slots, $chem['suggested_xi']
        );

        \TT\Modules\TeamDevelopment\Frontend\PitchSvg::render( $slots, $chem['suggested_xi'], $perspective, $blueprint['links'] );

        self::renderPerspectiveToggle( $perspective, $base_url, (int) $team->id, $template_id );
        self::renderLinkChemistryHeadline( $blueprint );
        self::renderChemistryBreakdown( $chem );
        self::renderDepthChart( $chem['depth'] );
        self::renderPairings( (int) $team->id, $user_id );

        // v3.110.174 — "Try a lineup" sandbox. JS + CSS only mount when
        // the manage cap is present. Localize the depth chart + roster so
        // the picker can render candidates without a second round-trip.
        if ( current_user_can( 'tt_manage_team_chemistry' ) ) {
            self::enqueueChemistrySandboxAssets( (int) $team->id, $template_id, $poss, $cntr, $prss, $chem );
        }
    }

    /**
     * v3.110.174 — "Try a lineup" sandbox affordances above the pitch.
     * Renders nothing for users without the manage cap; for managers it
     * renders a mode-toggle pill, a status banner area (filled by JS
     * when overrides are present), and reset / save-as-blueprint buttons
     * that the JS shows once overrides exist.
     */
    private static function renderSandboxControls( int $team_id, int $user_id ): void {
        $can_manage = current_user_can( 'tt_manage_team_chemistry' );
        if ( ! $can_manage ) return;
        ?>
        <div class="tt-chem-sandbox" data-team-id="<?php echo (int) $team_id; ?>" data-mode="off">
            <div class="tt-chem-sandbox-bar">
                <button type="button" class="tt-btn tt-btn-secondary tt-chem-sandbox-toggle" aria-pressed="false">
                    <?php esc_html_e( 'Try a lineup', 'talenttrack' ); ?>
                </button>
                <span class="tt-chem-sandbox-hint" hidden>
                    <?php esc_html_e( 'Tap any slot on the pitch to swap the player.', 'talenttrack' ); ?>
                </span>
                <div class="tt-chem-sandbox-actions" hidden>
                    <button type="button" class="tt-btn tt-btn-secondary tt-btn-sm tt-chem-sandbox-reset">
                        <?php esc_html_e( 'Reset to suggested XI', 'talenttrack' ); ?>
                    </button>
                    <button type="button" class="tt-btn tt-btn-primary tt-btn-sm tt-chem-sandbox-save">
                        <?php esc_html_e( 'Save as blueprint', 'talenttrack' ); ?>
                    </button>
                </div>
            </div>
            <p class="tt-chem-sandbox-status" role="status" aria-live="polite" hidden></p>
        </div>
        <?php
    }

    /**
     * "Link chemistry" headline + legend — separate from the
     * formation-fit-based composite score above. The number is the
     * mean of all scored adjacent-pair scores expressed as 0..100,
     * mirroring FIFA Ultimate Team's chemistry ceiling.
     *
     * @param array{team_score:?int, pair_count:int, scored_pair_count:int, links: list<array<string,mixed>>} $blueprint
     */
    private static function renderLinkChemistryHeadline( array $blueprint ): void {
        $score = $blueprint['team_score'] ?? null;
        $scored_pairs = (int) ( $blueprint['scored_pair_count'] ?? 0 );
        ?>
        <div class="tt-card tt-chem-link-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px 16px; margin:0 0 16px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div>
                    <div style="font-size:12px; color:#5b6e75; text-transform:uppercase; letter-spacing:0.04em;">
                        <?php esc_html_e( 'Link chemistry', 'talenttrack' ); ?>
                    </div>
                    <div style="font-size:28px; font-weight:700; line-height:1;" data-tt-link-headline>
                        <?php
                        if ( $score === null ) {
                            echo '<span style="color:#8a9099;">— / 100</span>';
                        } else {
                            echo esc_html( sprintf(
                                /* translators: %d: 0-100 chemistry score */
                                __( '%d / 100', 'talenttrack' ),
                                $score
                            ) );
                        }
                        ?>
                    </div>
                    <div style="font-size:12px; color:#5b6e75; margin-top:4px;" data-tt-link-subtitle>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d: scored adjacent pair count */
                            _n( '%d scored adjacent pair on the pitch.', '%d scored adjacent pairs on the pitch.', $scored_pairs, 'talenttrack' ),
                            $scored_pairs
                        ) );
                        ?>
                    </div>
                </div>
                <div class="tt-chem-legend" style="display:flex; gap:12px; font-size:12px; color:#5b6e75; flex-wrap:wrap;">
                    <span style="display:inline-flex; align-items:center; gap:6px;">
                        <span style="display:inline-block; width:18px; height:4px; background:var(--tt-chem-green-token, #2c8a2c); border-radius:2px;"></span>
                        <?php esc_html_e( 'Strong (2.0–3.0)', 'talenttrack' ); ?>
                    </span>
                    <span style="display:inline-flex; align-items:center; gap:6px;">
                        <span style="display:inline-block; width:18px; height:4px; background:var(--tt-chem-amber-token, #e0a000); border-radius:2px;"></span>
                        <?php esc_html_e( 'Workable (1.0–2.0)', 'talenttrack' ); ?>
                    </span>
                    <span style="display:inline-flex; align-items:center; gap:6px;">
                        <span style="display:inline-block; width:18px; height:4px; background:var(--tt-chem-red-token, #b32d2e); border-radius:2px;"></span>
                        <?php esc_html_e( 'Poor (0–1.0)', 'talenttrack' ); ?>
                    </span>
                </div>
            </div>
            <p style="margin:8px 0 0; font-size:12px; color:#5b6e75;">
                <?php esc_html_e( 'Lines connect formation-adjacent slots. Score combines coach-marked pairings, same line of play, and side-preference fit. Hover any line for the breakdown.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Per-team formation picker — switches the rendered shape via a
     * URL `template_id` param. The selected template is *not*
     * persisted to `tt_team_formations` from this picker (that's a
     * separate "set as team default" affordance); the URL parameter
     * acts as a try-this preview.
     */
    private static function renderTemplatePicker( int $team_id, int $current_template_id, string $current_name ): void {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT id, name, formation_shape FROM {$p}tt_formation_templates WHERE archived_at IS NULL ORDER BY formation_shape ASC, name ASC"
        );
        if ( ! is_array( $rows ) || count( $rows ) <= 1 ) {
            echo '<p style="color:#5b6e75; margin:0 0 4px;">'
                . esc_html( sprintf(
                    /* translators: %s = formation name */
                    __( 'Formation: %s', 'talenttrack' ),
                    $current_name
                ) ) . '</p>';
            return;
        }
        $base_url = remove_query_arg( [ 'template_id' ] );
        ?>
        <form method="get" action="" style="margin:0 0 8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <input type="hidden" name="tt_view" value="team-chemistry" />
            <input type="hidden" name="team_id" value="<?php echo (int) $team_id; ?>" />
            <label for="tt-formation-picker" style="color:#5b6e75; font-size:13px;">
                <?php esc_html_e( 'Formation:', 'talenttrack' ); ?>
            </label>
            <select id="tt-formation-picker" name="template_id" class="tt-input" onchange="this.form.submit();">
                <?php foreach ( $rows as $row ) : ?>
                    <option value="<?php echo (int) $row->id; ?>" <?php selected( $current_template_id, (int) $row->id ); ?>>
                        <?php echo esc_html( (string) $row->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php
    }

    /**
     * Visible banner shown when the team's eval coverage is below the
     * `MIN_DATA_COVERAGE` threshold. Lists what's missing so the coach
     * knows how to make the chemistry score start working.
     *
     * @param array<string,mixed> $chem
     */
    private static function renderEmptyStateBanner( array $chem ): void {
        $roster   = (int) ( $chem['roster_size']   ?? 0 );
        $coverage = (float) ( $chem['data_coverage'] ?? 0.0 );
        $needed   = max( 1, (int) ceil( $roster * 0.40 ) );
        $rated    = (int) round( $roster * $coverage );
        $missing  = max( 0, $needed - $rated );
        ?>
        <div class="tt-notice" style="background:#fffbe6; border:1px solid #c9962a; border-radius:8px; padding:12px 14px; margin:8px 0 16px;">
            <strong><?php esc_html_e( 'Not enough evaluations to compute team chemistry yet.', 'talenttrack' ); ?></strong>
            <p style="margin:6px 0 0;">
                <?php
                printf(
                    /* translators: 1: rated count, 2: roster size, 3: missing count */
                    esc_html__( '%1$d of %2$d players have at least one rated main category. Rate %3$d more players in any of technical / tactical / physical / mental to start seeing fit scores and a team composite.', 'talenttrack' ),
                    $rated, $roster, $missing
                );
                ?>
            </p>
            <p style="margin:6px 0 0; color:#5b6e75; font-size:13px;">
                <?php esc_html_e( 'The pitch below shows the suggested XI based on whatever data is available — players with "?" need their first evaluation; slots showing "—" mean the roster is smaller than this formation needs.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Toggle link between flat and isometric pitch perspectives.
     */
    private static function renderPerspectiveToggle( string $current, string $base_url, int $team_id, int $template_id ): void {
        $other = ( $current === \TT\Modules\TeamDevelopment\Frontend\PitchSvg::MODE_ISOMETRIC )
            ? \TT\Modules\TeamDevelopment\Frontend\PitchSvg::MODE_FLAT
            : \TT\Modules\TeamDevelopment\Frontend\PitchSvg::MODE_ISOMETRIC;
        $other_url = add_query_arg( [
            'tt_view'     => 'team-chemistry',
            'team_id'     => $team_id,
            'template_id' => $template_id,
            'perspective' => $other,
        ], $base_url );
        $other_label = $other === \TT\Modules\TeamDevelopment\Frontend\PitchSvg::MODE_ISOMETRIC
            ? __( 'Switch to isometric view', 'talenttrack' )
            : __( 'Switch to flat view', 'talenttrack' );
        ?>
        <p style="text-align:right; margin:-12px 0 16px; max-width:760px;">
            <a class="tt-link" href="<?php echo esc_url( $other_url ); ?>" style="font-size:12px; color:#5b6e75;">
                <?php echo esc_html( $other_label ); ?>
            </a>
        </p>
        <?php
    }

    /** @param array<string, mixed> $chem */
    private static function renderChemistryBreakdown( array $chem ): void {
        $composite = $chem['composite'] ?? null;
        $parts = [
            [ 'key' => 'formation_fit',    'label' => __( 'Formation fit', 'talenttrack' ), 'value' => $chem['formation_fit']    ?? null ],
            [ 'key' => 'style_fit',        'label' => __( 'Style fit',     'talenttrack' ), 'value' => $chem['style_fit']        ?? null ],
            [ 'key' => 'depth_score',      'label' => __( 'Depth',         'talenttrack' ), 'value' => $chem['depth_score']      ?? null ],
            [ 'key' => 'paired_chemistry', 'label' => __( 'Paired bonus',  'talenttrack' ), 'value' => $chem['paired_chemistry'] ?? 0.0 ],
        ];
        ?>
        <?php
        // v3.110.116 — was hardcoded `/ 5`. Read the rating_max from
        // config so the denominator matches the active rating scale.
        $tc_rmax = (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '10' );
        ?>
        <div class="tt-card" data-tt-chem-breakdown data-rating-max="<?php echo esc_attr( (string) $tc_rmax ); ?>" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px; margin-bottom:16px;">
            <h2 style="margin:0 0 8px; font-size:18px;" data-tt-chem-composite-heading><?php
                if ( $composite === null ) {
                    echo esc_html( sprintf(
                        /* translators: %s = rating max */
                        __( 'Team chemistry: ? / %s', 'talenttrack' ),
                        number_format_i18n( $tc_rmax, 0 )
                    ) );
                } else {
                    echo esc_html( sprintf(
                        /* translators: 1: composite score, 2: rating max */
                        __( 'Team chemistry: %1$s / %2$s', 'talenttrack' ),
                        number_format_i18n( (float) $composite, 2 ),
                        number_format_i18n( $tc_rmax, 0 )
                    ) );
                }
            ?></h2>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-top:8px;">
                <?php foreach ( $parts as $part ) : ?>
                    <div style="background:#fafbfc; padding:10px; border-radius:6px;">
                        <div style="font-size:12px; color:#5b6e75;"><?php echo esc_html( (string) $part['label'] ); ?></div>
                        <div style="font-size:18px; font-weight:600;" data-tt-chem-part="<?php echo esc_attr( (string) $part['key'] ); ?>">
                            <?php
                            if ( $part['value'] === null ) {
                                echo '<span style="color:#8a9099;">?</span>';
                            } else {
                                echo esc_html( number_format_i18n( (float) $part['value'], 2 ) );
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /** @param array<string, list<array{player_id:int, player_name:string, score:float, has_data:bool}>> $depth */
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
                                <?php if ( $cell ) :
                                    $has_data = ! empty( $cell['has_data'] );
                                    ?>
                                    <?php echo esc_html( (string) $cell['player_name'] ); ?>
                                    <?php if ( $has_data ) : ?>
                                        <span style="color:#5b6e75; font-size:12px;">(<?php echo esc_html( number_format_i18n( (float) $cell['score'], 2 ) ); ?>)</span>
                                    <?php else : ?>
                                        <span style="color:#8a9099; font-size:12px;" title="<?php esc_attr_e( 'Not enough evaluations to compute a fit score.', 'talenttrack' ); ?>">(?)</span>
                                    <?php endif; ?>
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

    /**
     * v3.110.174 — enqueue the "Try a lineup" sandbox CSS + JS and
     * localize the data the picker needs (depth chart, full roster,
     * suggested-XI baseline, REST root + nonce, i18n strings). Only
     * called for users with the manage cap.
     *
     * @param array<string,mixed> $chem
     */
    private static function enqueueChemistrySandboxAssets( int $team_id, int $template_id, int $poss, int $cntr, int $prss, array $chem ): void {
        wp_enqueue_style(
            'tt-team-chemistry',
            TT_PLUGIN_URL . 'assets/css/frontend-team-chemistry.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-team-chemistry',
            TT_PLUGIN_URL . 'assets/js/frontend-team-chemistry.js',
            [],
            TT_VERSION,
            true
        );

        // Full roster — needed so the picker can offer any player, not
        // just the depth-chart top-3. Includes name + the side preference
        // we already cache on `tt_players` so the picker can warn on
        // wrong-side picks in a follow-up. v1 ships the names only.
        $roster = [];
        foreach ( (array) QueryHelpers::get_players( $team_id ) as $pl ) {
            $roster[] = [
                'id'   => (int) $pl->id,
                'name' => QueryHelpers::player_display_name( $pl ),
            ];
        }

        wp_localize_script( 'tt-team-chemistry', 'TT_TEAM_CHEM', [
            'rest_root'   => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'team_id'     => $team_id,
            'template_id' => $template_id,
            'style'       => [ 'possession' => $poss, 'counter' => $cntr, 'press' => $prss ],
            'suggested'   => self::compactSuggested( (array) ( $chem['suggested_xi'] ?? [] ) ),
            'depth'       => self::compactDepth( (array) ( $chem['depth'] ?? [] ) ),
            'roster'      => $roster,
            'i18n'        => [
                'mode_on'        => __( 'Tap any slot on the pitch to swap the player.', 'talenttrack' ),
                'mode_off'       => __( 'Try a lineup', 'talenttrack' ),
                'mode_on_label'  => __( 'Stop trying a lineup', 'talenttrack' ),
                'picker_title'   => __( 'Pick a player for %s', 'talenttrack' ),
                'picker_empty'   => __( 'Leave slot empty', 'talenttrack' ),
                'picker_close'   => __( 'Close', 'talenttrack' ),
                'in_xi'          => __( 'currently in %s', 'talenttrack' ),
                'fit'            => __( 'fit %s', 'talenttrack' ),
                'no_fit'         => __( 'no fit data yet', 'talenttrack' ),
                'save_failed'    => __( 'Could not recompute chemistry. Try again.', 'talenttrack' ),
                'save_bp_title'  => __( 'Save lineup as blueprint', 'talenttrack' ),
                'save_bp_prompt' => __( 'Save as blueprint', 'talenttrack' ),
                'save_bp_default'=> __( 'Sandbox lineup', 'talenttrack' ),
                'save_bp_failed' => __( 'Could not save the blueprint. Try again.', 'talenttrack' ),
                // v3.110.184 — flavour picker on Save-as-blueprint.
                'save_bp_flavour_legend' => __( 'Blueprint type', 'talenttrack' ),
                'save_bp_flavour_match'  => __( 'Match-day lineup — single starting XI', 'talenttrack' ),
                'save_bp_flavour_squad'  => __( 'Squad plan — three tiers per slot (primary / secondary / tertiary)', 'talenttrack' ),
                'save_bp_name_label'     => __( 'Blueprint name', 'talenttrack' ),
                'save_bp_save'           => __( 'Save blueprint', 'talenttrack' ),
                'save_bp_cancel'         => __( 'Cancel', 'talenttrack' ),
                'reset_confirm'  => __( 'Discard the sandbox lineup and restore the suggested XI?', 'talenttrack' ),
                'sandbox_active' => __( '%d slot swapped from the suggested XI.', 'talenttrack' ),
                'sandbox_active_many' => __( '%d slots swapped from the suggested XI.', 'talenttrack' ),
                /* translators: 1: composite score, 2: rating max */
                'composite_label' => __( 'Team chemistry: %1$s / %2$s', 'talenttrack' ),
                /* translators: %s: rating max */
                'composite_unknown' => __( 'Team chemistry: ? / %s', 'talenttrack' ),
                'pairs_one'      => __( '%d scored adjacent pair on the pitch.', 'talenttrack' ),
                'pairs_many'     => __( '%d scored adjacent pairs on the pitch.', 'talenttrack' ),
                'link_score'     => __( '%d / 100', 'talenttrack' ),
                'link_score_unknown' => __( '— / 100', 'talenttrack' ),
                /* translators: 1: pair score 0-3, 2: comma-separated reasons */
                'link_tip'       => __( 'Chemistry %1$s / 3 — %2$s', 'talenttrack' ),
                'no_signals'     => __( 'no shared signals', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * Strip the suggested_xi payload to the fields the picker JS needs.
     * Keeps `wp_localize_script` from emitting roster-size × eval-data.
     *
     * @param array<string, array<string,mixed>> $suggested
     * @return array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>
     */
    private static function compactSuggested( array $suggested ): array {
        $out = [];
        foreach ( $suggested as $label => $entry ) {
            $out[ (string) $label ] = [
                'player_id'   => (int)    ( $entry['player_id']   ?? 0 ),
                'player_name' => (string) ( $entry['player_name'] ?? '' ),
                'score'       => (float)  ( $entry['score']       ?? 0.0 ),
                'has_data'    => (bool)   ( $entry['has_data']    ?? false ),
            ];
        }
        return $out;
    }

    /**
     * Same shape as compactSuggested but applied to the depth chart's
     * list-per-slot. Top-N already capped at 3 by the aggregator.
     *
     * @param array<string, list<array<string,mixed>>> $depth
     * @return array<string, list<array{player_id:int, player_name:string, score:float, has_data:bool}>>
     */
    private static function compactDepth( array $depth ): array {
        $out = [];
        foreach ( $depth as $label => $rows ) {
            $clean = [];
            foreach ( $rows as $r ) {
                $clean[] = [
                    'player_id'   => (int)    ( $r['player_id']   ?? 0 ),
                    'player_name' => (string) ( $r['player_name'] ?? '' ),
                    'score'       => (float)  ( $r['score']       ?? 0.0 ),
                    'has_data'    => (bool)   ( $r['has_data']    ?? false ),
                ];
            }
            $out[ (string) $label ] = $clean;
        }
        return $out;
    }
}
