<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\TeamDevelopment\BlueprintChemistryEngine;
use TT\Modules\TeamDevelopment\Repositories\TeamBlueprintsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

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
        $back_url   = add_query_arg( [ 'tt_view' => 'team-blueprints' ], $base_url );

        echo '<p style="margin:0 0 16px; display:flex; gap:8px; flex-wrap:wrap;">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $back_url ) . '">'
            . esc_html__( '← Back to team picker', 'talenttrack' ) . '</a>';
        if ( $can_manage ) {
            $new_url = add_query_arg( [
                'tt_view' => 'wizard',
                'slug'    => 'new-team-blueprint',
                'team_id' => (int) $team->id,
            ], home_url( '/' ) );
            echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
                . esc_html__( '+ New blueprint', 'talenttrack' ) . '</a>';
        }
        echo '</p>';

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
        $list_url = add_query_arg( [
            'tt_view' => 'team-blueprints',
            'team_id' => (int) $bp['team_id'],
        ], $base_url );

        echo '<p style="margin:0 0 12px;">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $list_url ) . '">'
            . esc_html__( '← Back to blueprints', 'talenttrack' ) . '</a>';
        echo '</p>';

        // Status row + action buttons.
        self::renderStatusRow( $bp, $can_manage, $is_locked );

        // Compute initial chemistry on the saved lineup.
        $lineup = [];
        foreach ( (array) ( $bp['assignments'] ?? [] ) as $slot => $pid ) {
            $lineup[ (string) $slot ] = (int) $pid;
        }
        $chemistry = ( new BlueprintChemistryEngine() )->computeForLineup(
            (int) $bp['team_id'], (array) ( $bp['slots'] ?? [] ), $lineup
        );
        self::renderChemistryHeadline( $chemistry );

        // Roster panel + pitch in a two-column flex layout.
        ?>
        <div class="tt-bp-editor"
             data-blueprint-id="<?php echo (int) $bp['id']; ?>"
             data-team-id="<?php echo (int) $bp['team_id']; ?>"
             data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
             data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">
            <div class="tt-bp-roster" aria-label="<?php esc_attr_e( 'Available players', 'talenttrack' ); ?>">
                <h3><?php esc_html_e( 'Roster', 'talenttrack' ); ?></h3>
                <p class="tt-bp-roster-hint">
                    <?php esc_html_e( 'Drag a player onto a slot. Drop a player back here to remove them from the lineup.', 'talenttrack' ); ?>
                </p>
                <div class="tt-bp-roster-list" data-dropzone="roster">
                    <?php self::renderRosterChips( (int) $bp['team_id'], $lineup, $can_manage && ! $is_locked ); ?>
                </div>
            </div>
            <div class="tt-bp-pitch-wrap">
                <?php
                PitchSvg::render( (array) ( $bp['slots'] ?? [] ), self::lineupAsSuggested( $lineup ), PitchSvg::MODE_FLAT, $chemistry['links'] );
                self::overlaySlotDropTargets( (array) ( $bp['slots'] ?? [] ), $lineup, $can_manage && ! $is_locked );
                ?>
            </div>
        </div>
        <?php

        if ( $is_locked ) {
            echo '<p class="tt-notice" style="margin-top:16px;">'
                . esc_html__( 'This blueprint is locked. Reopen it to make changes.', 'talenttrack' )
                . '</p>';
        }
    }

    /** @param array<string,mixed> $bp */
    private static function renderStatusRow( array $bp, bool $can_manage, bool $is_locked ): void {
        $status = (string) $bp['status'];
        echo '<div class="tt-bp-statusbar" style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">';
        echo '<span style="color:#5b6e75; font-size:12px; text-transform:uppercase; letter-spacing:0.04em;">'
            . esc_html__( 'Status', 'talenttrack' ) . '</span>';
        echo self::statusPill( $status );
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

    private static function renderRosterChips( int $team_id, array $lineup_player_ids, bool $can_drag ): void {
        $players  = QueryHelpers::get_players( $team_id );
        $assigned = array_flip( array_values( array_map( 'intval', $lineup_player_ids ) ) );
        if ( empty( $players ) ) {
            echo '<p style="color:#5b6e75;"><em>' . esc_html__( 'No players on this team yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $name = QueryHelpers::player_display_name( $pl );
            $is_assigned = isset( $assigned[ $pid ] );
            $classes = 'tt-bp-chip' . ( $is_assigned ? ' tt-bp-chip-assigned' : '' );
            $draggable = $can_drag && ! $is_assigned ? 'true' : 'false';
            echo '<div class="' . esc_attr( $classes ) . '"'
                . ' draggable="' . esc_attr( $draggable ) . '"'
                . ' data-player-id="' . (int) $pid . '"'
                . ' data-player-name="' . esc_attr( $name ) . '">'
                . esc_html( $name )
                . '</div>';
        }
    }

    /**
     * Slot droptarget overlay — positioned absolutely on top of the
     * pitch so JS can attach drop handlers without rebuilding the
     * pitch SVG. Each target carries `data-slot-label` + the player
     * already there (if any).
     *
     * @param list<array<string,mixed>> $slots
     * @param array<string,int>         $lineup
     */
    private static function overlaySlotDropTargets( array $slots, array $lineup, bool $can_drag ): void {
        echo '<div class="tt-bp-droptargets" aria-hidden="true">';
        foreach ( $slots as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $x = (float) ( $slot['pos']['x'] ?? 0.5 );
            $y = (float) ( $slot['pos']['y'] ?? 0.5 );
            $pid = isset( $lineup[ $label ] ) ? (int) $lineup[ $label ] : 0;
            echo '<div class="tt-bp-droptarget"'
                . ' data-slot-label="' . esc_attr( $label ) . '"'
                . ' data-player-id="' . (int) $pid . '"'
                . ' data-can-drag="' . ( $can_drag ? '1' : '0' ) . '"'
                . ' style="left:' . esc_attr( (string) ( $x * 100 ) ) . '%; top:' . esc_attr( (string) ( $y * 100 ) ) . '%;"></div>';
        }
        echo '</div>';
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
        wp_enqueue_style(
            'tt-team-blueprint',
            TT_PLUGIN_URL . 'assets/css/frontend-team-blueprint.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-team-blueprint',
            TT_PLUGIN_URL . 'assets/js/frontend-team-blueprint.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-team-blueprint', 'TT_BLUEPRINT', [
            'rest_root' => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'i18n'      => [
                'save_failed' => __( 'Could not save the change. Try again.', 'talenttrack' ),
                'locked'      => __( 'This blueprint is locked. Reopen it before editing.', 'talenttrack' ),
                'pairs_one'   => __( '%d scored adjacent pair on the pitch.', 'talenttrack' ),
                'pairs_many'  => __( '%d scored adjacent pairs on the pitch.', 'talenttrack' ),
                'score_fmt'   => __( '%d / 100', 'talenttrack' ),
            ],
        ] );
    }
}
