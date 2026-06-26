<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\TeamDevelopment\BlueprintChemistryEngine;
use TT\Modules\TeamDevelopment\ChemistryAggregator;
use TT\Modules\TeamDevelopment\Chemistry\ChemistryExplainer;
use TT\Modules\TeamDevelopment\Chemistry\LineupChemistryAggregator;
use TT\Modules\TeamDevelopment\Chemistry\TeamChemistryAggregator;
use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTeamChemistryView — coach-facing chemistry board.
 *
 * v4.13.0 (#1002) — full surface rework. Layout ports the blueprint-editor
 * mockup at `.local-mockups/team-chemistry/index.html`: a three-column
 * shell with a roster sidebar on the left, the pitch in the centre, and
 * a stacked KPI scoreboard plus coach-marked pairings panel on the right.
 *
 * The chemistry surface is single-tier — the chemistry engine only scores
 * the primary tier, so the secondary / tertiary stack the blueprint editor
 * shows is irrelevant here. Each pitch position renders one slot card.
 *
 * The PitchSvg renders the pitch + chemistry links (mode flat by default);
 * its slot-card output carries the data attributes the "Try a lineup"
 * JS uses to wire the picker and live recompute.
 *
 *   ?tt_view=team-chemistry                     — team picker
 *   ?tt_view=team-chemistry&team_id=<int>       — chemistry board for one team
 *
 * Same caps as v1:
 *   - tt_view_team_chemistry — gated at the ViewRouter dispatcher
 *   - tt_manage_team_chemistry — gates pairings CRUD + the sandbox
 *
 * REST endpoints used (unchanged):
 *   GET  /teams/{id}/chemistry
 *   POST /teams/{id}/chemistry/preview
 *   GET  /teams/{id}/pairings, POST /teams/{id}/pairings, DELETE /pairings/{id}
 *   POST /teams/{id}/blueprints + PUT /blueprints/{id}/assignments
 */
class FrontendTeamChemistryView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $chem_label = __( 'Team chemistry', 'talenttrack' );

        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'team_chemistry' )
        ) {
            FrontendBreadcrumbs::fromDashboard( $chem_label );
            self::renderHeader( $chem_label );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( $chem_label, 'pro' );
            return;
        }

        self::enqueueAssets();
        self::enqueueChemistryAssets();

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

        echo '<p class="tt-tc-lede">' . esc_html__( 'Pick a team to open its chemistry board with auto-suggested XI, live link scoring, and a sandbox to try a different lineup.', 'talenttrack' ) . '</p>';
        $base_url = remove_query_arg( [ 'team_id' ] );
        echo '<div class="tt-tc-picker-grid">';
        foreach ( $teams as $t ) {
            $url = add_query_arg( [ 'tt_view' => 'team-chemistry', 'team_id' => (int) $t->id ], $base_url );
            echo '<a class="tt-tc-picker-card" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) $t->name ) . '</strong>';
            echo '<span>' . esc_html__( 'Open chemistry board', 'talenttrack' ) . ' &rarr;</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function renderBoard( object $team, int $user_id ): void {
        $title = sprintf(
            /* translators: %s = team name */
            __( 'Team chemistry &mdash; %s', 'talenttrack' ),
            (string) $team->name
        );
        self::renderHeader( $title );

        global $wpdb; $p = $wpdb->prefix;

        // #1325 — `?blueprint_id=N` loads a previously-saved blueprint as
        // the lineup baseline. Loaded blueprints override the formation
        // template (so the page auto-switches to the blueprint's
        // formation) and replace the suggested XI on the pitch + in the
        // sandbox config.
        $loaded_blueprint = self::loadRequestedBlueprint( (int) $team->id );

        // Template pick — `?template_id` overrides the persisted choice
        // for a one-off preview; otherwise the team's saved template is
        // used, falling back to the lowest-id seeded shape. When a
        // blueprint is loaded, its formation_template_id wins.
        $requested_template = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
        $stored_template_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT formation_template_id FROM {$p}tt_team_formations WHERE team_id = %d",
            (int) $team->id
        ) );
        if ( $loaded_blueprint !== null && (int) ( $loaded_blueprint['formation_template_id'] ?? 0 ) > 0 ) {
            $template_id = (int) $loaded_blueprint['formation_template_id'];
        } else {
            $template_id = $requested_template > 0
                ? $requested_template
                : ( $stored_template_id > 0
                    ? $stored_template_id
                    : (int) $wpdb->get_var(
                        "SELECT id FROM {$p}tt_formation_templates WHERE is_seeded = 1 AND archived_at IS NULL ORDER BY id ASC LIMIT 1"
                    ) );
        }
        if ( $template_id <= 0 ) {
            echo '<div class="tt-tc-emptystate">';
            echo '<h2>' . esc_html__( 'No formation templates yet', 'talenttrack' ) . '</h2>';
            echo '<p>' . esc_html__( 'Chemistry needs at least one formation to score against. An admin can seed the default templates from Settings &rarr; Team development.', 'talenttrack' ) . '</p>';
            echo '</div>';
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

        // #1325 — when a blueprint is loaded, replace the aggregator's
        // suggested XI with the blueprint's primary-tier lineup. The
        // depth chart + style/formation/composite scores stay correct
        // (they reflect the roster, not the displayed XI); only the
        // pitch occupants + link chemistry pivot to the blueprint.
        if ( $loaded_blueprint !== null ) {
            $chem['suggested_xi'] = self::overlayBlueprintLineup(
                $chem['suggested_xi'],
                $chem['depth'] ?? [],
                $loaded_blueprint['primary_lineup'] ?? []
            );
        }

        $blueprint = ( new BlueprintChemistryEngine() )->computeForSuggested(
            (int) $team->id, $slots, $chem['suggested_xi']
        );

        $can_manage = current_user_can( 'tt_manage_team_chemistry' );

        // Toolbar: formation / style summary / mode toggle / save-as-blueprint.
        self::renderToolbar( (int) $team->id, $template_id, (string) ( $template->name ?? '' ), $poss, $cntr, $prss, $can_manage, $loaded_blueprint );

        // #1017 Phase 6 — reworked-engine insight panel (behind the
        // chemistry_engine_v2 toggle): lineup / unit scores + strongest /
        // weakest relationships + recommendations.
        self::renderChemistryInsight( (int) $team->id, $slots, (array) ( $chem['suggested_xi'] ?? [] ) );

        // #1325 — loaded-blueprint banner: shows what's loaded + clear link.
        if ( $loaded_blueprint !== null ) {
            self::renderLoadedBlueprintBanner( $loaded_blueprint, (int) $team->id );
        }

        // Empty-state banner sits above the layout so it's not buried.
        if ( ! $chem['has_enough_data'] ) {
            self::renderEmptyStateBanner( $chem );
        }

        // Override banner — JS toggles visibility on `data-mode="on"`.
        if ( $can_manage ) {
            ?>
            <div class="tt-tc-override-banner" role="status" hidden>
                <span><strong><?php esc_html_e( 'Try-a-lineup mode active.', 'talenttrack' ); ?></strong>
                    <span class="tt-tc-override-hint"><?php esc_html_e( 'Tap any slot on the pitch to swap the player; chemistry recomputes live.', 'talenttrack' ); ?></span></span>
                <span class="tt-tc-override-actions"></span>
            </div>
            <?php
        }

        // Three-column layout — roster sidebar / pitch / right column.
        echo '<div class="tt-tc-layout">';
        self::renderRosterSidebar( (int) $team->id, $chem );
        self::renderPitchCard( $slots, $chem['suggested_xi'], $blueprint );
        self::renderRightColumn( (int) $team->id, $blueprint, $chem, $can_manage );
        echo '</div>';

        // Localise the sandbox config for the "Try a lineup" JS.
        if ( $can_manage ) {
            self::localiseSandbox( (int) $team->id, $template_id, $poss, $cntr, $prss, $chem );
        }

        // Help link below.
        $help_url = add_query_arg(
            [ 'tt_view' => 'docs', 'topic' => 'team-chemistry' ],
            home_url( '/' )
        );
        echo '<p class="tt-tc-help-row">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $help_url ) . '">'
            . esc_html__( 'How does this work?', 'talenttrack' ) . '</a>';
        echo '</p>';
    }

    /**
     * Top toolbar — mockup chrome: formation picker, play-style summary,
     * try-a-lineup toggle, reset + save-as-blueprint actions.
     */
    private static function renderToolbar(
        int $team_id, int $current_template_id, string $current_name,
        int $poss, int $cntr, int $prss, bool $can_manage,
        ?array $loaded_blueprint = null
    ): void {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT id, name, formation_shape FROM {$p}tt_formation_templates WHERE archived_at IS NULL ORDER BY formation_shape ASC, name ASC"
        );
        ?>
        <section class="tt-tc-toolbar" aria-label="<?php esc_attr_e( 'Chemistry board controls', 'talenttrack' ); ?>">
            <form method="get" action="" class="tt-tc-toolbar-form">
                <input type="hidden" name="tt_view" value="team-chemistry" />
                <input type="hidden" name="team_id" value="<?php echo (int) $team_id; ?>" />
                <label class="tt-tc-toolbar-label" for="tt-tc-formation">
                    <?php esc_html_e( 'Formation', 'talenttrack' ); ?>
                </label>
                <?php if ( is_array( $rows ) && count( $rows ) > 1 ) : ?>
                    <select id="tt-tc-formation" name="template_id" class="tt-tc-toolbar-select" data-tt-tc-autosubmit>
                        <?php foreach ( $rows as $row ) : ?>
                            <option value="<?php echo (int) $row->id; ?>" <?php selected( $current_template_id, (int) $row->id ); ?>>
                                <?php echo esc_html( (string) $row->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <span class="tt-tc-toolbar-value"><?php echo esc_html( $current_name ); ?></span>
                <?php endif; ?>
            </form>

            <div class="tt-tc-toolbar-group">
                <span class="tt-tc-toolbar-label"><?php esc_html_e( 'Play style', 'talenttrack' ); ?></span>
                <span class="tt-tc-toolbar-value">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: possession 2: counter 3: press */
                        __( 'possession %1$d &middot; counter %2$d &middot; press %3$d', 'talenttrack' ),
                        $poss, $cntr, $prss
                    ) );
                    ?>
                </span>
            </div>

            <?php if ( $can_manage ) : ?>
                <div class="tt-tc-toolbar-actions tt-tc-sandbox" data-team-id="<?php echo (int) $team_id; ?>" data-mode="off">
                    <div class="tt-tc-seg" role="tablist" aria-label="<?php esc_attr_e( 'Lineup mode', 'talenttrack' ); ?>">
                        <button type="button" class="tt-tc-seg-btn is-active tt-tc-sandbox-mode-suggested" data-mode-target="suggested" aria-pressed="true">
                            <?php esc_html_e( 'Suggested XI', 'talenttrack' ); ?>
                        </button>
                        <button type="button" class="tt-tc-seg-btn tt-tc-sandbox-toggle" data-mode-target="override" aria-pressed="false">
                            <?php esc_html_e( 'Try a lineup', 'talenttrack' ); ?>
                        </button>
                    </div>
                    <button type="button" class="tt-btn tt-btn-secondary tt-tc-sandbox-reset" hidden>
                        <?php esc_html_e( 'Reset', 'talenttrack' ); ?>
                    </button>
                    <button type="button" class="tt-btn tt-btn-secondary tt-tc-load-blueprint"
                            data-team-id="<?php echo (int) $team_id; ?>">
                        <?php esc_html_e( 'Load blueprint', 'talenttrack' ); ?>
                    </button>
                    <button type="button" class="tt-btn tt-btn-primary tt-tc-sandbox-save" hidden>
                        <?php
                        echo $loaded_blueprint !== null
                            ? esc_html__( 'Save changes', 'talenttrack' )
                            : esc_html__( 'Save as blueprint', 'talenttrack' );
                        ?>
                    </button>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Roster sidebar — sorted by team-fit score, with the same pattern
     * the blueprint editor uses (avatar + name + position + fit pill).
     *
     * @param array<string,mixed> $chem
     */
    private static function renderRosterSidebar( int $team_id, array $chem ): void {
        $rmax_cfg         = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $strong_threshold = $rmax_cfg * 0.80;
        $weak_threshold   = $rmax_cfg * 0.40;

        // Effective fit per roster player = best fit from the depth chart
        // across all slots. Reuses the data the aggregator already
        // emitted so we don't re-query.
        $best_by_player = [];
        foreach ( (array) ( $chem['depth'] ?? [] ) as $slot_rows ) {
            foreach ( (array) $slot_rows as $row ) {
                $pid = (int) ( $row['player_id'] ?? 0 );
                if ( $pid <= 0 ) continue;
                $score = (float) ( $row['score'] ?? 0.0 );
                $has   = ! empty( $row['has_data'] );
                if ( ! isset( $best_by_player[ $pid ] ) || $score > $best_by_player[ $pid ]['score'] ) {
                    $best_by_player[ $pid ] = [ 'score' => $score, 'has_data' => $has ];
                }
            }
        }

        $players = (array) QueryHelpers::get_players( $team_id );
        $roster_rows = [];
        foreach ( $players as $pl ) {
            $name = QueryHelpers::player_display_name( $pl );
            $entry = $best_by_player[ (int) $pl->id ] ?? [ 'score' => 0.0, 'has_data' => false ];
            $pos   = '';
            if ( ! empty( $pl->preferred_positions ) ) {
                $parts = array_filter( array_map( 'trim', explode( ',', (string) $pl->preferred_positions ) ) );
                $pos = $parts ? (string) reset( $parts ) : '';
            }
            $roster_rows[] = [
                'id'       => (int) $pl->id,
                'name'     => $name,
                'pos'      => $pos,
                'score'    => (float) $entry['score'],
                'has_data' => (bool) $entry['has_data'],
            ];
        }

        // Highest-fit first; players without data go to the bottom.
        usort( $roster_rows, static function ( array $a, array $b ): int {
            if ( $a['has_data'] !== $b['has_data'] ) return $b['has_data'] ? 1 : -1;
            return ( $a['score'] === $b['score'] ) ? 0 : ( $a['score'] > $b['score'] ? -1 : 1 );
        } );
        ?>
        <aside class="tt-tc-roster" aria-label="<?php esc_attr_e( 'Roster sorted by team fit', 'talenttrack' ); ?>">
            <div class="tt-tc-roster-head">
                <h2><?php esc_html_e( 'Roster', 'talenttrack' ); ?></h2>
                <span class="tt-tc-roster-count"><?php echo (int) count( $roster_rows ); ?></span>
            </div>
            <label class="screen-reader-text" for="tt-tc-roster-filter">
                <?php esc_html_e( 'Search roster', 'talenttrack' ); ?>
            </label>
            <input id="tt-tc-roster-filter" type="search" class="tt-tc-roster-filter"
                   placeholder="<?php esc_attr_e( 'Search&hellip;', 'talenttrack' ); ?>"
                   autocomplete="off" inputmode="search">
            <ul class="tt-tc-roster-list" role="list">
                <?php if ( empty( $roster_rows ) ) : ?>
                    <li class="tt-tc-roster-empty">
                        <em><?php esc_html_e( 'No players on this team yet.', 'talenttrack' ); ?></em>
                    </li>
                <?php endif; ?>
                <?php foreach ( $roster_rows as $row ) :
                    $initials = self::initialsFromName( $row['name'] );
                    $fit_class = 'tt-tc-fit';
                    if ( $row['has_data'] ) {
                        if ( $row['score'] >= $strong_threshold )    $fit_class .= ' is-strong';
                        elseif ( $row['score'] <= $weak_threshold )  $fit_class .= ' is-weak';
                    }
                    $fit_text = $row['has_data']
                        ? number_format_i18n( $row['score'], 1 )
                        : '&mdash;';
                    ?>
                    <li class="tt-tc-roster-row" data-player-id="<?php echo (int) $row['id']; ?>" data-search="<?php echo esc_attr( strtolower( $row['name'] . ' ' . $row['pos'] ) ); ?>">
                        <span class="tt-tc-av" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
                        <span class="tt-tc-who">
                            <span class="tt-tc-name"><?php echo esc_html( $row['name'] ); ?></span>
                            <?php if ( $row['pos'] !== '' ) : ?>
                                <span class="tt-tc-meta"><?php echo esc_html( $row['pos'] ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="<?php echo esc_attr( $fit_class ); ?>" title="<?php esc_attr_e( 'Best team-fit score across formation slots', 'talenttrack' ); ?>">
                            <?php echo wp_kses( $fit_text, [] ); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>
        <?php
    }

    /**
     * Pitch card in the centre. Hands off to PitchSvg which renders the
     * pitch markings, the chemistry-link SVG lines, and the slot HTML
     * overlays (with the data attributes the JS needs).
     *
     * @param list<array<string,mixed>> $slots
     * @param array<string,array<string,mixed>> $suggested
     * @param array<string,mixed> $blueprint
     */
    private static function renderPitchCard( array $slots, array $suggested, array $blueprint ): void {
        ?>
        <section class="tt-tc-pitch-card" aria-label="<?php esc_attr_e( 'Pitch', 'talenttrack' ); ?>">
            <header class="tt-tc-pitch-head">
                <h2><?php esc_html_e( 'Starting XI', 'talenttrack' ); ?></h2>
                <div class="tt-tc-legend" aria-hidden="true">
                    <span><span class="tt-tc-swatch is-strong"></span><?php esc_html_e( 'Strong', 'talenttrack' ); ?></span>
                    <span><span class="tt-tc-swatch is-workable"></span><?php esc_html_e( 'Workable', 'talenttrack' ); ?></span>
                    <span><span class="tt-tc-swatch is-poor"></span><?php esc_html_e( 'Poor', 'talenttrack' ); ?></span>
                </div>
            </header>
            <?php
            // PitchSvg renders flat mode by default; the `?perspective=isometric`
            // opt-in stays for users who prefer the v1 tilted look.
            $perspective = ( isset( $_GET['perspective'] ) && $_GET['perspective'] === 'isometric' )
                ? PitchSvg::MODE_ISOMETRIC
                : PitchSvg::MODE_FLAT;
            $links = isset( $blueprint['links'] ) && is_array( $blueprint['links'] ) ? $blueprint['links'] : [];
            PitchSvg::render( $slots, $suggested, $perspective, $links );
            ?>
            <p class="tt-tc-pitch-hint">
                <?php esc_html_e( 'Lines connect formation-adjacent slots. Hover any line for the per-pair breakdown.', 'talenttrack' ); ?>
            </p>
        </section>
        <?php
    }

    /**
     * Right column — KPI scoreboard + chemistry score headline + coach
     * pairings panel. The scoreboard mirrors the mockup's vertical stack
     * of `.score-card`s; the pairings panel keeps the v1 CRUD form.
     *
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $chem
     */
    private static function renderRightColumn( int $team_id, array $blueprint, array $chem, bool $can_manage ): void {
        $rmax_cfg = (float) QueryHelpers::get_config( 'rating_max', '10' );
        echo '<aside class="tt-tc-rightcol" aria-label="' . esc_attr__( 'Chemistry insights', 'talenttrack' ) . '">';
        self::renderScoreboard( $blueprint, $chem, $rmax_cfg );
        self::renderPairingsCard( $team_id, $can_manage );
        echo '</aside>';
    }

    /**
     * Vertical KPI stack from the mockup: a green "Team chemistry"
     * headline card on top, then formation / style / depth / coverage
     * sub-cards.
     *
     * @param array<string,mixed> $blueprint
     * @param array<string,mixed> $chem
     */
    private static function renderScoreboard( array $blueprint, array $chem, float $rmax_cfg ): void {
        $link_score = isset( $blueprint['team_score'] ) ? $blueprint['team_score'] : null;
        $scored_pairs = (int) ( $blueprint['scored_pair_count'] ?? 0 );
        $composite    = $chem['composite']     ?? null;
        $formation_fit= $chem['formation_fit'] ?? null;
        $style_fit    = $chem['style_fit']     ?? null;
        $depth_score  = $chem['depth_score']   ?? null;
        $coverage     = isset( $chem['data_coverage'] ) ? (float) $chem['data_coverage'] : 0.0;
        ?>
        <section class="tt-tc-scoreboard" aria-label="<?php esc_attr_e( 'Chemistry scores', 'talenttrack' ); ?>"
                 data-tt-chem-breakdown data-rating-max="<?php echo esc_attr( (string) $rmax_cfg ); ?>">

            <div class="tt-tc-score-card is-headline">
                <span class="tt-tc-score-label"><?php esc_html_e( 'Link chemistry', 'talenttrack' ); ?></span>
                <span class="tt-tc-score-value" data-tt-link-headline>
                    <?php if ( $link_score === null ) : ?>
                        &mdash; <sup>/100</sup>
                    <?php else : ?>
                        <?php echo esc_html( (string) (int) $link_score ); ?><sup>/100</sup>
                    <?php endif; ?>
                </span>
                <span class="tt-tc-score-trend" data-tt-link-subtitle>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: scored adjacent pair count */
                        _n( '%d scored pair on the pitch.', '%d scored pairs on the pitch.', $scored_pairs, 'talenttrack' ),
                        $scored_pairs
                    ) );
                    ?>
                </span>
            </div>

            <div class="tt-tc-score-card">
                <span class="tt-tc-score-label"><?php esc_html_e( 'Composite', 'talenttrack' ); ?></span>
                <span class="tt-tc-score-value" data-tt-chem-composite-heading>
                    <?php
                    if ( $composite === null ) {
                        echo '&mdash; <sup>/' . esc_html( number_format_i18n( $rmax_cfg, 0 ) ) . '</sup>';
                    } else {
                        echo esc_html( number_format_i18n( (float) $composite, 2 ) );
                        echo ' <sup>/' . esc_html( number_format_i18n( $rmax_cfg, 0 ) ) . '</sup>';
                    }
                    ?>
                </span>
                <span class="tt-tc-score-trend"><?php esc_html_e( 'Weighted blend of the four parts.', 'talenttrack' ); ?></span>
            </div>

            <div class="tt-tc-score-card">
                <span class="tt-tc-score-label"><?php esc_html_e( 'Formation fit', 'talenttrack' ); ?></span>
                <span class="tt-tc-score-value" data-tt-chem-part="formation_fit">
                    <?php echo $formation_fit === null ? '&mdash;' : esc_html( number_format_i18n( (float) $formation_fit, 2 ) ); ?>
                </span>
                <span class="tt-tc-score-trend"><?php esc_html_e( 'Slot-by-slot best fit.', 'talenttrack' ); ?></span>
            </div>

            <div class="tt-tc-score-card">
                <span class="tt-tc-score-label"><?php esc_html_e( 'Style fit', 'talenttrack' ); ?></span>
                <span class="tt-tc-score-value" data-tt-chem-part="style_fit">
                    <?php echo $style_fit === null ? '&mdash;' : esc_html( number_format_i18n( (float) $style_fit, 2 ) ); ?>
                </span>
                <span class="tt-tc-score-trend"><?php esc_html_e( 'Roster vs play style.', 'talenttrack' ); ?></span>
            </div>

            <div class="tt-tc-score-card">
                <span class="tt-tc-score-label"><?php esc_html_e( 'Depth', 'talenttrack' ); ?></span>
                <span class="tt-tc-score-value" data-tt-chem-part="depth_score">
                    <?php echo $depth_score === null ? '&mdash;' : esc_html( number_format_i18n( (float) $depth_score, 2 ) ); ?>
                </span>
                <span class="tt-tc-score-trend"><?php esc_html_e( 'Backup quality per slot.', 'talenttrack' ); ?></span>
            </div>

            <div class="tt-tc-score-card">
                <span class="tt-tc-score-label"><?php esc_html_e( 'Coverage', 'talenttrack' ); ?></span>
                <span class="tt-tc-score-value">
                    <?php echo esc_html( (string) (int) round( $coverage * 100 ) ); ?><sup>%</sup>
                </span>
                <span class="tt-tc-score-trend"><?php esc_html_e( 'Players with at least one rated category.', 'talenttrack' ); ?></span>
            </div>
        </section>
        <?php
    }

    /**
     * Coach-marked pairings panel — inline on the chemistry page per the
     * mockup. List + add/remove for managers; read-only for everyone else.
     */
    private static function renderPairingsCard( int $team_id, bool $can_manage ): void {
        $pairings = ( new PairingsRepository() )->listForTeam( $team_id );
        $count = count( $pairings );
        ?>
        <section class="tt-tc-pairings" aria-label="<?php esc_attr_e( 'Coach-marked pairings', 'talenttrack' ); ?>">
            <div class="tt-tc-pairings-head">
                <h2><?php esc_html_e( 'Coach pairings', 'talenttrack' ); ?></h2>
                <span class="tt-tc-pairings-count"><?php echo (int) $count; ?></span>
            </div>
            <p class="tt-tc-pairings-sub">
                <?php esc_html_e( 'Players that work well together. Boosts link chemistry when paired in adjacent slots.', 'talenttrack' ); ?>
            </p>
            <?php if ( empty( $pairings ) ) : ?>
                <p class="tt-tc-pairings-empty">
                    <em><?php esc_html_e( 'No pairings yet.', 'talenttrack' ); ?></em>
                </p>
            <?php else : ?>
                <ul class="tt-tc-pairings-list">
                    <?php foreach ( $pairings as $pair ) :
                        $a = QueryHelpers::get_player( (int) $pair['player_a_id'] );
                        $b = QueryHelpers::get_player( (int) $pair['player_b_id'] );
                        $a_name = $a ? QueryHelpers::player_display_name( $a ) : '—';
                        $b_name = $b ? QueryHelpers::player_display_name( $b ) : '—';
                        ?>
                        <li class="tt-tc-pairing-row">
                            <span class="tt-tc-pairing-names">
                                <?php echo esc_html( $a_name . ' · ' . $b_name ); ?>
                            </span>
                            <?php if ( $can_manage ) :
                                $rest_path = 'pairings/' . (int) $pair['id'];
                                ?>
                                <button type="button" class="tt-tc-pairing-x tt-rest-action"
                                        data-rest-path="<?php echo esc_attr( $rest_path ); ?>"
                                        data-rest-method="DELETE"
                                        data-confirm="<?php esc_attr_e( 'Remove this pairing?', 'talenttrack' ); ?>"
                                        aria-label="<?php esc_attr_e( 'Remove pairing', 'talenttrack' ); ?>">&times;</button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ( $can_manage ) :
                $players = QueryHelpers::get_players( $team_id );
                ?>
                <details class="tt-tc-pairing-add">
                    <summary class="tt-tc-pairing-add-toggle">
                        <?php esc_html_e( '+ Mark a pairing', 'talenttrack' ); ?>
                    </summary>
                    <form class="tt-tc-pairing-form tt-ajax-form"
                          data-rest-path="<?php echo esc_attr( 'teams/' . $team_id . '/pairings' ); ?>"
                          data-rest-method="POST" data-redirect-after-save="1">
                        <label class="screen-reader-text" for="tt-tc-pair-a"><?php esc_html_e( 'Player A', 'talenttrack' ); ?></label>
                        <select id="tt-tc-pair-a" name="player_a_id" class="tt-input" required>
                            <option value=""><?php esc_html_e( '— Player A —', 'talenttrack' ); ?></option>
                            <?php foreach ( $players as $pl ) : ?>
                                <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="tt-tc-pair-b"><?php esc_html_e( 'Player B', 'talenttrack' ); ?></label>
                        <select id="tt-tc-pair-b" name="player_b_id" class="tt-input" required>
                            <option value=""><?php esc_html_e( '— Player B —', 'talenttrack' ); ?></option>
                            <?php foreach ( $players as $pl ) : ?>
                                <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="screen-reader-text" for="tt-tc-pair-note"><?php esc_html_e( 'Pairing note', 'talenttrack' ); ?></label>
                        <input id="tt-tc-pair-note" type="text" name="note" class="tt-input"
                               placeholder="<?php esc_attr_e( 'Optional note', 'talenttrack' ); ?>" inputmode="text" autocomplete="off">
                        <div class="tt-tc-pairing-form-actions">
                            <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm">
                                <?php esc_html_e( 'Add pairing', 'talenttrack' ); ?>
                            </button>
                        </div>
                        <div class="tt-form-msg" role="status" aria-live="polite"></div>
                    </form>
                </details>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Visible banner shown when the team's eval coverage is below the
     * aggregator threshold.
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
        <div class="tt-tc-empty-banner">
            <strong><?php esc_html_e( 'Not enough evaluations to compute team chemistry yet.', 'talenttrack' ); ?></strong>
            <p>
                <?php
                printf(
                    /* translators: 1: rated count, 2: roster size, 3: missing count */
                    esc_html__( '%1$d of %2$d players have at least one rated main category. Rate %3$d more players to start seeing fit scores and a team composite.', 'talenttrack' ),
                    $rated, $roster, $missing
                );
                ?>
            </p>
            <p class="tt-tc-empty-banner-hint">
                <?php esc_html_e( 'The pitch below shows the suggested XI based on whatever data is available — players with "?" need their first evaluation; slots showing "—" mean the roster is smaller than this formation needs.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    private static function userCoachesTeam( int $user_id, int $team_id ): bool {
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            if ( (int) $t->id === $team_id ) return true;
        }
        return false;
    }

    /**
     * Enqueue the chemistry surface CSS + JS for every entry path — picker,
     * board, error states all use them. v4.13.0 moves both out of the
     * cap-gated sandbox enqueue so the picker grid also gets styling AND
     * the unguarded JS helpers (roster filter, formation auto-submit)
     * always run for read-only viewers.
     *
     * The cap-gated `localiseSandbox()` adds the `TT_TEAM_CHEM` payload
     * the picker / sandbox / save-as-blueprint code paths need; without
     * it the IIFE wires only the read-only helpers and returns.
     */
    private static function enqueueChemistryAssets(): void {
        wp_enqueue_style(
            'tt-team-chemistry',
            TT_PLUGIN_URL . 'assets/css/frontend-team-chemistry.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-team-chemistry',
            TT_PLUGIN_URL . 'assets/js/frontend-team-chemistry.js',
            [],
            TT_VERSION,
            true
        );
    }

    /**
     * Cap-gated: localise the "Try a lineup" sandbox config onto the
     * already-enqueued chemistry script. Without this payload the IIFE
     * binds only the read-only helpers (roster filter + formation
     * auto-submit) and exits.
     *
     * @param array<string,mixed> $chem
     */
    private static function localiseSandbox( int $team_id, int $template_id, int $poss, int $cntr, int $prss, array $chem ): void {
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
            'eligible'    => (array) ( $chem['eligible_by_slot'] ?? [] ),
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
                'save_bp_flavour_legend' => __( 'Blueprint type', 'talenttrack' ),
                'save_bp_flavour_match'  => __( 'Match-day lineup — single starting XI', 'talenttrack' ),
                'save_bp_flavour_squad'  => __( 'Squad plan — three tiers per slot (primary / secondary / tertiary)', 'talenttrack' ),
                'save_bp_name_label'     => __( 'Blueprint name', 'talenttrack' ),
                'save_bp_save'           => __( 'Save blueprint', 'talenttrack' ),
                'save_bp_cancel'         => __( 'Cancel', 'talenttrack' ),
                'load_bp_title'          => __( 'Pick a saved blueprint', 'talenttrack' ),
                'load_bp_empty'          => __( 'No saved blueprints yet.', 'talenttrack' ),
                'load_bp_list_failed'    => __( 'Could not load blueprints.', 'talenttrack' ),
                'reset_confirm'  => __( 'Discard the sandbox lineup and restore the suggested XI?', 'talenttrack' ),
                'sandbox_active' => __( '%d slot swapped from the suggested XI.', 'talenttrack' ),
                'sandbox_active_many' => __( '%d slots swapped from the suggested XI.', 'talenttrack' ),
                /* translators: 1: composite score, 2: rating max */
                'composite_label' => __( 'Team chemistry: %1$s / %2$s', 'talenttrack' ),
                /* translators: %s: rating max */
                'composite_unknown' => __( 'Team chemistry: ? / %s', 'talenttrack' ),
                'pairs_one'      => __( '%d scored pair on the pitch.', 'talenttrack' ),
                'pairs_many'     => __( '%d scored pairs on the pitch.', 'talenttrack' ),
                'link_score'     => __( '%d / 100', 'talenttrack' ),
                'link_score_unknown' => __( '— / 100', 'talenttrack' ),
                /* translators: 1: pair score 0-3, 2: comma-separated reasons */
                'link_tip'       => __( 'Chemistry %1$s / 3 — %2$s', 'talenttrack' ),
                'no_signals'     => __( 'no shared signals', 'talenttrack' ),
            ],
        ] );
    }

    /**
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

    /**
     * Two-letter initials for a roster avatar. Falls back to a single
     * letter if the display name is one token.
     */
    private static function initialsFromName( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name );
        if ( ! is_array( $parts ) || count( $parts ) === 0 ) return strtoupper( substr( $name, 0, 1 ) );
        if ( count( $parts ) === 1 ) return strtoupper( substr( $parts[0], 0, 2 ) );
        return strtoupper( substr( $parts[0], 0, 1 ) . substr( end( $parts ), 0, 1 ) );
    }

    /**
     * #1325 — resolve `?blueprint_id` to the team's saved blueprint or
     * null. Filters to match-day flavour (single-tier — squad-plan is
     * out of scope until the chemistry surface goes multi-tier) and
     * verifies the blueprint belongs to this team to keep the URL
     * non-cross-team.
     *
     * @return array{id:int, name:string, formation_template_id:int, primary_lineup:array<string,int>}|null
     */
    private static function loadRequestedBlueprint( int $team_id ): ?array {
        $blueprint_id = isset( $_GET['blueprint_id'] ) ? absint( $_GET['blueprint_id'] ) : 0;
        if ( $blueprint_id <= 0 ) return null;

        $repo = new \TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository();
        $bp = $repo->find( $blueprint_id );
        if ( $bp === null ) return null;
        if ( (int) ( $bp['team_id'] ?? 0 ) !== $team_id ) return null;
        if ( (string) ( $bp['flavour'] ?? '' ) === \TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN ) {
            return null;
        }

        return [
            'id'                    => (int) $bp['id'],
            'name'                  => (string) ( $bp['name'] ?? '' ),
            'formation_template_id' => (int) ( $bp['formation_template_id'] ?? 0 ),
            'primary_lineup'        => $repo->loadPrimaryLineup( $blueprint_id ),
        ];
    }

    /**
     * #1325 — replace each slot's suggested entry with the blueprint's
     * picked player. Looks up the new player's fit score in the same
     * slot's depth chart so the pitch card colour-codes correctly; falls
     * back to "no data" when the player isn't in the depth chart for
     * that slot (e.g. blueprint pick is a cross-position assignment).
     *
     * @param array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>       $suggested
     * @param array<string, list<array<string,mixed>>>                                                   $depth
     * @param array<string, int>                                                                          $lineup
     * @return array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>
     */
    private static function overlayBlueprintLineup( array $suggested, array $depth, array $lineup ): array {
        if ( empty( $lineup ) ) return $suggested;

        $out = $suggested;
        foreach ( $lineup as $slot_label => $player_id ) {
            $slot_key = (string) $slot_label;
            $pid      = (int) $player_id;
            if ( $pid <= 0 ) {
                $out[ $slot_key ] = [
                    'player_id' => 0, 'player_name' => '', 'score' => 0.0, 'has_data' => false,
                ];
                continue;
            }

            // Try the slot's depth chart first — gives the precomputed
            // fit score + name without an extra query.
            $entry = null;
            foreach ( (array) ( $depth[ $slot_key ] ?? [] ) as $row ) {
                if ( (int) ( $row['player_id'] ?? 0 ) === $pid ) {
                    $entry = $row;
                    break;
                }
            }
            if ( $entry === null ) {
                // Player isn't in the slot's depth chart — pull the
                // display name from the player record and mark
                // `has_data = false` so the pitch renders the "?" pill.
                $player = QueryHelpers::get_player( $pid );
                $name   = $player ? QueryHelpers::player_display_name( $player ) : '';
                $out[ $slot_key ] = [
                    'player_id'   => $pid,
                    'player_name' => $name,
                    'score'       => 0.0,
                    'has_data'    => false,
                ];
            } else {
                $out[ $slot_key ] = [
                    'player_id'   => $pid,
                    'player_name' => (string) ( $entry['player_name'] ?? '' ),
                    'score'       => (float)  ( $entry['score']       ?? 0.0 ),
                    'has_data'    => (bool)   ( $entry['has_data']    ?? false ),
                ];
            }
        }
        return $out;
    }

    /**
     * #1325 — banner above the layout: "Loaded blueprint: <name> · Clear".
     */
    private static function renderLoadedBlueprintBanner( array $bp, int $team_id ): void {
        $clear_url = add_query_arg(
            [ 'tt_view' => 'team-chemistry', 'team_id' => $team_id ],
            remove_query_arg( [ 'blueprint_id', 'template_id' ] )
        );
        ?>
        <div class="tt-tc-loaded-banner" role="status">
            <span>
                <strong><?php esc_html_e( 'Loaded blueprint:', 'talenttrack' ); ?></strong>
                <?php echo esc_html( (string) ( $bp['name'] ?? '' ) ); ?>
            </span>
            <a class="tt-btn tt-btn-secondary tt-btn-sm" href="<?php echo esc_url( $clear_url ); ?>">
                <?php esc_html_e( 'Clear', 'talenttrack' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * #1017 Phase 6 — the reworked-engine explainability panel. Only when
     * the `chemistry_engine_v2` toggle is on; computes the lineup aggregate
     * for the suggested XI and renders scores + strongest/weakest
     * partnerships + recommendations. Degrades silently on any error.
     *
     * @param list<array<string,mixed>>          $slots
     * @param array<string, array<string,mixed>> $suggested slot → entry
     */
    private static function renderChemistryInsight( int $team_id, array $slots, array $suggested ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return;
        if ( ! ( new \TT\Infrastructure\Config\ConfigService() )->getBool( 'chemistry_engine_v2', false ) ) return;

        wp_enqueue_style(
            'tt-chemistry-insight',
            TT_PLUGIN_URL . 'assets/css/components/chemistry-insight.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );

        $lineup = [];
        foreach ( $suggested as $label => $entry ) {
            $pid = is_array( $entry ) ? (int) ( $entry['player_id'] ?? 0 ) : 0;
            $lineup[ (string) $label ] = $pid > 0 ? $pid : null;
        }

        try {
            $result = ( new LineupChemistryAggregator() )->aggregate( $team_id, $slots, $lineup );
        } catch ( \Throwable $e ) {
            return;
        }
        if ( ( $result['lineup_score'] ?? null ) === null ) {
            echo '<section class="tt-chem-insight"><p class="tt-notice">'
                . esc_html__( 'Not enough chemistry-attribute data yet — rate more players to see the reworked insight.', 'talenttrack' )
                . '</p></section>';
            return;
        }

        $explain    = ( new ChemistryExplainer() )->explain( $result );
        $team_score = ( new TeamChemistryAggregator() )->teamChemistry( $team_id );

        $ids = [];
        foreach ( array_merge( $explain['strongest'], $explain['weakest'], $explain['recommendations'] ) as $p ) {
            $ids[ (int) $p['a_player_id'] ] = true;
            $ids[ (int) $p['b_player_id'] ] = true;
        }
        $names = self::resolveNames( array_keys( $ids ) );

        echo '<section class="tt-chem-insight">';
        echo '<h2 class="tt-chem-insight__title">' . esc_html__( 'Chemistry insight (reworked engine)', 'talenttrack' ) . '</h2>';

        echo '<div class="tt-chem-insight__scores">';
        self::scoreChip( __( 'Lineup', 'talenttrack' ), $result['lineup_score'] );
        $unit = $result['unit_scores'] ?? [];
        foreach ( [ 'gk' => __( 'Goalkeeper', 'talenttrack' ), 'def' => __( 'Defence', 'talenttrack' ), 'mid' => __( 'Midfield', 'talenttrack' ), 'att' => __( 'Attack', 'talenttrack' ) ] as $g => $glabel ) {
            if ( ( $unit[ $g ] ?? null ) !== null ) self::scoreChip( $glabel, $unit[ $g ] );
        }
        if ( $team_score !== null ) self::scoreChip( __( 'Team (recent)', 'talenttrack' ), $team_score );
        echo '</div>';

        self::renderPairList( __( 'Strongest partnerships', 'talenttrack' ), $explain['strongest'], $names );
        self::renderPairList( __( 'Weakest partnerships', 'talenttrack' ), $explain['weakest'], $names );

        if ( ! empty( $explain['recommendations'] ) ) {
            echo '<div class="tt-chem-insight__recs"><h3>' . esc_html__( 'Recommendations', 'talenttrack' ) . '</h3><ul>';
            foreach ( $explain['recommendations'] as $rec ) {
                echo '<li>' . esc_html( self::recommendationText( $rec, $names ) ) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '</section>';
    }

    private static function scoreChip( string $label, ?int $score ): void {
        echo '<span class="tt-chem-insight__chip"><span class="tt-chem-insight__chip-label">'
            . esc_html( $label ) . '</span><span class="tt-chem-insight__chip-val">'
            . ( $score === null ? '—' : (int) $score ) . '</span></span>';
    }

    /**
     * @param list<array<string,mixed>> $pairs
     * @param array<int,string>         $names
     */
    private static function renderPairList( string $title, array $pairs, array $names ): void {
        if ( empty( $pairs ) ) return;
        echo '<div class="tt-chem-insight__list"><h3>' . esc_html( $title ) . '</h3><ul>';
        foreach ( $pairs as $p ) {
            $a = $names[ (int) $p['a_player_id'] ] ?? ( '#' . (int) $p['a_player_id'] );
            $b = $names[ (int) $p['b_player_id'] ] ?? ( '#' . (int) $p['b_player_id'] );
            echo '<li class="tt-chem-cat-' . esc_attr( (string) $p['category'] ) . '">'
                . esc_html( $a . ' & ' . $b ) . ' <span class="tt-chem-insight__score">' . (int) round( (float) $p['score'] ) . '</span></li>';
        }
        echo '</ul></div>';
    }

    /**
     * @param array<string,mixed> $rec
     * @param array<int,string>   $names
     */
    private static function recommendationText( array $rec, array $names ): string {
        $a = $names[ (int) $rec['a_player_id'] ] ?? ( '#' . (int) $rec['a_player_id'] );
        $b = $names[ (int) $rec['b_player_id'] ] ?? ( '#' . (int) $rec['b_player_id'] );
        if ( ! empty( $rec['needs_data'] ) ) {
            return sprintf(
                /* translators: 1,2: player names */
                __( 'Rate %1$s & %2$s — not enough data to score their partnership.', 'talenttrack' ),
                $a, $b
            );
        }
        $component = self::componentLabel( (string) ( $rec['weakest_component'] ?? '' ) );
        return sprintf(
            /* translators: 1: component, 2,3: player names */
            __( 'Improve the %1$s between %2$s and %3$s.', 'talenttrack' ),
            $component, $a, $b
        );
    }

    private static function componentLabel( string $key ): string {
        switch ( $key ) {
            case 'compatibility': return __( 'compatibility', 'talenttrack' );
            case 'familiarity':   return __( 'familiarity', 'talenttrack' );
            case 'development':   return __( 'development fit', 'talenttrack' );
            case 'behaviour':     return __( 'behaviour fit', 'talenttrack' );
            case 'performance':   return __( 'shared performance', 'talenttrack' );
            default:              return __( 'chemistry', 'talenttrack' );
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private static function resolveNames( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 ) ) );
        if ( empty( $ids ) ) return [];
        global $wpdb;
        $p   = $wpdb->prefix;
        $in  = implode( ',', $ids );
        $rows = $wpdb->get_results( "SELECT id, first_name, last_name FROM {$p}tt_players WHERE id IN ($in)" );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = trim( (string) ( $r->first_name ?? '' ) . ' ' . (string) ( $r->last_name ?? '' ) );
        }
        return $out;
    }
}
