<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\AgeTier;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Frontend\Components\EmptyStateCard;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendPlayerDetailView — primary working surface for one player at
 * `?tt_view=players&id=N`.
 *
 * Cap-gated on `tt_view_players`. Composition only: every datum comes
 * from existing repositories / QueryHelpers — no business logic in the
 * render (CLAUDE.md § 4 SaaS-readiness rule).
 *
 * The visible layout is the surface defined in
 * `.local-mockups/player-profile/index.html`: paper-background hero
 * with a status-coloured avatar ring, an action row, a 3-up key-facts
 * strip, an at-a-glance KPI strip, then pill-chip tabs with count
 * badges (Profile / Goals / Evaluations / Activities / PDP / Trials /
 * Notes). Mobile-first; at ≥1024px the key-facts + KPIs move into a
 * 320px left rail while the tabs + active pane occupy a right column.
 */
final class FrontendPlayerDetailView extends FrontendViewBase {

    private static bool $detail_css_enqueued = false;

    private static function enqueueDetailCss(): void {
        if ( self::$detail_css_enqueued ) return;
        wp_enqueue_style(
            'tt-frontend-player-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-player-detail.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        self::$detail_css_enqueued = true;
    }

    /**
     * Hero quick-record popovers (behaviour + potential). Only enqueues
     * + localises when the current user has at least one of the
     * recording caps — no payload sent to scouts / parents who can't
     * trigger anything anyway.
     */
    private static function enqueueHeroPopovers( int $player_id, ?object $player ): void {
        $can_log_behaviour = current_user_can( 'tt_rate_player_behaviour' );
        $can_set_potential = current_user_can( 'tt_set_player_potential' );
        if ( ! $can_log_behaviour && ! $can_set_potential ) return;

        wp_enqueue_style(
            'tt-frontend-player-hero-popovers',
            TT_PLUGIN_URL . 'assets/css/frontend-player-hero-popovers.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-player-hero-popovers',
            TT_PLUGIN_URL . 'assets/js/frontend-player-hero-popovers.js',
            [],
            TT_VERSION,
            true
        );

        // Pre-fetch the player's 20 most recent completed activities so
        // the behaviour popover doesn't need a second REST round-trip on
        // open. Mirrors FrontendPlayerStatusCaptureView::loadRecentActivitiesForPlayer.
        global $wpdb;
        $p = $wpdb->prefix;
        $recent = $can_log_behaviour ? (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT a.id, a.session_date, a.title
               FROM {$p}tt_activities a
               JOIN {$p}tt_attendance att ON att.activity_id = a.id
              WHERE att.player_id = %d
                AND a.activity_status_key = %s
                AND a.archived_at IS NULL
              ORDER BY a.session_date DESC
              LIMIT %d",
            $player_id, 'completed', 20
        ) ) : [];
        $activities = [];
        foreach ( $recent as $a ) {
            $activities[] = [
                'id'    => (int) $a->id,
                'label' => sprintf( '%s · %s', (string) $a->session_date, (string) $a->title ),
            ];
        }

        $rmin = (int) round( (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_min', '5' ) );
        $rmax = (int) round( (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '10' ) );

        $bands = [
            [ 'key' => PotentialBand::FIRST_TEAM,             'label' => __( 'First-team', 'talenttrack' ) ],
            [ 'key' => PotentialBand::PROFESSIONAL_ELSEWHERE, 'label' => __( 'Professional elsewhere', 'talenttrack' ) ],
            [ 'key' => PotentialBand::SEMI_PRO,               'label' => __( 'Semi-pro', 'talenttrack' ) ],
            [ 'key' => PotentialBand::TOP_AMATEUR,            'label' => __( 'Top amateur', 'talenttrack' ) ],
            [ 'key' => PotentialBand::RECREATIONAL,           'label' => __( 'Recreational', 'talenttrack' ) ],
        ];

        $history_url = add_query_arg(
            [ 'tt_view' => 'player-status-capture', 'player_id' => $player_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );

        wp_localize_script( 'tt-frontend-player-hero-popovers', 'TTPlayerHeroPopovers', [
            'rest_url'                => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce'              => wp_create_nonce( 'wp_rest' ),
            'player_id'               => $player_id,
            'rating_min'              => $rmin,
            'rating_max'              => $rmax,
            'activities'              => $activities,
            'potential_bands'         => $bands,
            'current_potential_band'  => isset( $player->potential_band ) ? (string) $player->potential_band : '',
            'history_url'             => $history_url,
            'i18n' => [
                'close'                => __( 'Close',                       'talenttrack' ),
                'cancel'               => __( 'Cancel',                      'talenttrack' ),
                'log_behaviour_title'  => __( 'Log behaviour',               'talenttrack' ),
                'set_potential_title'  => __( 'Set potential',               'talenttrack' ),
                'rating_label'         => __( 'Rating',                      'talenttrack' ),
                'rating_placeholder'   => __( '— pick a rating —',           'talenttrack' ),
                'activity_label'       => __( 'Related activity (optional)', 'talenttrack' ),
                'activity_none'        => __( '— none —',                    'talenttrack' ),
                'notes_label'          => __( 'Notes',                       'talenttrack' ),
                'notes_placeholder'    => __( 'Optional context',            'talenttrack' ),
                'band_label'           => __( 'Potential band',              'talenttrack' ),
                'band_placeholder'     => __( '— pick a band —',             'talenttrack' ),
                'save_behaviour'       => __( 'Save rating',                 'talenttrack' ),
                'save_potential'       => __( 'Update potential',            'talenttrack' ),
                'view_all_behaviour'   => __( 'View all behaviour ratings →','talenttrack' ),
                'view_all_potential'   => __( 'View potential history →',    'talenttrack' ),
                'success_behaviour'    => __( 'Behaviour recorded',          'talenttrack' ),
                'success_potential'    => __( 'Potential updated',           'talenttrack' ),
                'error_generic'        => __( 'Could not save. Try again.',  'talenttrack' ),
                'error_network'        => __( 'Network error. Try again.',   'talenttrack' ),
            ],
        ] );
    }

    /**
     * Tab key → human label. Per #1107, dev-data tabs (Evaluations,
     * PDP, Trials) are matrix-gated so AC users (whose seed strips
     * `evaluations`, `pdp_file`, `trial_*` per #1060 / migration 0136)
     * don't see the tabs at all. Goals + Activities stay always-on —
     * AC's seed grants both at team scope. Notes keeps its existing
     * cap-gate. Pattern matches `renderEvaluationsTab` / `renderPdpTab`
     * / `renderTrialsTab` which gate again on entry for defense in
     * depth (direct `?tab=evaluations` URL needs the same answer).
     *
     * @return array<string,string>  tab key => human label
     */
    private static function tabs( int $user_id ): array {
        $tabs = [
            'profile' => __( 'Profile', 'talenttrack' ),
            'goals'   => __( 'Goals', 'talenttrack' ),
        ];
        if ( MatrixGate::canAnyScope( $user_id, 'evaluations', MatrixGate::READ ) ) {
            $tabs['evaluations'] = __( 'Evaluations', 'talenttrack' );
        }
        $tabs['activities'] = __( 'Activities', 'talenttrack' );
        if ( MatrixGate::canAnyScope( $user_id, 'pdp_file', MatrixGate::READ ) ) {
            $tabs['pdp'] = __( 'PDP cycle', 'talenttrack' );
        }
        if ( MatrixGate::canAnyScope( $user_id, 'trial_cases', MatrixGate::READ ) ) {
            $tabs['trials'] = __( 'Trials', 'talenttrack' );
        }
        if ( current_user_can( 'tt_view_player_notes' ) ) {
            $tabs['notes'] = __( 'Notes', 'talenttrack' );
        }
        // Analytics tab removed v3.110.187 — operators reach the
        // dimension explorer via ?tt_view=explore.
        return $tabs;
    }

    public static function render( int $player_id, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_players' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view player details.', 'talenttrack' ) . '</p>';
            return;
        }

        // #1089 VCT-14 — handle PHV-panel POST before rendering so the
        // panel reflects the just-saved state. Cap-gated inside.
        $phv_panel_notice = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_tt_vct_phv_panel'] ) ) {
            $phv_panel_notice = self::handleVctPhvPost( $player_id, $user_id );
        }

        $player = QueryHelpers::get_player( $player_id );

        if ( ! $player ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Player not found', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ) ]
            );
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That player is no longer available, or you do not have access.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueDetailCss();
        self::enqueueHeroPopovers( $player_id, $player );
        $name = QueryHelpers::player_display_name( $player );

        $players_url = add_query_arg( [ 'tt_view' => 'players' ], RecordLink::dashboardUrl() );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( [
            [ 'label' => __( 'Dashboard', 'talenttrack' ), 'url' => RecordLink::dashboardUrl() ],
            [ 'label' => __( 'Players', 'talenttrack' ),   'url' => $players_url ],
            [ 'label' => $name ],
        ] );

        $team       = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $team_url   = $team ? add_query_arg( [ 'tt_view' => 'teams', 'id' => (int) $team->id ], RecordLink::dashboardUrl() ) : '';

        $tab_set    = self::tabs( $user_id );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'profile';
        if ( ! array_key_exists( $active_tab, $tab_set ) ) $active_tab = 'profile';
        $base_url = add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id ], RecordLink::dashboardUrl() );

        $counts = PlayerFileCounts::for( $player_id );
        ?>
        <article class="tt-player-detail" data-tab="<?php echo esc_attr( $active_tab ); ?>">
            <?php
            $phv_row = ( new \TT\Modules\Vct\Repositories\VctPhvFlagsRepository() )->findForPlayer( $player_id );
            self::renderHero( $player, $name, $team, $team_url, $phv_row );
            self::renderActionRow( $player, $players_url );
            ?>

            <div class="tt-player-detail__rail">
                <?php
                self::renderKeyFacts( $player );
                self::renderAtAGlance( $player_id, $player, $base_url, $counts );
                ?>
            </div>

            <div class="tt-player-detail__main">
                <nav class="tt-player-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Player sections', 'talenttrack' ); ?>">
                    <?php foreach ( $tab_set as $key => $label ) :
                        $url       = add_query_arg( [ 'tab' => $key ], $base_url );
                        $is_active = $key === $active_tab;
                        $count     = $counts[ $key ] ?? null;
                        $classes   = 'tt-player-tab';
                        if ( $is_active ) $classes .= ' tt-player-tab--active';
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>"
                           class="<?php echo esc_attr( $classes ); ?>"
                           role="tab"
                           aria-current="<?php echo $is_active ? 'true' : 'false'; ?>"
                           aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                            <?php echo esc_html( $label ); ?>
                            <?php if ( $key !== 'profile' && (int) $count > 0 ) : ?>
                                <span class="tt-player-tab__count"><?php echo (int) $count; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <section class="tt-player-tab-panel">
                    <?php
                    switch ( $active_tab ) {
                        case 'goals':       self::renderGoalsTab( $player_id ); break;
                        case 'evaluations': self::renderEvaluationsTab( $player_id ); break;
                        case 'activities':  self::renderActivitiesTab( $player_id, $player ); break;
                        case 'pdp':         self::renderPdpTab( $player_id ); break;
                        case 'trials':      self::renderTrialsTab( $player_id, $player ); break;
                        case 'notes':       self::renderNotesTab( $player_id, $user_id ); break;
                        case 'profile':
                        default:            self::renderProfileTab( $player, $phv_row, $phv_panel_notice ); break;
                    }
                    ?>
                </section>
            </div>
        </article>
        <?php
    }

    /**
     * Paper-backed hero. The status signal lives on a 4px coloured
     * border around the initials/photo avatar; jersey number is a
     * small badge tucked into the avatar's bottom-right corner.
     */
    private static function renderHero( object $player, string $name, ?object $team, string $team_url, ?array $phv_row = null ): void {
        $status    = (string) ( $player->status ?? 'inactive' );
        $photo     = (string) ( $player->photo_url ?? '' );
        $jersey    = ! empty( $player->jersey_number ) ? (int) $player->jersey_number : 0;
        $positions = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
        $first_pos = is_array( $positions ) && ! empty( $positions ) ? (string) $positions[0] : '';
        $journey   = self::journeyText( $player );
        // #1089 VCT-14 — orange PHV pill on the hero when active.
        $phv_active = $phv_row !== null && ! empty( $phv_row['is_active'] );
        ?>
        <header class="tt-player-detail__hero" aria-label="<?php esc_attr_e( 'Player', 'talenttrack' ); ?>">
            <div class="tt-player-hero__row">
                <div class="tt-player-hero__avatar" data-status="<?php echo esc_attr( $status ); ?>" aria-hidden="true">
                    <?php if ( $photo !== '' ) : ?>
                        <img class="tt-player-hero__photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
                    <?php else : ?>
                        <?php echo esc_html( self::initialsFor( $name ) ); ?>
                    <?php endif; ?>
                    <?php if ( $jersey > 0 ) : ?>
                        <span class="tt-player-hero__jersey">#<?php echo (int) $jersey; ?></span>
                    <?php endif; ?>
                </div>
                <div class="tt-player-hero__main">
                    <h1 class="tt-player-hero__name">
                        <?php echo esc_html( $name ); ?>
                        <?php if ( $phv_active ) : ?>
                            <span class="tt-player-phv-pill" title="<?php esc_attr_e( 'Physical / Health / Vitality flag — workload adjusted', 'talenttrack' ); ?>"><?php esc_html_e( 'PHV', 'talenttrack' ); ?></span>
                        <?php endif; ?>
                    </h1>
                    <?php if ( $team ) : ?>
                        <p class="tt-player-hero__sub">
                            <a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( (string) $team->name ); ?></a>
                            <?php if ( ! empty( $team->age_group ) ) : ?>
                                <span> · <?php echo esc_html( (string) $team->age_group ); ?></span>
                            <?php endif; ?>
                        </p>
                    <?php else : ?>
                        <p class="tt-player-hero__sub"><em><?php esc_html_e( 'Unassigned', 'talenttrack' ); ?></em></p>
                    <?php endif; ?>
                    <p class="tt-player-hero__pills">
                        <?php
                        $status_label = \TT\Infrastructure\Query\LabelTranslator::playerStatus( $status );
                        $journey_text = $journey !== null && $journey['days'] !== '' ? $journey['days'] : '';
                        ?>
                        <span class="tt-player-pill" data-status="<?php echo esc_attr( $status ); ?>">
                            <?php echo esc_html( $status_label ); ?>
                            <?php if ( $journey_text !== '' ) : ?>
                                · <?php echo esc_html( $journey_text ); ?>
                            <?php endif; ?>
                        </span>
                        <?php if ( $first_pos !== '' ) : ?>
                            <span class="tt-player-pill tt-player-pill--pos"><?php echo esc_html( $first_pos ); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </header>
        <?php
    }

    /**
     * Action row — `+ Log behaviour` · `Set potential` · `Edit` · `⋯`
     * overflow with Archive + Assign-to-team. Cap-gated identically
     * to the legacy page-header actions: Log behaviour →
     * `tt_rate_player_behaviour`, Set potential →
     * `tt_set_player_potential`, Edit + overflow items →
     * `tt_edit_players`.
     */
    private static function renderActionRow( object $player, string $players_url ): void {
        $player_id         = (int) $player->id;
        $can_log_behaviour = current_user_can( 'tt_rate_player_behaviour' );
        $can_set_potential = current_user_can( 'tt_set_player_potential' );
        $can_edit          = current_user_can( 'tt_edit_players' );

        // Render the row even when empty so the surface keeps its
        // visual rhythm (the paper-bottom shadow under the hero
        // continues into the action band).
        $edit_url = add_query_arg(
            [ 'tt_view' => 'players', 'id' => $player_id, 'action' => 'edit' ],
            RecordLink::dashboardUrl()
        );
        $assign_url = add_query_arg(
            [ 'tt_view' => 'players', 'id' => $player_id, 'tab' => 'profile' ],
            RecordLink::dashboardUrl()
        ) . '#tt-player-assign-team';

        $has_overflow = $can_edit; // overflow holds Archive + optional Assign.
        ?>
        <div class="tt-player-detail__actions" aria-label="<?php esc_attr_e( 'Actions', 'talenttrack' ); ?>">
            <?php if ( $can_log_behaviour ) : ?>
                <button type="button" class="tt-player-action tt-player-action--primary" data-tt-popover-trigger="behaviour">
                    + <?php esc_html_e( 'Log behaviour', 'talenttrack' ); ?>
                </button>
            <?php endif; ?>
            <?php if ( $can_set_potential ) : ?>
                <button type="button" class="tt-player-action" data-tt-popover-trigger="potential">
                    <?php esc_html_e( 'Set potential', 'talenttrack' ); ?>
                </button>
            <?php endif; ?>
            <?php if ( $can_edit ) : ?>
                <a class="tt-player-action" href="<?php echo esc_url( $edit_url ); ?>">
                    <?php esc_html_e( 'Edit', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>
            <?php if ( current_user_can( 'tt_edit_goals' ) ) :
                // #1064 — printable season-start goal-setting intake.
                $intake_url = add_query_arg(
                    [ 'tt_goal_intake_print' => '1', 'player_id' => $player_id ],
                    home_url( '/' )
                );
                ?>
                <a class="tt-player-action" href="<?php echo esc_url( $intake_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( 'Print doelenintake', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>
            <?php if ( $has_overflow ) : ?>
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
                        <?php if ( empty( $player->team_id ) ) : ?>
                            <a class="tt-player-action" href="<?php echo esc_url( $assign_url ); ?>" role="menuitem">
                                <?php esc_html_e( 'Assign to team', 'talenttrack' ); ?>
                            </a>
                        <?php endif; ?>
                        <button type="button"
                                class="tt-player-action tt-player-action--danger"
                                role="menuitem"
                                data-tt-archive-rest-path="<?php echo esc_attr( 'players/' . $player_id ); ?>"
                                data-tt-archive-confirm="<?php echo esc_attr__( 'Archive this player? They can be restored later by a site admin.', 'talenttrack' ); ?>"
                                data-tt-archive-redirect="<?php echo esc_attr( $players_url ); ?>">
                            <?php esc_html_e( 'Archive', 'talenttrack' ); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Surface DOB / Foot / Joined above the tabs. Coaches stop having
     * to open Profile to check basic identity.
     */
    private static function renderKeyFacts( object $player ): void {
        $dob_raw    = (string) ( $player->date_of_birth ?? '' );
        $foot_raw   = (string) ( $player->preferred_foot ?? '' );
        $joined_raw = (string) ( $player->date_joined ?? '' );
        $positions  = json_decode( (string) ( $player->preferred_positions ?? '' ), true );

        $dob_value = $dob_raw !== '' ? self::shortDate( $dob_raw ) : '—';
        $dob_hint  = $dob_raw !== '' ? self::ageHint( $dob_raw )   : '';

        $foot_value = $foot_raw !== ''
            ? \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'foot_options', $foot_raw )
            : '—';
        $foot_hint  = is_array( $positions ) && ! empty( $positions )
            ? implode( ' / ', array_map( 'strval', $positions ) )
            : '';

        $joined_value = $joined_raw !== '' ? self::shortDate( $joined_raw ) : '—';
        $joined_hint  = $joined_raw !== '' ? self::yearsInAcademy( $joined_raw ) : '';
        ?>
        <section class="tt-player-facts" aria-label="<?php esc_attr_e( 'Key facts', 'talenttrack' ); ?>">
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'DOB', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( $dob_value ); ?></p>
                <?php if ( $dob_hint !== '' ) : ?>
                    <p class="tt-player-facts__hint"><?php echo esc_html( $dob_hint ); ?></p>
                <?php endif; ?>
            </div>
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Foot', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( $foot_value ); ?></p>
                <?php if ( $foot_hint !== '' ) : ?>
                    <p class="tt-player-facts__hint"><?php echo esc_html( $foot_hint ); ?></p>
                <?php endif; ?>
            </div>
            <div class="tt-player-facts__cell">
                <span class="tt-player-facts__label"><?php esc_html_e( 'Joined', 'talenttrack' ); ?></span>
                <p class="tt-player-facts__value"><?php echo esc_html( $joined_value ); ?></p>
                <?php if ( $joined_hint !== '' ) : ?>
                    <p class="tt-player-facts__hint"><?php echo esc_html( $joined_hint ); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * KPI strip — three living signals (avg rating, attendance %,
     * active goals) replacing the loose "latest activity / eval /
     * goal" chips of the previous hero block. Each card jumps to the
     * relevant tab on tap.
     */
    private static function renderAtAGlance( int $player_id, object $player, string $base_url, array $counts ): void {
        $kpis = self::atAGlance( $player_id, $counts );
        ?>
        <section class="tt-player-glance" aria-label="<?php esc_attr_e( 'At a glance', 'talenttrack' ); ?>">
            <p class="tt-player-glance__title"><?php esc_html_e( 'At a glance', 'talenttrack' ); ?></p>
            <div class="tt-player-glance__grid">
                <?php
                // #1107 — avg rating + trend are computed from the same
                // `tt_eval_ratings` rows the Evaluations tab gates on.
                // Hide the KPI tile entirely when the user lacks
                // `evaluations:read` so the value (and the direction
                // arrow) don't leak through the side door.
                if ( MatrixGate::canAnyScope( get_current_user_id(), 'evaluations', MatrixGate::READ ) ) : ?>
                <a class="tt-player-kpi" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'evaluations' ], $base_url ) ); ?>">
                    <div class="tt-player-kpi__label"><?php esc_html_e( 'Avg rating', 'talenttrack' ); ?></div>
                    <div class="tt-player-kpi__num">
                        <?php echo esc_html( $kpis['avg_rating']['value'] ); ?>
                        <?php if ( $kpis['avg_rating']['scale'] !== '' ) : ?>
                            <small><?php echo esc_html( $kpis['avg_rating']['scale'] ); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ( $kpis['avg_rating']['hint'] !== '' ) : ?>
                        <div class="tt-player-kpi__hint <?php echo esc_attr( $kpis['avg_rating']['trend_class'] ); ?>"><?php echo esc_html( $kpis['avg_rating']['hint'] ); ?></div>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a class="tt-player-kpi" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'activities' ], $base_url ) ); ?>">
                    <div class="tt-player-kpi__label"><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></div>
                    <div class="tt-player-kpi__num">
                        <?php echo esc_html( $kpis['attendance']['value'] ); ?>
                        <?php if ( $kpis['attendance']['scale'] !== '' ) : ?>
                            <small><?php echo esc_html( $kpis['attendance']['scale'] ); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ( $kpis['attendance']['hint'] !== '' ) : ?>
                        <div class="tt-player-kpi__hint"><?php echo esc_html( $kpis['attendance']['hint'] ); ?></div>
                    <?php endif; ?>
                </a>
                <a class="tt-player-kpi" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'goals' ], $base_url ) ); ?>">
                    <div class="tt-player-kpi__label"><?php esc_html_e( 'Goals', 'talenttrack' ); ?></div>
                    <div class="tt-player-kpi__num">
                        <?php echo esc_html( $kpis['goals']['value'] ); ?>
                    </div>
                    <?php if ( $kpis['goals']['hint'] !== '' ) : ?>
                        <div class="tt-player-kpi__hint <?php echo esc_attr( $kpis['goals']['trend_class'] ); ?>"><?php echo esc_html( $kpis['goals']['hint'] ); ?></div>
                    <?php endif; ?>
                </a>
            </div>
        </section>
        <?php
    }

    /**
     * Build the three KPI signals. Each entry returns a `value`, a
     * `scale` (e.g. `/10`, `%`) shown smaller after the number, an
     * optional `hint` line, and a `trend_class` (`tt-player-kpi__trend--up|--down`)
     * for the hint colour.
     *
     * @return array<string,array{value:string,scale:string,hint:string,trend_class:string}>
     */
    private static function atAGlance( int $player_id, array $counts ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // Avg rating: mean of every rating row across the player's
        // non-archived evaluations. Ratings live in `tt_eval_ratings`
        // joined to `tt_evaluations` via `evaluation_id`; the
        // direct-mean shape mirrors `MiniPlayerListWidget` (the
        // dashboard's "recent evaluations" tile's per-evaluation
        // average), aggregated across the player's whole history.
        // Trend arrow compares the most recent evaluation's mean
        // against the rolling mean.
        $avg_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(r.rating) AS avg_r, COUNT(DISTINCT e.id) AS n
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
              WHERE e.player_id = %d
                AND e.archived_at IS NULL",
            $player_id
        ) );
        $avg_value = '—';
        $avg_scale = '';
        $avg_hint  = '';
        $avg_class = '';
        if ( $avg_row && (int) $avg_row->n > 0 ) {
            $avg_value = number_format_i18n( (float) $avg_row->avg_r, 1 );
            $rmax_cfg  = (float) QueryHelpers::get_config( 'rating_max', '10' );
            if ( $rmax_cfg > 0 ) {
                $avg_scale = '/' . (int) round( $rmax_cfg );
            }
            $last = $wpdb->get_var( $wpdb->prepare(
                "SELECT AVG(r.rating)
                   FROM {$p}tt_evaluations e
                   JOIN {$p}tt_eval_ratings r ON r.evaluation_id = e.id
                  WHERE e.player_id = %d AND e.archived_at IS NULL
                  GROUP BY e.id
                  ORDER BY e.eval_date DESC LIMIT 1",
                $player_id
            ) );
            if ( $last !== null && (int) $avg_row->n > 1 ) {
                $delta = (float) $last - (float) $avg_row->avg_r;
                if ( abs( $delta ) >= 0.1 ) {
                    $avg_hint  = ( $delta > 0 ? '▲ ' : '▼ ' ) . number_format_i18n( abs( $delta ), 1 );
                    $avg_class = $delta > 0 ? 'tt-player-kpi__trend--up' : 'tt-player-kpi__trend--down';
                }
            }
        }

        // Attendance %: present rows / non-cancelled completed
        // attendance rows in the last 30 days. Matches the
        // "actual attendance" scope of the activities tab — only
        // completed activities count.
        $att_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) AS present_n,
                COUNT(*) AS total_n
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND a.archived_at IS NULL
                AND a.plan_state = 'completed'
                AND a.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            $player_id
        ) );
        $att_value = '—';
        $att_scale = '';
        $att_hint  = '';
        if ( $att_row && (int) $att_row->total_n > 0 ) {
            $pct = (int) round( ( (int) $att_row->present_n / (int) $att_row->total_n ) * 100 );
            $att_value = (string) $pct;
            $att_scale = '%';
            $att_hint  = __( 'last 30 d', 'talenttrack' );
        } elseif ( (int) ( $counts['activities'] ?? 0 ) > 0 ) {
            $att_hint = __( 'last 30 d', 'talenttrack' );
        }

        // Goals KPI: count of active (non-archived, non-completed)
        // goals, with a "1 due soon" hint when the nearest due date
        // is within 7 days.
        $active_goals = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
                AND ( status IS NULL OR status NOT IN ( 'completed', 'cancelled' ) )",
            $player_id
        ) );
        $goals_hint  = '';
        $goals_class = '';
        if ( $active_goals > 0 ) {
            $due_soon = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_goals
                  WHERE player_id = %d AND archived_at IS NULL
                    AND due_date IS NOT NULL
                    AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    AND ( status IS NULL OR status NOT IN ( 'completed', 'cancelled' ) )",
                $player_id
            ) );
            if ( $due_soon > 0 ) {
                /* translators: %d: number of goals due in the next 7 days */
                $goals_hint  = sprintf( _n( '%d due soon', '%d due soon', $due_soon, 'talenttrack' ), $due_soon );
                $goals_class = 'tt-player-kpi__trend--down';
            }
        }

        return [
            'avg_rating' => [
                'value'       => $avg_value,
                'scale'       => $avg_scale,
                'hint'        => $avg_hint,
                'trend_class' => $avg_class,
            ],
            'attendance' => [
                'value'       => $att_value,
                'scale'       => $att_scale,
                'hint'        => $att_hint,
                'trend_class' => '',
            ],
            'goals' => [
                'value'       => (string) $active_goals,
                'scale'       => '',
                'hint'        => $goals_hint,
                'trend_class' => $goals_class,
            ],
        ];
    }

    /**
     * @return array{days:string,joined:string}|null  null when no usable date.
     */
    private static function journeyText( object $player ): ?array {
        $joined_raw  = (string) ( $player->date_joined ?? '' );
        $created_raw = (string) ( $player->created_at ?? '' );
        $source = $joined_raw !== '' ? $joined_raw : $created_raw;
        if ( $source === '' ) return null;

        $ts = strtotime( $source );
        if ( $ts === false ) return null;

        $now      = current_time( 'timestamp' );
        $days     = max( 0, (int) floor( ( $now - $ts ) / DAY_IN_SECONDS ) );
        $is_fresh = $joined_raw === '' && $days < 7;

        if ( $is_fresh ) {
            return [ 'days' => __( 'Joined recently', 'talenttrack' ), 'joined' => '' ];
        }

        $years = (int) floor( $days / 365 );
        if ( $years >= 1 ) {
            /* translators: %d: number of years */
            $days_text = sprintf( _n( '%d yr in academy', '%d yrs in academy', $years, 'talenttrack' ), $years );
        } else {
            /* translators: %d: number of days */
            $days_text = sprintf( _n( '%d day in academy', '%d days in academy', $days, 'talenttrack' ), $days );
        }

        /* translators: %s: ISO date the player joined */
        $joined_text = $joined_raw !== ''
            ? sprintf( __( 'Joined %s', 'talenttrack' ), gmdate( 'Y-m-d', $ts ) )
            : '';

        return [ 'days' => $days_text, 'joined' => $joined_text ];
    }

    private static function initialsFor( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name ) ?: [ $name ];
        $first = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 );
        $last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';
        return mb_strtoupper( $first . $last );
    }

    /** Compact "12 Mar '13" style date — readable in 360px facts cells. */
    private static function shortDate( string $iso ): string {
        $ts = strtotime( $iso );
        if ( $ts === false ) return $iso;
        return gmdate( "j M ’y", $ts );
    }

    private static function ageHint( string $dob_iso ): string {
        $ts = strtotime( $dob_iso );
        if ( $ts === false ) return '';
        $now   = current_time( 'timestamp' );
        $years = max( 0, (int) floor( ( $now - $ts ) / ( 365.25 * DAY_IN_SECONDS ) ) );
        if ( $years <= 0 ) return '';
        /* translators: %d: number of years */
        return sprintf( _n( '%d yr', '%d yrs', $years, 'talenttrack' ), $years );
    }

    private static function yearsInAcademy( string $joined_iso ): string {
        $ts = strtotime( $joined_iso );
        if ( $ts === false ) return '';
        $now  = current_time( 'timestamp' );
        $days = max( 0, (int) floor( ( $now - $ts ) / DAY_IN_SECONDS ) );
        if ( $days < 30 ) return __( 'new', 'talenttrack' );
        $years = (int) floor( $days / 365 );
        if ( $years >= 1 ) {
            /* translators: %d: number of years */
            return sprintf( _n( '%d yr in', '%d yrs in', $years, 'talenttrack' ), $years );
        }
        $months = max( 1, (int) round( $days / 30 ) );
        /* translators: %d: number of months */
        return sprintf( _n( '%d mo in', '%d mos in', $months, 'talenttrack' ), $months );
    }

    /**
     * Profile tab — Identity + Academy + Parents · Guardians + Discovery
     * cards. The Parents and Discovery cards surface data that already
     * exists in `tt_player_parents` (#0032) and `tt_prospects` (#0066)
     * but wasn't previously visible on this page.
     */
    private static function renderProfileTab( object $player, ?array $phv_row = null, string $phv_notice_html = '' ): void {
        $player_id  = (int) $player->id;
        $age_tier   = AgeTier::forPlayer( $player_id );
        $tier_label = AgeTier::labels()[ $age_tier ] ?? '';
        $positions  = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
        $team       = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;

        $foot_label = ! empty( $player->preferred_foot )
            ? LookupTranslator::byTypeAndName( 'foot_options', (string) $player->preferred_foot )
            : '';
        $status_label = ! empty( $player->status )
            ? \TT\Infrastructure\Query\LabelTranslator::playerStatus( (string) $player->status )
            : '';
        $status_history_url = add_query_arg(
            [ 'tt_view' => 'player-status-capture', 'player_id' => $player_id ],
            RecordLink::dashboardUrl()
        );

        $identity_rows = [];
        if ( ! empty( $player->date_of_birth ) ) {
            $identity_rows[] = [ __( 'Date of birth', 'talenttrack' ), esc_html( (string) $player->date_of_birth ) ];
        }
        if ( is_array( $positions ) && ! empty( $positions ) ) {
            $identity_rows[] = [ __( 'Position(s)', 'talenttrack' ), esc_html( implode( ' · ', array_map( 'strval', $positions ) ) ) ];
        }
        if ( $foot_label !== '' ) {
            $identity_rows[] = [ __( 'Preferred foot', 'talenttrack' ), esc_html( $foot_label ) ];
        }
        if ( ! empty( $player->jersey_number ) ) {
            $identity_rows[] = [ __( 'Jersey number', 'talenttrack' ), (string) (int) $player->jersey_number ];
        }
        if ( $status_label !== '' ) {
            $identity_rows[] = [
                __( 'Status', 'talenttrack' ),
                esc_html( $status_label ) . ' · <a href="' . esc_url( $status_history_url ) . '">' . esc_html__( 'history', 'talenttrack' ) . '</a>',
            ];
        }

        $team_html = '';
        if ( $team ) {
            $team_url   = add_query_arg( [ 'tt_view' => 'teams', 'id' => (int) $team->id ], RecordLink::dashboardUrl() );
            $team_html  = '<a href="' . esc_url( $team_url ) . '">' . esc_html( (string) $team->name ) . '</a>';
            if ( ! empty( $team->age_group ) ) {
                $team_html .= ' · ' . esc_html( (string) $team->age_group );
            }
        }

        $academy_rows = [];
        if ( $team_html !== '' ) {
            $academy_rows[] = [ __( 'Team', 'talenttrack' ), $team_html ];
        } else {
            $academy_rows[] = [
                __( 'Team', 'talenttrack' ),
                '<em>' . esc_html__( 'Unassigned', 'talenttrack' ) . '</em>',
            ];
        }
        if ( $tier_label !== '' ) {
            $academy_rows[] = [ __( 'Age tier', 'talenttrack' ), esc_html( $tier_label ) ];
        }
        if ( ! empty( $player->date_joined ) ) {
            $academy_rows[] = [ __( 'Date joined', 'talenttrack' ), esc_html( (string) $player->date_joined ) ];
        }
        ?>
        <div class="tt-player-profile-grid">
            <?php
            self::renderProfileCard( __( 'Identity', 'talenttrack' ), $identity_rows );
            self::renderProfileCard( __( 'Academy', 'talenttrack' ), $academy_rows );
            self::renderParentsCard( $player_id );
            self::renderDiscoveryCard( $player_id );
            ?>
        </div>

        <?php
        if ( empty( $player->team_id ) && current_user_can( 'tt_edit_players' ) ) {
            self::renderAssignTeamForm( $player_id );
        }

        // #1089 VCT-14 — PHV panel under the Identity/Academy cards.
        self::renderPhvPanel( $player_id, $phv_row, $phv_notice_html );
    }

    /**
     * One profile-tab card (Identity / Academy). Each $row is a
     * `[ field-label, value-html ]` tuple; values are emitted as
     * HTML and must already be escaped at the call site.
     *
     * @param array<int,array{0:string,1:string}> $rows
     */
    private static function renderProfileCard( string $title, array $rows ): void {
        if ( empty( $rows ) ) return;
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title"><?php echo esc_html( $title ); ?></h3>
            </div>
            <div class="tt-player-card__body">
                <?php foreach ( $rows as $row ) :
                    $label = (string) ( $row[0] ?? '' );
                    $value = (string) ( $row[1] ?? '' );
                    ?>
                    <div class="tt-player-kv">
                        <div class="tt-player-kv__k"><?php echo esc_html( $label ); ?></div>
                        <div class="tt-player-kv__v"><?php echo $value; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — value pre-escaped at call site ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Parents · Guardians card. Surfaces linked WP users from
     * `tt_player_parents`; relation / contact info is read from the
     * WP user record (display name, email; phone falls back to user
     * meta when present).
     */
    private static function renderParentsCard( int $player_id ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $rows  = [];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_player_parents" ) ) === "{$p}tt_player_parents" ) {
            $links = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT parent_user_id, is_primary FROM {$p}tt_player_parents
                  WHERE player_id = %d
                  ORDER BY is_primary DESC, created_at ASC",
                $player_id
            ) );
            foreach ( $links as $link ) {
                $user = get_userdata( (int) $link->parent_user_id );
                if ( ! $user ) continue;
                $name  = $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_email;
                $email = (string) $user->user_email;
                $phone = trim( (string) get_user_meta( (int) $user->ID, 'phone', true ) );
                $bits  = [];
                if ( ! empty( $link->is_primary ) ) {
                    $bits[] = '<em>' . esc_html__( 'primary', 'talenttrack' ) . '</em>';
                }
                if ( $phone !== '' ) {
                    $bits[] = '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>';
                }
                if ( $email !== '' ) {
                    $bits[] = '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
                }
                $rows[] = [ $name, implode( ' · ', $bits ) ];
            }
        }
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title"><?php esc_html_e( 'Parents · Guardians', 'talenttrack' ); ?></h3>
            </div>
            <div class="tt-player-card__body">
                <?php if ( empty( $rows ) ) : ?>
                    <p class="tt-player-kv__k" style="margin:0;"><?php esc_html_e( 'No linked parent or guardian yet.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <div class="tt-player-kv">
                            <div class="tt-player-kv__k"><?php echo esc_html( (string) $row[0] ); ?></div>
                            <div class="tt-player-kv__v"><?php echo $row[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Discovery card. Surfaces the prospect that was promoted to this
     * player (when one exists) — who scouted them, at what event,
     * when. Mirrors `MyRecentProspectsSource` shape.
     */
    private static function renderDiscoveryCard( int $player_id ): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = null;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_prospects" ) ) === "{$p}tt_prospects" ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT discovered_by_user_id, discovered_at, discovered_at_event, current_club
                   FROM {$p}tt_prospects
                  WHERE promoted_to_player_id = %d
                    AND archived_at IS NULL
                  ORDER BY discovered_at DESC
                  LIMIT 1",
                $player_id
            ) );
        }
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title"><?php esc_html_e( 'Discovery', 'talenttrack' ); ?></h3>
            </div>
            <div class="tt-player-card__body">
                <?php if ( $row ) :
                    $scout = get_userdata( (int) $row->discovered_by_user_id );
                    $scout_name = $scout ? (string) $scout->display_name : __( 'unknown', 'talenttrack' );
                    $event_bits = [];
                    if ( ! empty( $row->discovered_at_event ) ) $event_bits[] = (string) $row->discovered_at_event;
                    if ( ! empty( $row->current_club ) )        $event_bits[] = (string) $row->current_club;
                    if ( ! empty( $row->discovered_at ) )       $event_bits[] = (string) $row->discovered_at;
                    ?>
                    <div class="tt-player-kv">
                        <div class="tt-player-kv__k"><?php esc_html_e( 'Logged by', 'talenttrack' ); ?></div>
                        <div class="tt-player-kv__v"><?php echo esc_html( $scout_name ); ?></div>
                    </div>
                    <?php if ( ! empty( $event_bits ) ) : ?>
                        <div class="tt-player-kv">
                            <div class="tt-player-kv__k"><?php esc_html_e( 'At', 'talenttrack' ); ?></div>
                            <div class="tt-player-kv__v"><?php echo esc_html( implode( ' · ', $event_bits ) ); ?></div>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="tt-player-kv__k" style="margin:0;"><?php esc_html_e( 'No discovery record for this player.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Inline "Assign to team" picker, surfaced on the player file when
     * the player holds no team_id yet. Wires through the same
     * `PUT /players/{id}` endpoint the edit form uses, so the
     * existing permission_callback + payload validator govern;
     * nothing authorization-shaped lives in the view.
     *
     * Anchor `#tt-player-assign-team` is the jump target of the
     * action row's overflow "Assign to team" item.
     */
    private static function renderAssignTeamForm( int $player_id ): void {
        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        ?>
        <section id="tt-player-assign-team" class="tt-player-assign-team-card">
            <h2><?php esc_html_e( 'Assign this player to a team', 'talenttrack' ); ?></h2>
            <p><?php esc_html_e( 'This player has no team yet — typical after a trial admission. Pick a team to place them on the roster.', 'talenttrack' ); ?></p>
            <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( 'players/' . $player_id ); ?>" data-rest-method="PUT" data-redirect-after-save="reload">
                <?php echo \TT\Shared\Frontend\Components\TeamPickerComponent::render( [
                    'name'     => 'team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'required' => true,
                    'user_id'  => $user_id,
                    'is_admin' => $is_admin,
                    'selected' => 0,
                ] ); ?>
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Assign to team', 'talenttrack' ); ?>
                </button>
            </form>
        </section>
        <?php
    }

    /** Goals tab — card-row list of every non-archived goal. */
    private static function renderGoalsTab( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, priority, due_date FROM {$wpdb->prefix}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY due_date IS NULL, due_date ASC, created_at DESC
              LIMIT 50",
            $player_id
        ) );
        $add_url = add_query_arg(
            [ 'tt_view' => 'goals', 'action' => 'new', 'player_id' => $player_id ],
            RecordLink::dashboardUrl()
        );
        if ( empty( $rows ) ) {
            EmptyStateCard::render( [
                'icon'      => 'goals',
                'headline'  => __( 'No goals yet for this player', 'talenttrack' ),
                'explainer' => __( 'Goals capture what the player is working on this season — start with one.', 'talenttrack' ),
                'cta_label' => __( 'Add first goal', 'talenttrack' ),
                'cta_url'   => $add_url,
                'cta_cap'   => 'tt_edit_goals',
            ] );
            return;
        }
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title">
                    <?php
                    /* translators: %d: number of active goals */
                    echo esc_html( sprintf( __( 'Active goals · %d', 'talenttrack' ), count( $rows ) ) );
                    ?>
                </h3>
                <?php if ( current_user_can( 'tt_edit_goals' ) ) : ?>
                    <a class="tt-player-card__cta" href="<?php echo esc_url( $add_url ); ?>">+ <?php esc_html_e( 'Add goal', 'talenttrack' ); ?></a>
                <?php endif; ?>
            </div>
            <ul class="tt-player-list">
                <?php foreach ( $rows as $g ) :
                    $url      = RecordLink::detailUrlForWithBack( 'goals', (int) $g->id );
                    $date_bit = self::dueDateBadge( (string) ( $g->due_date ?? '' ) );
                    ?>
                    <li>
                        <a class="tt-player-row" href="<?php echo esc_url( $url ); ?>">
                            <div class="tt-player-row__date <?php echo esc_attr( $date_bit['class'] ); ?>">
                                <span class="tt-player-row__date-m"><?php echo esc_html( $date_bit['m'] ); ?></span>
                                <span class="tt-player-row__date-d"><?php echo $date_bit['d']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped ?></span>
                            </div>
                            <div class="tt-player-row__body">
                                <p class="tt-player-row__title"><?php echo esc_html( (string) $g->title ); ?></p>
                                <p class="tt-player-row__meta">
                                    <?php if ( ! empty( $g->priority ) ) : ?>
                                        <span class="tt-player-row__pill" data-priority="<?php echo esc_attr( (string) $g->priority ); ?>">
                                            <?php echo esc_html( LookupTranslator::byTypeAndName( 'goal_priority', (string) $g->priority ) ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $g->status ) ) : ?>
                                        <span class="tt-player-row__pill" data-status="<?php echo esc_attr( (string) $g->status ); ?>">
                                            <?php echo esc_html( LookupTranslator::byTypeAndName( 'goal_status', (string) $g->status ) ); ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="tt-player-row__chev" aria-hidden="true">›</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /** Evaluations tab — card-row list with right-side colour-coded rating chip. */
    private static function renderEvaluationsTab( int $player_id ): void {
        // #1107 — defense in depth. tabs() filters this out for users
        // without `evaluations:read`, but a direct `?tab=evaluations`
        // URL still routes here (sanitize_key + array_key_exists guards
        // the active tab against the post-filter set). If the user
        // shouldn't see the tab they shouldn't see the query either.
        if ( ! MatrixGate::canAnyScope( get_current_user_id(), 'evaluations', MatrixGate::READ ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view evaluations for this player.', 'talenttrack' ) . '</p>';
            return;
        }
        global $wpdb;
        // Tab list and PlayerFileCounts must agree on scope —
        // (player_id, club_id, archived_at IS NULL) — otherwise the
        // badge and the tab can fall out of sync.
        // Rating is the per-evaluation mean of `tt_eval_ratings` rows;
        // surface it inline so the row-right rating chip can render
        // without a second round-trip per row.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.eval_date, e.eval_type_id,
                    (SELECT AVG(r.rating) FROM {$wpdb->prefix}tt_eval_ratings r
                      WHERE r.evaluation_id = e.id AND r.club_id = e.club_id) AS avg_rating
               FROM {$wpdb->prefix}tt_evaluations e
              WHERE e.player_id = %d AND e.club_id = %d AND e.archived_at IS NULL
              ORDER BY e.eval_date DESC LIMIT 50",
            $player_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        $add_url = add_query_arg(
            [ 'tt_view' => 'evaluations', 'action' => 'new', 'player_id' => $player_id ],
            RecordLink::dashboardUrl()
        );
        if ( empty( $rows ) ) {
            EmptyStateCard::render( [
                'icon'      => 'evaluations',
                'headline'  => __( 'No evaluations yet for this player', 'talenttrack' ),
                'explainer' => __( 'Evaluations track how the player is developing across your rating categories. Record one after a training or match.', 'talenttrack' ),
                'cta_label' => __( 'Record first evaluation', 'talenttrack' ),
                'cta_url'   => $add_url,
                'cta_cap'   => 'tt_edit_evaluations',
            ] );
            return;
        }
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '10' );
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title">
                    <?php
                    /* translators: %d: number of recent evaluations */
                    echo esc_html( sprintf( __( 'Recent · %d', 'talenttrack' ), count( $rows ) ) );
                    ?>
                </h3>
                <?php if ( current_user_can( 'tt_edit_evaluations' ) ) : ?>
                    <a class="tt-player-card__cta" href="<?php echo esc_url( $add_url ); ?>">+ <?php esc_html_e( 'New evaluation', 'talenttrack' ); ?></a>
                <?php endif; ?>
            </div>
            <ul class="tt-player-list">
                <?php foreach ( $rows as $ev ) :
                    $url      = RecordLink::detailUrlForWithBack( 'evaluations', (int) $ev->id );
                    $date_bit = self::dateBadge( (string) ( $ev->eval_date ?? '' ) );
                    $rating   = $ev->avg_rating !== null ? (float) $ev->avg_rating : null;
                    $rating_class = '';
                    if ( $rating !== null && $rmax > 0 ) {
                        $pct = $rating / $rmax;
                        if ( $pct >= 0.75 )      $rating_class = 'tt-player-row__rating--high';
                        elseif ( $pct < 0.5 )    $rating_class = 'tt-player-row__rating--low';
                    }
                    ?>
                    <li>
                        <a class="tt-player-row" href="<?php echo esc_url( $url ); ?>">
                            <div class="tt-player-row__date">
                                <span class="tt-player-row__date-m"><?php echo esc_html( $date_bit['m'] ); ?></span>
                                <span class="tt-player-row__date-d"><?php echo esc_html( $date_bit['d'] ); ?></span>
                            </div>
                            <div class="tt-player-row__body">
                                <p class="tt-player-row__title"><?php esc_html_e( 'Evaluation', 'talenttrack' ); ?></p>
                                <p class="tt-player-row__meta"><?php echo esc_html( (string) ( $ev->eval_date ?? '' ) ); ?></p>
                            </div>
                            <?php if ( $rating !== null ) : ?>
                                <div class="tt-player-row__rating <?php echo esc_attr( $rating_class ); ?>">
                                    <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?>
                                </div>
                            <?php else : ?>
                                <span class="tt-player-row__chev" aria-hidden="true">›</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        // v3.110.148 — inline row-level archive/delete intentionally
        // not re-added: destructive actions live on the evaluation
        // detail page.
    }

    /** Activities tab — recent attended + planned activities for the player. */
    private static function renderActivitiesTab( int $player_id, ?object $player = null ): void {
        global $wpdb;
        // v3.110.185 (#789) — both planned and completed activities;
        // planned rows render a neutral "Planned" pill instead of the
        // wizard's default-Present pre-fill so coach intent stays
        // distinct from coach pre-fill.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.activity_type_key, a.plan_state, att.status
               FROM {$wpdb->prefix}tt_attendance att
               JOIN {$wpdb->prefix}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND a.archived_at IS NULL
                AND (
                    ( a.plan_state = 'completed' AND a.session_date <= CURDATE() )
                    OR a.plan_state IN ( 'planned', 'scheduled' )
                )
              ORDER BY a.session_date DESC LIMIT 25",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            $team_id = $player !== null ? (int) ( $player->team_id ?? 0 ) : 0;
            if ( $team_id > 0 ) {
                EmptyStateCard::render( [
                    'icon'      => 'activities',
                    'headline'  => __( 'No attended activities yet', 'talenttrack' ),
                    'explainer' => __( 'Activities are recorded at the team level. Schedule one for the player\'s team and the player will appear in the roster.', 'talenttrack' ),
                    'cta_label' => __( 'Plan a team activity', 'talenttrack' ),
                    'cta_url'   => add_query_arg(
                        [ 'tt_view' => 'activities', 'action' => 'new', 'team_id' => $team_id ],
                        RecordLink::dashboardUrl()
                    ),
                    'cta_cap'   => 'tt_edit_activities',
                ] );
            } else {
                EmptyStateCard::render( [
                    'icon'      => 'activities',
                    'headline'  => __( 'No attended activities yet', 'talenttrack' ),
                    'explainer' => __( 'Activities are recorded at the team level. Assign this player to a team first; activities planned for that team will then surface here.', 'talenttrack' ),
                ] );
            }
            return;
        }
        $list_url = add_query_arg( [ 'tt_view' => 'activities' ], RecordLink::dashboardUrl() );
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title">
                    <?php
                    /* translators: %d: number of recent activities */
                    echo esc_html( sprintf( __( 'Recent · %d', 'talenttrack' ), count( $rows ) ) );
                    ?>
                </h3>
                <a class="tt-player-card__cta" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'View all', 'talenttrack' ); ?></a>
            </div>
            <ul class="tt-player-list">
                <?php
                $today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
                foreach ( $rows as $a ) :
                    $url        = RecordLink::detailUrlForWithBack( 'activities', (int) $a->id );
                    $date_bit   = self::dateBadge( (string) ( $a->session_date ?? '' ) );
                    $is_today   = (string) ( $a->session_date ?? '' ) === $today;
                    $is_planned = in_array( (string) ( $a->plan_state ?? '' ), [ 'planned', 'scheduled' ], true );
                    $status_key = $is_planned ? 'planned' : (string) ( $a->status ?? '' );
                    $status_lbl = $is_planned
                        ? __( 'Planned', 'talenttrack' )
                        : ( ! empty( $a->status ) ? LookupTranslator::byTypeAndName( 'attendance_status', (string) $a->status ) : '' );
                    $date_cls   = $is_today ? 'tt-player-row__date--today' : '';
                    ?>
                    <li>
                        <a class="tt-player-row" href="<?php echo esc_url( $url ); ?>">
                            <div class="tt-player-row__date <?php echo esc_attr( $date_cls ); ?>">
                                <span class="tt-player-row__date-m"><?php echo esc_html( $date_bit['m'] ); ?></span>
                                <span class="tt-player-row__date-d"><?php echo esc_html( $date_bit['d'] ); ?></span>
                            </div>
                            <div class="tt-player-row__body">
                                <p class="tt-player-row__title"><?php echo esc_html( (string) ( $a->title ?? '' ) ); ?></p>
                                <p class="tt-player-row__meta"><?php echo esc_html( (string) ( $a->session_date ?? '' ) ); ?></p>
                            </div>
                            <?php if ( $status_lbl !== '' ) : ?>
                                <span class="tt-player-row__pill" data-status="<?php echo esc_attr( $status_key ); ?>">
                                    <?php echo esc_html( $status_lbl ); ?>
                                </span>
                            <?php else : ?>
                                <span class="tt-player-row__chev" aria-hidden="true">›</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /** PDP tab — active cycle with 4-step progress bar + past cycles list. */
    private static function renderPdpTab( int $player_id ): void {
        // #1107 — defense in depth. See renderEvaluationsTab note.
        if ( ! MatrixGate::canAnyScope( get_current_user_id(), 'pdp_file', MatrixGate::READ ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view PDP files for this player.', 'talenttrack' ) . '</p>';
            return;
        }
        global $wpdb;
        $files = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, season_id, created_at FROM {$wpdb->prefix}tt_pdp_files
              WHERE player_id = %d ORDER BY created_at DESC LIMIT 10",
            $player_id
        ) );
        if ( empty( $files ) ) {
            EmptyStateCard::render( [
                'icon'      => 'pdp',
                'headline'  => __( 'No PDP cycle yet for this player', 'talenttrack' ),
                'explainer' => __( 'A Personal Development Plan documents what the player is focusing on, agreed with parents and the coach. Open one to start the cycle.', 'talenttrack' ),
                'cta_label' => __( 'Start PDP cycle', 'talenttrack' ),
                'cta_url'   => add_query_arg(
                    [ 'tt_view' => 'pdp', 'action' => 'new', 'player_id' => $player_id ],
                    RecordLink::dashboardUrl()
                ),
                'cta_cap'   => 'tt_edit_pdp',
            ] );
            return;
        }

        $active = $files[0];
        $past   = array_slice( $files, 1 );

        // Map PDP status → 4-step progress (kickoff, mid-cycle,
        // end-of-cycle, signoff). The lookup vocabulary has evolved
        // across migrations so we recognise the common keys and fall
        // back to "kickoff done" for anything unrecognised.
        $progress = self::pdpProgress( (string) ( $active->status ?? '' ) );
        $active_url = add_query_arg(
            [ 'tt_view' => 'pdp', 'id' => (int) $active->id ],
            RecordLink::dashboardUrl()
        );
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title">
                    <?php esc_html_e( 'Active cycle', 'talenttrack' ); ?>
                </h3>
                <a class="tt-player-card__cta" href="<?php echo esc_url( $active_url ); ?>"><?php esc_html_e( 'Open file', 'talenttrack' ); ?></a>
            </div>
            <div class="tt-player-card__body">
                <p style="margin:0; font-weight:600;">
                    <?php echo esc_html( LookupTranslator::byTypeAndName( 'pdp_status', (string) ( $active->status ?? '' ) ) ); ?>
                </p>
                <div class="tt-player-pdp-progress" aria-label="<?php esc_attr_e( 'PDP progress', 'talenttrack' ); ?>">
                    <?php foreach ( $progress as $cls ) : ?>
                        <div class="tt-player-pdp-step <?php echo esc_attr( $cls ); ?>"></div>
                    <?php endforeach; ?>
                </div>
                <?php if ( ! empty( $active->created_at ) ) : ?>
                    <p class="tt-player-row__meta" style="margin-top:8px;">
                        <?php
                        /* translators: %s: ISO date the PDP cycle was created */
                        echo esc_html( sprintf( __( 'Created %s', 'talenttrack' ), (string) $active->created_at ) );
                        ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( ! empty( $past ) ) : ?>
            <div class="tt-player-card">
                <div class="tt-player-card__head">
                    <h3 class="tt-player-card__title">
                        <?php
                        /* translators: %d: number of past PDP cycles */
                        echo esc_html( sprintf( __( 'Past cycles · %d', 'talenttrack' ), count( $past ) ) );
                        ?>
                    </h3>
                </div>
                <ul class="tt-player-list">
                    <?php foreach ( $past as $f ) :
                        $url  = add_query_arg( [ 'tt_view' => 'pdp', 'id' => (int) $f->id ], RecordLink::dashboardUrl() );
                        $date_bit = self::dateBadge( (string) ( $f->created_at ?? '' ) );
                        ?>
                        <li>
                            <a class="tt-player-row" href="<?php echo esc_url( $url ); ?>">
                                <div class="tt-player-row__date">
                                    <span class="tt-player-row__date-m"><?php echo esc_html( $date_bit['m'] ); ?></span>
                                    <span class="tt-player-row__date-d"><?php echo esc_html( $date_bit['d'] ); ?></span>
                                </div>
                                <div class="tt-player-row__body">
                                    <p class="tt-player-row__title">
                                        <?php echo esc_html( LookupTranslator::byTypeAndName( 'pdp_status', (string) ( $f->status ?? '' ) ) ); ?>
                                    </p>
                                    <p class="tt-player-row__meta"><?php echo esc_html( (string) ( $f->created_at ?? '' ) ); ?></p>
                                </div>
                                <span class="tt-player-row__chev" aria-hidden="true">›</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Translate a PDP `status` key into a 4-step progress bitmask.
     * Returns the CSS class for each of the 4 steps (kickoff,
     * mid-cycle, end-of-cycle, signoff).
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private static function pdpProgress( string $status ): array {
        switch ( $status ) {
            case 'closed':
            case 'completed':
            case 'signed_off':
                return [
                    'tt-player-pdp-step--done',
                    'tt-player-pdp-step--done',
                    'tt-player-pdp-step--done',
                    'tt-player-pdp-step--done',
                ];
            case 'end_of_cycle':
            case 'end-of-cycle':
                return [
                    'tt-player-pdp-step--done',
                    'tt-player-pdp-step--done',
                    'tt-player-pdp-step--current',
                    '',
                ];
            case 'mid_cycle':
            case 'mid-cycle':
            case 'in_progress':
                return [
                    'tt-player-pdp-step--done',
                    'tt-player-pdp-step--current',
                    '',
                    '',
                ];
            case 'kickoff':
            case 'opened':
            case 'draft':
            default:
                return [
                    'tt-player-pdp-step--current',
                    '',
                    '',
                    '',
                ];
        }
    }

    /** Trials tab — every trial case the player has been part of. */
    private static function renderTrialsTab( int $player_id, $player = null ): void {
        // #1107 — defense in depth. See renderEvaluationsTab note.
        if ( ! MatrixGate::canAnyScope( get_current_user_id(), 'trial_cases', MatrixGate::READ ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view trial cases for this player.', 'talenttrack' ) . '</p>';
            return;
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, start_date, end_date FROM {$wpdb->prefix}tt_trial_cases
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY start_date DESC LIMIT 10",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            $is_trial_player = $player && isset( $player->status ) && (string) $player->status === PlayerStatus::TRIAL;
            $card = [
                'icon'      => 'trials',
                'headline'  => __( 'No trial history for this player', 'talenttrack' ),
                'explainer' => $is_trial_player
                    ? __( 'A trial case tracks the prospect from first training through to the academy decision. Open one when you want to evaluate this player formally.', 'talenttrack' )
                    : __( 'This player is not currently on trial, so there is no trial history to show.', 'talenttrack' ),
            ];
            if ( $is_trial_player ) {
                $card['cta_label'] = __( 'Open trial case', 'talenttrack' );
                $card['cta_url']   = add_query_arg(
                    [ 'tt_view' => 'trials', 'action' => 'new', 'player_id' => $player_id ],
                    RecordLink::dashboardUrl()
                );
                $card['cta_cap']   = 'tt_manage_trials';
            }
            EmptyStateCard::render( $card );
            return;
        }
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title">
                    <?php
                    /* translators: %d: number of trial cases */
                    echo esc_html( sprintf( __( 'Trial cases · %d', 'talenttrack' ), count( $rows ) ) );
                    ?>
                </h3>
            </div>
            <ul class="tt-player-list">
                <?php foreach ( $rows as $t ) :
                    $url      = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $t->id ], RecordLink::dashboardUrl() );
                    $date_bit = self::dateBadge( (string) ( $t->start_date ?? '' ) );
                    $status_key = (string) ( $t->status ?? '' );
                    $status_lbl = LookupTranslator::byTypeAndName( 'trial_case_status', $status_key );
                    $meta = trim(
                        ( ! empty( $t->start_date ) ? (string) $t->start_date : '' ) .
                        ( ! empty( $t->end_date )   ? ' → ' . (string) $t->end_date : '' )
                    );
                    ?>
                    <li>
                        <a class="tt-player-row" href="<?php echo esc_url( $url ); ?>">
                            <div class="tt-player-row__date">
                                <span class="tt-player-row__date-m"><?php echo esc_html( $date_bit['m'] ); ?></span>
                                <span class="tt-player-row__date-d"><?php echo esc_html( $date_bit['d'] ); ?></span>
                            </div>
                            <div class="tt-player-row__body">
                                <p class="tt-player-row__title"><?php echo esc_html( $status_lbl ); ?></p>
                                <p class="tt-player-row__meta"><?php echo esc_html( $meta ); ?></p>
                            </div>
                            <span class="tt-player-row__chev" aria-hidden="true">›</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Notes tab — staff-only running log via the Threads infrastructure.
     * Tab visibility is cap-gated at the `tt_view_player_notes` level
     * before the dispatcher reaches here; per-player scope is enforced
     * by `PlayerThreadAdapter::canRead` when FrontendThreadView renders.
     */
    private static function renderNotesTab( int $player_id, int $user_id ): void {
        if ( ! current_user_can( 'tt_view_player_notes' ) ) {
            EmptyStateCard::render( [
                'headline'  => __( 'Notes are staff-only', 'talenttrack' ),
                'explainer' => __( 'A running log of staff observations about this player. You don\'t have access — talk to your academy admin if you should.', 'talenttrack' ),
            ] );
            return;
        }

        $adapter = \TT\Modules\Threads\ThreadTypeRegistry::get( 'player' );
        if ( ! $adapter || ! $adapter->canRead( $user_id, $player_id ) ) {
            EmptyStateCard::render( [
                'headline'  => __( 'Not in scope for this player', 'talenttrack' ),
                'explainer' => __( 'You have notes access in general, but not for this player\'s team. Talk to your academy admin if you should.', 'talenttrack' ),
            ] );
            return;
        }
        ?>
        <div class="tt-player-card">
            <div class="tt-player-card__head">
                <h3 class="tt-player-card__title"><?php esc_html_e( 'Staff notes', 'talenttrack' ); ?></h3>
            </div>
            <div class="tt-player-card__body">
                <p style="margin: 0 0 12px; font-size: 13px; color: var(--tt-ink-soft);">
                    <?php esc_html_e( 'A running log staff use to share observations about this player. Notes are visible to staff only — never to the player or their parents.', 'talenttrack' ); ?>
                </p>
                <?php \TT\Shared\Frontend\Components\FrontendThreadView::render( 'player', $player_id, $user_id ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Format a date string into the row-badge tuple (month abbrev +
     * day-of-month). Empty input renders an em-dash placeholder so
     * the badge keeps its square footprint.
     *
     * @return array{m:string,d:string}
     */
    private static function dateBadge( string $iso ): array {
        $iso = trim( $iso );
        if ( $iso === '' ) return [ 'm' => '—', 'd' => '' ];
        $ts = strtotime( $iso );
        if ( $ts === false ) return [ 'm' => '—', 'd' => '' ];
        return [
            'm' => gmdate( 'M', $ts ),
            'd' => gmdate( 'j', $ts ),
        ];
    }

    /**
     * Format a goal due-date into the row-badge tuple, with a
     * `due-soon` class flag when the date is within the next 7 days.
     *
     * @return array{m:string,d:string,class:string}
     */
    private static function dueDateBadge( string $iso ): array {
        $iso = trim( $iso );
        if ( $iso === '' ) {
            return [ 'm' => __( 'Due', 'talenttrack' ), 'd' => '—', 'class' => '' ];
        }
        $ts = strtotime( $iso );
        if ( $ts === false ) {
            return [ 'm' => __( 'Due', 'talenttrack' ), 'd' => '—', 'class' => '' ];
        }
        $now      = current_time( 'timestamp' );
        $diff     = $ts - $now;
        $due_soon = $diff >= 0 && $diff <= 7 * DAY_IN_SECONDS;
        $cls      = $due_soon ? 'tt-player-row__date--due-soon' : '';
        return [
            'm'     => __( 'Due', 'talenttrack' ),
            'd'     => esc_html( gmdate( 'j', $ts ) ) . '<small>' . esc_html( gmdate( 'M', $ts ) ) . '</small>',
            'class' => $cls,
        ];
    }

    /**
     * #1089 VCT-14 — PHV (Physical / Health / Vitality) panel.
     *
     * Lives on the Profile tab. Coaches + HoD with `tt_edit_players`
     * see the form; everyone with `tt_view_players` sees a read-only
     * summary. The pill on the hero is emitted by `renderHero()` when
     * the row is active.
     *
     * Mockup design-of-record at `.local-mockups/vct-phv-flag/`.
     */
    private static function renderPhvPanel( int $player_id, ?array $phv_row, string $notice_html ): void {
        $can_edit  = current_user_can( 'tt_edit_players' );
        $is_active = $phv_row !== null && ! empty( $phv_row['is_active'] );
        $reason    = $phv_row !== null ? (string) $phv_row['reason_key']        : '';
        $ceiling   = $phv_row !== null ?         $phv_row['intensity_ceiling']  : null;
        $notes     = $phv_row !== null ? (string) $phv_row['notes']             : '';

        // Hide the panel entirely from non-staff viewers (parents) when
        // no flag is active — medical-adjacent metadata stays off-screen
        // unless there's something to surface.
        if ( ! $can_edit && ! $is_active ) {
            return;
        }

        // The mockup's reason picker uses a fixed Dutch enum; expose the
        // same labels with stable internal keys so they survive locale
        // changes + future i18n via `tt_lookups`.
        $reasons = [
            ''               => __( '— Pick a reason —', 'talenttrack' ),
            'injury_knee'    => __( 'Injury — knee', 'talenttrack' ),
            'injury_ankle'   => __( 'Injury — ankle', 'talenttrack' ),
            'asthma'         => __( 'Asthma', 'talenttrack' ),
            'cardiac'        => __( 'Cardiac condition', 'talenttrack' ),
            'other_medical'  => __( 'Other medical reason', 'talenttrack' ),
            'temp_fatigue'   => __( 'Temporary fatigue', 'talenttrack' ),
        ];
        $ceilings = [
            1 => __( '1 — recovery only', 'talenttrack' ),
            2 => __( '2 — low', 'talenttrack' ),
            3 => __( '3 — medium', 'talenttrack' ),
            4 => __( '4 — high', 'talenttrack' ),
        ];

        $cancel_url = add_query_arg(
            [ 'tt_view' => 'players', 'id' => $player_id, 'tab' => 'profile' ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );
        ?>
        <section class="tt-player-card tt-player-phv-panel<?php echo $is_active ? ' is-active' : ''; ?>">
            <div class="tt-player-card__head">
                <h3><?php esc_html_e( 'PHV — Physical / Health / Vitality', 'talenttrack' ); ?></h3>
            </div>
            <?php echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled markup from handleVctPhvPost(). ?>
            <?php if ( $can_edit ) : ?>
                <form class="tt-player-phv-panel__form" method="POST" action="">
                    <?php wp_nonce_field( 'tt_vct_phv_panel_' . $player_id, '_tt_vct_phv_panel_nonce' ); ?>
                    <input type="hidden" name="_tt_vct_phv_panel" value="1">

                    <label class="tt-player-phv-toggle">
                        <input type="checkbox" name="is_active" value="1"<?php checked( $is_active ); ?>>
                        <span class="tt-player-phv-toggle__label">
                            <?php esc_html_e( 'Player has a PHV flag', 'talenttrack' ); ?>
                            <span class="tt-player-phv-toggle__sub">
                                <?php esc_html_e( 'Automatically excluded from VCT blocks above the configured intensity ceiling.', 'talenttrack' ); ?>
                            </span>
                        </span>
                    </label>

                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-phv-reason-<?php echo (int) $player_id; ?>">
                            <?php esc_html_e( 'Reason (short, for the coach)', 'talenttrack' ); ?>
                        </label>
                        <select id="tt-phv-reason-<?php echo (int) $player_id; ?>" class="tt-input" name="reason_key">
                            <?php foreach ( $reasons as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $reason, $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="tt-field-hint">
                            <?php esc_html_e( 'Visible to staff on the coach view; not visible to other parents.', 'talenttrack' ); ?>
                        </p>
                    </div>

                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-phv-ceiling-<?php echo (int) $player_id; ?>">
                            <?php esc_html_e( 'Intensity ceiling (max intensity band)', 'talenttrack' ); ?>
                        </label>
                        <select id="tt-phv-ceiling-<?php echo (int) $player_id; ?>" class="tt-input" name="intensity_ceiling">
                            <option value=""><?php esc_html_e( '— None set —', 'talenttrack' ); ?></option>
                            <?php foreach ( $ceilings as $value => $label ) : ?>
                                <option value="<?php echo (int) $value; ?>"<?php selected( (int) $ceiling, (int) $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-phv-notes-<?php echo (int) $player_id; ?>">
                            <?php esc_html_e( 'Notes (optional)', 'talenttrack' ); ?>
                        </label>
                        <textarea id="tt-phv-notes-<?php echo (int) $player_id; ?>" class="tt-input" name="notes" rows="3" placeholder="<?php esc_attr_e( 'e.g. Cleared for level 4 from 1 July — physio checked.', 'talenttrack' ); ?>"><?php echo esc_textarea( $notes ); ?></textarea>
                    </div>

                    <?php
                    echo \TT\Shared\Frontend\Components\FormSaveButton::render( [
                        'label'      => __( 'Save PHV flag', 'talenttrack' ),
                        'cancel_url' => $cancel_url,
                    ] );
                    ?>
                </form>
            <?php else : ?>
                <dl class="tt-player-phv-summary">
                    <dt><?php esc_html_e( 'Status', 'talenttrack' ); ?></dt>
                    <dd><?php echo $is_active ? esc_html__( 'Active', 'talenttrack' ) : esc_html__( 'Cleared', 'talenttrack' ); ?></dd>
                    <?php if ( $reason !== '' && isset( $reasons[ $reason ] ) ) : ?>
                        <dt><?php esc_html_e( 'Reason', 'talenttrack' ); ?></dt>
                        <dd><?php echo esc_html( $reasons[ $reason ] ); ?></dd>
                    <?php endif; ?>
                    <?php if ( $ceiling !== null && isset( $ceilings[ (int) $ceiling ] ) ) : ?>
                        <dt><?php esc_html_e( 'Intensity ceiling', 'talenttrack' ); ?></dt>
                        <dd><?php echo esc_html( $ceilings[ (int) $ceiling ] ); ?></dd>
                    <?php endif; ?>
                    <?php if ( $notes !== '' ) : ?>
                        <dt><?php esc_html_e( 'Notes', 'talenttrack' ); ?></dt>
                        <dd><?php echo esc_html( $notes ); ?></dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
            <p class="tt-player-phv-panel__surfaces">
                <strong><?php esc_html_e( 'Where the PHV flag appears:', 'talenttrack' ); ?></strong>
                <?php esc_html_e( 'Hero pill next to the name · VCT session wizard workload check · coach-view sideline banner · match prep per-player attention.', 'talenttrack' ); ?>
            </p>
        </section>
        <style>
        .tt-player-phv-pill { background: #c75c1f; color: #fff; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.4px; margin-left: 8px; vertical-align: middle; }
        .tt-player-phv-panel { margin-top: 16px; border-color: #c75c1f33; }
        .tt-player-phv-panel.is-active { border-color: #c75c1f; }
        .tt-player-phv-panel .tt-player-card__head h3 { color: #c75c1f; text-transform: uppercase; letter-spacing: 0.4px; font-size: 14px; font-weight: 800; }
        .tt-player-phv-toggle { display: flex; align-items: center; gap: 12px; padding: 12px 0; cursor: pointer; }
        .tt-player-phv-toggle input { width: 22px; height: 22px; accent-color: #c75c1f; cursor: pointer; }
        .tt-player-phv-toggle__label { font-weight: 600; }
        .tt-player-phv-toggle__sub { display: block; font-size: 12px; color: var(--tt-muted, #5b6e75); font-weight: 400; margin-top: 2px; }
        .tt-player-phv-panel .tt-field { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--tt-line, #d6dadd); }
        .tt-player-phv-panel__surfaces { background: var(--tt-mute, #f0f3f2); padding: 10px 12px; border-radius: 6px; font-size: 12px; color: var(--tt-muted, #5b6e75); margin-top: 16px; }
        .tt-player-phv-panel__surfaces strong { display: block; color: var(--tt-ink, #1a1d21); margin-bottom: 2px; }
        .tt-player-phv-summary { display: grid; grid-template-columns: 1fr 2fr; gap: 6px 12px; margin: 8px 0; }
        .tt-player-phv-summary dt { font-weight: 600; color: var(--tt-muted, #5b6e75); }
        .tt-player-phv-summary dd { margin: 0; }
        </style>
        <?php
    }

    /**
     * #1089 VCT-14 — POST handler for the PHV panel form. Returns
     * a notice HTML fragment (empty when the request isn't ours).
     */
    private static function handleVctPhvPost( int $player_id, int $user_id ): string {
        if ( ! current_user_can( 'tt_edit_players' ) ) {
            return '<div class="tt-notice tt-notice--error">' . esc_html__( 'You do not have permission to edit PHV flags.', 'talenttrack' ) . '</div>';
        }
        $nonce = isset( $_POST['_tt_vct_phv_panel_nonce'] ) ? (string) $_POST['_tt_vct_phv_panel_nonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'tt_vct_phv_panel_' . $player_id ) ) {
            return '<div class="tt-notice tt-notice--error">' . esc_html__( 'Save failed: session expired. Reload and try again.', 'talenttrack' ) . '</div>';
        }
        $is_active = ! empty( $_POST['is_active'] );
        $reason    = isset( $_POST['reason_key'] ) ? sanitize_key( (string) $_POST['reason_key'] ) : '';
        // Restrict reason to the known enum to avoid arbitrary strings
        // landing in the column.
        $valid_reasons = [ 'injury_knee', 'injury_ankle', 'asthma', 'cardiac', 'other_medical', 'temp_fatigue' ];
        if ( $reason !== '' && ! in_array( $reason, $valid_reasons, true ) ) {
            $reason = '';
        }
        $ceiling_raw = isset( $_POST['intensity_ceiling'] ) ? trim( (string) $_POST['intensity_ceiling'] ) : '';
        $ceiling     = $ceiling_raw === '' ? null : max( 1, min( 10, (int) $ceiling_raw ) );
        $notes       = isset( $_POST['notes'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['notes'] ) ) : '';

        $ok = ( new \TT\Modules\Vct\Repositories\VctPhvFlagsRepository() )->setFlag(
            $player_id,
            $is_active,
            $user_id,
            $notes,
            $reason,
            $ceiling
        );
        return $ok
            ? '<div class="tt-notice tt-notice--success">' . esc_html__( 'PHV flag saved.', 'talenttrack' ) . '</div>'
            : '<div class="tt-notice tt-notice--error">' . esc_html__( 'Save failed: database error.', 'talenttrack' ) . '</div>';
    }
}
