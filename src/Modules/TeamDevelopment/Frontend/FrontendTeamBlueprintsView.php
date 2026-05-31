<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\TeamDevelopment\BlueprintChemistryEngine;
use TT\Modules\TeamDevelopment\BlueprintShareToken;
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendThreadView;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendTeamBlueprintsView — coach-authored, persisted lineups
 * (#0068 follow-up, Phase 1: match-day flavour only).
 *
 *   ?tt_view=team-blueprints                       — team picker
 *   ?tt_view=team-blueprints&team_id=<int>         — list of blueprints for one team
 *   ?tt_view=team-blueprints&id=<int>              — editor (drag-drop + status controls)
 *
 * The editor renders the same `PitchSvg` the chemistry view uses,
 * with chemistry lines computed via `BlueprintChemistryEngine` on
 * the persisted assignments. The roster sidebar is HTML5-draggable;
 * JS handles drop → REST `PUT /blueprints/{id}/assignment` and
 * re-renders the chemistry score + lines from the response payload.
 *
 * Squad-plan flavour + trial overlay land in Phase 2.
 */
class FrontendTeamBlueprintsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'team_chemistry' )
        ) {
            self::renderHeader( __( 'Team blueprint', 'talenttrack' ) );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Team blueprint', 'talenttrack' ), 'pro' );
            return;
        }

        self::enqueueAssets();
        self::enqueueBlueprintAssets();

        $blueprint_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $blueprint_id > 0 ) {
            self::renderEditor( $blueprint_id, $user_id, $is_admin );
            return;
        }

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

        self::renderTeamList( $team );
    }

    private static function renderTeamPicker( int $user_id, bool $is_admin ): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Team blueprint', 'talenttrack' ) );
        self::renderHeader( __( 'Team blueprint', 'talenttrack' ) );

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) {
            echo '<p><em>' . esc_html__( 'No teams to show. Coaches see blueprint boards for teams they head-coach.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<p style="color:#5b6e75; margin-bottom:12px;">' . esc_html__( 'Pick a team to open its saved blueprints — match-day lineups you can build, share with staff, and lock once finalised.', 'talenttrack' ) . '</p>';
        $base_url = remove_query_arg( [ 'team_id' ] );
        echo '<div class="tt-card-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:12px;">';
        foreach ( $teams as $t ) {
            $url = add_query_arg( [ 'tt_view' => 'team-blueprints', 'team_id' => (int) $t->id ], $base_url );
            echo '<a class="tt-card" href="' . esc_url( $url ) . '" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px 16px; text-decoration:none; color:#1a1d21;">';
            echo '<strong style="display:block; margin-bottom:4px;">' . esc_html( (string) $t->name ) . '</strong>';
            echo '<span style="color:#5b6e75; font-size:13px;">' . esc_html__( 'Open blueprints →', 'talenttrack' ) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function renderTeamList( object $team ): void {
        FrontendBreadcrumbs::fromDashboard(
            sprintf( /* translators: %s = team name */ __( '%s — blueprints', 'talenttrack' ), $team->name ),
            [ FrontendBreadcrumbs::viewCrumb( 'team-blueprints', __( 'Team blueprint', 'talenttrack' ) ) ]
        );
        self::renderHeader( sprintf(
            /* translators: %s = team name */
            __( 'Blueprints — %s', 'talenttrack' ),
            (string) $team->name
        ) );

        $rows = ( new TeamBlueprintsRepository() )->listForTeam( (int) $team->id );

        $can_manage = current_user_can( 'tt_manage_team_chemistry' );
        $base_url   = remove_query_arg( [ 'team_id', 'id', 'action' ] );

        if ( $can_manage ) {
            $new_url = WizardEntryPoint::buildUrl( 'new-team-blueprint', [ 'team_id' => (int) $team->id ] );
            echo '<p style="margin:0 0 16px;">';
            echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
                . esc_html__( '+ New blueprint', 'talenttrack' ) . '</a>';
            echo '</p>';
        }

        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No blueprints yet for this team. Click "+ New blueprint" to start one.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<table class="tt-list-table-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Formation', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Updated', 'talenttrack' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $open = add_query_arg( [
                'tt_view' => 'team-blueprints',
                'id'      => (int) $row['id'],
            ], $base_url );
            echo '<tr>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $open ) . '">' . esc_html( (string) $row['name'] ) . '</a></td>';
            echo '<td>' . esc_html( (string) ( $row['template_name'] ?? '—' ) ) . '</td>';
            echo '<td>' . self::statusPill( (string) $row['status'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['updated_at'] ) . '</td>';
            echo '<td><a class="tt-btn tt-btn-secondary tt-btn-sm" href="' . esc_url( $open ) . '">'
                . esc_html__( 'Open', 'talenttrack' ) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderEditor( int $blueprint_id, int $user_id, bool $is_admin ): void {
        // #953 — toast for the "Save" button on the editor toolbar.
        if ( isset( $_GET['tt_saved'] ) && $_GET['tt_saved'] === '1' ) {
            echo '<div class="tt-notice tt-notice-success" role="status" style="margin-bottom:12px;">'
                . esc_html__( 'Blueprint saved.', 'talenttrack' )
                . '</div>';
        }
        $repo = new TeamBlueprintsRepository();
        $bp   = $repo->find( $blueprint_id );
        if ( $bp === null ) {
            self::renderHeader( __( 'Blueprint not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That blueprint no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }
        $team = QueryHelpers::get_team( (int) $bp['team_id'] );
        if ( ! $team ) {
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'The team for this blueprint no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! $is_admin && ! self::userCoachesTeam( $user_id, (int) $bp['team_id'] ) ) {
            self::renderHeader( __( 'Access denied', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not coach this team.', 'talenttrack' ) . '</p>';
            return;
        }

        $can_manage = current_user_can( 'tt_manage_team_chemistry' );
        $is_locked  = $bp['status'] === TeamBlueprintsRepository::STATUS_LOCKED;
        $is_squad   = (string) ( $bp['flavour'] ?? '' ) === TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN;
        $heatmap    = $is_squad && isset( $_GET['heatmap'] ) && $_GET['heatmap'] === '1';

        FrontendBreadcrumbs::fromDashboard(
            (string) $bp['name'],
            [
                FrontendBreadcrumbs::viewCrumb( 'team-blueprints', __( 'Team blueprint', 'talenttrack' ) ),
                FrontendBreadcrumbs::viewCrumb(
                    'team-blueprints',
                    sprintf( /* translators: %s = team name */ __( '%s — blueprints', 'talenttrack' ), $team->name ),
                    [ 'team_id' => (int) $team->id ]
                ),
            ]
        );
        self::renderHeader( sprintf(
            /* translators: 1 = blueprint name, 2 = team name */
            __( '%1$s · %2$s', 'talenttrack' ),
            (string) $bp['name'], (string) $team->name
        ) );

        $base_url = remove_query_arg( [ 'id', 'team_id', 'action' ] );

        // #0068 Phase 3 — tabbed editor (Lineup | Comments). The
        // Comments tab is gated on `tt_view_team_chemistry` (every
        // viewer of the editor already holds it).
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'lineup';
        if ( $tab !== 'comments' ) $tab = 'lineup';

        if ( $is_squad && $tab === 'lineup' ) {
            $toggle_url = $heatmap
                ? remove_query_arg( 'heatmap' )
                : add_query_arg( 'heatmap', '1' );
            $toggle_label = $heatmap
                ? __( 'Show lineup view', 'talenttrack' )
                : __( 'Show coverage heatmap', 'talenttrack' );
            echo '<p style="margin:0 0 12px;">';
            echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $toggle_url ) . '">'
                . esc_html( $toggle_label ) . '</a>';
            echo '</p>';
        }

        // Tab nav.
        $editor_url   = add_query_arg( [ 'tt_view' => 'team-blueprints', 'id' => (int) $bp['id'] ], $base_url );
        $comments_url = add_query_arg( [ 'tab' => 'comments' ], $editor_url );
        echo '<nav class="tt-bp-tabs" role="tablist" style="display:flex; gap:4px; border-bottom:1px solid #e5e7ea; margin-bottom:16px;">';
        $lineup_cls   = 'tt-bp-tab' . ( $tab === 'lineup' ? ' is-active' : '' );
        $comments_cls = 'tt-bp-tab' . ( $tab === 'comments' ? ' is-active' : '' );
        echo '<a class="' . esc_attr( $lineup_cls ) . '" href="' . esc_url( $editor_url ) . '" role="tab" aria-selected="' . ( $tab === 'lineup' ? 'true' : 'false' ) . '">'
            . esc_html__( 'Lineup', 'talenttrack' ) . '</a>';
        echo '<a class="' . esc_attr( $comments_cls ) . '" href="' . esc_url( $comments_url ) . '" role="tab" aria-selected="' . ( $tab === 'comments' ? 'true' : 'false' ) . '">'
            . esc_html__( 'Comments', 'talenttrack' ) . '</a>';
        echo '</nav>';

        // #0068 Phase 4 — share-link buttons (cap-gated on
        // tt_manage_team_chemistry; same as locking).
        if ( $can_manage && $tab === 'lineup' ) {
            self::renderShareLinkActions( $bp );
        }

        if ( $tab === 'comments' ) {
            FrontendThreadView::render( 'blueprint', (int) $bp['id'], $user_id );
            return;
        }

        // Status row + flavour pill + action buttons.
        self::renderStatusRow( $bp, $can_manage, $is_locked );

        // v3.110.184 — editor toolbar: Save / Save As / Hide chemistry.
        // Save: returns to the blueprints list with a toast confirming
        // the work is persisted (every drop already auto-saved; this is
        // "done editing" navigation). Save As: clones to a new draft
        // with a fresh name and redirects to the new editor.
        // Hide chemistry: CSS toggle bound by `frontend-team-blueprint.js`
        // (sessionStorage-persisted per blueprint).
        if ( $can_manage && ! $is_locked ) {
            self::renderEditorToolbar( $bp, $team );
        }

        // #953 — primary-tier lineup map drives the chemistry headline.
        // `loadAssignments()` already filters to `ref_kind='player'`, so
        // guest / custom refs naturally fall out of chemistry scoring.
        $tiered = (array) ( $bp['assignments'] ?? [] );
        $primary_lineup = [];
        foreach ( $tiered as $slot => $tiers ) {
            if ( isset( $tiers[ TeamBlueprintsRepository::TIER_PRIMARY ] ) ) {
                $primary_lineup[ (string) $slot ] = (int) $tiers[ TeamBlueprintsRepository::TIER_PRIMARY ];
            }
        }
        $chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
            (int) $bp['team_id'], (array) ( $bp['slots'] ?? [] ), $primary_lineup
        );
        self::renderChemistryHeadline( $chemistry );

        // Heatmap is a squad-plan-only read-only view — keep the old
        // path. Everyone else gets the new depth-chart editor.
        if ( $is_squad && $heatmap ) {
            self::renderHeatmapPitch( (array) ( $bp['slots'] ?? [] ), $tiered );
            return;
        }

        // #953 — slots with tier-2/3 entries but no tier-primary leave
        // chemistry silently uncomputed; surface the list as a warning
        // strip so the coach sees the score drop.
        $missing_primary = $repo->slotsMissingPrimary( $blueprint_id );
        if ( ! empty( $missing_primary ) ) {
            echo '<p class="tt-notice tt-notice-warning" style="margin:0 0 12px;">'
                . esc_html( sprintf(
                    /* translators: %s = comma-separated slot labels e.g. "ST, CM" */
                    __( 'Tier-1 unassigned on: %s — chemistry score skips these slots.', 'talenttrack' ),
                    implode( ', ', $missing_primary )
                ) )
                . '</p>';
        }

        self::renderBlueprintEditor( $bp, $can_manage && ! $is_locked );

        if ( $is_locked ) {
            echo '<p class="tt-notice" style="margin-top:16px;">'
                . esc_html__( 'This blueprint is locked. Reopen it to make changes.', 'talenttrack' )
                . '</p>';
        }
    }

    /**
     * Depth-chart editor surface — matches the prototype at
     * `.local-mockups/blueprint-editor/index.html`.
     *
     * Pitch markings + the formation toolbar are rendered server-side
     * so the page is meaningful before JS hydrates. Position cards,
     * tier-stack pills, drag-drop, picker and the add-form interactions
     * are populated by `assets/js/components/blueprint-editor.js` from
     * the localised `TT_BLUEPRINT_EDITOR` config.
     *
     * @param array<string,mixed> $bp
     */
    private static function renderBlueprintEditor( array $bp, bool $can_edit ): void {
        $team_id   = (int) $bp['team_id'];
        $team      = QueryHelpers::get_team( $team_id );
        $team_name = $team ? (string) $team->name : '';
        $is_locked = $bp['status'] === TeamBlueprintsRepository::STATUS_LOCKED;
        ?>
        <div class="tt-bpe-editor"
             data-blueprint-id="<?php echo (int) $bp['id']; ?>"
             data-team-id="<?php echo (int) $bp['team_id']; ?>"
             data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
             data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>">

            <?php self::renderEditorToolbarFormation( $bp, $team_name, $can_edit ); ?>

            <div class="tt-bpe-layout">
                <aside class="tt-bpe-roster" aria-label="<?php esc_attr_e( 'Roster', 'talenttrack' ); ?>">
                    <h3 class="tt-bpe-roster-title">
                        <?php
                        /* translators: %s = team name */
                        echo esc_html( sprintf( __( '%s — roster', 'talenttrack' ), $team_name ) );
                        ?>
                        <span class="tt-bpe-roster-count" aria-hidden="true"></span>
                    </h3>
                    <p class="tt-bpe-roster-hint">
                        <?php esc_html_e( 'Drag a player onto a slot, or click a slot to pick from the list.', 'talenttrack' ); ?>
                    </p>
                    <ul class="tt-bpe-roster-list" role="list"></ul>
                    <?php if ( $can_edit ) : ?>
                        <button type="button" class="tt-bpe-add-toggle">
                            <?php esc_html_e( '+ Add guest / custom name', 'talenttrack' ); ?>
                        </button>
                        <div class="tt-bpe-add-form" hidden>
                            <div class="tt-bpe-add-tabs" role="tablist">
                                <button type="button" class="tt-bpe-add-tab is-active" data-tab="crossteam" role="tab"><?php esc_html_e( 'Other team', 'talenttrack' ); ?></button>
                                <button type="button" class="tt-bpe-add-tab" data-tab="guest" role="tab"><?php esc_html_e( 'Guest', 'talenttrack' ); ?></button>
                                <button type="button" class="tt-bpe-add-tab" data-tab="custom" role="tab"><?php esc_html_e( 'Custom', 'talenttrack' ); ?></button>
                            </div>
                            <div class="tt-bpe-add-pane is-active" data-pane="crossteam" role="tabpanel">
                                <label class="screen-reader-text" for="tt-bpe-ct-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                                <select id="tt-bpe-ct-team" class="tt-bpe-ct-team">
                                    <option value=""><?php esc_html_e( '— pick a team —', 'talenttrack' ); ?></option>
                                </select>
                                <label class="screen-reader-text" for="tt-bpe-ct-player"><?php esc_html_e( 'Player', 'talenttrack' ); ?></label>
                                <select id="tt-bpe-ct-player" class="tt-bpe-ct-player">
                                    <option value=""><?php esc_html_e( '— pick a player —', 'talenttrack' ); ?></option>
                                </select>
                                <div class="tt-bpe-add-actions">
                                    <button type="button" class="tt-bpe-ct-add is-primary"><?php esc_html_e( 'Add to roster', 'talenttrack' ); ?></button>
                                    <button type="button" class="tt-bpe-add-cancel"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></button>
                                </div>
                            </div>
                            <div class="tt-bpe-add-pane" data-pane="guest" role="tabpanel">
                                <label class="screen-reader-text" for="tt-bpe-guest-name"><?php esc_html_e( 'Guest name', 'talenttrack' ); ?></label>
                                <input id="tt-bpe-guest-name" type="text" class="tt-bpe-guest-name" placeholder="<?php esc_attr_e( 'Guest name (e.g. visiting trialist)', 'talenttrack' ); ?>" inputmode="text" autocomplete="off">
                                <label class="screen-reader-text" for="tt-bpe-guest-pos"><?php esc_html_e( 'Position', 'talenttrack' ); ?></label>
                                <input id="tt-bpe-guest-pos" type="text" class="tt-bpe-guest-pos" placeholder="<?php esc_attr_e( 'Position (optional, e.g. ST)', 'talenttrack' ); ?>" inputmode="text" autocomplete="off">
                                <div class="tt-bpe-add-actions">
                                    <button type="button" class="tt-bpe-guest-add is-primary"><?php esc_html_e( 'Add guest', 'talenttrack' ); ?></button>
                                    <button type="button" class="tt-bpe-add-cancel"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></button>
                                </div>
                            </div>
                            <div class="tt-bpe-add-pane" data-pane="custom" role="tabpanel">
                                <label class="screen-reader-text" for="tt-bpe-custom-name"><?php esc_html_e( 'Custom label', 'talenttrack' ); ?></label>
                                <input id="tt-bpe-custom-name" type="text" class="tt-bpe-custom-name" placeholder="<?php esc_attr_e( 'Custom label (e.g. "Scout target #4")', 'talenttrack' ); ?>" inputmode="text" autocomplete="off">
                                <div class="tt-bpe-add-actions">
                                    <button type="button" class="tt-bpe-custom-add is-primary"><?php esc_html_e( 'Add custom', 'talenttrack' ); ?></button>
                                    <button type="button" class="tt-bpe-add-cancel"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </aside>

                <section class="tt-bpe-pitch-card" aria-label="<?php esc_attr_e( 'Pitch', 'talenttrack' ); ?>">
                    <div class="tt-bpe-pitch-wrap">
                        <svg class="tt-bpe-pitch-svg" viewBox="0 0 680 1050" preserveAspectRatio="none" aria-hidden="true">
                            <rect class="tt-bpe-pitch-line" x="20" y="20" width="640" height="1010"/>
                            <rect class="tt-bpe-pitch-line" x="138.4" y="20" width="403.2" height="165"/>
                            <rect class="tt-bpe-pitch-line" x="248.4" y="20" width="183.2" height="55"/>
                            <path class="tt-bpe-pitch-line" d="M 266.82 185 A 91.5 91.5 0 0 0 413.18 185"/>
                            <line class="tt-bpe-pitch-line" x1="20" y1="525" x2="660" y2="525"/>
                            <circle class="tt-bpe-pitch-line" cx="340" cy="525" r="91.5"/>
                            <rect class="tt-bpe-pitch-line" x="138.4" y="865" width="403.2" height="165"/>
                            <rect class="tt-bpe-pitch-line" x="248.4" y="975" width="183.2" height="55"/>
                            <path class="tt-bpe-pitch-line" d="M 266.82 865 A 91.5 91.5 0 0 1 413.18 865"/>
                        </svg>
                    </div>
                    <p class="tt-bpe-pitch-hint">
                        <?php
                        echo wp_kses(
                            __( 'Pill colours: <b class="tt-bpe-tier-1">&#9679; tier 1</b> &nbsp; <b class="tt-bpe-tier-2">&#9679; tier 2</b> &nbsp; <b class="tt-bpe-tier-3">&#9679; tier 3</b>. The &times;N badge next to a roster name shows how many slots reference that player.', 'talenttrack' ),
                            [ 'b' => [ 'class' => [] ] ]
                        );
                        ?>
                    </p>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * Toolbar above the layout — team display (read-only), formation
     * dropdown, clear-all button and a save-state hint.
     *
     * @param array<string,mixed> $bp
     */
    private static function renderEditorToolbarFormation( array $bp, string $team_name, bool $can_edit ): void {
        global $wpdb; $p = $wpdb->prefix;
        $templates = $wpdb->get_results(
            "SELECT id, name, formation_shape FROM {$p}tt_formation_templates
              WHERE archived_at IS NULL ORDER BY is_seeded DESC, name ASC"
        );
        $current_template_id = (int) ( $bp['formation_template_id'] ?? 0 );
        echo '<div class="tt-bpe-toolbar">';
        echo '<div class="tt-bpe-toolbar-group">';
        echo '<span class="tt-bpe-toolbar-label">' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<span class="tt-bpe-toolbar-value">' . esc_html( $team_name ) . '</span>';
        echo '</div>';
        echo '<div class="tt-bpe-toolbar-group">';
        echo '<label class="tt-bpe-toolbar-label" for="tt-bpe-formation">' . esc_html__( 'Formation', 'talenttrack' ) . '</label>';
        echo '<select id="tt-bpe-formation" class="tt-bpe-formation-select"' . ( $can_edit ? '' : ' disabled' ) . '>';
        foreach ( (array) $templates as $tpl ) {
            $sel = ( (int) $tpl->id === $current_template_id ) ? ' selected' : '';
            $label = (string) $tpl->name;
            if ( $tpl->formation_shape ) $label .= ' (' . (string) $tpl->formation_shape . ')';
            echo '<option value="' . (int) $tpl->id . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        if ( $can_edit ) {
            echo '<button type="button" class="tt-bpe-clear-all">' . esc_html__( 'Clear all slots', 'talenttrack' ) . '</button>';
        }
        echo '<span class="tt-bpe-toolbar-hint" aria-live="polite" data-tt-bpe-savehint></span>';
        echo '</div>';
    }

    /**
     * #0068 Phase 4 — share-link controls. "Open share link" opens a
     * read-only public view; "Rotate share link" sets a fresh seed
     * invalidating every prior URL for this blueprint.
     *
     * @param array<string,mixed> $bp
     */
    private static function renderShareLinkActions( array $bp ): void {
        $repo = new TeamBlueprintsRepository();
        $seed = $repo->ensureShareTokenSeed( (int) $bp['id'] );
        if ( $seed === '' ) return;
        $token = BlueprintShareToken::tokenFor( (int) $bp['id'], (string) $bp['uuid'], $seed );
        $share_url = add_query_arg( [
            'tt_view' => 'team-blueprint-share',
            'id'      => (string) $bp['uuid'],
            'token'   => $token,
        ], RecordLink::dashboardUrl() );
        $rotate_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tt_blueprint_rotate_share&id=' . (int) $bp['id'] ),
            'tt_blueprint_rotate_share_' . (int) $bp['id']
        );
        echo '<p class="tt-bp-share" style="margin:0 0 12px; display:flex; gap:8px; flex-wrap:wrap;">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $share_url ) . '" target="_blank" rel="noopener">'
            . esc_html__( 'Open share link', 'talenttrack' ) . '</a>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $rotate_url ) . '" '
            . 'onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Rotate the share link? Anyone holding the previous URL will be locked out.', 'talenttrack' ) ) ) . ');">'
            . esc_html__( 'Rotate share link', 'talenttrack' ) . '</a>';
        echo '</p>';
    }

    /**
     * #0068 Phase 4 — public read-only blueprint render. Reachable
     * without authentication via a signed-token URL. Renders pitch +
     * lineup table + chemistry headline + status pill, no comments.
     */
    public static function renderShared(): void {
        $uuid  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : '';
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';
        if ( $uuid === '' || $token === '' ) {
            self::renderSharedNotFound();
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_team_blueprints WHERE uuid = %s",
            $uuid
        ) );
        if ( ! $row ) {
            self::renderSharedNotFound();
            return;
        }

        // Switch the current_club filter to the blueprint's club so
        // the repository's club-scoped reads succeed without an active
        // session. The blueprint id is the only secret we'd be leaking
        // here, and it's already in the URL.
        $blueprint_id = (int) $row->id;
        $bp_club_id   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT club_id FROM {$wpdb->prefix}tt_team_blueprints WHERE id = %d",
            $blueprint_id
        ) );
        $club_filter = static function ( $club ) use ( $bp_club_id ) {
            return $bp_club_id > 0 ? $bp_club_id : $club;
        };
        add_filter( 'tt_current_club_id', $club_filter );

        $repo = new TeamBlueprintsRepository();
        $bp   = $repo->find( $blueprint_id );
        if ( $bp === null ) {
            remove_filter( 'tt_current_club_id', $club_filter );
            self::renderSharedNotFound();
            return;
        }

        $seed = $repo->ensureShareTokenSeed( $blueprint_id );
        if ( ! BlueprintShareToken::verify( $blueprint_id, (string) $bp['uuid'], $seed, $token ) ) {
            remove_filter( 'tt_current_club_id', $club_filter );
            self::renderSharedNotFound();
            return;
        }

        $team = QueryHelpers::get_team( (int) $bp['team_id'] );

        self::enqueueAssets();
        self::enqueueBlueprintAssets();

        echo '<div class="tt-bp-shared-wrap" style="max-width:960px; margin:0 auto; padding:16px;">';
        echo '<header style="margin-bottom:16px;">';
        echo '<h1 style="margin:0 0 6px;">' . esc_html( (string) $bp['name'] ) . '</h1>';
        if ( $team ) {
            echo '<p style="margin:0; color:#5b6e75;">' . esc_html( (string) $team->name ) . '</p>';
        }
        echo '</header>';

        echo '<div style="display:flex; gap:12px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">';
        echo self::statusPill( (string) $bp['status'] );
        echo self::flavourPill( (string) ( $bp['flavour'] ?? '' ) );
        echo '</div>';

        $tiered = (array) ( $bp['assignments'] ?? [] );
        $primary_lineup = [];
        foreach ( $tiered as $slot => $tiers ) {
            if ( isset( $tiers[ TeamBlueprintsRepository::TIER_PRIMARY ] ) ) {
                $primary_lineup[ (string) $slot ] = (int) $tiers[ TeamBlueprintsRepository::TIER_PRIMARY ];
            }
        }
        $chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
            (int) $bp['team_id'], (array) ( $bp['slots'] ?? [] ), $primary_lineup
        );
        self::renderChemistryHeadline( $chemistry );

        echo '<div class="tt-bp-shared-pitch" style="margin:16px 0;">';
        PitchSvg::render( (array) ( $bp['slots'] ?? [] ), self::lineupAsSuggested( $primary_lineup ), PitchSvg::MODE_FLAT, $chemistry['links'] );
        echo '</div>';

        // Lineup table for accessibility + parents reading on small
        // screens where the SVG is hard to scan.
        self::renderSharedLineupTable( (array) ( $bp['slots'] ?? [] ), $primary_lineup );

        echo '<p style="margin-top:24px; color:#5b6e75; font-size:13px;">'
            . esc_html__( 'This is a read-only share link. Comments and edits are coach-only inside TalentTrack.', 'talenttrack' )
            . '</p>';
        echo '</div>';

        remove_filter( 'tt_current_club_id', $club_filter );
    }

    /**
     * @param list<array<string,mixed>> $slots
     * @param array<string,int>         $primary_lineup
     */
    private static function renderSharedLineupTable( array $slots, array $primary_lineup ): void {
        echo '<table class="tt-bp-shared-lineup" style="width:100%; max-width:560px; border-collapse:collapse; font-size:14px;">';
        echo '<thead><tr>';
        echo '<th style="text-align:left; padding:6px 8px; border-bottom:1px solid #e5e7ea;">' . esc_html__( 'Slot', 'talenttrack' ) . '</th>';
        echo '<th style="text-align:left; padding:6px 8px; border-bottom:1px solid #e5e7ea;">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $slots as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $pid   = isset( $primary_lineup[ $label ] ) ? (int) $primary_lineup[ $label ] : 0;
            $name  = $pid > 0 ? QueryHelpers::player_display_name( QueryHelpers::get_player( $pid ) ) : '';
            echo '<tr>';
            echo '<td style="padding:6px 8px; border-bottom:1px solid #f1f3f5;">' . esc_html( $label ) . '</td>';
            echo '<td style="padding:6px 8px; border-bottom:1px solid #f1f3f5;">'
                . ( $name !== '' ? esc_html( $name ) : '<em style="color:#8a9099;">' . esc_html__( '— empty —', 'talenttrack' ) . '</em>' )
                . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderSharedNotFound(): void {
        status_header( 404 );
        echo '<div style="max-width:560px; margin:48px auto; padding:24px; text-align:center;">';
        echo '<h1>' . esc_html__( 'Share link not valid', 'talenttrack' ) . '</h1>';
        echo '<p style="color:#5b6e75;">'
            . esc_html__( 'This blueprint share link is no longer valid. Ask the coach for an updated link.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    /**
     * `admin-post.php?action=tt_blueprint_rotate_share&id=N` — operator
     * action behind a per-row nonce. Cap-gated on
     * `tt_manage_team_chemistry`. Sets a fresh seed; every prior URL
     * for this blueprint immediately fails verification.
     */
    public static function handleRotateShareLink(): void {
        if ( ! current_user_can( 'tt_manage_team_chemistry' ) ) {
            wp_die( esc_html__( 'You do not have permission to rotate the share link.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( $id <= 0 ) wp_die( esc_html__( 'Bad blueprint id.', 'talenttrack' ), '', [ 'response' => 400 ] );
        check_admin_referer( 'tt_blueprint_rotate_share_' . $id );
        ( new TeamBlueprintsRepository() )->rotateShareTokenSeed( $id, (int) get_current_user_id() );
        wp_safe_redirect( add_query_arg( [
            'tt_view' => 'team-blueprints',
            'id'      => $id,
            'tt_msg'  => 'share_rotated',
        ], RecordLink::dashboardUrl() ) );
        exit;
    }

    /** @param array<string,mixed> $bp */
    /**
     * v3.110.184 — editor toolbar above the chemistry headline. Three
     * affordances: "Hide chemistry" toggle (sessionStorage-persisted),
     * "Save" (returns to the team-blueprints list with toast), "Save as"
     * (clones the blueprint to a new draft via `POST /blueprints/{id}/clone`).
     *
     * The toggle + Save / Save-As behaviour is wired by
     * `frontend-team-blueprint.js`; this method only emits the markup
     * with the right data-attributes for the JS to find.
     */
    private static function renderEditorToolbar( array $bp, object $team ): void {
        $base_url = remove_query_arg( [ 'id', 'team_id', 'action' ] );
        $list_url = add_query_arg(
            [ 'tt_view' => 'team-blueprints', 'team_id' => (int) $bp['team_id'] ],
            $base_url
        );
        echo '<div class="tt-bp-editor-toolbar" data-blueprint-id="' . (int) $bp['id'] . '" data-list-url="' . esc_attr( $list_url ) . '" style="display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;">';

        // Hide-chemistry toggle. Bound by JS; the button reads its
        // pressed state from sessionStorage on hydrate so a refresh
        // keeps the preference.
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-hide-chem-toggle" aria-pressed="false">'
            . esc_html__( 'Hide chemistry', 'talenttrack' )
            . '</button>';

        // Save / Save As. Auto-save is on under the hood (every drop
        // PUTs immediately), so "Save" is effectively "done editing,
        // take me back to the list". Save As prompts the user for a
        // new name and clones.
        echo '<div style="margin-left:auto; display:inline-flex; gap:6px; flex-wrap:wrap;">';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-save-as">'
            . esc_html__( 'Save as…', 'talenttrack' )
            . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-primary tt-btn-sm tt-bp-save-done">'
            . esc_html__( 'Save', 'talenttrack' )
            . '</button>';
        echo '</div>';
        echo '</div>';
    }

    private static function renderStatusRow( array $bp, bool $can_manage, bool $is_locked ): void {
        $status  = (string) $bp['status'];
        $flavour = (string) ( $bp['flavour'] ?? '' );
        echo '<div class="tt-bp-statusbar" style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">';
        echo '<span style="color:#5b6e75; font-size:12px; text-transform:uppercase; letter-spacing:0.04em;">'
            . esc_html__( 'Status', 'talenttrack' ) . '</span>';
        echo self::statusPill( $status );
        echo self::flavourPill( $flavour );
        if ( $can_manage ) {
            echo '<span class="tt-bp-status-actions" data-blueprint-id="' . (int) $bp['id'] . '" style="display:inline-flex; gap:6px;">';
            if ( $status === TeamBlueprintsRepository::STATUS_DRAFT ) {
                echo '<button class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-status-btn" data-target-status="shared">'
                    . esc_html__( 'Share with staff', 'talenttrack' ) . '</button>';
            }
            if ( $status === TeamBlueprintsRepository::STATUS_SHARED ) {
                echo '<button class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-status-btn" data-target-status="draft">'
                    . esc_html__( 'Move back to draft', 'talenttrack' ) . '</button>';
                echo '<button class="tt-btn tt-btn-primary tt-btn-sm tt-bp-status-btn" data-target-status="locked">'
                    . esc_html__( 'Lock', 'talenttrack' ) . '</button>';
            }
            if ( $is_locked ) {
                echo '<button class="tt-btn tt-btn-secondary tt-btn-sm tt-bp-status-btn" data-target-status="shared">'
                    . esc_html__( 'Reopen', 'talenttrack' ) . '</button>';
            }
            echo '</span>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $chemistry */
    private static function renderChemistryHeadline( array $chemistry ): void {
        $score = $chemistry['team_score'] ?? null;
        $scored = (int) ( $chemistry['scored_pair_count'] ?? 0 );
        ?>
        <div class="tt-bp-chem-card" id="tt-bp-chem-card">
            <div class="tt-bp-chem-head">
                <div class="tt-bp-chem-label"><?php esc_html_e( 'Link chemistry', 'talenttrack' ); ?></div>
                <div class="tt-bp-chem-value" id="tt-bp-chem-value">
                    <?php
                    if ( $score === null ) {
                        echo '<span style="color:#8a9099;">— / 100</span>';
                    } else {
                        echo esc_html( sprintf(
                            /* translators: %d: 0-100 chemistry score */
                            __( '%d / 100', 'talenttrack' ),
                            (int) $score
                        ) );
                    }
                    ?>
                </div>
                <div class="tt-bp-chem-pairs" id="tt-bp-chem-pairs">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: scored adjacent pair count */
                        _n( '%d scored adjacent pair on the pitch.', '%d scored adjacent pairs on the pitch.', $scored, 'talenttrack' ),
                        $scored
                    ) );
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Heatmap pitch — same SVG markings, but each slot is tinted by
     * how many tiers are filled (0=red, 1=orange, 2=yellow, 3=green).
     * Read-only; clicking a slot returns to the lineup view via the
     * heatmap toggle in the editor toolbar.
     *
     * @param list<array<string,mixed>>                                              $slots
     * @param array<string, array{primary?:int, secondary?:int, tertiary?:int}>      $tiered
     */
    private static function renderHeatmapPitch( array $slots, array $tiered ): void {
        $names = self::playerNames( self::flatPlayerIds( $tiered ) );
        ?>
        <div class="tt-pitch-wrap tt-bp-heatmap-wrap">
            <div class="tt-pitch" style="background: linear-gradient(180deg, var(--tt-pitch-grass-token, #4ea35f) 0%, var(--tt-pitch-grass-2-token, #3c8a4d) 100%);">
                <?php
                // Reuse the markings only — no chemistry lines on the heatmap.
                ?>
                <svg class="tt-pitch-svg" viewBox="0 0 680 1050" preserveAspectRatio="none" aria-hidden="true">
                    <rect class="tt-pitch-line" x="20" y="20" width="640" height="1010" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                    <rect class="tt-pitch-line" x="138.4" y="20" width="403.2" height="165" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                    <rect class="tt-pitch-line" x="248.4" y="20" width="183.2" height="55" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                    <line class="tt-pitch-line" x1="20" y1="525" x2="660" y2="525" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                    <circle class="tt-pitch-line" cx="340" cy="525" r="91.5" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                    <rect class="tt-pitch-line" x="138.4" y="865" width="403.2" height="165" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                    <rect class="tt-pitch-line" x="248.4" y="975" width="183.2" height="55" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="3" />
                </svg>
                <?php
                foreach ( $slots as $slot ) {
                    $label = (string) ( $slot['label'] ?? '' );
                    if ( $label === '' ) continue;
                    $tiers_for_slot = (array) ( $tiered[ $label ] ?? [] );
                    $depth = 0;
                    foreach ( TeamBlueprintsRepository::TIERS as $t ) {
                        if ( ! empty( $tiers_for_slot[ $t ] ) ) $depth++;
                    }
                    $depth_class = 'tt-bp-heat-' . $depth;
                    $x = (float) ( $slot['pos']['x'] ?? 0.5 ) * 100;
                    $y = (float) ( $slot['pos']['y'] ?? 0.5 ) * 100;
                    $tip = sprintf(
                        /* translators: 1: slot label, 2: primary name or em-dash, 3: depth count 0-3 */
                        __( '%1$s — primary: %2$s — %3$d/3 tiers covered', 'talenttrack' ),
                        $label,
                        ! empty( $tiers_for_slot[ TeamBlueprintsRepository::TIER_PRIMARY ] )
                            ? ( $names[ (int) $tiers_for_slot[ TeamBlueprintsRepository::TIER_PRIMARY ] ] ?? '?' )
                            : '—',
                        $depth
                    );
                    ?>
                    <div class="tt-pitch-slot tt-bp-heat-slot <?php echo esc_attr( $depth_class ); ?>"
                         style="left:<?php echo esc_attr( (string) $x ); ?>%; top:<?php echo esc_attr( (string) $y ); ?>%;"
                         title="<?php echo esc_attr( $tip ); ?>">
                        <strong><?php echo esc_html( $label ); ?></strong>
                        <span class="tt-bp-heat-count"><?php echo (int) $depth; ?>/3</span>
                    </div>
                    <?php
                }
                ?>
            </div>
            <div class="tt-bp-heat-legend">
                <span class="tt-bp-heat-legend-item tt-bp-heat-0"><?php esc_html_e( '0 — uncovered', 'talenttrack' ); ?></span>
                <span class="tt-bp-heat-legend-item tt-bp-heat-1"><?php esc_html_e( '1 — primary only', 'talenttrack' ); ?></span>
                <span class="tt-bp-heat-legend-item tt-bp-heat-2"><?php esc_html_e( '2 — primary + secondary', 'talenttrack' ); ?></span>
                <span class="tt-bp-heat-legend-item tt-bp-heat-3"><?php esc_html_e( '3 — full depth', 'talenttrack' ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, array{primary?:int, secondary?:int, tertiary?:int}> $tiered
     * @return list<int>
     */
    private static function flatPlayerIds( array $tiered ): array {
        $ids = [];
        foreach ( $tiered as $tiers ) {
            foreach ( (array) $tiers as $pid ) {
                $pid_int = (int) $pid;
                if ( $pid_int > 0 ) $ids[ $pid_int ] = true;
            }
        }
        return array_keys( $ids );
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function playerNames( array $ids ): array {
        if ( empty( $ids ) ) return [];
        global $wpdb; $p = $wpdb->prefix;
        $in = implode( ',', array_map( 'intval', $ids ) );
        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name FROM {$p}tt_players WHERE id IN ($in)"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = (string) $r->first_name . ' ' . (string) $r->last_name;
        }
        return $out;
    }

    /**
     * Convert a slot→player_id lineup into the shape PitchSvg's
     * `$suggested` parameter expects, so PitchSvg can keep its
     * existing render path (player name + slot label per slot).
     *
     * @param array<string,int> $lineup
     * @return array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>
     */
    private static function lineupAsSuggested( array $lineup ): array {
        if ( empty( $lineup ) ) return [];
        $ids = array_filter( array_map( 'intval', array_values( $lineup ) ), static fn( $i ) => $i > 0 );
        if ( empty( $ids ) ) return [];

        global $wpdb; $p = $wpdb->prefix;
        $in = implode( ',', array_map( 'intval', $ids ) );
        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name FROM {$p}tt_players WHERE id IN ($in)"
        );
        $by_id = [];
        foreach ( (array) $rows as $r ) {
            $by_id[ (int) $r->id ] = (string) $r->first_name . ' ' . (string) $r->last_name;
        }

        $out = [];
        foreach ( $lineup as $slot => $pid ) {
            $pid_int = (int) $pid;
            if ( $pid_int <= 0 ) continue;
            $out[ (string) $slot ] = [
                'player_id'   => $pid_int,
                'player_name' => $by_id[ $pid_int ] ?? '',
                'score'       => 0.0,
                'has_data'    => true,
            ];
        }
        return $out;
    }

    private static function flavourPill( string $flavour ): string {
        $is_squad = $flavour === TeamBlueprintsRepository::FLAVOUR_SQUAD_PLAN;
        $label = $is_squad
            ? __( 'Squad plan', 'talenttrack' )
            : __( 'Match-day', 'talenttrack' );
        $bg = $is_squad ? '#e8f0e8' : '#eef0f2';
        $fg = $is_squad ? '#2c8a2c' : '#5b6e75';
        return '<span class="tt-bp-flavour-pill" style="background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . '; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:600;">'
            . esc_html( $label ) . '</span>';
    }

    private static function statusPill( string $status ): string {
        $map = [
            TeamBlueprintsRepository::STATUS_DRAFT  => [ 'Draft',  '#5b6e75', '#eef0f2' ],
            TeamBlueprintsRepository::STATUS_SHARED => [ 'Shared', '#1d6cb1', '#e2eefb' ],
            TeamBlueprintsRepository::STATUS_LOCKED => [ 'Locked', '#7a4f1d', '#fbeed0' ],
        ];
        [ $label, $fg, $bg ] = $map[ $status ] ?? [ ucfirst( $status ), '#5b6e75', '#eef0f2' ];
        $translated = '';
        switch ( $status ) {
            case TeamBlueprintsRepository::STATUS_DRAFT:  $translated = __( 'Draft',  'talenttrack' ); break;
            case TeamBlueprintsRepository::STATUS_SHARED: $translated = __( 'Shared', 'talenttrack' ); break;
            case TeamBlueprintsRepository::STATUS_LOCKED: $translated = __( 'Locked', 'talenttrack' ); break;
            default: $translated = $label;
        }
        return '<span class="tt-status-badge" style="background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . '; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:600;">'
            . esc_html( $translated ) . '</span>';
    }

    private static function userCoachesTeam( int $user_id, int $team_id ): bool {
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            if ( (int) $t->id === $team_id ) return true;
        }
        return false;
    }

    private static function enqueueBlueprintAssets(): void {
        // Single editor stylesheet (mobile-first). The legacy
        // `frontend-team-blueprint.css` + `frontend-team-blueprint.js`
        // were retired in v4.6.0 (#972) — every editor / share / status
        // surface now reads styles + behaviour from this one pair.
        wp_enqueue_style(
            'tt-blueprint-editor',
            TT_PLUGIN_URL . 'assets/css/frontend-blueprint-editor.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-blueprint-editor',
            TT_PLUGIN_URL . 'assets/js/components/blueprint-editor.js',
            [],
            TT_VERSION,
            true
        );
        self::localiseBlueprintEditor();
    }

    /**
     * #953 — config for the new depth-chart editor. Localises:
     *
     *   - The blueprint's current formation template id + the slot list
     *     with `(label, x, y, num)` so the JS can render the position
     *     stack overlays.
     *   - The blueprint's roster (team players, augmented with any
     *     player already referenced by an existing cross-team
     *     assignment row so a returning user sees the pick they made
     *     last session).
     *   - The hydrated `assignment_refs` map (slot → tier → ref with
     *     display_name) so the JS doesn't re-fetch on first render.
     *   - Sibling-team players for the "+ Add → Other team" tab,
     *     scoped to the same club (`get_teams()` is club-scoped
     *     already).
     *   - i18n strings for the picker, search box, add-form labels and
     *     error toasts.
     */
    private static function localiseBlueprintEditor(): void {
        $blueprint_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $blueprint_id <= 0 ) return;

        $repo = new TeamBlueprintsRepository();
        $bp   = $repo->find( $blueprint_id );
        if ( $bp === null ) return;

        $team_id = (int) $bp['team_id'];

        // Slot list with the position-card x/y + (optional) jersey num
        // hint. The seeded formation templates carry `pos.x`, `pos.y`
        // and `label`; `num` is the slot-name digit when present in
        // `slots_json` (some templates do, some don't — JS handles
        // either).
        $slots = [];
        foreach ( (array) ( $bp['slots'] ?? [] ) as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $slots[] = [
                'label' => $label,
                'num'   => isset( $slot['num'] ) ? (int) $slot['num'] : null,
                'x'     => (float) ( $slot['pos']['x'] ?? 0.5 ),
                'y'     => (float) ( $slot['pos']['y'] ?? 0.5 ),
            ];
        }

        // Roster — team players first; cross-team / guest / custom
        // entries that already appear in stored assignments come back
        // via `assignment_refs` and the JS re-seeds them from there.
        $roster = [];
        foreach ( QueryHelpers::get_players( $team_id ) as $pl ) {
            $roster[] = [
                'roster_id' => 'p:' . (int) $pl->id,
                'kind'      => 'player',
                'player_id' => (int) $pl->id,
                'name'      => QueryHelpers::player_display_name( $pl ),
                'pos'       => self::firstPreferredPosition( $pl ),
                'age'       => self::ageFromDob( (string) ( $pl->date_of_birth ?? '' ) ),
                'team_id'   => $team_id,
                'team_name' => '',
            ];
        }

        // Sibling teams for the "+ Add → Other team" tab. Lazy: we hand
        // the full list of (team, [players]) up front so the chained
        // selects don't need a REST round-trip per change. Excludes
        // the current team — players already in `roster` above don't
        // need to surface as cross-team picks for themselves.
        $other_teams = [];
        foreach ( QueryHelpers::get_teams() as $t ) {
            if ( (int) $t->id === $team_id ) continue;
            $players = [];
            foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                $players[] = [
                    'id'   => (int) $pl->id,
                    'name' => QueryHelpers::player_display_name( $pl ),
                    'pos'  => self::firstPreferredPosition( $pl ),
                    'age'  => self::ageFromDob( (string) ( $pl->date_of_birth ?? '' ) ),
                ];
            }
            $other_teams[] = [
                'id'      => (int) $t->id,
                'name'    => (string) $t->name,
                'players' => $players,
            ];
        }

        // Formation templates list (id, name, shape, slots) so the JS
        // can swap the on-pitch position cards client-side without a
        // REST round-trip on every formation switch. Slots dropped
        // from the previous formation stay in `assignment_refs` —
        // round-tripping back to the old formation restores them.
        global $wpdb; $p = $wpdb->prefix;
        $tpl_rows = $wpdb->get_results(
            "SELECT id, name, formation_shape, slots_json FROM {$p}tt_formation_templates
              WHERE archived_at IS NULL ORDER BY is_seeded DESC, name ASC"
        );
        $formation_templates = [];
        foreach ( (array) $tpl_rows as $row ) {
            $decoded = json_decode( (string) ( $row->slots_json ?? '[]' ), true );
            if ( ! is_array( $decoded ) ) $decoded = [];
            $tpl_slots = [];
            foreach ( $decoded as $s ) {
                $label = (string) ( $s['label'] ?? '' );
                if ( $label === '' ) continue;
                $tpl_slots[] = [
                    'label' => $label,
                    'num'   => isset( $s['num'] ) ? (int) $s['num'] : null,
                    'abbr'  => isset( $s['abbr'] ) ? (string) $s['abbr'] : $label,
                    'x'     => (float) ( $s['pos']['x'] ?? 0.5 ),
                    'y'     => (float) ( $s['pos']['y'] ?? 0.5 ),
                ];
            }
            $formation_templates[] = [
                'id'    => (int) $row->id,
                'name'  => (string) $row->name,
                'shape' => isset( $row->formation_shape ) ? (string) $row->formation_shape : '',
                'slots' => $tpl_slots,
            ];
        }

        // Hydrate the stored ref shape with display_name + team_id /
        // team_name so the JS doesn't have to lookup names from the
        // roster list on first paint.
        $hydrated_refs = self::hydrateAssignmentRefsForEditor( $repo->loadAssignmentRefs( $blueprint_id ) );

        // List URL for the "Save" button (returns to the team's blueprint
        // list with a confirmation toast).
        $list_url = add_query_arg(
            [ 'tt_view' => 'team-blueprints', 'team_id' => $team_id ],
            remove_query_arg( [ 'id', 'action' ] )
        );

        wp_localize_script( 'tt-blueprint-editor', 'TT_BLUEPRINT_EDITOR', [
            'rest_root'           => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'               => wp_create_nonce( 'wp_rest' ),
            'blueprint_id'        => (int) $bp['id'],
            'team_id'             => $team_id,
            'list_url'            => $list_url,
            'locked'              => $bp['status'] === TeamBlueprintsRepository::STATUS_LOCKED,
            'can_manage'          => current_user_can( 'tt_manage_team_chemistry' ),
            'formation'           => [
                'template_id' => (int) ( $bp['formation_template_id'] ?? 0 ),
                'slots'       => $slots,
            ],
            'formation_templates' => $formation_templates,
            'roster'              => $roster,
            'other_teams'         => $other_teams,
            'assignment_refs'     => $hydrated_refs,
            'i18n'                => [
                'roster_empty'          => __( 'No players on this team yet.', 'talenttrack' ),
                /* translators: %1$s = team name, %2$d = roster size */
                'roster_title_fmt'      => __( '%1$s — roster (%2$d)', 'talenttrack' ),
                'kind_crossteam'        => __( 'cross-team', 'talenttrack' ),
                'kind_guest'            => __( 'guest', 'talenttrack' ),
                'kind_custom'           => __( 'custom', 'talenttrack' ),
                'kind_player'           => __( 'player', 'talenttrack' ),
                /* translators: %d = tier number 1/2/3 */
                'picker_head'           => __( 'Pick a player for tier %d', 'talenttrack' ),
                'search_placeholder'    => __( 'Search…', 'talenttrack' ),
                'clear_slot'            => __( 'Clear this slot', 'talenttrack' ),
                'no_matches'            => __( 'No players match.', 'talenttrack' ),
                /* translators: %d = placement count for that player */
                'placed_n'              => __( '×%d on pitch', 'talenttrack' ),
                'pick_team_and_player'  => __( 'Pick a team and a player.', 'talenttrack' ),
                'already_in_roster'     => __( 'That player is already on the roster.', 'talenttrack' ),
                'name_required'         => __( 'Name is required.', 'talenttrack' ),
                'label_required'        => __( 'Custom label is required.', 'talenttrack' ),
                'confirm_clear_all'     => __( 'Clear every slot on this blueprint? This cannot be undone.', 'talenttrack' ),
                'save_failed'           => __( 'Could not save the change. Try again.', 'talenttrack' ),
                'bad_ref'               => __( 'Could not identify the player. Try again or pick a different row.', 'talenttrack' ),
                /* translators: %d = age in years */
                'age_fmt'               => __( 'age %d', 'talenttrack' ),
                'hide_chem_label'       => __( 'Hide chemistry', 'talenttrack' ),
                'show_chem_label'       => __( 'Show chemistry', 'talenttrack' ),
                'save_as_prompt'        => __( 'Name the new blueprint:', 'talenttrack' ),
                'save_as_default'       => __( 'Copy of blueprint', 'talenttrack' ),
                'save_as_failed'        => __( 'Could not duplicate. Try again.', 'talenttrack' ),
                'saving'                => __( 'Saving…', 'talenttrack' ),
                'saved'                 => __( 'Saved.', 'talenttrack' ),
                'remove_from_roster'    => __( 'Remove from roster', 'talenttrack' ),
                /* translators: %s = entry display name (guest/custom/cross-team) */
                'confirm_remove_roster' => __( 'Remove %s from the roster? Any slots holding this entry will be cleared.', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * Best-effort age in whole years from a `YYYY-MM-DD` DOB. Returns
     * 0 if the DOB is empty / unparseable — JS treats 0 as "unknown"
     * and hides the "age N" suffix on roster rows.
     */
    private static function ageFromDob( string $dob ): int {
        if ( $dob === '' || strlen( $dob ) < 10 ) return 0;
        try {
            $birth = new \DateTimeImmutable( $dob );
            $now   = new \DateTimeImmutable( 'now' );
            return (int) $birth->diff( $now )->y;
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    /**
     * First preferred-position label (the `preferred_positions` column
     * is a comma-separated list of abbreviations like `ST,LW`). Used
     * for the roster row's meta line.
     */
    private static function firstPreferredPosition( object $player ): string {
        $raw = isset( $player->preferred_positions ) ? (string) $player->preferred_positions : '';
        if ( $raw === '' ) return '';
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        return $parts ? (string) reset( $parts ) : '';
    }

    /**
     * Helper for the localiser — does the same denormalisation as the
     * REST controller's `hydrateAssignmentRefs()` but renamed to keep
     * the call site obvious + colocated. (Both helpers exist because
     * the view's localised payload is read before the REST GET fires;
     * de-duping into a shared service is a future cleanup.)
     *
     * @param array<string, array<string, array<string,mixed>>> $refs
     * @return array<string, array<string, array<string,mixed>>>
     */
    private static function hydrateAssignmentRefsForEditor( array $refs ): array {
        if ( empty( $refs ) ) return $refs;
        $player_ids = [];
        foreach ( $refs as $tiers ) {
            foreach ( $tiers as $ref ) {
                if ( ( $ref['kind'] ?? '' ) === 'player' && (int) ( $ref['player_id'] ?? 0 ) > 0 ) {
                    $player_ids[ (int) $ref['player_id'] ] = true;
                }
            }
        }
        $meta = [];
        if ( ! empty( $player_ids ) ) {
            global $wpdb; $p = $wpdb->prefix;
            $in = implode( ',', array_map( 'intval', array_keys( $player_ids ) ) );
            $rows = $wpdb->get_results(
                "SELECT p.id, p.first_name, p.last_name, p.team_id, t.name AS team_name
                   FROM {$p}tt_players p
                   LEFT JOIN {$p}tt_teams t ON t.id = p.team_id
                  WHERE p.id IN ($in)"
            );
            foreach ( (array) $rows as $row ) {
                $meta[ (int) $row->id ] = [
                    'display_name' => trim( (string) $row->first_name . ' ' . (string) $row->last_name ),
                    'team_id'      => $row->team_id !== null ? (int) $row->team_id : null,
                    'team_name'    => $row->team_name !== null ? (string) $row->team_name : null,
                ];
            }
        }
        $out = [];
        foreach ( $refs as $slot_label => $tiers ) {
            foreach ( $tiers as $tier => $ref ) {
                $kind = (string) ( $ref['kind'] ?? '' );
                if ( $kind === 'player' ) {
                    $m = $meta[ (int) ( $ref['player_id'] ?? 0 ) ] ?? null;
                    $ref['display_name'] = $m['display_name'] ?? '';
                    $ref['team_id']      = $m['team_id']      ?? null;
                    $ref['team_name']    = $m['team_name']    ?? null;
                } elseif ( $kind === 'guest' ) {
                    $ref['display_name'] = (string) ( $ref['name'] ?? '' );
                } elseif ( $kind === 'custom' ) {
                    $ref['display_name'] = (string) ( $ref['label'] ?? '' );
                }
                $out[ (string) $slot_label ][ (string) $tier ] = $ref;
            }
        }
        return $out;
    }
}
