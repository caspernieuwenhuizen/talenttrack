<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * FrontendMyGoalsView — the "My goals" tile destination.
 *
 * v3.0.0 slice 3 listed goals; #0061 follow-up wraps each row in a
 * link to its detail view (`?tt_view=my-goals&id=N`) so users can
 * drill into the full description, due date, and status history
 * without leaving the player surface.
 */
class FrontendMyGoalsView extends FrontendViewBase {

    public static function render( object $player ): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $id > 0 ) {
            // v3.110.46 — migrated from fromDashboardWithBack() (referer-
            // based back crumb) to plain fromDashboard(). The
            // tt_back-borne pill is now the canonical "back to where I
            // came from" affordance per docs/back-navigation.md, and
            // FrontendBreadcrumbs::render() auto-renders it above the
            // chain when the entry URL captured a back-target.
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Goal detail', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'my-goals', __( 'My goals', 'talenttrack' ) ) ]
            );
            self::renderDetail( $player, $id );
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My goals', 'talenttrack' ) );
        self::renderHeader( __( 'My goals', 'talenttrack' ) );

        // #1077 — was inline SQL + per-row LabelTranslator calls in
        // the loop below. GoalsRepository centralises the read +
        // pre-localises `status_localised` / `priority_localised` so
        // the view echoes the translated field by construction. Same
        // shape as #1081 EvaluationsRepository / #806 worked example.
        $goals = ( new GoalsRepository() )->listForPlayer( (int) $player->id );

        if ( empty( $goals ) ) {
            echo '<p><em>' . esc_html__( 'No goals assigned yet. Your coaches will add development goals here as you progress.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $base         = remove_query_arg( [ 'id' ] );
        $threads_repo = class_exists( ThreadMessagesRepository::class ) ? new ThreadMessagesRepository() : null;
        ?>
        <div class="tt-goals-list">
            <?php foreach ( $goals as $g ) :
                $detail_url = add_query_arg( 'id', (int) $g->id, $base );
                $msg_count  = 0;
                if ( $threads_repo !== null ) {
                    // Count public + player-readable messages so the
                    // CTA reflects what the player can actually see.
                    $msgs = $threads_repo->listForThread( 'goal', (int) $g->id, false );
                    $msg_count = is_array( $msgs ) ? count( $msgs ) : 0;
                }
                ?>
                <a class="tt-goal-item tt-status-<?php echo esc_attr( (string) $g->status ); ?> tt-record-link"
                   href="<?php echo esc_url( $detail_url ); ?>">
                    <h4><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( (string) $g->title ) ); ?></h4>
                    <?php if ( ! empty( $g->description ) ) : ?>
                        <p><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( (string) $g->description ) ); ?></p>
                    <?php endif; ?>
                    <span class="tt-status-badge"><?php echo esc_html( (string) $g->status_localised ); ?></span>
                    <?php if ( ! empty( $g->due_date ) ) : ?>
                        <small><?php esc_html_e( 'Due:', 'talenttrack' ); ?> <?php echo esc_html( (string) $g->due_date ); ?></small>
                    <?php endif; ?>
                    <p class="tt-goal-conversation-cta">
                        <span aria-hidden="true">💬</span>
                        <?php if ( $msg_count > 0 ) : ?>
                            <?php
                            /* translators: %d is the number of messages on this goal's conversation thread. */
                            printf( esc_html( _n( 'Open conversation (%d message)', 'Open conversation (%d messages)', $msg_count, 'talenttrack' ) ), $msg_count );
                            ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Open goal &amp; start the conversation', 'talenttrack' ); ?>
                        <?php endif; ?>
                        →
                    </p>
                </a>
            <?php endforeach; ?>
        </div>
        <style>
            /* .tt-record-link styling now lives in assets/css/public.css (#0063). */
            .tt-goal-conversation-cta { margin: 10px 0 0; font-size: 13px; color: var(--tt-primary, #0b3d2e); }
        </style>
        <?php
    }

    /**
     * Single-goal detail view, reachable via `?tt_view=my-goals&id=N`.
     * Back button returns to the goals list. Renders the goal title,
     * full description, status, priority, due date, and the
     * conversation thread (#0028) inline so the player can read coach
     * comments without losing context.
     */
    private static function renderDetail( object $player, int $goal_id ): void {
        // #1077 — same repository pattern as the list path above.
        // findForPlayer() returns null if the goal doesn't exist or
        // doesn't belong to this player, replacing the inline
        // SQL + per-row LabelTranslator calls.
        $goal = ( new GoalsRepository() )->findForPlayer( $goal_id, (int) $player->id );

        if ( ! $goal ) {
            self::renderHeader( __( 'Goal not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That goal is no longer available, or it does not belong to your record.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( (string) \TT\Modules\Translations\TranslationLayer::render( (string) $goal->title ) );

        $status   = (string) $goal->status;
        $priority = (string) ( $goal->priority ?? '' );
        ?>
        <article class="tt-record-detail tt-goal-detail tt-status-<?php echo esc_attr( $status ); ?>">
            <p class="tt-record-detail-meta tt-goal-detail-meta">
                <span class="tt-status-badge"><?php echo esc_html( (string) $goal->status_localised ); ?></span>
                <?php if ( $priority !== '' ) : ?>
                    <span class="tt-priority-badge"><?php echo esc_html( (string) $goal->priority_localised ); ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $goal->due_date ) ) : ?>
                    <span class="tt-due"><?php esc_html_e( 'Due:', 'talenttrack' ); ?> <?php echo esc_html( (string) $goal->due_date ); ?></span>
                <?php endif; ?>
            </p>
            <?php if ( ! empty( $goal->description ) ) : ?>
                <div class="tt-record-detail-body tt-goal-detail-body">
                    <?php echo wp_kses_post( wpautop( \TT\Modules\Translations\TranslationLayer::render( (string) $goal->description ) ) ); ?>
                </div>
            <?php endif; ?>
        </article>

        <?php
        // #0028 conversation thread on the goal — coaches and the
        // player + parent see chat-style messages without leaving
        // this surface.
        if ( class_exists( '\TT\Shared\Frontend\Components\FrontendThreadView' ) ) {
            // v3.92.2 — was a target=_blank anchor opening the docs page
            // in a new tab; pilot operator wanted the right-side help
            // drawer instead. HelpDrawer::button outputs the
            // [data-tt-docs-drawer-open] hook docs-drawer.js listens for
            // (drawer DOM + JS already shipped in #0016 Part B).
            echo '<header style="display:flex; align-items:baseline; gap:8px; margin: 1.25rem 0 0.5rem;">';
            echo '<h3 style="margin:0; font-size:1rem;">' . esc_html__( 'Conversation', 'talenttrack' ) . '</h3>';
            \TT\Shared\Frontend\Components\HelpDrawer::button(
                'conversational-goals',
                __( 'How does this work?', 'talenttrack' )
            );
            echo '</header>';
            \TT\Shared\Frontend\Components\FrontendThreadView::render( 'goal', (int) $goal->id, get_current_user_id() );
        }
    }
}
