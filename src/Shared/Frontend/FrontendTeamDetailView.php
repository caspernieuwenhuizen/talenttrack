<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Teams\TeamDetailSections;
use TT\Infrastructure\Teams\TeamKpisRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendTeamDetailView — the team's working surface at
 * `?tt_view=teams&id=N` (#0063, redesigned in #1613).
 *
 * The layout mirrors the player profile (FrontendPlayerDetailView /
 * frontend-player-detail.css): a paper hero carrying the team identity,
 * a key-facts strip, an at-a-glance KPI strip, then the content (Roster,
 * Staff, Team info, Upcoming activities, Trial roster) in
 * `tt-player-card`-style panels. Every table row is a whole-row link via
 * the shared `is-row-link` handler (tt-table-tools.js).
 *
 * The head coach can hide sections they don't want, saved as a per-user
 * preference (TeamDetailSections → user meta, read back via
 * `GET/PUT /me/preferences/team-detail`). The hero is always shown;
 * everything else is toggleable. Non-coaches — and coaches who haven't
 * customised — see the default, every section on.
 *
 * Cap-gated on `tt_view_teams`. Composition only: all data comes from
 * repositories / QueryHelpers, all visibility/KPI logic lives outside
 * this file (CLAUDE.md §4).
 */
final class FrontendTeamDetailView extends FrontendViewBase {

    private static bool $detail_css_enqueued = false;

    private static function enqueueDetailAssets(): void {
        if ( self::$detail_css_enqueued ) return;
        // Reuse the player-detail card system + tokens (1:1 shapes).
        wp_enqueue_style(
            'tt-frontend-player-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-player-detail.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-team-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-team-detail.css',
            [ 'tt-frontend-player-detail', 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        self::$detail_css_enqueued = true;
    }

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

        $teams_label = __( 'Teams', 'talenttrack' );
        if ( ! $team ) {
            // #2022 — retry through the archive-aware gate so an archived /
            // trashed team renders read-only instead of "does not exist". A
            // null return stays a clean 404 (honours the trashed-visibility
            // gate inside the domain method).
            $resolved = \TT\Shared\Frontend\Components\ArchivedDetailCard::resolve( 'team', $team_id );
            if ( $resolved !== null && $resolved['state'] !== 'active' ) {
                self::renderArchivedReadOnly( $resolved, $teams_label );
                return;
            }
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Team not found', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'teams', $teams_label ) ]
            );
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That team is no longer available.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueDetailAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            (string) $team->name,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'teams', $teams_label ) ]
        );

        // Per-user section visibility. The hero is always shown.
        $sections = TeamDetailSections::forUser( $user_id );
        // The customize control is for coaches who manage this team.
        $can_customize = AuthorizationService::canManageTeam( $user_id, $team_id );

        $roster = QueryHelpers::get_players( $team_id );
        $trials = self::loadTrialPlayers( $team_id );
        $staff  = ( new PeopleRepository() )->getTeamStaff( $team_id );
        ?>
        <article class="tt-team-detail tt-player-detail">
            <?php
            self::renderHero( $team, $roster, $staff );
            self::renderActionRow( $team, $can_customize );

            if ( $can_customize ) {
                self::enqueueCustomizeAssets( $team_id );
                self::renderCustomizePanel( $sections );
            }
            ?>

            <div class="tt-player-detail__rail">
                <?php
                if ( $sections['key_facts'] ) {
                    self::renderKeyFacts( $team, $roster, $staff );
                }
                if ( $sections['kpis'] ) {
                    self::renderKpis( $team_id );
                }
                ?>
            </div>

            <div class="tt-player-detail__main">
                <?php
                if ( $sections['roster'] ) {
                    self::renderRoster( $roster, $team_id );
                }
                if ( $sections['staff'] ) {
                    self::renderStaff( $staff );
                }
                if ( $sections['team_info'] ) {
                    self::renderTeamInfo( $team );
                }
                if ( $sections['trial_roster'] ) {
                    self::renderTrialRoster( $trials );
                }
                if ( $sections['upcoming_activities'] ) {
                    self::renderUpcomingActivities( $team_id );
                }
                // Team chemistry teaser stays on — a link card, not a
                // toggleable content section.
                self::renderChemistryTeaser( $team_id );
                // #1088 VCT-13 — inline VCT training-defaults panel
                // (settings sub-form per CLAUDE.md §3 exemption (a)).
                self::renderVctDefaultsPanel( $team_id, $vct_panel_notice );
                ?>
            </div>
        </article>
        <?php
    }

    /**
     * #2022 — compact read-only surface for an archived / trashed team.
     * Reached only via the not-found retry in render().
     *
     * @param array{row:object,state:string} $resolved
     */
    private static function renderArchivedReadOnly( array $resolved, string $teams_label ): void {
        $team = $resolved['row'];
        $name = (string) $team->name;

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            $name,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'teams', $teams_label ) ]
        );
        \TT\Shared\Frontend\Components\ArchivedDetailCard::enqueue();

        $teams_url = add_query_arg( [ 'tt_view' => 'teams' ], RecordLink::dashboardUrl() );
        $self_url  = add_query_arg( [ 'tt_view' => 'teams', 'id' => (int) $team->id ], RecordLink::dashboardUrl() );

        $fields = [];
        if ( ! empty( $team->age_group ) ) {
            $fields[] = [
                __( 'Age group', 'talenttrack' ),
                esc_html( \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $team->age_group ) ),
            ];
        }

        \TT\Shared\Frontend\Components\ArchivedDetailCard::render( 'team', $resolved, [
            'title'            => $name,
            'initials'         => self::crestFor( $name ),
            'fields'           => $fields,
            'list_url'         => $teams_url,
            'restore_redirect' => $self_url,
        ] );
    }

    /**
     * Paper hero with the team crest (initials in an accent chip,
     * status-coloured border), name, "Teams · age group · season"
     * sub-line, and identity pills.
     *
     * @param array<int,object> $roster
     * @param array<string,mixed> $staff
     */
    private static function renderHero( object $team, array $roster, array $staff ): void {
        $name      = (string) $team->name;
        $age_group = (string) ( $team->age_group ?? '' );
        $age_label = $age_group !== ''
            ? \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', $age_group )
            : '';
        $status    = $team->archived_at ? 'archived' : 'active';
        $teams_url = add_query_arg( [ 'tt_view' => 'teams' ], RecordLink::dashboardUrl() );
        $player_n  = count( $roster );
        ?>
        <header class="tt-player-detail__hero tt-team-hero" aria-label="<?php esc_attr_e( 'Team', 'talenttrack' ); ?>">
            <div class="tt-player-hero__row">
                <div class="tt-player-hero__avatar tt-team-hero__crest" data-status="<?php echo esc_attr( $status ); ?>" aria-hidden="true">
                    <?php echo esc_html( self::crestFor( $name ) ); ?>
                </div>
                <div class="tt-player-hero__main">
                    <h1 class="tt-player-hero__name"><?php echo esc_html( $name ); ?></h1>
                    <p class="tt-player-hero__sub">
                        <a href="<?php echo esc_url( $teams_url ); ?>"><?php esc_html_e( 'Teams', 'talenttrack' ); ?></a>
                        <?php if ( $age_label !== '' ) : ?>
                            <span> · <?php echo esc_html( $age_label ); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="tt-player-hero__pills">
                        <span class="tt-player-pill" data-status="<?php echo esc_attr( $status ); ?>">
                            <?php echo $status === 'archived'
                                ? esc_html__( 'Archived', 'talenttrack' )
                                : esc_html__( 'Active', 'talenttrack' ); ?>
                        </span>
                        <?php if ( $age_label !== '' ) : ?>
                            <span class="tt-player-pill tt-player-pill--pos"><?php echo esc_html( $age_label ); ?></span>
                        <?php endif; ?>
                        <span class="tt-player-pill">
                            <?php
                            /* translators: %d: number of players on the roster */
                            echo esc_html( sprintf( _n( '%d player', '%d players', $player_n, 'talenttrack' ), $player_n ) );
                            ?>
                        </span>
                    </p>
                </div>
            </div>
        </header>
        <?php
    }

    /**
     * Action row — New activity · Edit · Planner · Archive. Cap-gated
     * identically to the legacy page-header actions.
     */
    private static function renderActionRow( object $team, bool $can_customize ): void {
        $team_id   = (int) $team->id;
        $teams_url = add_query_arg( [ 'tt_view' => 'teams' ], RecordLink::dashboardUrl() );
        $can_edit  = current_user_can( 'tt_edit_teams' );
        ?>
        <div class="tt-player-detail__actions" aria-label="<?php esc_attr_e( 'Actions', 'talenttrack' ); ?>">
            <?php if ( current_user_can( 'tt_edit_activities' ) ) :
                $new_activity_url = add_query_arg(
                    [ 'tt_view' => 'activities', 'action' => 'new', 'team_id' => $team_id ],
                    RecordLink::dashboardUrl()
                );
                ?>
                <a class="tt-player-action tt-player-action--primary" href="<?php echo esc_url( $new_activity_url ); ?>">
                    + <?php esc_html_e( 'New activity', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>

            <?php
            $planner_url = add_query_arg(
                [ 'tt_view' => 'team-planner', 'team_id' => $team_id ],
                RecordLink::dashboardUrl()
            );
            ?>
            <a class="tt-player-action" href="<?php echo esc_url( $planner_url ); ?>">
                <?php esc_html_e( 'Planner', 'talenttrack' ); ?>
            </a>

            <?php if ( $can_edit ) :
                $edit_url = add_query_arg(
                    [ 'tt_view' => 'teams', 'id' => $team_id, 'action' => 'edit' ],
                    RecordLink::dashboardUrl()
                );
                ?>
                <a class="tt-player-action" href="<?php echo esc_url( $edit_url ); ?>">
                    <?php esc_html_e( 'Edit', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>

            <?php if ( current_user_can( 'tt_edit_goals' ) ) :
                // #1064 — team-batch print: one 3-page intake per active
                // roster player, concatenated into a single PDF.
                $intake_batch_url = add_query_arg(
                    [ 'tt_goal_intake_print' => '1', 'team_id' => $team_id ],
                    home_url( '/' )
                );
                ?>
                <a class="tt-player-action" href="<?php echo esc_url( $intake_batch_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Print seizoens-intakes', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>

            <?php if ( $can_customize ) : ?>
                <button type="button" class="tt-player-action tt-team-customize-trigger" data-tt-team-customize-trigger="1" aria-expanded="false" aria-controls="tt-team-customize-panel">
                    <?php esc_html_e( 'Customize', 'talenttrack' ); ?>
                </button>
            <?php endif; ?>

            <?php if ( $can_edit ) : ?>
                <div class="tt-player-action tt-player-action--more"
                     role="button"
                     tabindex="0"
                     aria-haspopup="true"
                     aria-expanded="false"
                     aria-label="<?php esc_attr_e( 'More actions', 'talenttrack' ); ?>"
                     onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');}">
                    ⋯
                    <div class="tt-player-action__menu" role="menu">
                        <button type="button"
                                class="tt-player-action tt-player-action--danger"
                                role="menuitem"
                                data-tt-archive-rest-path="<?php echo esc_attr( 'teams/' . $team_id ); ?>"
                                data-tt-archive-confirm="<?php echo esc_attr__( 'Archive this team? It will be hidden but the data is preserved.', 'talenttrack' ); ?>"
                                data-tt-archive-redirect="<?php echo esc_attr( $teams_url ); ?>">
                            <?php esc_html_e( 'Archive', 'talenttrack' ); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Customize panel — per-coach section visibility toggles. Settings
     * sub-form per CLAUDE.md §3 exemption (a) / §6 exemption (a): it's a
     * preference panel, not record creation, so Save-only is fine. The
     * toggles persist via PUT /me/preferences/team-detail
     * (frontend-team-detail-customize.js).
     *
     * @param array<string,bool> $sections
     */
    private static function renderCustomizePanel( array $sections ): void {
        $labels = TeamDetailSections::labels();
        ?>
        <section id="tt-team-customize-panel" class="tt-team-customize" hidden aria-label="<?php esc_attr_e( 'Customize sections', 'talenttrack' ); ?>">
            <h3 class="tt-team-customize__title"><?php esc_html_e( 'Show on this page', 'talenttrack' ); ?></h3>
            <p class="tt-team-customize__sub"><?php esc_html_e( 'Personal to you, across every team you coach. The team header always shows.', 'talenttrack' ); ?></p>
            <div class="tt-team-customize__grid" data-tt-team-customize-form="1">
                <?php foreach ( TeamDetailSections::SECTIONS as $key ) :
                    $on    = ! empty( $sections[ $key ] );
                    $label = (string) ( $labels[ $key ] ?? $key );
                    ?>
                    <label class="tt-team-customize__row">
                        <input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1"<?php checked( $on ); ?> data-tt-team-section="<?php echo esc_attr( $key ); ?>">
                        <span><?php echo esc_html( $label ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="tt-team-customize__foot">
                <button type="button" class="tt-btn tt-btn-primary" data-tt-team-customize-save="1">
                    <?php esc_html_e( 'Save layout', 'talenttrack' ); ?>
                </button>
                <span class="tt-team-customize__status" data-tt-team-customize-status="1" role="status" aria-live="polite"></span>
            </div>
        </section>
        <?php
    }

    private static function enqueueCustomizeAssets( int $team_id ): void {
        wp_enqueue_script(
            'tt-frontend-team-detail-customize',
            TT_PLUGIN_URL . 'assets/js/frontend-team-detail-customize.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-team-detail-customize', 'TTTeamDetailCustomize', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/me/preferences/team-detail' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'       => [
                'saving'  => __( 'Saving…', 'talenttrack' ),
                'saved'   => __( 'Saved. Reloading…', 'talenttrack' ),
                'error'   => __( 'Could not save. Try again.', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * Key-facts strip — Age group · Head coach · Players.
     *
     * @param array<int,object> $roster
     * @param array<string,mixed> $staff
     */
    private static function renderKeyFacts( object $team, array $roster, array $staff ): void {
        $age_group = (string) ( $team->age_group ?? '' );
        $age_value = $age_group !== ''
            ? \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', $age_group )
            : '—';

        $hc_name = self::headCoachName( $staff );
        $player_n = count( $roster );
        ?>
        <section class="tt-player-facts" aria-label="<?php esc_attr_e( 'Key facts', 'talenttrack' ); ?>">
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Age group', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( $age_value ); ?></p>
            </div>
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Head coach', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo $hc_name !== '' ? esc_html( $hc_name ) : '—'; ?></p>
            </div>
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Players', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo (int) $player_n; ?></p>
            </div>
        </section>
        <?php
    }

    /**
     * At-a-glance KPI strip — Upcoming (14 d) · Avg attendance (30 d) ·
     * Avg squad rating. Numbers come from TeamKpisRepository; the view
     * only shapes them.
     */
    private static function renderKpis( int $team_id ): void {
        $repo       = new TeamKpisRepository();
        $upcoming   = $repo->upcomingCount( $team_id, 14 );
        $attendance = $repo->avgAttendance( $team_id, 30 );
        $rating     = $repo->avgSquadRating( $team_id );

        $rmax = (int) round( (float) QueryHelpers::get_config( 'rating_max', '10' ) );

        $planner_url = add_query_arg(
            [ 'tt_view' => 'team-planner', 'team_id' => $team_id ],
            RecordLink::dashboardUrl()
        );
        ?>
        <section class="tt-player-glance" aria-label="<?php esc_attr_e( 'At a glance', 'talenttrack' ); ?>">
            <p class="tt-player-glance__title"><?php esc_html_e( 'At a glance', 'talenttrack' ); ?></p>
            <div class="tt-player-glance__grid">
                <a class="tt-player-kpi" href="<?php echo esc_url( $planner_url ); ?>">
                    <div class="tt-player-kpi__label"><?php esc_html_e( 'Upcoming', 'talenttrack' ); ?></div>
                    <div class="tt-player-kpi__num"><?php echo (int) $upcoming; ?></div>
                    <div class="tt-player-kpi__hint"><?php esc_html_e( 'next 14 d', 'talenttrack' ); ?></div>
                </a>
                <div class="tt-player-kpi">
                    <div class="tt-player-kpi__label"><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></div>
                    <div class="tt-player-kpi__num">
                        <?php echo $attendance !== null ? (int) $attendance : '—'; ?><?php if ( $attendance !== null ) : ?><small>%</small><?php endif; ?>
                    </div>
                    <div class="tt-player-kpi__hint"><?php esc_html_e( 'last 30 d', 'talenttrack' ); ?></div>
                </div>
                <div class="tt-player-kpi">
                    <div class="tt-player-kpi__label"><?php esc_html_e( 'Squad rating', 'talenttrack' ); ?></div>
                    <div class="tt-player-kpi__num">
                        <?php
                        echo $rating !== null ? esc_html( number_format_i18n( $rating, 1 ) ) : '—';
                        if ( $rating !== null && $rmax > 0 ) :
                            ?><small>/<?php echo (int) $rmax; ?></small><?php
                        endif;
                        ?>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * Roster card. Each row links to the player detail; the whole row is
     * a click target via is-row-link (tt-table-tools.js).
     *
     * @param array<int, object> $players
     */
    private static function renderRoster( array $players, int $team_id = 0 ): void {
        $can_status = \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\Players\PlayerStatusModule::class );
        if ( $can_status ) {
            \TT\Modules\Players\Frontend\PlayerStatusRenderer::enqueueStyles();
        }

        // Sort by jersey number ascending; un-numbered players last,
        // alphabetised.
        usort( $players, static function ( $a, $b ): int {
            $an = isset( $a->jersey_number ) && (int) $a->jersey_number > 0 ? (int) $a->jersey_number : PHP_INT_MAX;
            $bn = isset( $b->jersey_number ) && (int) $b->jersey_number > 0 ? (int) $b->jersey_number : PHP_INT_MAX;
            if ( $an !== $bn ) return $an <=> $bn;
            $cmp = strcasecmp( (string) ( $a->last_name ?? '' ), (string) ( $b->last_name ?? '' ) );
            if ( $cmp !== 0 ) return $cmp;
            return strcasecmp( (string) ( $a->first_name ?? '' ), (string) ( $b->first_name ?? '' ) );
        } );

        $bulk_url = '';
        if ( $team_id > 0 && current_user_can( 'tt_rate_player_behaviour' ) ) {
            $bulk_url = add_query_arg(
                [ 'tt_view' => 'team-behaviour-capture', 'team_id' => $team_id ],
                RecordLink::dashboardUrl()
            );
        }

        $title = sprintf(
            /* translators: %d: number of players on the roster */
            __( 'Roster · %d', 'talenttrack' ),
            count( $players )
        );
        self::cardOpen( $title, $bulk_url !== '' ? [
            'href'  => $bulk_url,
            'label' => __( 'Bulk-record behaviour', 'talenttrack' ),
        ] : null );

        if ( empty( $players ) ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'No players on this roster yet.', 'talenttrack' ) . '</p>';
            self::cardClose();
            return;
        }
        ?>
        <div class="tt-table-wrap">
        <table class="tt-table tt-team-roster-table">
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
                    $url  = RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
                    $jersey = isset( $pl->jersey_number ) && (int) $pl->jersey_number > 0
                        ? (int) $pl->jersey_number
                        : null;
                    ?>
                    <tr class="is-row-link" data-row-href="<?php echo esc_url( $url ); ?>">
                        <td><?php echo $jersey !== null ? (int) $jersey : '<span style="color:var(--tt-muted,#5f6368);">—</span>'; ?></td>
                        <td>
                            <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>">
                                <?php echo esc_html( $name ); ?>
                            </a>
                        </td>
                        <?php if ( $can_status ) : ?>
                            <td><?php
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
        <?php
        self::cardClose();
    }

    /**
     * Staff card. Each name links to the person detail; whole-row link.
     *
     * @param array<int, object> $staff
     */
    private static function renderStaff( array $staff ): void {
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

        self::cardOpen( __( 'Staff', 'talenttrack' ) );
        ?>
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
                    $url       = RecordLink::detailUrlForWithBack( 'people', $person_id );
                    ?>
                    <tr class="is-row-link" data-row-href="<?php echo esc_url( $url ); ?>">
                        <td>
                            <a class="tt-record-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
                        </td>
                        <td><?php echo $role !== '' ? esc_html( $role ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        self::cardClose();
    }

    /**
     * Team info card — age group / level as a key/value list.
     */
    private static function renderTeamInfo( object $team ): void {
        $rows = [];
        if ( ! empty( $team->age_group ) ) {
            $rows[] = [
                __( 'Age group', 'talenttrack' ),
                \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $team->age_group ),
            ];
        }
        if ( ! empty( $team->level ) ) {
            $rows[] = [ __( 'Level', 'talenttrack' ), (string) $team->level ];
        }
        if ( ! empty( $team->notes ) ) {
            $rows[] = [ __( 'Notes', 'talenttrack' ), (string) $team->notes ];
        }
        if ( $rows === [] ) return;

        self::cardOpen( __( 'Team info', 'talenttrack' ) );
        foreach ( $rows as $row ) {
            echo '<div class="tt-player-kv">';
            echo '<div class="tt-player-kv__k">' . esc_html( (string) $row[0] ) . '</div>';
            echo '<div class="tt-player-kv__v">' . esc_html( (string) $row[1] ) . '</div>';
            echo '</div>';
        }
        self::cardClose();
    }

    /**
     * #0077 M4 — fetch trial players for the team. Status='trial' is
     * filtered out by QueryHelpers::get_players, so we run a small
     * dedicated query here.
     *
     * @return array<int,object>
     */
    private static function loadTrialPlayers( int $team_id ): array {
        global $wpdb;
        $scope     = QueryHelpers::apply_demo_scope( 'p', 'player' );
        $lifecycle = \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active', 'p' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p
              WHERE p.team_id = %d AND p.status = 'trial' AND p.club_id = %d AND {$lifecycle} {$scope}
              ORDER BY p.last_name, p.first_name ASC",
            $team_id,
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #0077 M4 — trial-roster card. Each row links to the player detail;
     * whole-row link.
     *
     * @param array<int,object> $players
     */
    private static function renderTrialRoster( array $players ): void {
        if ( empty( $players ) ) return;
        self::cardOpen( __( 'Trial roster', 'talenttrack' ) );
        ?>
        <div class="tt-table-wrap">
        <table class="tt-table tt-team-trial-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $players as $pl ) :
                    $url  = RecordLink::detailUrlForWithBack( 'players', (int) $pl->id );
                    $name = QueryHelpers::player_display_name( $pl );
                    ?>
                    <tr class="is-row-link" data-row-href="<?php echo esc_url( $url ); ?>">
                        <td><a class="tt-record-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a></td>
                        <td><span class="tt-player-row__pill" data-status="trial"><?php esc_html_e( 'Trial', 'talenttrack' ); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        self::cardClose();
    }

    private static function renderUpcomingActivities( int $team_id ): void {
        global $wpdb;
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

        // #1098 — Activity volume preset (Explorer →).
        $activity_explore_url = \TT\Modules\Analytics\Domain\ExplorerUrl::build(
            'activity_volume',
            [ 'team_id' => (string) $team_id, 'date_after' => '-12 months' ],
            'month'
        );
        $head_action = null;
        if ( \TT\Modules\Analytics\AnalyticsModule::explorerEnabled() ) {
            $head_action = [ 'href' => $activity_explore_url, 'label' => __( 'Explorer →', 'talenttrack' ) ];
        }
        self::cardOpen( __( 'Upcoming activities', 'talenttrack' ), $head_action );
        ?>
        <div class="tt-table-wrap">
        <table class="tt-table tt-team-activities-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) :
                    $url = RecordLink::detailUrlForWithBack( 'activities', (int) $r->id );
                    $type_label   = \TT\Infrastructure\Query\LabelTranslator::activityType( (string) ( $r->activity_type_key ?? '' ) );
                    $status_key   = (string) ( $r->activity_status_key ?? '' );
                    $status_label = $status_key !== '' ? ucfirst( str_replace( '_', ' ', $status_key ) ) : '';
                    $st = (string) ( $r->start_time ?? '' );
                    $et = (string) ( $r->end_time   ?? '' );
                    $date_text = (string) $r->session_date;
                    if ( $st !== '' ) {
                        $date_text .= ' · ' . substr( $st, 0, 5 ) . ( $et !== '' ? '–' . substr( $et, 0, 5 ) : '' );
                    }
                    ?>
                    <tr class="is-row-link" data-row-href="<?php echo esc_url( $url ); ?>">
                        <td><?php echo esc_html( $date_text ); ?></td>
                        <td><a class="tt-record-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $r->title ); ?></a></td>
                        <td><?php echo $type_label !== '' ? esc_html( $type_label ) : '—'; ?></td>
                        <td><?php echo $status_label !== '' ? esc_html( $status_label ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        self::cardClose();
    }

    private static function renderChemistryTeaser( int $team_id ): void {
        if ( ! class_exists( '\TT\Modules\TeamDevelopment\Frontend\FrontendTeamChemistryView' ) ) return;
        // #2033 — hide the teaser when the team_chemistry sub-feature is off or
        // the user lacks chemistry READ authority (mirrors the board's own gate).
        if ( ! \TT\Modules\TeamDevelopment\TeamChemistryAccess::canReadChemistry( get_current_user_id() ) ) return;
        $url = add_query_arg(
            [ 'tt_view' => 'team-chemistry', 'team_id' => $team_id ],
            RecordLink::dashboardUrl()
        );
        self::cardOpen( __( 'Team chemistry', 'talenttrack' ) );
        echo '<p style="margin:0;"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $url ) . '">';
        echo esc_html__( 'Open the chemistry board', 'talenttrack' );
        echo '</a></p>';
        self::cardClose();
    }

    /* ---------- Small render helpers ---------- */

    /**
     * Open a `tt-player-card` panel with an optional head action link.
     *
     * @param array{href:string,label:string}|null $action
     */
    private static function cardOpen( string $title, ?array $action = null ): void {
        echo '<div class="tt-player-card tt-team-card">';
        echo '<div class="tt-player-card__head">';
        echo '<h3 class="tt-player-card__title">' . esc_html( $title ) . '</h3>';
        if ( $action !== null && ! empty( $action['href'] ) ) {
            echo '<a class="tt-player-card__cta" href="' . esc_url( (string) $action['href'] ) . '">'
                . esc_html( (string) $action['label'] ) . '</a>';
        }
        echo '</div>';
        echo '<div class="tt-player-card__body">';
    }

    private static function cardClose(): void {
        echo '</div></div>';
    }

    /** Two-letter crest from the team name (first letters of first two words). */
    private static function crestFor( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name ) ?: [ $name ];
        $a = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 );
        $b = count( $parts ) > 1 ? mb_substr( (string) ( $parts[1] ?? '' ), 0, 1 ) : '';
        return mb_strtoupper( $a . $b );
    }

    /**
     * Head coach display name from the staff-assignment groups (first
     * head_coach entry). Empty string when none assigned.
     *
     * @param array<string,mixed> $staff
     */
    private static function headCoachName( array $staff ): string {
        $group = $staff['head_coach'] ?? null;
        if ( ! is_array( $group ) ) return '';
        foreach ( $group as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $person = $entry['person'] ?? null;
            if ( ! is_object( $person ) ) continue;
            $name = trim( ( (string) ( $person->first_name ?? '' ) ) . ' ' . ( (string) ( $person->last_name ?? '' ) ) );
            if ( $name !== '' ) return $name;
        }
        return '';
    }

    /**
     * #1088 VCT-13 — inline VCT defaults panel.
     *
     * Renders weekday chips + default start time + default duration for
     * the team's current-season schedule row. Settings sub-form per
     * CLAUDE.md §3 exemption (a).
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

        // v4.20.36 (#1196) — honour `tt_back` (CLAUDE.md §6 point 5).
        $back       = \TT\Shared\Frontend\Components\BackLink::resolve();
        $cancel_url = $back !== null
            ? (string) $back['url']
            : add_query_arg(
                [ 'tt_view' => 'teams', 'id' => $team_id ],
                RecordLink::dashboardUrl()
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
