<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\SubjectVoice;
use TT\Shared\Dates\TTDate;

/**
 * FrontendMyDevelopmentView — the player + parent "development home"
 * (#1850, Phase 2 of #1846). One overview-led anchor that composes the
 * existing rich My-X surfaces into a single, scannable, mobile-first
 * column:
 *
 *   Hero          — reuses FrontendOverviewView's player header.
 *   Today band    — the PDP cycle state (#1851): what to do now. Degrades
 *                   gracefully to the next-talk date or nothing.
 *   Your focus    — top active goals preview → My goals.
 *   How you're doing — headline rating + momentum → My evaluations.
 *   Playing time  — minutes played and the matches they came from (#3666).
 *   Coming up     — next activities → My activities.
 *   Your journey  — last milestone → My journey.
 *
 * Player (self) and parent (their child, via #1849's `?player_id=N`
 * scoped routing) share this home. The parent variant is read-only and
 * possessive ("<Child>'s development"). Composition only — every block
 * reads from a repository or service; no business logic lives here (§4).
 * Each block links through to its deep view, carrying `tt_back` so the
 * deep view shows a "← Back to …" pill (§5).
 */
class FrontendMyDevelopmentView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-my-development',
            TT_PLUGIN_URL . 'assets/css/frontend-my-development.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        // #1903 — one-time welcome card; a dismiss POST sets the per-viewer
        // preference before the card is rendered below.
        self::maybeHandleWelcomeDismiss();

        // #3477 — the reader, resolved once. `$is_self` stays because the
        // section-visibility gate and the link builders below are genuinely
        // binary (self vs everyone else); the *wording* is not, which is what
        // the voice is for.
        $voice   = SubjectVoice::forPlayer( $player );
        $is_self = $voice->isSelf();
        $name    = $voice->name();
        $title   = $voice->pick(
            __( 'My development', 'talenttrack' ),
            sprintf(
                /* translators: %s = the player's name, to a parent or a coach. */
                __( "%s's development", 'talenttrack' ),
                $name
            )
        );

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        echo '<div class="tt-devhome">';
        self::renderWelcome( $is_self, $name );
        FrontendOverviewView::renderHero( $player );
        // #1867 — a parent only sees the sections the child hasn't hidden.
        // The PDP-driven Today band is simply skipped when hidden (it's an
        // action prompt); the section previews show a "kept private" card.
        if ( self::sectionVisible( $player, $is_self, 'pdp' ) ) {
            self::renderTodayBand( $player, $is_self, $name );
        }
        if ( self::sectionVisible( $player, $is_self, 'goals' ) ) {
            self::renderFocus( $player, $is_self, $voice );
        } else {
            self::renderPrivateBlock( self::focusHeading( $voice ) );
        }
        if ( self::sectionVisible( $player, $is_self, 'evaluations' ) ) {
            self::renderForm( $player, $is_self, $voice );
        } else {
            self::renderPrivateBlock( self::formHeading( $voice ) );
        }
        // #3666 — playing time. It sits under the form block because it is
        // the other half of the same question ("how am I doing?"), and
        // above Coming up because it is about matches already played.
        if ( self::sectionVisible( $player, $is_self, 'minutes' ) ) {
            self::renderPlayingTime( $player, $is_self, $voice );
        } else {
            self::renderPrivateBlock( self::playingTimeHeading( $voice ) );
        }
        self::renderComingUp( $player, $is_self );
        if ( self::sectionVisible( $player, $is_self, 'journey' ) ) {
            self::renderJourney( $player, $is_self, $voice );
        } else {
            self::renderPrivateBlock( self::journeyHeading( $voice ) );
        }
        echo '</div>';
    }

    /**
     * #1903 — one-time, dismissible welcome card at the top of the home.
     * Informational only (no CTA); persona-aware copy. Renders while the
     * viewer hasn't dismissed it — that's the "new user" signal, no
     * first-login timestamp needed. Dismissal is per viewer (user meta).
     */
    private static function renderWelcome( bool $is_self, string $name ): void {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return;
        if ( get_user_meta( $uid, 'tt_dev_welcome_dismissed', true ) ) return;

        $body = $is_self
            ? __( 'Welcome to TalentTrack! This is your development home: your talks, goals, form and journey, all in one place.', 'talenttrack' )
            // #3398 — this used to read "You choose what <child> shares with
            // you", which names the wrong person. The control belongs to the
            // player (#1867, PlayerParentVisibilityRepository): a young person
            // may withhold evaluations, goals, journey, measurements, PDP and
            // training from a parent. Telling the parent they hold it, on the
            // first screen they ever see, is worse than saying nothing.
            : sprintf(
                /* translators: %1$s and %2$s are both the child's name (parent viewing their child). */
                __( "Welcome to TalentTrack! This is %1\$s's development home. %2\$s chooses what to share with you.", 'talenttrack' ),
                $name, $name
            );
        ?>
        <section class="tt-devhome-welcome" aria-label="<?php esc_attr_e( 'Welcome', 'talenttrack' ); ?>">
            <div class="tt-devhome-welcome__body">
                <h2 class="tt-devhome-welcome__title"><?php esc_html_e( 'Welcome to TalentTrack', 'talenttrack' ); ?></h2>
                <p class="tt-devhome-welcome__text"><?php echo esc_html( $body ); ?></p>
            </div>
            <form method="post" class="tt-devhome-welcome__dismiss">
                <?php wp_nonce_field( 'tt_devhome_welcome', 'tt_devhome_welcome_nonce' ); ?>
                <input type="hidden" name="tt_devhome_action" value="dismiss_welcome" />
                <button type="submit" class="tt-btn tt-btn-secondary tt-btn-sm"><?php esc_html_e( 'Got it', 'talenttrack' ); ?></button>
            </form>
        </section>
        <?php
    }

    /** Handle the welcome-card dismiss POST (per-viewer user meta). */
    private static function maybeHandleWelcomeDismiss(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        if ( ( $_POST['tt_devhome_action'] ?? '' ) !== 'dismiss_welcome' ) return;
        if ( ! isset( $_POST['tt_devhome_welcome_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_devhome_welcome_nonce'] ) ), 'tt_devhome_welcome' ) ) {
            return;
        }
        $uid = get_current_user_id();
        if ( $uid > 0 ) update_user_meta( $uid, 'tt_dev_welcome_dismissed', '1' );
    }

    /** #1867 — section visible to this viewer? Self + staff always true. */
    private static function sectionVisible( object $player, bool $is_self, string $section ): bool {
        if ( $is_self ) return true;
        return \TT\Infrastructure\Security\AuthorizationService::parentCanViewSection(
            get_current_user_id(), (int) $player->id, $section
        );
    }

    /** A compact "kept private" card for a hidden home block. */
    private static function renderPrivateBlock( string $heading ): void {
        \TT\Shared\Frontend\Components\FrontendPrivateSection::enqueue();
        echo '<section class="tt-devhome-card">';
        echo '<div class="tt-devhome-card__head"><h2 class="tt-devhome-card__title">' . esc_html( $heading ) . '</h2></div>';
        echo \TT\Shared\Frontend\Components\FrontendPrivateSection::card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped within card().
        echo '</section>';
    }

    /**
     * Today / action band — driven by the PDP cycle state. Degrades
     * gracefully: nothing when there's no season / PDP file / pending
     * moment, otherwise a single prominent call-to-action into My PDP.
     */
    private static function renderTodayBand( object $player, bool $is_self, string $name ): void {
        $season = ( new SeasonsRepository() )->current();
        if ( ! $season ) return;
        $file = ( new PdpFilesRepository() )->findByPlayerSeason( (int) $player->id, (int) $season->id );
        if ( ! $file ) return;
        $convs = ( new PdpConversationsRepository() )->listForFile( (int) $file->id );
        if ( empty( $convs ) ) return;

        $viewer = $is_self ? PdpCycleState::VIEWER_PLAYER : PdpCycleState::VIEWER_PARENT;
        $cycle  = PdpCycleState::derive( $convs, $viewer );
        if ( $cycle->state === PdpCycleState::IDLE ) return;

        $date = self::formatDate( $cycle->talk_date );
        $url  = self::meUrl( 'my-pdp', $player, $is_self );

        $eyebrow = __( 'Today', 'talenttrack' );
        if ( $cycle->state === PdpCycleState::REVIEW_WINDOW ) {
            $headline = $date !== ''
                ? sprintf(
                    /* translators: %s = date of the upcoming development talk */
                    __( 'Prepare for your talk on %s', 'talenttrack' ),
                    $date
                )
                : __( 'Prepare for your upcoming development talk', 'talenttrack' );
            $cta = $is_self
                ? __( 'Start your self-review', 'talenttrack' )
                : __( 'Open the development plan', 'talenttrack' );
        } elseif ( $cycle->state === PdpCycleState::POST ) {
            $headline = $date !== ''
                ? sprintf(
                    /* translators: %s = date of the most recent development talk */
                    __( 'Your talk on %s is ready to review', 'talenttrack' ),
                    $date
                )
                : __( 'Your last talk is ready to review', 'talenttrack' );
            $cta = __( 'Review and acknowledge', 'talenttrack' );
        } else { // WORKING
            $headline = $date !== ''
                ? sprintf(
                    /* translators: %s = date of the next development talk */
                    __( 'Next development talk: %s', 'talenttrack' ),
                    $date
                )
                : __( 'Your next development talk will be planned soon.', 'talenttrack' );
            $cta = __( 'Open the development plan', 'talenttrack' );
        }

        echo '<a class="tt-devhome-today tt-devhome-today--' . esc_attr( $cycle->state ) . '" href="' . esc_url( $url ) . '">';
        echo '<span class="tt-devhome-today__eyebrow">' . esc_html( $eyebrow ) . '</span>';
        echo '<span class="tt-devhome-today__headline">' . esc_html( $headline ) . '</span>';
        echo '<span class="tt-devhome-today__cta">' . esc_html( $cta ) . '</span>';
        echo '</a>';
    }

    /**
     * #3477 — the three section headings, resolved for the reader in one
     * place so the heading and its "kept private" placeholder cannot say
     * different things about whose focus, form or journey it is.
     */
    private static function focusHeading( SubjectVoice $voice ): string {
        return $voice->pick(
            __( 'Your focus', 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( "%s's focus", 'talenttrack' ), $voice->firstName() )
        );
    }

    private static function formHeading( SubjectVoice $voice ): string {
        return $voice->pick(
            __( "How you're doing", 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( 'How %s is doing', 'talenttrack' ), $voice->firstName() )
        );
    }

    private static function playingTimeHeading( SubjectVoice $voice ): string {
        return $voice->pick(
            __( 'Your playing time', 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( "%s's playing time", 'talenttrack' ), $voice->firstName() )
        );
    }

    private static function journeyHeading( SubjectVoice $voice ): string {
        return $voice->pick(
            __( 'Your journey', 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( "%s's journey", 'talenttrack' ), $voice->firstName() )
        );
    }

    /** Focus — top active goals preview → My goals. */
    private static function renderFocus( object $player, bool $is_self, SubjectVoice $voice ): void {
        $goals = ( new GoalsRepository() )->topActiveForPlayer( (int) $player->id, 3 );
        self::sectionOpen( self::focusHeading( $voice ), self::meUrl( 'my-goals', $player, $is_self ), __( 'See all goals', 'talenttrack' ) );
        if ( empty( $goals ) ) {
            echo '<p class="tt-devhome-empty">' . esc_html( $voice->pick(
                __( 'No active goals yet. Your coach will set some during your next talk.', 'talenttrack' ),
                /* translators: %s = the player's first name. */
                sprintf( __( 'No active goals yet. A coach will set some at the next development talk with %s.', 'talenttrack' ), $voice->firstName() )
            ) ) . '</p>';
        } else {
            echo '<ul class="tt-devhome-list">';
            foreach ( $goals as $g ) {
                $due = (string) ( $g->due_date ?? '' );
                echo '<li class="tt-devhome-row">';
                echo self::rowTitleLink( 'my-goals', (int) ( $g->id ?? 0 ), (string) ( $g->title ?? '' ), $player, $is_self ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — rowTitleLink escapes label + URL.
                if ( $due !== '' ) {
                    echo '<span class="tt-devhome-row__meta">' . esc_html( sprintf(
                        /* translators: %s = goal due date */
                        __( 'Due %s', 'talenttrack' ),
                        $due
                    ) ) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        self::sectionClose();
    }

    /** How you're doing — headline rating + momentum → My evaluations. */
    private static function renderForm( object $player, bool $is_self, SubjectVoice $voice ): void {
        $max   = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $heads = ( new PlayerStatsService() )->getHeadlineNumbers( (int) $player->id, [], 5 );
        $rolling = isset( $heads['rolling'] ) && $heads['rolling'] !== null ? (float) $heads['rolling'] : null;
        $alltime = isset( $heads['alltime'] ) && $heads['alltime'] !== null ? (float) $heads['alltime'] : null;
        $latest  = isset( $heads['latest'] )  && $heads['latest']  !== null ? (float) $heads['latest']  : null;
        $headline = $rolling !== null ? $rolling : $latest;

        self::sectionOpen( self::formHeading( $voice ), self::meUrl( 'my-evaluations', $player, $is_self ), __( 'See all evaluations', 'talenttrack' ) );
        if ( $headline === null ) {
            echo '<p class="tt-devhome-empty">' . esc_html( $voice->pick(
                __( 'No evaluations yet. Your first rating will appear here once your coach completes one.', 'talenttrack' ),
                /* translators: %s = the player's first name. */
                sprintf( __( 'No evaluations yet. The first rating appears here once a coach completes one for %s.', 'talenttrack' ), $voice->firstName() )
            ) ) . '</p>';
        } else {
            $max_str = number_format_i18n( $max, 0 );
            echo '<div class="tt-devhome-rating">';
            echo '<span class="tt-devhome-rating__val">' . esc_html( number_format_i18n( $headline, 1 ) ) . '</span>';
            echo '<span class="tt-devhome-rating__max">/ ' . esc_html( $max_str ) . '</span>';
            echo '</div>';
            if ( $rolling !== null && $alltime !== null ) {
                $diff = round( $rolling - $alltime, 1 );
                // #3477 — "your average" is the player's average, and a
                // parent reading it about their child is being told it is
                // theirs. The third-person form drops the possessive rather
                // than repeating the name a third time in one block.
                if ( $diff > 0 ) {
                    $delta    = '+' . number_format_i18n( $diff, 1 );
                    $momentum = $voice->pick(
                        /* translators: %s = signed rating delta, e.g. +0.4 */
                        sprintf( __( 'Up %s on your average', 'talenttrack' ), $delta ),
                        /* translators: %s = signed rating delta, e.g. +0.4 */
                        sprintf( __( 'Up %s on the season average', 'talenttrack' ), $delta )
                    );
                } elseif ( $diff < 0 ) {
                    $delta    = number_format_i18n( $diff, 1 );
                    $momentum = $voice->pick(
                        /* translators: %s = signed rating delta, e.g. -0.3 */
                        sprintf( __( 'Down %s on your average', 'talenttrack' ), $delta ),
                        /* translators: %s = signed rating delta, e.g. -0.3 */
                        sprintf( __( 'Down %s on the season average', 'talenttrack' ), $delta )
                    );
                } else {
                    $momentum = $voice->pick(
                        __( 'Steady with your average', 'talenttrack' ),
                        __( 'Steady with the season average', 'talenttrack' )
                    );
                }
                echo '<p class="tt-devhome-rating__meta">' . esc_html( $momentum ) . '</p>';
            }
        }
        self::sectionClose();
    }

    /**
     * #3666 — playing time. Total minutes over the shared default window,
     * the matches they came from, and the three most recent of those.
     *
     * Absolute minutes only: no share of the team's available minutes and
     * no team-mate's figures. A young player reading their own playing
     * time should not be handed a league table of the changing room.
     *
     * The figures come from the same `MinutesQuery` the coach's report and
     * the REST route read, so the screen and the API cannot disagree (§4).
     */
    private static function renderPlayingTime( object $player, bool $is_self, SubjectVoice $voice ): void {
        $player_id = (int) ( $player->id ?? 0 );
        $team_id   = (int) ( $player->team_id ?? 0 );
        $window    = MinutesQuery::defaultWindow();
        $playing   = $team_id > 0 && $player_id > 0
            ? ( new MinutesQuery() )->playingTimeForPlayer( $team_id, $player_id, $window['from'], $window['to'] )
            : [ 'total_minutes' => 0, 'matches' => [] ];

        $total   = (int) $playing['total_minutes'];
        $matches = $playing['matches'];

        self::sectionOpenPlain( self::playingTimeHeading( $voice ) );
        if ( $total <= 0 ) {
            echo '<p class="tt-devhome-empty">' . esc_html( $voice->pick(
                __( 'No minutes recorded yet. They appear here once your coach records a match you played in.', 'talenttrack' ),
                /* translators: %s = the player's first name. */
                sprintf( __( 'No minutes recorded yet. They appear here once a coach records a match %s played in.', 'talenttrack' ), $voice->firstName() )
            ) ) . '</p>';
            self::sectionClose();
            return;
        }

        echo '<div class="tt-devhome-minutes">';
        echo '<span class="tt-devhome-minutes__val">' . esc_html( number_format_i18n( $total ) ) . '</span>';
        echo '<span class="tt-devhome-minutes__unit">' . esc_html__( 'minutes played', 'talenttrack' ) . '</span>';
        echo '</div>';

        $count = count( $matches );
        echo '<p class="tt-devhome-minutes__meta">' . esc_html( sprintf(
            /* translators: %s = number of matches played in the last twelve months. */
            _n( 'In %s match over the past 12 months.', 'In %s matches over the past 12 months.', $count, 'talenttrack' ),
            number_format_i18n( $count )
        ) ) . '</p>';

        // Most recent first — the breakdown comes back oldest-first.
        $recent = array_reverse( array_slice( $matches, -3 ) );
        echo '<ul class="tt-devhome-list">';
        foreach ( $recent as $m ) {
            $date  = self::formatDate( substr( (string) $m['session_date'], 0, 10 ) );
            $title = (string) $m['title'];
            if ( $title === '' ) $title = $date;
            echo '<li class="tt-devhome-row">';
            echo self::rowTitleLink( 'my-activities', (int) $m['activity_id'], $title, $player, $is_self ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — rowTitleLink escapes label + URL.
            echo '<span class="tt-devhome-row__meta">' . esc_html( sprintf(
                /* translators: 1: match date, 2: minutes played in that match. */
                __( '%1$s · %2$s min', 'talenttrack' ),
                $date,
                number_format_i18n( (int) $m['minutes'] )
            ) ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** Coming up — next activities → My activities. */
    private static function renderComingUp( object $player, bool $is_self ): void {
        $team_id = (int) ( $player->team_id ?? 0 );
        $rows    = $team_id > 0 ? ( new ActivitiesRepository() )->upcomingForTeam( $team_id, 3 ) : [];
        self::sectionOpen( __( 'Coming up', 'talenttrack' ), self::meUrl( 'my-activities', $player, $is_self ), __( 'See all activities', 'talenttrack' ) );
        if ( empty( $rows ) ) {
            echo '<p class="tt-devhome-empty">' . esc_html__( 'Nothing on the calendar in the next few weeks.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-devhome-list">';
            foreach ( $rows as $r ) {
                echo '<li class="tt-devhome-row">';
                echo self::rowTitleLink( 'my-activities', (int) ( $r->id ?? 0 ), (string) ( $r->title ?? '' ), $player, $is_self ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — rowTitleLink escapes label + URL.
                echo '<span class="tt-devhome-row__meta">' . esc_html( self::formatDate( substr( (string) ( $r->session_date ?? '' ), 0, 10 ) ) ) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
        self::sectionClose();
    }

    /** Your journey — most recent milestone → My journey. */
    private static function renderJourney( object $player, bool $is_self, SubjectVoice $voice ): void {
        $vis    = PlayerEventsRepository::visibilitiesForUser( get_current_user_id() );
        $events = ( new PlayerEventsRepository() )->transitionsForPlayer( (int) $player->id, $vis );
        $latest = ! empty( $events ) ? $events[0] : null;

        self::sectionOpen(
            self::journeyHeading( $voice ),
            self::meUrl( 'my-journey', $player, $is_self ),
            $voice->pick(
                __( 'See your journey', 'talenttrack' ),
                __( 'See the full journey', 'talenttrack' )
            )
        );
        if ( $latest === null ) {
            echo '<p class="tt-devhome-empty">' . esc_html( $voice->pick(
                __( 'Your academy story starts here. Milestones will appear as your season unfolds.', 'talenttrack' ),
                /* translators: %s = the player's first name. */
                sprintf( __( "%s's academy story starts here. Milestones will appear as the season unfolds.", 'talenttrack' ), $voice->firstName() )
            ) ) . '</p>';
        } else {
            echo '<div class="tt-devhome-row">';
            echo self::rowTitleLink( 'my-journey', (int) ( $latest->id ?? 0 ), (string) ( $latest->summary ?? '' ), $player, $is_self ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — rowTitleLink escapes label + URL.
            echo '<span class="tt-devhome-row__meta">' . esc_html( self::formatDate( substr( (string) ( $latest->event_date ?? '' ), 0, 10 ) ) ) . '</span>';
            echo '</div>';
        }
        self::sectionClose();
    }

    // ── helpers ──────────────────────────────────────────────────────

    private static function sectionOpen( string $heading, string $link_url, string $link_label ): void {
        echo '<section class="tt-devhome-card">';
        echo '<div class="tt-devhome-card__head">';
        echo '<h2 class="tt-devhome-card__title">' . esc_html( $heading ) . '</h2>';
        echo '<a class="tt-devhome-card__more" href="' . esc_url( $link_url ) . '">' . esc_html( $link_label ) . '</a>';
        echo '</div>';
    }

    /** A card head with no "see all" link — for a block with no deep view. */
    private static function sectionOpenPlain( string $heading ): void {
        echo '<section class="tt-devhome-card">';
        echo '<div class="tt-devhome-card__head">';
        echo '<h2 class="tt-devhome-card__title">' . esc_html( $heading ) . '</h2>';
        echo '</div>';
    }

    private static function sectionClose(): void {
        echo '</section>';
    }

    /**
     * Build a Me-view URL carrying player_id when a parent views their
     * child, plus a tt_back hint so the deep view shows a "← Back to …"
     * pill back to this home (§5).
     */
    private static function meUrl( string $view, object $player, bool $is_self ): string {
        // #3393 — the implementation moved to RecordLink beside
        // meDetailUrl(), so the player file's "View all" shares it. This
        // stays as the local signature the callers below use.
        return RecordLink::meUrl( $view, $is_self ? null : (int) $player->id );
    }

    /**
     * #2152 — Me-view detail URL for a single record (`?tt_view=<view>&id=N`),
     * carrying `player_id` for the parent-viewing-child case and a `tt_back`
     * hint so the deep view shows a "← Back to My development" pill (§5).
     * Mirrors `meUrl()` but targets one record rather than the list.
     */
    private static function meDetailUrl( string $view, int $id, object $player, bool $is_self ): string {
        // #3397 — the implementation moved to RecordLink so the PDP page
        // (and anything added later) shares it instead of growing a third
        // copy. This stays as the local signature the callers below use.
        return RecordLink::meDetailUrl( $view, $id, $is_self ? null : (int) $player->id );
    }

    /**
     * #2152 — render a row's title as a record link to its player-facing
     * detail view. Falls back to a plain `<span>` title when the record
     * carries no id (defensive — keeps the row readable, just not tappable).
     * The link reuses the shared `.tt-record-link` styling on top of the
     * existing `.tt-devhome-row__title` so touch-target + hover/focus rules
     * stay consistent with the rest of the home.
     */
    private static function rowTitleLink( string $view, int $id, string $title, object $player, bool $is_self ): string {
        if ( $id <= 0 || $title === '' ) {
            return '<span class="tt-devhome-row__title">' . esc_html( $title ) . '</span>';
        }
        return RecordLink::inline(
            $title,
            self::meDetailUrl( $view, $id, $player, $is_self ),
            'tt-devhome-row__title'
        );
    }

    /** Locale-aware date from a YYYY-MM-DD string, or '' when empty. */
    private static function formatDate( ?string $ymd ): string {
        if ( $ymd === null || $ymd === '' ) return '';
        $ts = strtotime( $ymd . ' UTC' );
        if ( $ts === false ) return $ymd;
        return TTDate::date( $ts );
    }
}
