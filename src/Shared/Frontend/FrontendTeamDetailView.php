<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendTeamDetailView — read-only display of a single team (#0063)
 * reachable via `?tt_view=teams&id=N`.
 *
 * Lists the roster (one row per player, RecordLink to the player
 * detail), the staff assignments (head coach / assistant / manager
 * pulled via `PeopleRepository::getTeamStaff` — NOT the legacy
 * `tt_teams.head_coach_id` column the user flagged), upcoming
 * activities, and a chemistry-score teaser linking to the chemistry
 * board.
 *
 * Cap-gated on `tt_view_teams`. Composition only.
 */
final class FrontendTeamDetailView extends FrontendViewBase {

    public static function render( int $team_id, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_teams' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view team details.', 'talenttrack' ) . '</p>';
            return;
        }

        // #1088 VCT-13 — handle inline VCT defaults panel POST before
        // rendering so the rendered panel reflects the saved state.
        // Cap-gated on tt_vct_admin_library inside the handler.
        $vct_panel_notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_tt_vct_team_panel'] ) ) {
            $vct_panel_notice = self::handleVctDefaultsPost( $team_id, $user_id );
        }

        $team = QueryHelpers::get_team( $team_id );

        // v3.92.1 — breadcrumb chain replaces the standalone back link.
        $teams_label = __( 'Teams', 'talenttrack' );
        if ( ! $team ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Team not found', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'teams', $teams_label ) ]
            );
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That team is no longer available.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            (string) $team->name,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'teams', $teams_label ) ]
        );

        // v3.110.53 — Edit + Archive page-header actions. Same pattern
        // as Player detail: Edit becomes a FAB on mobile, Archive
        // (danger) is desktop-only and routes to REST DELETE
        // teams/{id} via tt-frontend-archive-button.js.
        $actions  = [];
        $teams_url = add_query_arg( [ 'tt_view' => 'teams' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        if ( current_user_can( 'tt_edit_teams' ) ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'teams', 'id' => $team_id, 'action' => 'edit' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            );
            $actions[] = [
                'label'   => __( 'Edit', 'talenttrack' ),
                'href'    => $edit_url,
                'primary' => true,
                'icon'    => '✎',
            ];
            $actions[] = [
                'label'   => __( 'Archive', 'talenttrack' ),
                'variant' => 'danger',
                'data_attrs' => [
                    'tt-archive-rest-path' => 'teams/' . $team_id,
                    'tt-archive-confirm'   => __( 'Archive this team? It will be hidden but the data is preserved.', 'talenttrack' ),
                    'tt-archive-redirect'  => $teams_url,
                ],
            ];
        }
        // #1064 — team-batch print: one 3-page intake per active
        // roster player, concatenated into a single PDF when the
        // operator picks "Save as PDF" in the browser print dialog.
        if ( current_user_can( 'tt_edit_goals' ) ) {
            $intake_batch_url = add_query_arg(
                [ 'tt_goal_intake_print' => '1', 'team_id' => $team_id ],
                home_url( '/' )
            );
            $actions[] = [
                'label'  => __( 'Print seizoens-intakes', 'talenttrack' ),
                'href'   => $intake_batch_url,
                'target' => '_blank',
            ];
        }
        self::renderHeader( (string) $team->name, self::pageActionsHtml( $actions ) );

        $roster = QueryHelpers::get_players( $team_id );
        $trials = self::loadTrialPlayers( $team_id );
        $staff  = ( new PeopleRepository() )->getTeamStaff( $team_id );
        ?>
        <article class="tt-team-detail">
            <?php
            // v3.110.95 — header attributes (age group, level) as a
            // key/value table so the team page reads consistently with
            // the staff / roster / activities tables below. Was a
            // <dl class="tt-profile-dl"> definition list; operators
            // asked for tables across the whole page.
            $detail_rows = [];
            if ( ! empty( $team->age_group ) ) {
                $detail_rows[] = [ __( 'Age group', 'talenttrack' ), (string) $team->age_group ];
            }
            if ( ! empty( $team->level ) ) {
                $detail_rows[] = [ __( 'Level', 'talenttrack' ), (string) $team->level ];
            }
            if ( $detail_rows !== [] ) :
                ?>
                <section class="tt-pde-section">
                    <div class="tt-table-wrap">
                    <table class="tt-table tt-team-attrs-table">
                        <tbody>
                            <?php foreach ( $detail_rows as $row ) : ?>
                                <tr>
                                    <th scope="row" style="width:30%; text-align:left; font-weight:600;"><?php echo esc_html( (string) $row[0] ); ?></th>
                                    <td><?php echo esc_html( (string) $row[1] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php
            // #0063 — staff via tt_team_people, NOT the legacy
            // tt_teams.head_coach_id column the user explicitly flagged.
            self::renderStaff( $staff );
            self::renderRoster( $roster, $team_id );
            // #0077 M4 — surface trial players on the team page; they
            // were silently hidden behind the status='active' filter
            // in get_players. Coaches need to see who's currently on
            // a tryout with their team without jumping to /trials.
            self::renderTrialRoster( $trials );
            self::renderUpcomingActivities( $team_id );
            self::renderChemistryTeaser( $team_id );
            // #1088 VCT-13 — inline VCT training-defaults panel.
            // Settings sub-form per CLAUDE.md §3 exemption (a); drives
            // the new-VCT-session wizard's basis-step prefill.
            self::renderVctDefaultsPanel( $team_id, $vct_panel_notice );
            // v3.110.100 — team-scoped Analytics section removed from
            // the detail page, mirroring v3.110.99's activity-detail
            // change. Operator wants analytics from the central tile,
            // not per-team. The renderer + team-scoped KPIs stay on
            // disk so the central tile keeps consuming them.
            ?>
        </article>
        <?php
    }

    /**
     * Render head coach / assistant coach / team manager rows from
     * the staff-assignment pivot. Each name links to the person
     * detail surface.
     *
     * @param array<int, object> $staff
     */
    private static function renderStaff( array $staff ): void {
        if ( empty( $staff ) ) return;

        // v3.71.0 — `PeopleRepository::getTeamStaff()` returns rows
        // grouped by functional-role key (`['head_coach' => [...]]`),
        // each row a nested assoc array carrying the `person` object.
        // The previous loop iterated the outer array as if it were a
        // flat list of staff objects, so `$s->person_id` was always
        // unset and the section silently emitted empty `<li>`s — the
        // user's "staff is assigned but not showing up".
        $rows = [];
        foreach ( $staff as $role_key => $group ) {
            if ( ! is_array( $group ) ) continue;
            foreach ( $group as $entry ) {
                if ( ! is_array( $entry ) ) continue;
                $person = $entry['person'] ?? null;
                if ( ! is_object( $person ) ) continue;
                $rows[] = [
                    'person'   => $person,
                    'role_key' => (string) ( $entry['functional_role_key'] ?? $role_key ),
                ];
            }
        }
        if ( empty( $rows ) ) return;
        ?>
        <section class="tt-pde-section">
            <h3><?php esc_html_e( 'Staff', 'talenttrack' ); ?></h3>
            <div class="tt-table-wrap">
            <table class="tt-table tt-team-staff-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) :
                        $p = $row['person'];
                        $person_id = (int) ( $p->id ?? 0 );
                        $name      = trim( ( (string) ( $p->first_name ?? '' ) ) . ' ' . ( (string) ( $p->last_name ?? '' ) ) );
                        if ( $name === '' || $person_id <= 0 ) continue;
                        $role_key  = (string) $row['role_key'];
                        $role      = $role_key !== '' ? \TT\Infrastructure\Query\LabelTranslator::roleType( $role_key ) : '';
                        $url       = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $person_id );
                        ?>
                        <tr>
                            <td>
                                <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
                            </td>
                            <td><?php echo $role !== '' ? esc_html( $role ) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
        <?php
    }

    /**
     * Roster table with traffic-light column for player status. Each
     * row links to the player detail.
     *
     * @param array<int, object> $players
     */
    private static function renderRoster( array $players, int $team_id = 0 ): void {
        if ( empty( $players ) ) return;
        $can_status = class_exists( '\TT\Modules\Players\Frontend\PlayerStatusRenderer' );
        // v3.110.65 — load the player-status CSS. Without it, the
        // `<span class="tt-status-dot">` markup `PlayerStatusRenderer::dot()`
        // emits has no width / height / colour and the Status column
        // appears blank. The wp-admin Teams panel was already
        // enqueueing this; the frontend equivalent was not.
        if ( $can_status ) {
            \TT\Modules\Players\Frontend\PlayerStatusRenderer::enqueueStyles();
        }

        // v3.110.100 — sort the roster by jersey number ascending,
        // players without a jersey number drop to the end alphabetised
        // by last/first name. Operator request: positions were
        // optional and rarely filled so the column was always '—';
        // jersey numbers are the natural ordering on team sheets.
        // get_players() returns alpha-sorted by last_name; re-sort
        // locally so the change is scoped to this view (other callers
        // depend on the alpha default).
        usort( $players, static function ( $a, $b ): int {
            $an = isset( $a->jersey_number ) && (int) $a->jersey_number > 0 ? (int) $a->jersey_number : PHP_INT_MAX;
            $bn = isset( $b->jersey_number ) && (int) $b->jersey_number > 0 ? (int) $b->jersey_number : PHP_INT_MAX;
            if ( $an !== $bn ) return $an <=> $bn;
            $cmp = strcasecmp( (string) ( $a->last_name ?? '' ), (string) ( $b->last_name ?? '' ) );
            if ( $cmp !== 0 ) return $cmp;
            return strcasecmp( (string) ( $a->first_name ?? '' ), (string) ( $b->first_name ?? '' ) );
        } );
        ?>
        <section class="tt-pde-section">
            <h3><?php esc_html_e( 'Roster', 'talenttrack' ); ?></h3>
            <?php
            // #872 — bulk behaviour-rating entry point on the team
            // detail roster section. Cap-gated; users without
            // `tt_rate_player_behaviour` don't see the button.
            if ( $team_id > 0 && current_user_can( 'tt_rate_player_behaviour' ) ) :
                $bulk_url = add_query_arg(
                    [ 'tt_view' => 'team-behaviour-capture', 'team_id' => $team_id ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                );
                ?>
                <p style="margin: 0 0 12px;">
                    <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $bulk_url ); ?>">
                        <?php esc_html_e( 'Bulk-record behaviour', 'talenttrack' ); ?>
                    </a>
                </p>
                <?php
            endif;
            ?>
            <div class="tt-table-wrap">
            <table class="tt-table">
                <thead>
                    <tr>
                        <th style="width:80px;"><?php esc_html_e( 'Jersey #', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                        <?php if ( $can_status ) : ?>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $players as $pl ) :
                        $name = QueryHelpers::player_display_name( $pl );
                        $url  = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
                        $jersey = isset( $pl->jersey_number ) && (int) $pl->jersey_number > 0
                            ? (int) $pl->jersey_number
                            : null;
                        ?>
                        <tr>
                            <td><?php echo $jersey !== null ? (int) $jersey : '<span style="color:var(--tt-muted,#5f6368);">—</span>'; ?></td>
                            <td>
                                <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>">
                                    <?php echo esc_html( $name ); ?>
                                </a>
                            </td>
                            <?php if ( $can_status ) : ?>
                                <td><?php
                                    // Traffic-light dot via PlayerStatusCalculator +
                                    // PlayerStatusRenderer (#0057). Closes the
                                    // "where is player status displayed" complaint
                                    // by surfacing it on the team roster.
                                    if ( class_exists( '\TT\Infrastructure\PlayerStatus\PlayerStatusCalculator' ) ) {
                                        $verdict = ( new \TT\Infrastructure\PlayerStatus\PlayerStatusCalculator() )->calculate( (int) $pl->id );
                                        echo \TT\Modules\Players\Frontend\PlayerStatusRenderer::dot( (string) $verdict->color );
                                    }
                                ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
        <?php
    }

    /**
     * #0077 M4 — fetch trial players for the team. Status='trial' is
     * filtered out by QueryHelpers::get_players, so we run a small
     * dedicated query here. Demo-mode scope is applied so the panel
     * stays consistent with the rest of the page.
     *
     * @return array<int,object>
     */
    private static function loadTrialPlayers( int $team_id ): array {
        global $wpdb;
        $scope = QueryHelpers::apply_demo_scope( 'p', 'player' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p
              WHERE p.team_id = %d AND p.status = 'trial' AND p.club_id = %d {$scope}
              ORDER BY p.last_name, p.first_name ASC",
            $team_id,
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #0077 M4 — render trial-roster section.
     * v3.110.95 — converted from <ul> to a table matching the roster
     * shape (Name | Status pill) so the team page is consistent.
     */
    private static function renderTrialRoster( array $players ): void {
        if ( empty( $players ) ) return;
        echo '<section class="tt-pde-section">';
        echo '<h3>' . esc_html__( 'Trial players', 'talenttrack' ) . '</h3>';
        echo '<div class="tt-table-wrap">';
        echo '<table class="tt-table tt-team-trial-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $players as $pl ) {
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
            $name = QueryHelpers::player_display_name( $pl );
            echo '<tr>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></td>';
            echo '<td><span class="tt-pill" style="background:#fff3e0; color:#a86322; font-size:11px; padding:2px 8px; border-radius:999px;">' . esc_html__( 'Trial', 'talenttrack' ) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    private static function renderUpcomingActivities( int $team_id ): void {
        global $wpdb;
        // v3.110.65 — exclude activities the coach has already marked
        // Completed or Cancelled. The "upcoming" panel should be the
        // forward-looking schedule (planned activities from today
        // onwards), not a historical log. Filters on
        // `activity_status_key` (the user-facing lookup the coach
        // edits on the activities form) — same source-of-truth field
        // the team planner uses since v3.110.56. The legacy
        // `plan_state` column is ignored here for the same reason.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, start_time, end_time, activity_type_key, activity_status_key
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d
                AND ( archived_at IS NULL OR archived_at = '' )
                AND session_date >= CURDATE()
                AND activity_status_key NOT IN ('completed', 'cancelled')
              ORDER BY session_date ASC, start_time ASC LIMIT 5",
            $team_id
        ) );
        if ( empty( $rows ) ) return;

        // v3.110.95 — render the upcoming activities as a table so the
        // team page is consistent with the staff / roster / trial
        // tables. Adds Type + Status columns (read from the same
        // `activity_type_key` / `activity_status_key` lookup fields the
        // coach edits on the activity form).
        // #1098 — Activity volume preset (Explorer →).
        $activity_explore_url = \TT\Modules\Analytics\Domain\ExplorerUrl::build(
            'activity_volume',
            [ 'team_id' => (string) $team_id, 'date_after' => '-12 months' ],
            'month'
        );
        echo '<section class="tt-pde-section">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">';
        echo '<h3 style="margin:0;">' . esc_html__( 'Upcoming activities', 'talenttrack' ) . '</h3>';
        echo '<a href="' . esc_url( $activity_explore_url ) . '" style="background:transparent;border:1px solid var(--tt-line, #d6dadd);color:var(--tt-muted, #5b6e75);text-decoration:none;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;">'
            . esc_html__( 'Explorer →', 'talenttrack' )
            . '</a>';
        echo '</div>';
        echo '<div class="tt-table-wrap" style="margin-top:8px;">';
        echo '<table class="tt-table tt-team-activities-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Title', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            // v3.70.1 hotfix — use generic `activities` slug, not
            // `my-activities` (which is player-self-scope and gates
            // out academy admins / HoD opening from the team page).
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'activities', (int) $r->id );
            $type_label   = \TT\Infrastructure\Query\LabelTranslator::activityType( (string) ( $r->activity_type_key ?? '' ) );
            // Status lookup has no dedicated translator method — humanise
            // the key inline (`in_progress` → `In progress`). When the
            // lookup gains i18n coverage, swap this for a registry call.
            $status_key   = (string) ( $r->activity_status_key ?? '' );
            $status_label = $status_key !== '' ? ucfirst( str_replace( '_', ' ', $status_key ) ) : '';
            // #1126 — append the time window to the date cell when set.
            $st = (string) ( $r->start_time ?? '' );
            $et = (string) ( $r->end_time   ?? '' );
            $date_text = (string) $r->session_date;
            if ( $st !== '' ) {
                $date_text .= ' · ' . substr( $st, 0, 5 ) . ( $et !== '' ? '–' . substr( $et, 0, 5 ) : '' );
            }
            echo '<tr>';
            echo '<td>' . esc_html( $date_text ) . '</td>';
            echo '<td><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( (string) $r->title ) . '</a></td>';
            echo '<td>' . ( $type_label !== '' ? esc_html( $type_label ) : '—' ) . '</td>';
            echo '<td>' . ( $status_label !== '' ? esc_html( $status_label ) : '—' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    private static function renderChemistryTeaser( int $team_id ): void {
        if ( ! class_exists( '\TT\Modules\TeamDevelopment\Frontend\FrontendTeamChemistryView' ) ) return;
        $url = add_query_arg(
            [ 'tt_view' => 'team-chemistry', 'team_id' => $team_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );
        echo '<section class="tt-pde-section">';
        echo '<h3>' . esc_html__( 'Team chemistry', 'talenttrack' ) . '</h3>';
        echo '<p><a class="tt-btn tt-btn-secondary" href="' . esc_url( $url ) . '">';
        echo esc_html__( 'Open the chemistry board', 'talenttrack' );
        echo '</a></p>';
        echo '</section>';
    }

    // v3.110.100 — renderAnalyticsTeaser removed. The team-scoped
    // Analytics surface (#0083 Child 4) is no longer rendered on the
    // team detail page; coaches access analytics through the central
    // tile. EntityAnalyticsTabRenderer + the team-scoped KPIs in
    // KpiRegistry are unchanged — re-instate by adding a call back
    // to render() if the operator changes their mind.

    /**
     * #1088 VCT-13 — inline VCT defaults panel.
     *
     * Renders weekday chips + default start time + default duration for
     * the team's current-season schedule row. Drives the new-VCT-session
     * wizard's basis-step prefill (read by VctTeamSchedulesRepository::
     * findForTeamSeason).
     *
     * Settings sub-form per CLAUDE.md §3 exemption (a). Mockup
     * design-of-record at .local-mockups/vct-team-panel/.
     */
    private static function renderVctDefaultsPanel( int $team_id, string $notice_html ): void {
        if ( ! \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_vct_admin_library' ) ) {
            return;
        }
        $season = ( new \TT\Modules\Pdp\Repositories\SeasonsRepository() )->current();
        if ( ! $season ) {
            return;
        }
        $season_id = (int) $season->id;

        $existing = ( new \TT\Modules\Vct\Repositories\VctTeamSchedulesRepository() )->findForTeamSeason( $team_id, $season_id );
        $bitmask  = $existing !== null ? (int) $existing['weekdays_bitmask'] : 0;
        $start    = $existing !== null && $existing['default_start_time'] !== null
            ? substr( (string) $existing['default_start_time'], 0, 5 )
            : '';
        $duration = $existing !== null && $existing['default_duration_minutes'] !== null
            ? (int) $existing['default_duration_minutes']
            : 0;

        $cancel_url = add_query_arg(
            [ 'tt_view' => 'teams', 'id' => $team_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );

        $weekdays = [
            1  => __( 'Mon', 'talenttrack' ),
            2  => __( 'Tue', 'talenttrack' ),
            4  => __( 'Wed', 'talenttrack' ),
            8  => __( 'Thu', 'talenttrack' ),
            16 => __( 'Fri', 'talenttrack' ),
            32 => __( 'Sat', 'talenttrack' ),
            64 => __( 'Sun', 'talenttrack' ),
        ];
        ?>
        <section class="tt-pde-section tt-vct-team-panel">
            <h3><?php esc_html_e( 'VCT — Training defaults', 'talenttrack' ); ?></h3>
            <p class="tt-vct-team-panel__sub"><?php esc_html_e( 'Smart prefills for new VCT sessions of this team.', 'talenttrack' ); ?></p>
            <?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — emitted by handleVctDefaultsPost() with controlled markup. ?>
            <form class="tt-vct-team-panel__form" method="POST" action="">
                <?php wp_nonce_field( 'tt_vct_team_panel_' . $team_id . '_' . $season_id, '_tt_vct_team_panel_nonce' ); ?>
                <input type="hidden" name="_tt_vct_team_panel" value="1">
                <input type="hidden" name="season_id" value="<?php echo (int) $season_id; ?>">

                <div class="tt-field">
                    <span class="tt-field-label"><?php esc_html_e( 'Training days', 'talenttrack' ); ?></span>
                    <div class="tt-vct-dow-row" role="group" aria-label="<?php esc_attr_e( 'Training days', 'talenttrack' ); ?>">
                        <?php foreach ( $weekdays as $bit => $label ) :
                            $checked = ( $bitmask & $bit ) === $bit;
                            ?>
                            <label class="tt-vct-dow-chip<?php echo $checked ? ' is-selected' : ''; ?>">
                                <input type="checkbox" name="weekday_bits[]" value="<?php echo (int) $bit; ?>"<?php checked( $checked ); ?>>
                                <span><?php echo esc_html( $label ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="tt-field-hint"><?php esc_html_e( 'The wizard defaults to the next selected weekday.', 'talenttrack' ); ?></p>
                </div>

                <div class="tt-vct-team-panel__row">
                    <label class="tt-field">
                        <span class="tt-field-label"><?php esc_html_e( 'Default start time', 'talenttrack' ); ?></span>
                        <input type="time" class="tt-input" name="default_start_time" value="<?php echo esc_attr( $start ); ?>">
                    </label>
                    <label class="tt-field">
                        <span class="tt-field-label"><?php esc_html_e( 'Default duration (min)', 'talenttrack' ); ?></span>
                        <input type="number" class="tt-input" name="default_duration_minutes" min="30" max="180" step="5" inputmode="numeric" value="<?php echo $duration > 0 ? (int) $duration : ''; ?>">
                    </label>
                </div>

                <?php
                echo \TT\Shared\Frontend\Components\FormSaveButton::render( [
                    'label'      => __( 'Save VCT defaults', 'talenttrack' ),
                    'cancel_url' => $cancel_url,
                ] );
                ?>
            </form>
        </section>
        <style>
        .tt-vct-team-panel { margin-top: 16px; }
        .tt-vct-team-panel__sub { margin: 0 0 12px; color: var(--tt-muted, #5b6e75); font-size: 13px; }
        .tt-vct-team-panel__row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 480px) { .tt-vct-team-panel__row { grid-template-columns: 1fr; } }
        .tt-vct-dow-row { display: flex; gap: 6px; flex-wrap: wrap; }
        .tt-vct-dow-chip { min-height: 48px; min-width: 48px; padding: 0 14px; display: inline-flex; align-items: center; justify-content: center; border: 1.5px solid var(--tt-line, #d6dadd); border-radius: 8px; background: #fff; font-weight: 600; font-size: 14px; color: var(--tt-muted, #5b6e75); cursor: pointer; touch-action: manipulation; }
        .tt-vct-dow-chip input { position: absolute; opacity: 0; pointer-events: none; }
        .tt-vct-dow-chip.is-selected, .tt-vct-dow-chip:has(input:checked) { border-color: #1d7874; background: #e3eeed; color: #1d7874; box-shadow: 0 0 0 3px rgba(29,120,116,0.15); }
        .tt-vct-dow-chip:focus-within { outline: 2px solid #1d7874; outline-offset: 2px; }
        </style>
        <script>
        (function(){
            // Toggle the .is-selected class on day chips for browsers that
            // don't support :has(). Keeps the visual feedback in sync with
            // checkbox state without depending on selector support.
            document.querySelectorAll('.tt-vct-dow-chip input[type="checkbox"]').forEach(function(cb){
                cb.addEventListener('change', function(){
                    cb.parentElement.classList.toggle('is-selected', cb.checked);
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * #1088 VCT-13 — POST handler for the inline VCT defaults panel.
     * Returns a notice HTML fragment (empty string on no-op) that the
     * caller injects above the form.
     */
    private static function handleVctDefaultsPost( int $team_id, int $user_id ): string {
        if ( ! \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_admin_library' ) ) {
            return '<div class="tt-notice tt-notice--error">' . esc_html__( 'You do not have permission to edit VCT defaults.', 'talenttrack' ) . '</div>';
        }
        $season_id = isset( $_POST['season_id'] ) ? absint( $_POST['season_id'] ) : 0;
        if ( $season_id <= 0 ) {
            return '';
        }
        $nonce = isset( $_POST['_tt_vct_team_panel_nonce'] ) ? (string) $_POST['_tt_vct_team_panel_nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'tt_vct_team_panel_' . $team_id . '_' . $season_id ) ) {
            return '<div class="tt-notice tt-notice--error">' . esc_html__( 'Save failed: session expired. Reload and try again.', 'talenttrack' ) . '</div>';
        }
        $bits = 0;
        foreach ( (array) ( $_POST['weekday_bits'] ?? [] ) as $b ) {
            $bits |= (int) $b;
        }
        $bits &= 0x7F; // 7 bits (Mon..Sun)
        $start = isset( $_POST['default_start_time'] ) ? trim( (string) $_POST['default_start_time'] ) : '';
        if ( $start !== '' && ! preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $start ) ) {
            return '<div class="tt-notice tt-notice--error">' . esc_html__( 'Save failed: start time is not valid.', 'talenttrack' ) . '</div>';
        }
        $duration_raw = isset( $_POST['default_duration_minutes'] ) ? (int) $_POST['default_duration_minutes'] : 0;
        $duration = $duration_raw > 0 ? max( 30, min( 180, $duration_raw ) ) : 0;
        $ok = ( new \TT\Modules\Vct\Repositories\VctTeamSchedulesRepository() )->upsert(
            $team_id,
            $season_id,
            $bits,
            $start !== '' ? $start : null,
            $duration > 0 ? $duration : null,
            $user_id
        );
        return $ok
            ? '<div class="tt-notice tt-notice--success">' . esc_html__( 'VCT defaults saved.', 'talenttrack' ) . '</div>'
            : '<div class="tt-notice tt-notice--error">' . esc_html__( 'Save failed: database error.', 'talenttrack' ) . '</div>';
    }
}
