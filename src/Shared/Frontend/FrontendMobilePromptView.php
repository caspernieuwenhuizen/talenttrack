<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendMobilePromptView — the "open this on desktop" page shown when a
 * phone visits a `desktop_only` route (#0084 Child 1, widened by #2811).
 *
 * Rendered inline by `DashboardShortcode::render()` when:
 *   1. The visitor is a phone (`MobileDetector::isPhone()`).
 *   2. The requested view is `desktop_only` per `MobileSurfaceRegistry`.
 *   3. The per-club `force_mobile_for_user_agents` setting is on.
 *   4. The user has NOT bypassed via `?force_mobile=1`.
 *
 * ## Why #2811 rebuilt it
 *
 * It was designed when it stood in front of 22 surfaces. The #2807
 * classification takes that to **77 of 156** — half the routable product.
 * At 22 it was something a coach met rarely; at 77 it is something they
 * meet weekly, and at that frequency a generic "best on a larger screen"
 * stops being polite and starts reading as the product being broken.
 *
 * So the page now does four things it did not:
 *
 *   - **Names the surface** the visitor was trying to reach, so the page is
 *     about their intent rather than about the device.
 *   - **Says why this one** needs the width. A reason true of all 77
 *     surfaces tells a coach nothing.
 *   - **Offers the phone path where one genuinely exists** — that is what
 *     turns a wall into a signpost.
 *   - **Makes the override a visible button.** `?force_mobile=1` is a URL
 *     parameter you have to already know about, which is not an escape
 *     hatch for a gate this wide.
 *
 * Audit-logs each show via `mobile.desktop_prompt_shown` so operators can
 * spot routes with heavy phone traffic and revisit the classification.
 */
class FrontendMobilePromptView extends FrontendViewBase {

    public static function render( int $user_id, string $blocked_view = '' ): void {
        $blocked_view = sanitize_key( $blocked_view );

        // Audit-log the show. Logged once per render; a reload loop re-logs,
        // which is intentional — a high count on one (route, user) pair is
        // exactly the signal an operator wants.
        if ( class_exists( '\\TT\\Infrastructure\\Audit\\AuditService' ) ) {
            ( new \TT\Infrastructure\Audit\AuditService() )->record(
                'mobile.desktop_prompt_shown',
                'view',
                0,
                [ 'view' => $blocked_view ]
            );
        }

        wp_enqueue_style(
            'tt-frontend-mobile-prompt',
            TT_PLUGIN_URL . 'assets/css/frontend-mobile-prompt.css',
            [ 'tt-public' ],
            TT_VERSION
        );

        self::renderHeader( __( 'Open on desktop', 'talenttrack' ) );

        $current_url   = self::currentUrl();
        $email_action  = admin_url( 'admin-post.php' );
        $dashboard_url = WizardEntryPoint::dashboardBaseUrl();
        $tt_msg        = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) $_GET['tt_msg'] ) : '';

        echo '<div class="tt-mprompt">';

        if ( $tt_msg === 'mobile_link_sent' ) {
            echo '<div class="tt-notice tt-notice-success tt-mprompt__flash" role="status">'
                . esc_html__( 'We sent the link to your account email. Check your inbox.', 'talenttrack' )
                . '</div>';
        } elseif ( $tt_msg === 'mobile_link_failed' ) {
            echo '<div class="tt-notice tt-notice-error tt-mprompt__flash" role="alert">'
                . esc_html__( "We couldn't send the link. Try the dashboard link below.", 'talenttrack' )
                . '</div>';
        }

        self::renderTitle( $blocked_view );

        echo '<p class="tt-mprompt__reason">' . esc_html( self::reasonFor( $blocked_view ) ) . '</p>';

        self::renderAlternative( $blocked_view );

        echo '<div class="tt-mprompt__actions">';
        echo '<form method="post" action="' . esc_url( $email_action ) . '">';
        wp_nonce_field( 'tt_mobile_email_link', 'tt_mobile_nonce' );
        echo '<input type="hidden" name="action" value="tt_mobile_email_link">';
        echo '<input type="hidden" name="target_url" value="' . esc_attr( $current_url ) . '">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">'
            . esc_html__( 'Email me the link', 'talenttrack' )
            . '</button>';
        echo '</form>';

        echo '<a href="' . esc_url( $dashboard_url ) . '" class="tt-btn tt-btn-secondary">'
            . esc_html__( 'Go to dashboard', 'talenttrack' )
            . '</a>';
        echo '</div>';

        // The override. A button, below a divider, visually subordinate —
        // available without being the obvious thing to press.
        echo '<div class="tt-mprompt__override">';
        echo '<p class="tt-mprompt__override-lead">'
            . esc_html__( 'You can open it here anyway. Expect to scroll sideways.', 'talenttrack' )
            . '</p>';
        echo '<a href="' . esc_url( add_query_arg( 'force_mobile', '1', $current_url ) ) . '" class="tt-btn">'
            . esc_html__( 'Show it anyway', 'talenttrack' )
            . '</a>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * The heading, naming the surface where one can be named.
     *
     * "Team attendance needs a desktop" is about what the reader was doing;
     * "This page is designed for desktop" is about the device. The first is
     * the one that does not read as a shrug.
     */
    private static function renderTitle( string $slug ): void {
        $name = self::nameFor( $slug );

        $title = $name === ''
            ? __( 'This page is designed for desktop.', 'talenttrack' )
            : sprintf(
                /* translators: %s: the name of the screen the user tried to open. */
                __( '%s is designed for desktop.', 'talenttrack' ),
                $name
            );

        echo '<h2 class="tt-mprompt__title">' . esc_html( $title ) . '</h2>';
    }

    /**
     * A human name for the blocked surface.
     *
     * Read from `TileRegistry` rather than a second hand-maintained list —
     * the tile's label is already the name this surface is called elsewhere
     * in the product, and a list here would drift from it. Sub-views with no
     * tile of their own return `''` and the heading falls back to the
     * generic phrasing, which is correct: inventing a name for a surface
     * nobody named is how you end up telling a coach that
     * "Eval Category Weights" needs a desktop.
     */
    private static function nameFor( string $slug ): string {
        if ( $slug === '' ) return '';

        foreach ( TileRegistry::allRegistered() as $tile ) {
            if ( ! is_array( $tile ) ) continue;
            if ( (string) ( $tile['view_slug'] ?? '' ) !== $slug ) continue;

            $label = (string) ( $tile['labels']['*'] ?? $tile['label'] ?? '' );
            return trim( $label );
        }

        return '';
    }

    /**
     * Why *this* surface wants a bigger screen.
     *
     * "Open it on a laptop for the best experience" is true of all 77 gated
     * surfaces and therefore tells a coach nothing. A reason that names the
     * actual work — this is a document, this is a matrix, this is something
     * you study — is the difference between a wall and an explanation.
     *
     * Grouped by family rather than written 77 times. The families are the
     * five questions `config/mobile_surfaces.php` documents for choosing a
     * class, so a surface's reason follows from the same thinking that
     * gated it.
     *
     * The literals live here rather than in `config/mobile_surfaces.php`
     * because that file's reasons are developer rationale: they are not
     * wrapped in `__()`, and it is `require`d early enough that translating
     * at load would be too soon. Two audiences, two sets of words.
     */
    private static function reasonFor( string $view_slug ): string {
        switch ( $view_slug ) {
            // Study surfaces.
            case 'course':
            case 'lesson':
                return __( 'A course is something you sit down and study, not something you read on the touchline. Open it at a desk and the lesson gets the width it was written for.', 'talenttrack' );

            // Spreadsheet-style entry across a whole roster.
            case 'attendance-grid':
            case 'minutes-grid':
            case 'ratings-grid':
                return __( 'This is spreadsheet-style entry across your whole roster — every player against every column. On a phone you would be scrolling in two directions at once.', 'talenttrack' );

            // Matrices: the grid IS the content.
            case 'matrix':
            case 'eval-coverage':
            case 'measurements-coverage':
            case 'minutes-audit':
            case 'eval-category-weights':
                return __( 'The rows and the columns are the content here. Reflowed into one column it is not a smaller table, it is a different and much less useful thing.', 'talenttrack' );

            // Documents authored to be printed.
            case 'match-prep':
            case 'match-analysis':
            case 'methodology':
                return __( 'This screen is the printed page — it is laid out at A4 because that is what it becomes. It needs the width of the document it is.', 'talenttrack' );

            // Builders and editors.
            case 'report-wizard':
            case 'persona-templates':
            case 'exports':
            case 'explore':
                return __( 'You build something here by dragging and comparing across the screen at once. There is no version of that which works under a thumb.', 'talenttrack' );

            // Settings whose blast radius reaches past one record.
            case 'roles':
            case 'modules':
            case 'features':
            case 'season-rollover':
            case 'migrations':
            case 'scout-access':
            case 'recycle-bin':
                return __( 'What you change here reaches well past any one record, so it is worth doing sitting down, with everything it affects visible at once.', 'talenttrack' );

            // Bulk upload + mapping + confirm.
            case 'players-import':
            case 'exercises-import':
            case 'import-history':
                return __( 'Importing means checking a file row by row before anything is saved. That check is the point, and it needs a screen you can read it on.', 'talenttrack' );
        }

        return __( 'Open it on a laptop or computer for the best experience.', 'talenttrack' );
    }

    /**
     * The nearest phone-friendly surface, where one genuinely exists.
     *
     * **Deliberately sparse.** Most gated surfaces have no phone
     * equivalent, and an invented one is worse than none: sending a coach
     * to a screen that does not do what they came for costs them more than
     * the honest "not on a phone" they already had.
     *
     * So only verified pairs are listed — each target is classified
     * `native` in `config/mobile_surfaces.php`, and each genuinely does the
     * same job in a phone-shaped way. Everything else returns null and the
     * page simply does not make the offer.
     *
     * Per-record alternatives are absent on purpose: match prep's phone
     * path is its share link, but that is minted per activity and cannot be
     * linked to from here without the record's own token.
     *
     * @return array{slug:string,label:string,lead:string}|null
     */
    private static function alternativeFor( string $view_slug ): ?array {
        switch ( $view_slug ) {
            case 'attendance-grid':
            case 'minutes-grid':
            case 'ratings-grid':
                return [
                    'slug'  => 'activities',
                    'label' => __( 'Open the activity', 'talenttrack' ),
                    'lead'  => __( 'Taking attendance for one session? Open the session itself — that screen is built for a phone.', 'talenttrack' ),
                ];
        }

        return null;
    }

    /**
     * Render the alternative, if the target is one this user can reach.
     *
     * Gated through `CrossViewLink` for the same reason every other
     * cross-view affordance is: offering somebody a way onward that then
     * refuses them is worse than not offering it.
     */
    private static function renderAlternative( string $slug ): void {
        $alt = self::alternativeFor( $slug );
        if ( $alt === null ) return;

        \TT\Shared\Frontend\Components\CrossViewLink::render(
            $alt['slug'],
            static function () use ( $alt ): void {
                $url = add_query_arg(
                    [ 'tt_view' => $alt['slug'] ],
                    WizardEntryPoint::dashboardBaseUrl()
                ); /* tt-xview-ok — the affordance is wrapped in CrossViewLink */

                echo '<div class="tt-mprompt__alt">';
                echo '<p class="tt-mprompt__alt-lead">' . esc_html( $alt['lead'] ) . '</p>';
                echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( $url ) . '">'
                    . esc_html( $alt['label'] )
                    . '</a>';
                echo '</div>';
            }
        );
    }

    /**
     * The full URL of the current request, used for the emailed link and
     * the override.
     */
    private static function currentUrl(): string {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return home_url( '/' );
        $path = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
        $host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
        if ( $host === '' ) return home_url( $path );
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . '://' . $host . $path;
    }
}
