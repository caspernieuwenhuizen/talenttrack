<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupPill;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\AgeTier;

/**
 * FrontendPlayerDetailView — read-only display of a single player
 * (#0063), reachable via `?tt_view=players&id=N`.
 *
 * Mirrors the v3.62 per-record-detail precedent established by
 * FrontendMyGoalsView::renderDetail and FrontendMyActivitiesView::renderDetail
 * — the manage view's render() method early-branches into here when
 * `?id=N` is present.
 *
 * Cap-gated on `tt_view_players` (existing). The list-side pages
 * already gate at that level; this view re-asserts inside the render
 * so direct URL access also enforces.
 *
 * Composition only: every datum comes from existing repositories /
 * QueryHelpers — no business logic in the render. Per CLAUDE.md § 4
 * SaaS-readiness rule.
 */
final class FrontendPlayerDetailView extends FrontendViewBase {

    /**
     * #0077 M8 — tabbed case page. Profile is default; other tabs swap
     * via `?tab=goals|evaluations|activities|pdp|trials`.
     *
     * @return array<string,string>  tab key => human label
     */
    private static function tabs(): array {
        return [
            'profile'     => __( 'Profile', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'evaluations' => __( 'Evaluations', 'talenttrack' ),
            'activities'  => __( 'Activities', 'talenttrack' ),
            'pdp'         => __( 'PDP cycle', 'talenttrack' ),
            'trials'      => __( 'Trials', 'talenttrack' ),
        ];
    }

    public static function render( int $player_id, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_players' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view player details.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( $player_id );

        if ( ! $player ) {
            FrontendBackButton::render( remove_query_arg( [ 'id' ] ), __( '← Back to players', 'talenttrack' ) );
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That player is no longer available, or you do not have access.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        $name = QueryHelpers::player_display_name( $player );

        // #0077 F2 — breadcrumb chain replaces the standalone back link.
        $players_url = add_query_arg( [ 'tt_view' => 'players' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( [
            [ 'label' => __( 'Dashboard', 'talenttrack' ), 'url' => \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() ],
            [ 'label' => __( 'Players', 'talenttrack' ),   'url' => $players_url ],
            [ 'label' => $name ],
        ] );
        // v3.92.0 — page title is now "Player file of {name}" instead
        // of just the name. The hero card inside the article already
        // surfaces the name as `<h2>`; the page title carries the
        // contextual "this is a player file about ..." framing.
        /* translators: %s: player display name */
        $page_title = sprintf( __( 'Player file of %s', 'talenttrack' ), $name );
        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">' . esc_html( $page_title ) . '</h1>';

        $team       = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $team_url   = $team ? add_query_arg( [ 'tt_view' => 'teams', 'id' => (int) $team->id ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() ) : '';
        $photo      = (string) ( $player->photo_url ?? '' );

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'profile';
        if ( ! array_key_exists( $active_tab, self::tabs() ) ) $active_tab = 'profile';
        $base_url = add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        ?>
        <article class="tt-player-detail">
            <header class="tt-player-detail-hero">
                <?php if ( $photo !== '' ) : ?>
                    <img class="tt-player-detail-photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
                <?php endif; ?>
                <div class="tt-player-detail-meta">
                    <?php /* v3.92.0 — name is in the page title above and the hero card surfaces it. Drop the redundant duplicate <h2>. The team line + age tier badge on the meta column already anchors the hero visually; the photo + name role is carried by the page <h1>. */ ?>
                    <?php if ( $team ) : ?>
                        <p class="tt-player-detail-team">
                            <?php esc_html_e( 'Team:', 'talenttrack' ); ?>
                            <a class="tt-record-link" href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( (string) $team->name ); ?></a>
                            <?php if ( ! empty( $team->age_group ) ) : ?>
                                <span class="tt-muted"> &middot; <?php echo esc_html( (string) $team->age_group ); ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </header>

            <nav class="tt-player-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Player sections', 'talenttrack' ); ?>">
                <?php foreach ( self::tabs() as $key => $label ) :
                    $url = add_query_arg( [ 'tab' => $key ], $base_url );
                    $is_active = $key === $active_tab;
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="tt-player-tab<?php echo $is_active ? ' tt-player-tab--active' : ''; ?>"
                       role="tab"
                       aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="tt-player-tab-panel">
                <?php
                switch ( $active_tab ) {
                    case 'goals':       self::renderGoalsTab( $player_id ); break;
                    case 'evaluations': self::renderEvaluationsTab( $player_id ); break;
                    case 'activities':  self::renderActivitiesTab( $player_id ); break;
                    case 'pdp':         self::renderPdpTab( $player_id ); break;
                    case 'trials':      self::renderTrialsTab( $player_id ); break;
                    case 'profile':
                    default:            self::renderProfileTab( $player ); break;
                }
                ?>
            </section>
        </article>

        <style>
            .tt-player-detail-hero { display: flex; gap: 14px; align-items: center; margin: 8px 0 18px; }
            .tt-player-detail-photo { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 2px solid var(--tt-line, #e5e7ea); }
            .tt-player-detail-name { margin: 0 0 4px; font-size: 1.5rem; }
            .tt-player-detail-team { margin: 0; color: #5b6e75; font-size: 0.9rem; }
            .tt-player-detail .tt-muted { color: #5b6e75; }
            /* #0077 M8 — tabbed case page nav */
            .tt-player-tabs { display: flex; flex-wrap: wrap; gap: 0; border-bottom: 1px solid var(--tt-line, #e5e7ea); margin: 0 0 16px; }
            .tt-player-tab { padding: 10px 16px; min-height: 48px; display: inline-flex; align-items: center; color: #5b6e75; text-decoration: none; border-bottom: 2px solid transparent; font-weight: 500; }
            .tt-player-tab:hover, .tt-player-tab:focus { color: #0b3d2e; outline: none; }
            .tt-player-tab--active { color: #0b3d2e; border-bottom-color: #0b3d2e; }
        </style>
        <?php
    }

    /**
     * Profile tab — DL + behaviour/potential capture entry.
     */
    private static function renderProfileTab( object $player ): void {
        $player_id  = (int) $player->id;
        $age_tier   = AgeTier::forPlayer( $player_id );
        $tier_label = AgeTier::labels()[ $age_tier ] ?? '';
        $positions  = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
        ?>
        <dl class="tt-profile-dl">
            <?php /* v3.92.0 — field order rearranged. DOB and position
                     are the most asked-about facts on a player file;
                     age tier is a derived convenience and lands last. */ ?>
            <?php if ( ! empty( $player->date_of_birth ) ) : ?>
                <dt><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( (string) $player->date_of_birth ); ?></dd>
            <?php endif; ?>
            <?php if ( is_array( $positions ) && ! empty( $positions ) ) : ?>
                <dt><?php esc_html_e( 'Position(s)', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( implode( ', ', array_map( 'strval', $positions ) ) ); ?></dd>
            <?php endif; ?>
            <?php if ( ! empty( $player->preferred_foot ) ) : ?>
                <dt><?php esc_html_e( 'Preferred foot', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( (string) $player->preferred_foot ); ?></dd>
            <?php endif; ?>
            <?php if ( ! empty( $player->jersey_number ) ) : ?>
                <dt><?php esc_html_e( 'Jersey number', 'talenttrack' ); ?></dt>
                <dd>#<?php echo (int) $player->jersey_number; ?></dd>
            <?php endif; ?>
            <?php if ( ! empty( $player->status ) ) : ?>
                <dt><?php esc_html_e( 'Status', 'talenttrack' ); ?></dt>
                <dd><?php echo \TT\Infrastructure\Query\LabelTranslator::playerStatus( (string) $player->status ); ?></dd>
            <?php endif; ?>
            <?php if ( $tier_label !== '' ) : ?>
                <dt><?php esc_html_e( 'Age tier', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( $tier_label ); ?></dd>
            <?php endif; ?>
        </dl>

        <?php
        if ( current_user_can( 'tt_edit_player_status' ) ) {
            self::renderBehaviourPotentialForm( $player_id );
        }
    }

    /** Goals tab — paged-list-aware "all goals for this player". */
    private static function renderGoalsTab( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, due_date FROM {$wpdb->prefix}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY created_at DESC LIMIT 50",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No goals recorded yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $g ) {
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'goals', (int) $g->id );
            echo '<li>';
            echo '<a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) $g->title ) . '</strong> &middot; ';
            echo LookupPill::render( 'goal_status', (string) $g->status );
            if ( ! empty( $g->due_date ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $g->due_date ) . '</span>';
            }
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /** Evaluations tab — every evaluation, linkified (was a flat date list). */
    private static function renderEvaluationsTab( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.eval_date, et.name AS type_name
               FROM {$wpdb->prefix}tt_evaluations e
          LEFT JOIN {$wpdb->prefix}tt_eval_types et ON et.id = e.eval_type_id
              WHERE e.player_id = %d AND e.archived_at IS NULL
              ORDER BY e.eval_date DESC LIMIT 50",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No evaluations recorded yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $ev ) {
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'evaluations', (int) $ev->id );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) ( $ev->eval_date ?? '—' ) ) . '</strong>';
            if ( ! empty( $ev->type_name ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $ev->type_name ) . '</span>';
            }
            echo '</a></li>';
        }
        echo '</ul>';
    }

    /** Activities tab — recent activities the player attended. */
    private static function renderActivitiesTab( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.activity_type_key, att.status
               FROM {$wpdb->prefix}tt_attendance att
               JOIN {$wpdb->prefix}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d AND att.is_guest = 0 AND a.archived_at IS NULL
              ORDER BY a.session_date DESC LIMIT 25",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No attended activities yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $a ) {
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'activities', (int) $a->id );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) ( $a->title ?? '—' ) ) . '</strong>';
            if ( ! empty( $a->session_date ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $a->session_date ) . '</span>';
            }
            if ( ! empty( $a->status ) ) {
                echo ' &middot; ' . LookupPill::render( 'attendance_status', (string) $a->status );
            }
            echo '</a></li>';
        }
        echo '</ul>';
    }

    /** PDP tab — files + recent conversations. */
    private static function renderPdpTab( int $player_id ): void {
        global $wpdb;
        $files = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, season_id, created_at FROM {$wpdb->prefix}tt_pdp_files
              WHERE player_id = %d ORDER BY created_at DESC LIMIT 10",
            $player_id
        ) );
        if ( empty( $files ) ) {
            echo '<p><em>' . esc_html__( 'No PDP cycle yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        echo '<h3>' . esc_html__( 'PDP files', 'talenttrack' ) . '</h3><ul class="tt-stack">';
        foreach ( $files as $f ) {
            echo '<li><strong>' . esc_html( (string) $f->status ) . '</strong>';
            echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $f->created_at ) . '</span></li>';
        }
        echo '</ul>';
    }

    /** Trials tab — every trial case the player has been part of. */
    private static function renderTrialsTab( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, start_date, end_date FROM {$wpdb->prefix}tt_trial_cases
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY start_date DESC LIMIT 10",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No trial history.', 'talenttrack' ) . '</em></p>';
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $t ) {
            $url = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $t->id ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) $t->status ) . '</strong>';
            if ( ! empty( $t->start_date ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $t->start_date );
                if ( ! empty( $t->end_date ) ) echo ' → ' . esc_html( (string) $t->end_date );
                echo '</span>';
            }
            echo '</a></li>';
        }
        echo '</ul>';
    }

    /**
     * Sprint 3 — capture surface for `behaviour` + `potential` ratings.
     * Stub: registers the form skeleton; full save handler wires in
     * Sprint 3 alongside the >100% inline-warning.
     */
    private static function renderBehaviourPotentialForm( int $player_id ): void {
        ?>
        <section class="tt-pde-section">
            <h3><?php esc_html_e( 'Behaviour &amp; potential', 'talenttrack' ); ?></h3>
            <p class="tt-muted" style="font-size:13px; margin: 0 0 8px;">
                <?php esc_html_e( 'Quick capture for the player status calculation. Edit weights and thresholds in Configuration → Player status methodology.', 'talenttrack' ); ?>
            </p>
            <p>
                <?php
                $url = add_query_arg(
                    [ 'tt_view' => 'player-status-capture', 'player_id' => $player_id ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                );
                ?>
                <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $url ); ?>">
                    <?php esc_html_e( 'Capture behaviour and potential', 'talenttrack' ); ?>
                </a>
            </p>
        </section>
        <?php
    }

}
