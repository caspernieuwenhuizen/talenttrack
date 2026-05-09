<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\EventTypeDefinition;
use TT\Infrastructure\Journey\EventTypeRegistry;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * FrontendJourneyView — chronological journey for one player.
 *
 * Two modes (toggle in the header):
 *   - timeline    — full chronological list, default
 *   - transitions — milestone-severity events only (parent-meeting + new-coach onboarding)
 *
 * Filter chips above the list narrow by event type. Visibility filtering
 * happens server-side; events the viewer can't see render as discreet
 * "1 entry hidden" placeholders so the count is honest without leaking
 * detail.
 *
 * The same view backs the player-side `?tt_view=my-journey` slug and
 * the coach-side `?tt_view=player-journey&player_id=N` slug — only the
 * player resolution differs.
 */
class FrontendJourneyView {

    public static function render( object $player ): void {
        // #0061 round 3 — visual timeline restyle. Enqueue the new
        // partial that turns the inline-styled list into a centered-
        // node vertical timeline. Idempotent via wp_enqueue_style.
        wp_enqueue_style(
            'tt-frontend-journey',
            TT_PLUGIN_URL . 'assets/css/frontend-journey.css',
            [ 'tt-frontend-admin' ],
            TT_VERSION
        );

        $user_id   = get_current_user_id();
        $player_id = (int) $player->id;

        if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this player.', 'talenttrack' ) . '</p>';
            return;
        }

        // v3.92.1 — breadcrumb chain. The view is reachable both as
        // the player's own "my-journey" tile (no parent) and from a
        // coach's player-detail page (parent: Players → [name]). The
        // `tt_view` GET param tells us which.
        $view_slug = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        $name = \TT\Infrastructure\Query\QueryHelpers::player_display_name( $player );
        if ( $view_slug === 'my-journey' ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My journey', 'talenttrack' ) );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                /* translators: %s: player display name */
                sprintf( __( 'Journey of %s', 'talenttrack' ), $name ),
                [
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ),
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', $name, [ 'id' => $player_id ] ),
                ]
            );
        }

        $mode               = isset( $_GET['journey_mode'] ) && $_GET['journey_mode'] === 'transitions' ? 'transitions' : 'timeline';
        $include_superseded = ! empty( $_GET['include_superseded'] );
        $full               = ! empty( $_GET['full'] );

        $selected_types = [];
        if ( isset( $_GET['event_type'] ) ) {
            $raw = is_array( $_GET['event_type'] ) ? $_GET['event_type'] : explode( ',', (string) $_GET['event_type'] );
            $selected_types = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', $raw ) ) ) );
        }

        $allowed_visibilities = PlayerEventsRepository::visibilitiesForUser( $user_id );

        $repo = new PlayerEventsRepository();
        if ( $mode === 'transitions' ) {
            $events = $repo->transitionsForPlayer( $player_id, $allowed_visibilities );
            $hidden = 0;
        } else {
            $now = strtotime( current_time( 'mysql' ) ) ?: time();
            $filters = [
                'event_types'        => $selected_types,
                'include_superseded' => $include_superseded,
                'limit'              => 50,
            ];
            if ( ! $full ) {
                $filters['from'] = gmdate( 'Y-m-d 00:00:00', $now - ( 60 * 60 * 24 * 365 ) );
                $filters['to']   = current_time( 'mysql' );
            }
            $result = $repo->timelineForPlayer( $player_id, $filters, $allowed_visibilities );
            $events = $result['events'];
            $hidden = $result['hidden_count'];
        }

        ?>
        <section class="tt-journey">
            <header class="tt-journey-head">
                <h2><?php echo esc_html( sprintf( __( 'Journey — %s', 'talenttrack' ), trim( $player->first_name . ' ' . $player->last_name ) ) ); ?></h2>
                <p class="tt-muted">
                    <?php esc_html_e( 'Chronological story for this player. Filter by type or switch to milestones-only for the parent meeting.', 'talenttrack' ); ?>
                </p>

                <div class="tt-journey-tabs" role="tablist" style="display:flex; gap:6px; margin: 10px 0;">
                    <a href="<?php echo esc_url( self::buildModeUrl( 'timeline' ) ); ?>"
                       class="tt-btn <?php echo $mode === 'timeline' ? 'tt-btn-primary' : 'tt-btn-secondary'; ?>"
                       role="tab"
                       aria-selected="<?php echo $mode === 'timeline' ? 'true' : 'false'; ?>">
                        <?php esc_html_e( 'Timeline', 'talenttrack' ); ?>
                    </a>
                    <a href="<?php echo esc_url( self::buildModeUrl( 'transitions' ) ); ?>"
                       class="tt-btn <?php echo $mode === 'transitions' ? 'tt-btn-primary' : 'tt-btn-secondary'; ?>"
                       role="tab"
                       aria-selected="<?php echo $mode === 'transitions' ? 'true' : 'false'; ?>">
                        <?php esc_html_e( 'Transitions', 'talenttrack' ); ?>
                    </a>
                </div>
            </header>

            <?php if ( $mode === 'timeline' ) : ?>
                <?php self::renderFilters( $selected_types, $full ); ?>
            <?php endif; ?>

            <?php if ( $hidden > 0 ) : ?>
                <p class="tt-notice tt-notice-info">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: count of events hidden because the viewer lacks the medical or safeguarding cap */
                        _n( '%d entry hidden — visible to other roles only.', '%d entries hidden — visible to other roles only.', $hidden, 'talenttrack' ),
                        $hidden
                    ) );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ( empty( $events ) ) : ?>
                <p class="tt-empty"><?php esc_html_e( 'No journey entries yet.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <ol class="tt-journey-list">
                    <?php foreach ( $events as $event ) : ?>
                        <?php self::renderEventCard( $event ); ?>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ( $mode === 'timeline' && ! $full ) : ?>
                <p style="margin-top:14px;">
                    <a href="<?php echo esc_url( add_query_arg( 'full', '1' ) ); ?>" class="tt-btn tt-btn-secondary">
                        <?php esc_html_e( 'Show full history', 'talenttrack' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param list<string> $selected_types
     */
    private static function renderFilters( array $selected_types, bool $full ): void {
        $types = EventTypeRegistry::all();
        ?>
        <form method="get" class="tt-journey-filters" style="margin: 10px 0; display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
            <?php
            foreach ( $_GET as $key => $value ) {
                if ( in_array( $key, [ 'event_type', 'full', 'include_superseded' ], true ) ) continue;
                if ( is_string( $value ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
                }
            }
            ?>
            <span class="tt-muted" style="font-size:13px;"><?php esc_html_e( 'Show:', 'talenttrack' ); ?></span>
            <?php
            // Primary filters always visible — the milestones coaches scan for.
            $primary_keys = [ 'evaluation_completed', 'injury_started', 'trial_ended' ];
            $primary = array_filter( $types, static fn( $def ) => in_array( $def->key, $primary_keys, true ) );
            $secondary = array_filter( $types, static fn( $def ) => ! in_array( $def->key, $primary_keys, true ) );
            $any_secondary_active = false;
            foreach ( $secondary as $def ) {
                if ( in_array( $def->key, $selected_types, true ) ) { $any_secondary_active = true; break; }
            }
            foreach ( $primary as $def ) : ?>
                <label class="tt-chip" style="display:inline-flex; align-items:center; gap:4px; padding:6px 10px; border:1px solid <?php echo esc_attr( $def->color ); ?>; border-radius:999px; cursor:pointer; min-height:32px;">
                    <input type="checkbox" name="event_type[]" value="<?php echo esc_attr( $def->key ); ?>"
                           <?php checked( in_array( $def->key, $selected_types, true ) ); ?> />
                    <?php echo esc_html( $def->label ); ?>
                </label>
            <?php endforeach; ?>
            <?php if ( $secondary ) : ?>
                <details class="tt-journey-filters-more" <?php echo $any_secondary_active ? 'open' : ''; ?> style="display:inline-block;">
                    <summary style="cursor:pointer; padding:6px 10px; border:1px dashed #c4c7c5; border-radius:999px; min-height:32px; display:inline-flex; align-items:center;">
                        <?php
                        printf(
                            /* translators: %d: number of additional filter types */
                            esc_html__( 'More filters (%d)', 'talenttrack' ),
                            count( $secondary )
                        );
                        ?>
                    </summary>
                    <span style="display:inline-flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
                    <?php foreach ( $secondary as $def ) : ?>
                        <label class="tt-chip" style="display:inline-flex; align-items:center; gap:4px; padding:6px 10px; border:1px solid <?php echo esc_attr( $def->color ); ?>; border-radius:999px; cursor:pointer; min-height:32px;">
                            <input type="checkbox" name="event_type[]" value="<?php echo esc_attr( $def->key ); ?>"
                                   <?php checked( in_array( $def->key, $selected_types, true ) ); ?> />
                            <?php echo esc_html( $def->label ); ?>
                        </label>
                    <?php endforeach; ?>
                    </span>
                </details>
            <?php endif; ?>
            <?php if ( $full ) : ?>
                <input type="hidden" name="full" value="1" />
            <?php endif; ?>
            <button type="submit" class="tt-btn tt-btn-primary" style="min-height:48px;">
                <?php esc_html_e( 'Filter', 'talenttrack' ); ?>
            </button>
            <a href="<?php echo esc_url( remove_query_arg( [ 'event_type', 'full', 'include_superseded' ] ) ); ?>" class="tt-btn tt-btn-secondary" style="min-height:48px;">
                <?php esc_html_e( 'Reset', 'talenttrack' ); ?>
            </a>
        </form>
        <?php
    }

    /** @param object $event */
    private static function renderEventCard( $event ): void {
        $def        = EventTypeRegistry::find( (string) $event->event_type );
        $color      = $def ? $def->color : '#5b6e75';
        $severity   = $def ? $def->severity : EventTypeDefinition::SEVERITY_INFO;
        $label      = $def ? $def->label : (string) $event->event_type;
        $date_label = self::formatDate( (string) $event->event_date );
        $superseded = ! empty( $event->superseded_by_event_id );
        ?>
        <li class="tt-journey-card<?php echo $superseded ? ' tt-journey-superseded' : ''; ?>"
            style="--tt-journey-color: <?php echo esc_attr( $color ); ?>;">
            <span class="tt-journey-node" aria-hidden="true"></span>
            <div class="tt-journey-card-body">
                <p class="tt-journey-card-meta">
                    <strong class="tt-journey-card-label"><?php echo esc_html( $label ); ?></strong>
                    · <?php echo esc_html( $date_label ); ?>
                    <?php if ( $severity === EventTypeDefinition::SEVERITY_MILESTONE ) : ?>
                        · <span class="tt-journey-tag tt-journey-tag-milestone"><?php esc_html_e( 'Milestone', 'talenttrack' ); ?></span>
                    <?php elseif ( $severity === EventTypeDefinition::SEVERITY_WARNING ) : ?>
                        · <span class="tt-journey-tag tt-journey-tag-warning"><?php esc_html_e( 'Warning', 'talenttrack' ); ?></span>
                    <?php endif; ?>
                </p>
                <p class="tt-journey-card-summary"><?php echo esc_html( (string) $event->summary ); ?></p>
                <?php if ( $superseded ) : ?>
                    <p class="tt-journey-card-retracted"><?php esc_html_e( 'Retracted — replaced by a corrected entry.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </div>
        </li>
        <?php
    }

    private static function formatDate( string $datetime ): string {
        $ts = strtotime( $datetime );
        if ( ! $ts ) return $datetime;
        return date_i18n( get_option( 'date_format', 'Y-m-d' ), $ts );
    }

    private static function buildModeUrl( string $mode ): string {
        if ( $mode === 'timeline' ) {
            return esc_url_raw( remove_query_arg( 'journey_mode' ) );
        }
        return esc_url_raw( add_query_arg( 'journey_mode', $mode ) );
    }
}
