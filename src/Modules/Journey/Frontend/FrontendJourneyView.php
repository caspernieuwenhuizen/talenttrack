<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Journey\EventTypeDefinition;
use TT\Infrastructure\Journey\EventTypeRegistry;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Dates\TTDate;

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
        // #1695 — 2026 "chrome" restyle. The journey body becomes a clean
        // left-rail timeline with brand-coloured nodes + white chrome cards.
        // Depends on the shared app chrome (#1690) so the brand tokens and
        // KPI/card language are present. Idempotent via wp_enqueue_style.
        wp_enqueue_style(
            'tt-frontend-my-journey',
            TT_PLUGIN_URL . 'assets/css/frontend-my-journey.css',
            [ 'tt-frontend-app-chrome' ],
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
        // #3398 — the player reading their own record, as opposed to a coach
        // reading it or a parent reading their child's. The slug already told
        // us this for the breadcrumb; the body copy now asks the same question.
        // A parent lands on `my-journey` with ?player_id=N, so the subject
        // being someone else is what separates them from the player.
        $is_own_journey = $view_slug === 'my-journey'
            && (int) ( $player->wp_user_id ?? 0 ) === $user_id
            && $user_id > 0;
        // #3474 — three readers, not two. `$is_own_journey` false used to mean
        // "coach", so a parent was handed a sentence written to staff about
        // "this player" and "the parent meeting".
        $voice = \TT\Shared\Frontend\Components\SubjectVoice::forPlayer( $player, $user_id );
        $name  = $voice->name();
        if ( $view_slug === 'my-journey' ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $voice->pick(
                __( 'My journey', 'talenttrack' ),
                /* translators: %s: player display name */
                sprintf( __( 'Journey of %s', 'talenttrack' ), $name )
            ) );
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
                <?php
                // #3398 — this view backs two slugs, and until now it branched
                // on that for the breadcrumb only, then told a player their own
                // record was "for this player" and pointed them at "the parent
                // meeting". Both are a coach's vocabulary. FrontendMyPdpView
                // and FrontendMyDevelopmentView already branch their copy the
                // same way; this is that, applied here.
                ?>
                <h2>
                    <?php
                    echo esc_html( $is_own_journey
                        ? __( 'My journey', 'talenttrack' )
                        : sprintf(
                            /* translators: %s: player display name */
                            __( 'Journey — %s', 'talenttrack' ),
                            trim( $player->first_name . ' ' . $player->last_name )
                        )
                    );
                    ?>
                </h2>
                <p class="tt-muted">
                    <?php
                    if ( $is_own_journey ) {
                        $lead = __( 'Everything that has happened so far, newest first. Filter by what you want to see, or switch to milestones for the big moments only.', 'talenttrack' );
                    } elseif ( $voice->isParent() ) {
                        $lead = sprintf(
                            /* translators: %s: the child's first name */
                            __( "Everything that has happened in %s's time at the academy, newest first. Switch to milestones to see just the big moments.", 'talenttrack' ),
                            $voice->firstName()
                        );
                    } else {
                        $lead = __( 'Chronological story for this player. Filter by type or switch to milestones-only for the parent meeting.', 'talenttrack' );
                    }
                    echo esc_html( $lead );
                    ?>
                </p>

                <?php
                // #3398 — these are two links that navigate to different URLs,
                // not a tab widget. `role="tablist"` told a screen reader to
                // expect arrow-key traversal between panels that do not exist,
                // and none of the tab keyboard model was implemented.
                ?>
                <div class="tt-journey-tabs">
                    <a href="<?php echo esc_url( self::buildModeUrl( 'timeline' ) ); ?>"
                       class="tt-btn <?php echo $mode === 'timeline' ? 'tt-btn-primary' : 'tt-btn-secondary'; ?>"
                       <?php echo $mode === 'timeline' ? 'aria-current="page"' : ''; ?>>
                        <?php esc_html_e( 'Timeline', 'talenttrack' ); ?>
                    </a>
                    <a href="<?php echo esc_url( self::buildModeUrl( 'transitions' ) ); ?>"
                       class="tt-btn <?php echo $mode === 'transitions' ? 'tt-btn-primary' : 'tt-btn-secondary'; ?>"
                       <?php echo $mode === 'transitions' ? 'aria-current="page"' : ''; ?>>
                        <?php esc_html_e( 'Transitions', 'talenttrack' ); ?>
                    </a>
                </div>
            </header>

            <?php if ( $mode === 'timeline' ) : ?>
                <?php self::renderFilters( $selected_types, $full, $is_own_journey ); ?>
            <?php endif; ?>

            <?php if ( $hidden > 0 ) : ?>
                <p class="tt-notice tt-notice-info">
                    <?php
                    // #3398 — the count stays for everyone: a timeline with
                    // silent gaps is its own kind of dishonesty on a record
                    // that is about the person reading it. What changes is
                    // the voice. "Visible to other roles only" is staff
                    // vocabulary; a player gets told plainly who can see the
                    // rest and that asking is how to find out, because that
                    // conversation belongs with their coach rather than with
                    // a notice bar (decided 2026-09-14).
                    echo esc_html( $is_own_journey
                        ? sprintf(
                            /* translators: %d: count of entries on the player's own journey that they may not read */
                            _n(
                                '%d entry is only visible to your coaches. Ask them if you want to know more.',
                                '%d entries are only visible to your coaches. Ask them if you want to know more.',
                                $hidden,
                                'talenttrack'
                            ),
                            $hidden
                        )
                        : sprintf(
                            /* translators: %d: count of events hidden because the viewer lacks the medical or safeguarding cap */
                            _n( '%d entry hidden — visible to other roles only.', '%d entries hidden — visible to other roles only.', $hidden, 'talenttrack' ),
                            $hidden
                        )
                    );
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
                <p class="tt-journey-more">
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
    private static function renderFilters( array $selected_types, bool $full, bool $is_own_journey = false ): void {
        $types = EventTypeRegistry::all();
        ?>
        <form method="get" class="tt-journey-filters">
            <?php
            foreach ( $_GET as $key => $value ) {
                if ( in_array( $key, [ 'event_type', 'full', 'include_superseded' ], true ) ) continue;
                if ( is_string( $value ) ) {
                    echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
                }
            }
            ?>
            <span class="tt-muted"><?php esc_html_e( 'Show:', 'talenttrack' ); ?></span>
            <?php
            // The three chips that stay visible; the rest fold behind "More
            // filters". #3398 — the staff set is "the milestones coaches scan
            // for"; a signed player has no trials, so `trial_ended` was a
            // permanently empty filter taking one of only three slots on their
            // own screen. Theirs is the set that describes progress: what a
            // coach wrote, what they were set to work on, and moving up.
            $primary_keys = $is_own_journey
                ? [
                    JourneyEventType::EVALUATION_COMPLETED,
                    JourneyEventType::GOAL_SET,
                    JourneyEventType::AGE_GROUP_PROMOTED,
                ]
                : [
                    JourneyEventType::EVALUATION_COMPLETED,
                    JourneyEventType::INJURY_STARTED,
                    JourneyEventType::TRIAL_ENDED,
                ];
            $primary = array_filter( $types, static fn( $def ) => in_array( $def->key, $primary_keys, true ) );
            $secondary = array_filter( $types, static fn( $def ) => ! in_array( $def->key, $primary_keys, true ) );
            $any_secondary_active = false;
            foreach ( $secondary as $def ) {
                if ( in_array( $def->key, $selected_types, true ) ) { $any_secondary_active = true; break; }
            }
            foreach ( $primary as $def ) : ?>
                <label class="tt-chip" style="border:1px solid <?php echo esc_attr( $def->color ); ?>;">
                    <input type="checkbox" name="event_type[]" value="<?php echo esc_attr( $def->key ); ?>"
                           <?php checked( in_array( $def->key, $selected_types, true ) ); ?> />
                    <?php echo esc_html( self::journeyLabel( $def ) ); ?>
                </label>
            <?php endforeach; ?>
            <?php if ( $secondary ) : ?>
                <details class="tt-journey-filters-more" <?php echo $any_secondary_active ? 'open' : ''; ?>>
                    <summary>
                        <?php
                        printf(
                            /* translators: %d: number of additional filter types */
                            esc_html__( 'More filters (%d)', 'talenttrack' ),
                            count( $secondary )
                        );
                        ?>
                    </summary>
                    <span class="tt-journey-filters-more-list">
                    <?php foreach ( $secondary as $def ) : ?>
                        <label class="tt-chip" style="border:1px solid <?php echo esc_attr( $def->color ); ?>;">
                            <input type="checkbox" name="event_type[]" value="<?php echo esc_attr( $def->key ); ?>"
                                   <?php checked( in_array( $def->key, $selected_types, true ) ); ?> />
                            <?php echo esc_html( self::journeyLabel( $def ) ); ?>
                        </label>
                    <?php endforeach; ?>
                    </span>
                </details>
            <?php endif; ?>
            <?php if ( $full ) : ?>
                <input type="hidden" name="full" value="1" />
            <?php endif; ?>
            <button type="submit" class="tt-btn tt-btn-primary">
                <?php esc_html_e( 'Filter', 'talenttrack' ); ?>
            </button>
            <a href="<?php echo esc_url( remove_query_arg( [ 'event_type', 'full', 'include_superseded' ] ) ); ?>" class="tt-btn tt-btn-secondary">
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
        $label      = $def ? self::journeyLabel( $def ) : (string) $event->event_type;
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

    /**
     * #1818 — translate a journey event-type label. The displayed label is
     * the lookup's `description`; route it through the lookup translator so
     * nl_NL (and other locales) resolve via tt_translations, falling back
     * to the English description when no translation exists.
     */
    private static function journeyLabel( object $def ): string {
        $tx = \TT\Infrastructure\Query\LookupTranslator::descriptionByTypeAndName( 'journey_event_type', (string) ( $def->key ?? '' ) );
        return $tx !== '' ? $tx : (string) ( $def->label ?? '' );
    }

    private static function formatDate( string $datetime ): string {
        $ts = strtotime( $datetime );
        if ( ! $ts ) return $datetime;
        return TTDate::date( $ts );
    }

    private static function buildModeUrl( string $mode ): string {
        if ( $mode === 'timeline' ) {
            return esc_url_raw( remove_query_arg( 'journey_mode' ) );
        }
        return esc_url_raw( add_query_arg( 'journey_mode', $mode ) );
    }
}
