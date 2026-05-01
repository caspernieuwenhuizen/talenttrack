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

    public static function render( int $player_id, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_players' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view player details.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( $player_id );
        $back_url = remove_query_arg( [ 'id' ] );
        FrontendBackButton::render( $back_url );

        if ( ! $player ) {
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That player is no longer available, or you do not have access.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        $name = QueryHelpers::player_display_name( $player );
        self::renderHeader( $name );

        $team       = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $team_url   = $team ? add_query_arg( [ 'tt_view' => 'teams', 'id' => (int) $team->id ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() ) : '';
        $age_tier   = AgeTier::forPlayer( $player_id );
        $tier_label = AgeTier::labels()[ $age_tier ] ?? '';
        $positions  = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
        $photo      = (string) ( $player->photo_url ?? '' );
        ?>
        <article class="tt-player-detail">
            <header class="tt-player-detail-hero">
                <?php if ( $photo !== '' ) : ?>
                    <img class="tt-player-detail-photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
                <?php endif; ?>
                <div class="tt-player-detail-meta">
                    <h2 class="tt-player-detail-name"><?php echo esc_html( $name ); ?></h2>
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

            <dl class="tt-profile-dl">
                <?php if ( $tier_label !== '' ) : ?>
                    <dt><?php esc_html_e( 'Age tier', 'talenttrack' ); ?></dt>
                    <dd><?php echo esc_html( $tier_label ); ?></dd>
                <?php endif; ?>
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
            </dl>

            <?php
            // #0063 Sprint 3 — behaviour + potential capture form. Lives
            // here on the player detail so coaches have one consolidated
            // entry point instead of two scattered surfaces.
            if ( current_user_can( 'tt_edit_player_status' ) ) {
                self::renderBehaviourPotentialForm( $player_id );
            }

            // Active goals + recent evaluations are linked through to
            // their own detail surfaces via RecordLink — closes the
            // "every record should be drillable" complaint.
            self::renderActiveGoals( $player_id );
            self::renderRecentEvaluations( $player_id );
            ?>
        </article>

        <style>
            .tt-player-detail-hero { display: flex; gap: 14px; align-items: center; margin: 8px 0 18px; }
            .tt-player-detail-photo { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 2px solid var(--tt-line, #e5e7ea); }
            .tt-player-detail-name { margin: 0 0 4px; font-size: 1.5rem; }
            .tt-player-detail-team { margin: 0; color: #5b6e75; font-size: 0.9rem; }
            .tt-player-detail .tt-muted { color: #5b6e75; }
        </style>
        <?php
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

    private static function renderActiveGoals( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status FROM {$wpdb->prefix}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY created_at DESC LIMIT 5",
            $player_id
        ) );
        if ( empty( $rows ) ) return;

        echo '<section class="tt-pde-section">';
        echo '<h3>' . esc_html__( 'Recent goals', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-stack">';
        foreach ( $rows as $g ) {
            // v3.70.1 hotfix — use generic `goals` slug + dashboard URL.
            $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'goals', (int) $g->id );
            echo '<li>';
            echo '<a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) $g->title ) . '</strong> &middot; ';
            echo LookupPill::render( 'goal_status', (string) $g->status );
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    private static function renderRecentEvaluations( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date FROM {$wpdb->prefix}tt_evaluations
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY eval_date DESC LIMIT 5",
            $player_id
        ) );
        if ( empty( $rows ) ) return;

        echo '<section class="tt-pde-section">';
        echo '<h3>' . esc_html__( 'Recent evaluations', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-stack">';
        foreach ( $rows as $ev ) {
            echo '<li>' . esc_html( (string) $ev->eval_date ) . '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }
}
