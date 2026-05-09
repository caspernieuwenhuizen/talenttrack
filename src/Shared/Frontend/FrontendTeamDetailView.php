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
        self::renderHeader( (string) $team->name, self::pageActionsHtml( $actions ) );

        $roster = QueryHelpers::get_players( $team_id );
        $trials = self::loadTrialPlayers( $team_id );
        $staff  = ( new PeopleRepository() )->getTeamStaff( $team_id );
        ?>
        <article class="tt-team-detail">
            <dl class="tt-profile-dl">
                <?php if ( ! empty( $team->age_group ) ) : ?>
                    <dt><?php esc_html_e( 'Age group', 'talenttrack' ); ?></dt>
                    <dd><?php echo esc_html( (string) $team->age_group ); ?></dd>
                <?php endif; ?>
                <?php if ( ! empty( $team->level ) ) : ?>
                    <dt><?php esc_html_e( 'Level', 'talenttrack' ); ?></dt>
                    <dd><?php echo esc_html( (string) $team->level ); ?></dd>
                <?php endif; ?>
            </dl>

            <?php
            // #0063 — staff via tt_team_people, NOT the legacy
            // tt_teams.head_coach_id column the user explicitly flagged.
            self::renderStaff( $staff );
            self::renderRoster( $roster );
            // #0077 M4 — surface trial players on the team page; they
            // were silently hidden behind the status='active' filter
            // in get_players. Coaches need to see who's currently on
            // a tryout with their team without jumping to /trials.
            self::renderTrialRoster( $trials );
            self::renderUpcomingActivities( $team_id );
            self::renderChemistryTeaser( $team_id );
            self::renderAnalyticsTeaser( $team_id );
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
            <ul class="tt-stack">
                <?php foreach ( $rows as $row ) :
                    $p = $row['person'];
                    $person_id = (int) ( $p->id ?? 0 );
                    $name      = trim( ( (string) ( $p->first_name ?? '' ) ) . ' ' . ( (string) ( $p->last_name ?? '' ) ) );
                    if ( $name === '' || $person_id <= 0 ) continue;
                    $role_key  = (string) $row['role_key'];
                    $role      = $role_key !== '' ? \TT\Infrastructure\Query\LabelTranslator::roleType( $role_key ) : '';
                    $url       = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $person_id );
                    ?>
                    <li>
                        <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
                        <?php if ( $role !== '' ) : ?>
                            <span class="tt-muted"> &middot; <?php echo esc_html( $role ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    /**
     * Roster table with traffic-light column for player status. Each
     * row links to the player detail.
     *
     * @param array<int, object> $players
     */
    private static function renderRoster( array $players ): void {
        if ( empty( $players ) ) return;
        $can_status = class_exists( '\TT\Modules\Players\Frontend\PlayerStatusRenderer' );
        ?>
        <section class="tt-pde-section">
            <h3><?php esc_html_e( 'Roster', 'talenttrack' ); ?></h3>
            <table class="tt-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Position', 'talenttrack' ); ?></th>
                        <?php if ( $can_status ) : ?>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $players as $pl ) :
                        $name = QueryHelpers::player_display_name( $pl );
                        $url  = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
                        $positions = json_decode( (string) ( $pl->preferred_positions ?? '' ), true );
                        ?>
                        <tr>
                            <td>
                                <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>">
                                    <?php echo esc_html( $name ); ?>
                                </a>
                            </td>
                            <td><?php echo is_array( $positions ) ? esc_html( implode( ', ', array_map( 'strval', $positions ) ) ) : '—'; ?></td>
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

    /** #0077 M4 — render trial-roster section. */
    private static function renderTrialRoster( array $players ): void {
        if ( empty( $players ) ) return;
        echo '<section class="tt-pde-section">';
        echo '<h3>' . esc_html__( 'Trial players', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-stack">';
        foreach ( $players as $pl ) {
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
            $name = QueryHelpers::player_display_name( $pl );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
            echo ' <span class="tt-pill" style="background:#fff3e0; color:#a86322; font-size:11px; padding:2px 8px; border-radius:999px; margin-left:6px;">' . esc_html__( 'Trial', 'talenttrack' ) . '</span>';
            echo '</li>';
        }
        echo '</ul></section>';
    }

    private static function renderUpcomingActivities( int $team_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d
                AND ( archived_at IS NULL OR archived_at = '' )
                AND session_date >= CURDATE()
              ORDER BY session_date ASC LIMIT 5",
            $team_id
        ) );
        if ( empty( $rows ) ) return;

        echo '<section class="tt-pde-section">';
        echo '<h3>' . esc_html__( 'Upcoming activities', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-stack">';
        foreach ( $rows as $r ) {
            // v3.70.1 hotfix — use generic `activities` slug, not
            // `my-activities` (which is player-self-scope and gates
            // out academy admins / HoD opening from the team page).
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'activities', (int) $r->id );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo esc_html( (string) $r->session_date ) . ' &middot; ' . esc_html( (string) $r->title );
            echo '</a></li>';
        }
        echo '</ul>';
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

    /**
     * #0083 Child 4 follow-up — team-scoped Analytics surface. Renders
     * the KPI grid via `EntityAnalyticsTabRenderer`. Defensive against
     * module-disable: when the Analytics module is off, the section
     * disappears entirely rather than rendering an error.
     */
    private static function renderAnalyticsTeaser( int $team_id ): void {
        if ( ! class_exists( '\\TT\\Modules\\Analytics\\Frontend\\EntityAnalyticsTabRenderer' ) ) return;
        echo '<section class="tt-team-analytics" style="margin-top:24px;">';
        echo '<h3>' . esc_html__( 'Analytics', 'talenttrack' ) . '</h3>';
        \TT\Modules\Analytics\Frontend\EntityAnalyticsTabRenderer::render( 'team', $team_id );
        echo '</section>';
    }
}
