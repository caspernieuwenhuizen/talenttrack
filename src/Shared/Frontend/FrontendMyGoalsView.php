<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\GoalPriority;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\Threads\ThreadMessagesRepository;
use TT\Shared\Frontend\Components\EmptyStateCard;

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
        self::enqueueGoalsStyle();

        // #3477 — a parent opening their child's goals was told they were
        // "Mijn doelen", in the crumb and in the heading.
        $voice = \TT\Shared\Frontend\Components\SubjectVoice::forPlayer( $player );
        $title = $voice->pick(
            __( 'My goals', 'talenttrack' ),
            /* translators: %s = the player's name, to a parent or a coach. */
            sprintf( __( "%s's goals", 'talenttrack' ), $voice->name() )
        );

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        // #1077 — was inline SQL + per-row LabelTranslator calls in
        // the loop below. GoalsRepository centralises the read +
        // pre-localises `status_localised` / `priority_localised` so
        // the view echoes the translated field by construction. Same
        // shape as #1081 EvaluationsRepository / #806 worked example.
        $goals = ( new GoalsRepository() )->listForPlayer( (int) $player->id );

        if ( empty( $goals ) ) {
            // #3396 — the guided empty state (#1362) every comparable player
            // surface already uses, instead of a bare italic line.
            EmptyStateCard::render( [
                'icon'      => 'goals',
                'headline'  => __( 'No goals set for you yet', 'talenttrack' ),
                'explainer' => __( 'Your coach sets development goals during your talks. When they do, they appear here with the conversation about each one.', 'talenttrack' ),
            ] );
            return;
        }

        $base = remove_query_arg( [ 'id' ] );

        // #3396 — one grouped count for the whole board. This used to call
        // listForThread() per goal, hydrating every message body to count
        // the array: N queries and N result sets for N numbers.
        $msg_counts = [];
        if ( class_exists( ThreadMessagesRepository::class ) ) {
            $msg_counts = ( new ThreadMessagesRepository() )->countsForThreads(
                'goal',
                array_map( static fn( $g ): int => (int) $g->id, $goals ),
                false
            );
        }

        // #1687 — 2026 restyle: group goals into the three mockup columns
        // (Active / Achieved / Missed) keyed on the same status buckets
        // GoalsRepository already uses (completed / cancelled / everything
        // else is active). Pure presentation — no extra query.
        $columns = [
            'active' => [ 'label' => __( 'Active',   'talenttrack' ), 'goals' => [] ],
            'done'   => [ 'label' => __( 'Achieved', 'talenttrack' ), 'goals' => [] ],
            'missed' => [ 'label' => __( 'Missed',   'talenttrack' ), 'goals' => [] ],
        ];
        foreach ( $goals as $g ) {
            $columns[ self::bucketFor( (string) ( $g->status ?? '' ) ) ]['goals'][] = $g;
        }
        ?>
        <div class="tt-goal-board">
            <?php foreach ( $columns as $bucket => $col ) : ?>
                <section class="tt-goal-col tt-goal-col--<?php echo esc_attr( $bucket ); ?>">
                    <h2 class="tt-goal-col__head">
                        <span class="tt-goal-col__dot" aria-hidden="true"></span>
                        <?php echo esc_html( $col['label'] ); ?>
                        <span class="tt-goal-col__count"><?php echo (int) count( $col['goals'] ); ?></span>
                    </h2>
                    <div class="tt-goal-col__cards">
                        <?php if ( empty( $col['goals'] ) ) : ?>
                            <p class="tt-goal-col__empty"><?php esc_html_e( 'Nothing here yet.', 'talenttrack' ); ?></p>
                        <?php endif; ?>
                        <?php foreach ( $col['goals'] as $g ) :
                            $detail_url = add_query_arg( 'id', (int) $g->id, $base );
                            // Public + player-readable messages only, so the CTA
                            // reflects what the player can actually open.
                            $msg_count     = (int) ( $msg_counts[ (int) $g->id ] ?? 0 );
                            $priority_chip = self::priorityChipClass( (string) ( $g->priority ?? '' ) );
                            $status_chip   = self::statusChipClass( (string) ( $g->status ?? '' ), $bucket );
                            ?>
                            <a class="tt-goal-card tt-goal-card--<?php echo esc_attr( $bucket ); ?> tt-record-link"
                               href="<?php echo esc_url( $detail_url ); ?>">
                                <h3 class="tt-goal-card__title"><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( (string) $g->title ) ); ?></h3>
                                <div class="tt-goal-card__meta">
                                    <span class="tt-goal-chip <?php echo esc_attr( $status_chip ); ?>"><?php echo esc_html( (string) $g->status_localised ); ?></span>
                                    <?php if ( ! empty( $g->priority ) ) : ?>
                                        <span class="tt-goal-chip <?php echo esc_attr( $priority_chip ); ?>"><?php echo esc_html( (string) $g->priority_localised ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $g->due_date ) ) : ?>
                                        <span class="tt-goal-due"><?php esc_html_e( 'Due:', 'talenttrack' ); ?> <?php echo esc_html( \TT\Shared\Dates\TTDate::date( (string) $g->due_date ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( ! empty( $g->description ) ) : ?>
                                    <p class="tt-goal-card__desc"><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( (string) $g->description ) ); ?></p>
                                <?php endif; ?>
                                <p class="tt-goal-card__cta">
                                    <span aria-hidden="true"><?php echo \TT\Shared\Icons\IconRenderer::render( 'comment', [ 'width' => 14, 'height' => 14, 'style' => 'vertical-align:-2px;' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG. ?></span>
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
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * #1687 — enqueue the shared 2026 goals restyle stylesheet. Depends on
     * the global app-chrome sheet so the brand tokens + card chrome load
     * first. Idempotent; registers an asset only.
     */
    private static function enqueueGoalsStyle(): void {
        wp_enqueue_style(
            'tt-goals',
            TT_PLUGIN_URL . 'assets/css/frontend-goals.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    /**
     * Map a stored goal status to one of the three board buckets.
     *
     * #3396 — compares against `GoalStatus`, the authority for what is
     * actually in `tt_goals.status`. The previous version matched
     * `achieved`, `signed_off`, `missed` and `canceled`, none of which the
     * product stores, while the two values that DO fall through here —
     * `pending_approval` and `on_hold` — were silently indistinguishable
     * from live work.
     *
     * Both stay in **Active**, deliberately. A goal waiting on a coach's
     * approval and a goal the coach has paused are still assigned to the
     * player; filing either under "Missed" would be a verdict nobody has
     * reached. What they get instead is their own chip — see
     * `statusChipClass()` — so the column is honest without the board
     * inventing a fourth one.
     *
     * An unrecognised value (an academy's own vocabulary) reads as active,
     * which is the safe end: a goal that exists is one to work on until
     * something says otherwise.
     */
    private static function bucketFor( string $status ): string {
        $s = strtolower( str_replace( ' ', '_', trim( $status ) ) );
        if ( $s === GoalStatus::COMPLETED ) return 'done';
        if ( $s === GoalStatus::CANCELLED ) return 'missed';
        return 'active';
    }

    /**
     * Status-chip modifier class.
     *
     * #3396 — keyed on the canonical status rather than the bucket alone,
     * so the two statuses that share the Active column do not share its
     * styling. The chip's text is `status_localised`, which already names
     * them; this is what makes the difference visible at a glance.
     */
    private static function statusChipClass( string $status, string $bucket ): string {
        $base = 'tt-goal-chip--status';
        $s    = strtolower( str_replace( ' ', '_', trim( $status ) ) );

        if ( $bucket === 'done' )   return $base . ' ' . $base . '-done';
        if ( $bucket === 'missed' ) return $base . ' ' . $base . '-missed';
        if ( $s === GoalStatus::PENDING_APPROVAL ) return $base . ' ' . $base . '-awaiting';
        if ( $s === GoalStatus::ON_HOLD )          return $base . ' ' . $base . '-onhold';

        return $base;
    }

    /**
     * Priority-chip modifier class from a stored priority value.
     *
     * #3396 — `GoalPriority` members only. This used to also match `hoog`
     * and `laag`, which are Dutch *labels* from `tt_lookups` and never
     * reach the column: two dead branches, and the shape of them is the
     * mistake #2909 fixed for attendance — comparing against something an
     * operator can rename in the admin.
     */
    private static function priorityChipClass( string $priority ): string {
        $p    = strtolower( trim( $priority ) );
        $base = 'tt-goal-chip--priority';

        if ( $p === GoalPriority::HIGH ) return $base . ' ' . $base . '-high';
        if ( $p === GoalPriority::LOW )  return $base . ' ' . $base . '-low';

        return $base;
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
        self::enqueueGoalsStyle();
        self::renderHeader( (string) \TT\Modules\Translations\TranslationLayer::render( (string) $goal->title ) );

        $status   = (string) $goal->status;
        $priority = (string) ( $goal->priority ?? '' );
        $bucket   = self::bucketFor( $status );
        ?>
        <div class="tt-goal-detail-grid">
        <article class="tt-goal-card tt-goal-detail-card tt-goal-card--<?php echo esc_attr( $bucket ); ?>">
            <div class="tt-goal-card__meta">
                <span class="tt-goal-chip <?php echo esc_attr( self::statusChipClass( $status, $bucket ) ); ?>"><?php echo esc_html( (string) $goal->status_localised ); ?></span>
                <?php if ( $priority !== '' ) : ?>
                    <span class="tt-goal-chip <?php echo esc_attr( self::priorityChipClass( $priority ) ); ?>"><?php echo esc_html( (string) $goal->priority_localised ); ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $goal->due_date ) ) : ?>
                    <span class="tt-goal-due"><?php esc_html_e( 'Due:', 'talenttrack' ); ?> <?php echo esc_html( \TT\Shared\Dates\TTDate::date( (string) $goal->due_date ) ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $goal->description ) ) : ?>
                <div class="tt-goal-detail-field">
                    <span class="tt-goal-detail-field__label"><?php esc_html_e( 'Description', 'talenttrack' ); ?></span>
                    <div class="tt-goal-detail-field__value"><?php echo wp_kses_post( wpautop( \TT\Modules\Translations\TranslationLayer::render( (string) $goal->description ) ) ); ?></div>
                </div>
            <?php endif; ?>
            <?php
            // #2218 — progress %, connected principle + football action.
            // Name resolution lives in the repositories (CLAUDE.md § 4).
            \TT\Shared\Frontend\Components\GoalDetailFields::render( $goal );
            ?>
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
            echo '<section class="tt-pde-section">';
            echo '<header class="tt-goal-conversation__header">';
            echo '<h3>' . esc_html__( 'Conversation', 'talenttrack' ) . '</h3>';
            \TT\Shared\Frontend\Components\HelpDrawer::button(
                'conversational-goals',
                __( 'How does this work?', 'talenttrack' )
            );
            echo '</header>';
            \TT\Shared\Frontend\Components\FrontendThreadView::render( 'goal', (int) $goal->id, get_current_user_id() );
            echo '</section>';
        }

        echo '</div>';
    }
}
