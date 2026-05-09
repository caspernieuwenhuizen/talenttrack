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
            $new_url = add_query_arg( [
                'tt_view' => 'wizard',
                'slug'    => 'new-team-blueprint',
                'team_id' => (int) $team->id,
            ], RecordLink::dashboardUrl() );
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

        // Tiered assignments. Match-day blueprints only have primary;
        // squad-plan can have primary/secondary/tertiary per slot.
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

        // Set of all assigned player IDs across all tiers, so the
        // roster panel can grey them out.
        $assigned_ids = [];
        foreach ( $tiered as $tiers ) {
            foreach ( (array) $tiers as $pid ) {
                $assigned_ids[ (int) $pid ] = true;
            }
        }

        // Roster panel + pitch in a two-column layout.
        ?>
        <div class="tt-bp-editor <?php echo $is_squad ? 'tt-bp-flavour-squad' : 'tt-bp-flavour-match'; ?> <?php echo $heatmap ? 'tt-bp-heatmap-on' : ''; ?>"
             data-blueprint-id="<?php echo (int) $bp['id']; ?>"
             data-team-id="<?php echo (int) $bp['team_id']; ?>"
             data-flavour="<?php echo esc_attr( (string) ( $bp['flavour'] ?? '' ) ); ?>"
             data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
             data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">
            <div class="tt-bp-roster" aria-label="<?php esc_attr_e( 'Available players', 'talenttrack' ); ?>">
                <h3><?php esc_html_e( 'Roster', 'talenttrack' ); ?></h3>
                <p class="tt-bp-roster-hint">
                    <?php
                    if ( $is_squad ) {
                        esc_html_e( 'Drag onto a depth-chart cell below or onto a pitch slot. Drop back here to remove. Trial players carry a yellow border.', 'talenttrack' );
                    } else {
                        esc_html_e( 'Drag a player onto a slot. Drop a player back here to remove them from the lineup.', 'talenttrack' );
                    }
                    ?>
                </p>
                <div class="tt-bp-roster-list" data-dropzone="roster">
                    <?php self::renderRosterChips( (int) $bp['team_id'], $assigned_ids, $can_manage && ! $is_locked, $is_squad ); ?>
                </div>
            </div>
            <div class="tt-bp-pitch-wrap">
                <?php
                if ( $heatmap ) {
                    self::renderHeatmapPitch( (array) ( $bp['slots'] ?? [] ), $tiered );
                } else {
                    PitchSvg::render( (array) ( $bp['slots'] ?? [] ), self::lineupAsSuggested( $primary_lineup ), PitchSvg::MODE_FLAT, $chemistry['links'] );
                    self::overlaySlotDropTargets( (array) ( $bp['slots'] ?? [] ), $primary_lineup, $can_manage && ! $is_locked, TeamBlueprintsRepository::TIER_PRIMARY );
                }
                ?>
            </div>
        </div>
        <?php

        if ( $is_squad && ! $heatmap ) {
            self::renderDepthChart( (array) ( $bp['slots'] ?? [] ), $tiered, $can_manage && ! $is_locked );
        }

        if ( $is_locked ) {
            echo '<p class="tt-notice" style="margin-top:16px;">'
                . esc_html__( 'This blueprint is locked. Reopen it to make changes.', 'talenttrack' )
                . '</p>';
        }
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
     * @param array<int, true> $assigned_ids set of player IDs already on the blueprint (any tier)
     */
    private static function renderRosterChips( int $team_id, array $assigned_ids, bool $can_drag, bool $include_trials ): void {
        $players = QueryHelpers::get_players( $team_id );
        $trials  = $include_trials ? self::loadTrialPlayers( $team_id ) : [];
        if ( empty( $players ) && empty( $trials ) ) {
            echo '<p style="color:#5b6e75;"><em>' . esc_html__( 'No players on this team yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        foreach ( $players as $pl ) {
            self::renderRosterChip( (int) $pl->id, QueryHelpers::player_display_name( $pl ), false, isset( $assigned_ids[ (int) $pl->id ] ), $can_drag );
        }
        if ( ! empty( $trials ) ) {
            echo '<div class="tt-bp-roster-divider" aria-hidden="true"></div>';
            echo '<p class="tt-bp-roster-section-label">' . esc_html__( 'Trials', 'talenttrack' ) . '</p>';
            foreach ( $trials as $row ) {
                $tid = (int) $row->id;
                $name = (string) $row->first_name . ' ' . (string) $row->last_name;
                self::renderRosterChip( $tid, $name, true, isset( $assigned_ids[ $tid ] ), $can_drag );
            }
        }
    }

    private static function renderRosterChip( int $pid, string $name, bool $is_trial, bool $is_assigned, bool $can_drag ): void {
        $classes = 'tt-bp-chip';
        if ( $is_trial )    $classes .= ' tt-bp-chip-trial';
        if ( $is_assigned ) $classes .= ' tt-bp-chip-assigned';
        $draggable = $can_drag && ! $is_assigned ? 'true' : 'false';
        echo '<div class="' . esc_attr( $classes ) . '"'
            . ' draggable="' . esc_attr( $draggable ) . '"'
            . ' data-player-id="' . (int) $pid . '"'
            . ' data-is-trial="' . ( $is_trial ? '1' : '0' ) . '"'
            . ' data-player-name="' . esc_attr( $name ) . '">'
            . esc_html( $name );
        if ( $is_trial ) {
            echo '<span class="tt-bp-trial-badge" aria-label="' . esc_attr__( 'Trial player', 'talenttrack' ) . '">' . esc_html__( 'TRIAL', 'talenttrack' ) . '</span>';
        }
        echo '</div>';
    }

    /**
     * Active trial players for this team — the locked decision is "only
     * trials assigned to this team", which we resolve to
     * `tt_players.status = 'trial'` rows on this team's roster.
     *
     * @return list<object>
     */
    private static function loadTrialPlayers( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$p}tt_players
              WHERE team_id = %d AND status = 'trial' AND club_id = %d
              ORDER BY last_name ASC, first_name ASC",
            $team_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Slot droptarget overlay — positioned absolutely on top of the
     * pitch so JS can attach drop handlers without rebuilding the
     * pitch SVG. Pitch slots target the primary tier (squad-plan
     * uses the depth chart below for secondary / tertiary tiers).
     *
     * @param list<array<string,mixed>> $slots
     * @param array<string,int>         $lineup_for_tier slot → player_id at this tier
     */
    private static function overlaySlotDropTargets( array $slots, array $lineup_for_tier, bool $can_drag, string $tier ): void {
        echo '<div class="tt-bp-droptargets" aria-hidden="true">';
        foreach ( $slots as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $x = (float) ( $slot['pos']['x'] ?? 0.5 );
            $y = (float) ( $slot['pos']['y'] ?? 0.5 );
            $pid = isset( $lineup_for_tier[ $label ] ) ? (int) $lineup_for_tier[ $label ] : 0;
            echo '<div class="tt-bp-droptarget"'
                . ' data-slot-label="' . esc_attr( $label ) . '"'
                . ' data-tier="' . esc_attr( $tier ) . '"'
                . ' data-player-id="' . (int) $pid . '"'
                . ' data-can-drag="' . ( $can_drag ? '1' : '0' ) . '"'
                . ' style="left:' . esc_attr( (string) ( $x * 100 ) ) . '%; top:' . esc_attr( (string) ( $y * 100 ) ) . '%;"></div>';
        }
        echo '</div>';
    }

    /**
     * Squad-plan depth chart — one row per slot, three columns
     * (primary / secondary / tertiary), each cell a drop target.
     *
     * @param list<array<string,mixed>>                                              $slots
     * @param array<string, array{primary?:int, secondary?:int, tertiary?:int}>      $tiered
     */
    private static function renderDepthChart( array $slots, array $tiered, bool $can_drag ): void {
        if ( empty( $slots ) ) return;

        $all_pids = [];
        foreach ( $tiered as $tiers ) {
            foreach ( (array) $tiers as $pid ) {
                $all_pids[ (int) $pid ] = true;
            }
        }
        $names = self::playerNames( array_keys( $all_pids ) );

        echo '<h3 class="tt-bp-depth-heading">' . esc_html__( 'Depth chart', 'talenttrack' ) . '</h3>';
        echo '<table class="tt-bp-depth-table" data-can-drag="' . ( $can_drag ? '1' : '0' ) . '"><thead><tr>';
        echo '<th>' . esc_html__( 'Slot', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Primary', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Secondary', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Tertiary', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $slots as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $tiers_for_slot = (array) ( $tiered[ $label ] ?? [] );
            echo '<tr>';
            echo '<td class="tt-bp-depth-slot"><strong>' . esc_html( $label ) . '</strong></td>';
            foreach ( TeamBlueprintsRepository::TIERS as $tier ) {
                $pid  = isset( $tiers_for_slot[ $tier ] ) ? (int) $tiers_for_slot[ $tier ] : 0;
                $name = $pid > 0 ? ( $names[ $pid ] ?? '' ) : '';
                echo '<td class="tt-bp-depth-cell tt-bp-droptarget-cell"'
                    . ' data-slot-label="' . esc_attr( $label ) . '"'
                    . ' data-tier="' . esc_attr( $tier ) . '"'
                    . ' data-player-id="' . (int) $pid . '"'
                    . ' data-can-drag="' . ( $can_drag ? '1' : '0' ) . '">';
                if ( $pid > 0 ) {
                    echo '<span class="tt-bp-depth-chip" data-player-id="' . (int) $pid . '" data-player-name="' . esc_attr( $name ) . '" draggable="' . ( $can_drag ? 'true' : 'false' ) . '">'
                        . esc_html( $name ) . '</span>';
                } else {
                    echo '<span class="tt-bp-depth-empty">—</span>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
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
