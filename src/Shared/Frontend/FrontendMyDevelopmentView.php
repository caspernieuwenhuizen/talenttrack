<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

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

        $is_self = (int) ( $player->wp_user_id ?? 0 ) === get_current_user_id();
        $name    = QueryHelpers::player_display_name( $player );
        $title   = $is_self
            ? __( 'My development', 'talenttrack' )
            : sprintf(
                /* translators: %s = the child's name (parent viewing their child) */
                __( "%s's development", 'talenttrack' ),
                $name
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
            self::renderFocus( $player, $is_self );
        } else {
            self::renderPrivateBlock( __( 'Your focus', 'talenttrack' ) );
        }
        if ( self::sectionVisible( $player, $is_self, 'evaluations' ) ) {
            self::renderForm( $player, $is_self );
        } else {
            self::renderPrivateBlock( __( "How you're doing", 'talenttrack' ) );
        }
        self::renderComingUp( $player, $is_self );
        if ( self::sectionVisible( $player, $is_self, 'journey' ) ) {
            self::renderJourney( $player, $is_self );
        } else {
            self::renderPrivateBlock( __( 'Your journey', 'talenttrack' ) );
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
            : sprintf(
                /* translators: %1$s and %2$s are both the child's name (parent viewing their child). */
                __( "Welcome to TalentTrack! This is %1\$s's development home. You choose what %2\$s shares with you.", 'talenttrack' ),
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

    /** Your focus — top active goals preview → My goals. */
    private static function renderFocus( object $player, bool $is_self ): void {
        $goals = ( new GoalsRepository() )->topActiveForPlayer( (int) $player->id, 3 );
        self::sectionOpen( __( 'Your focus', 'talenttrack' ), self::meUrl( 'my-goals', $player, $is_self ), __( 'See all goals', 'talenttrack' ) );
        if ( empty( $goals ) ) {
            echo '<p class="tt-devhome-empty">' . esc_html__( 'No active goals yet. Your coach will set some during your next talk.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-devhome-list">';
            foreach ( $goals as $g ) {
                $due = (string) ( $g->due_date ?? '' );
                echo '<li class="tt-devhome-row">';
                echo '<span class="tt-devhome-row__title">' . esc_html( (string) ( $g->title ?? '' ) ) . '</span>';
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
    private static function renderForm( object $player, bool $is_self ): void {
        $max   = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $heads = ( new PlayerStatsService() )->getHeadlineNumbers( (int) $player->id, [], 5 );
        $rolling = isset( $heads['rolling'] ) && $heads['rolling'] !== null ? (float) $heads['rolling'] : null;
        $alltime = isset( $heads['alltime'] ) && $heads['alltime'] !== null ? (float) $heads['alltime'] : null;
        $latest  = isset( $heads['latest'] )  && $heads['latest']  !== null ? (float) $heads['latest']  : null;
        $headline = $rolling !== null ? $rolling : $latest;

        self::sectionOpen( __( "How you're doing", 'talenttrack' ), self::meUrl( 'my-evaluations', $player, $is_self ), __( 'See all evaluations', 'talenttrack' ) );
        if ( $headline === null ) {
            echo '<p class="tt-devhome-empty">' . esc_html__( 'No evaluations yet. Your first rating will appear here once your coach completes one.', 'talenttrack' ) . '</p>';
        } else {
            $max_str = number_format_i18n( $max, 0 );
            echo '<div class="tt-devhome-rating">';
            echo '<span class="tt-devhome-rating__val">' . esc_html( number_format_i18n( $headline, 1 ) ) . '</span>';
            echo '<span class="tt-devhome-rating__max">/ ' . esc_html( $max_str ) . '</span>';
            echo '</div>';
            if ( $rolling !== null && $alltime !== null ) {
                $diff = round( $rolling - $alltime, 1 );
                if ( $diff > 0 ) {
                    $momentum = sprintf(
                        /* translators: %s = signed rating delta, e.g. +0.4 */
                        __( 'Up %s on your average', 'talenttrack' ),
                        '+' . number_format_i18n( $diff, 1 )
                    );
                } elseif ( $diff < 0 ) {
                    $momentum = sprintf(
                        /* translators: %s = signed rating delta, e.g. -0.3 */
                        __( 'Down %s on your average', 'talenttrack' ),
                        number_format_i18n( $diff, 1 )
                    );
                } else {
                    $momentum = __( 'Steady with your average', 'talenttrack' );
                }
                echo '<p class="tt-devhome-rating__meta">' . esc_html( $momentum ) . '</p>';
            }
        }
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
                echo '<span class="tt-devhome-row__title">' . esc_html( (string) ( $r->title ?? '' ) ) . '</span>';
                echo '<span class="tt-devhome-row__meta">' . esc_html( self::formatDate( substr( (string) ( $r->session_date ?? '' ), 0, 10 ) ) ) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
        self::sectionClose();
    }

    /** Your journey — most recent milestone → My journey. */
    private static function renderJourney( object $player, bool $is_self ): void {
        $vis    = PlayerEventsRepository::visibilitiesForUser( get_current_user_id() );
        $events = ( new PlayerEventsRepository() )->transitionsForPlayer( (int) $player->id, $vis );
        $latest = ! empty( $events ) ? $events[0] : null;

        self::sectionOpen( __( 'Your journey', 'talenttrack' ), self::meUrl( 'my-journey', $player, $is_self ), __( 'See your journey', 'talenttrack' ) );
        if ( $latest === null ) {
            echo '<p class="tt-devhome-empty">' . esc_html__( 'Your academy story starts here. Milestones will appear as your season unfolds.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-devhome-row">';
            echo '<span class="tt-devhome-row__title">' . esc_html( (string) ( $latest->summary ?? '' ) ) . '</span>';
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

    private static function sectionClose(): void {
        echo '</section>';
    }

    /**
     * Build a Me-view URL carrying player_id when a parent views their
     * child, plus a tt_back hint so the deep view shows a "← Back to …"
     * pill back to this home (§5).
     */
    private static function meUrl( string $view, object $player, bool $is_self ): string {
        $base = remove_query_arg( [ 'tt_view', 'player_id', 'id', 'tt_back' ] );
        $url  = add_query_arg( 'tt_view', $view, $base ?: home_url( '/' ) );
        if ( ! $is_self ) {
            $url = add_query_arg( 'player_id', (int) $player->id, $url );
        }
        return BackLink::appendTo( $url );
    }

    /** Locale-aware date from a YYYY-MM-DD string, or '' when empty. */
    private static function formatDate( ?string $ymd ): string {
        if ( $ymd === null || $ymd === '' ) return '';
        $ts = strtotime( $ymd . ' UTC' );
        if ( $ts === false ) return $ymd;
        return date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
    }
}
