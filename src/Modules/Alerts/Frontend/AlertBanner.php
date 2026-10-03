<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertBanner (#2631, epic #2629) — the `banner` surface.
 *
 * Renders open occurrences at the top of the dashboard, above the body,
 * alongside the flash-message queue.
 *
 * **It never evaluates.** Epic decision 2: the render reads persisted rows
 * and nothing else, so adding a definition can never slow down a login. The
 * cron sweep is what keeps those rows true. If this class ever grows a call
 * into `AlertEvaluator`, that decision has been broken.
 *
 * Presentation is deliberately close to `FlashMessages`: a coach should not
 * have to learn a second visual language for "something needs your
 * attention". The difference is that a flash message is about what just
 * happened and an alert is about what is still true, which is why these
 * cannot be dismissed away permanently.
 *
 * #3034 — each row carries a "Not today" control wired to the snooze
 * endpoint (#2632). Snoozing is not muting: the occurrence stays in the
 * table, keeps being reconciled, and comes back when the snooze lapses if
 * the condition is still true. Operational alerts — the ones about a
 * child's safety — get no control, matching
 * `AlertPolicyResolver::lockReason()`.
 *
 * #4191 — on a phone the banner shows on the dashboard root only. Views get
 * a one-line strip for operational alerts instead, linking to the inbox.
 */
final class AlertBanner {

    /** Most occurrences rendered inline before collapsing to a count. */
    private const MAX_VISIBLE = 3;

    public static function init(): void {
        // Priority 20 puts alerts below the flash queue: "your evaluation
        // was saved" is about the action just taken and should stay at the
        // top; alerts are ambient state.
        add_action( 'tt_dashboard_before_body', [ self::class, 'render' ], 20 );
    }

    public static function render(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;

        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return;

        // #2632 — only occurrences whose alert may use the banner surface
        // for this user. Filtering here rather than in SQL keeps the
        // precedence rules in one place (`AlertPolicyResolver`) instead of
        // half in a query; the row count per user is small enough that the
        // cost is a few array lookups.
        //
        // Over-fetched because the filter runs after the query: asking for
        // exactly MAX_VISIBLE would show fewer than that whenever the top
        // rows happen to be badge-only.
        $policy = new AlertPolicyResolver();
        $rows   = $repo->openForUser( $user_id, self::MAX_VISIBLE + 20 );

        $eligible = [];
        foreach ( $rows as $row ) {
            if ( $policy->allows( $user_id, (string) ( $row->alert_key ?? '' ), Surface::BANNER ) ) {
                $eligible[] = $row;
            }
        }
        if ( empty( $eligible ) ) return;

        // #4191 — on a phone the full banner belongs to the dashboard root
        // only. Above a list or a record it cost ~440px and pushed the first
        // row below the fold; the bell and the inbox still carry every alert.
        // Root vs. view is known here, so the root renders as it always has.
        if ( self::onDashboardRoot() ) {
            self::renderBar( $eligible, '' );
            return;
        }

        // On a view the viewport decides, and the server cannot see it. A
        // confirmed phone user agent gets no banner markup at all — no cards,
        // no snooze buttons sitting in the DOM unseen. Everyone else gets the
        // banner with a modifier that the sheet hides below 768px, which also
        // covers a narrow desktop window and a phone the detector misses.
        $phone = self::isPhone();
        if ( ! $phone ) {
            self::renderBar( $eligible, 'tt-alert-bar--view' );
        }

        self::renderSafetyStrip( $eligible, $phone );
    }

    /** True on the dashboard landing, where no `tt_view` is requested. */
    private static function onDashboardRoot(): bool {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_view'] ) ) : '';
        return $view === '';
    }

    private static function isPhone(): bool {
        return class_exists( '\\TT\\Shared\\MobileDetector' ) && \TT\Shared\MobileDetector::isPhone();
    }

    /**
     * The compact strip for operational alerts on a phone view (#4191).
     *
     * Operational alerts concern a child's safety. Hiding the banner on a
     * phone is fine for everything else, because "it was on the bell" is
     * good enough for a missing evaluation; it is not good enough for a
     * safeguarding concern. So those keep one line, one tap to the inbox.
     *
     * When the full banner was also emitted (no phone user agent), the strip
     * carries a modifier the sheet hides from 768px up, so a desktop never
     * shows the same alert twice.
     *
     * @param list<object> $eligible
     */
    private static function renderSafetyStrip( array $eligible, bool $phone ): void {
        $count = 0;
        foreach ( $eligible as $row ) {
            $definition = AlertRegistry::find( (string) ( $row->alert_key ?? '' ) );
            if ( $definition !== null && $definition->isOperational() ) {
                $count++;
            }
        }
        if ( $count === 0 ) return;

        $label = sprintf(
            /* translators: %d: number of open alerts about a child's safety */
            _n( '%d safety alert', '%d safety alerts', $count, 'talenttrack' ),
            $count
        );

        printf(
            '<a class="%1$s" href="%2$s">'
                . '<span class="tt-alert-strip__text">%3$s</span>'
                . '<span class="tt-alert-strip__cta">%4$s</span>'
            . '</a>',
            esc_attr( $phone ? 'tt-alert-strip' : 'tt-alert-strip tt-alert-strip--narrow-only' ),
            esc_url( (string) add_query_arg( 'tt_view', 'alerts', self::dashboardBase() ) ), /* tt-xview-ok */
            esc_html( $label ),
            esc_html__( 'Open', 'talenttrack' )
        );
    }

    private static function dashboardBase(): string {
        if ( class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' ) ) {
            return \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
        }
        return home_url( '/' );
    }

    /** @param list<object> $eligible */
    private static function renderBar( array $eligible, string $modifier ): void {
        $visible   = array_slice( $eligible, 0, self::MAX_VISIBLE );
        $remaining = max( 0, count( $eligible ) - count( $visible ) );

        $class = trim( 'tt-alert-bar ' . $modifier );
        echo '<div class="' . esc_attr( $class ) . '" role="region" aria-label="' . esc_attr__( 'Alerts', 'talenttrack' ) . '">';

        foreach ( $visible as $row ) {
            self::renderOne( $row );
        }

        if ( $remaining > 0 ) {
            printf(
                '<p class="tt-alert-bar-more">%s</p>',
                esc_html( sprintf(
                    /* translators: %d: number of further alerts not shown */
                    _n( '%d more alert needs your attention.', '%d more alerts need your attention.', $remaining, 'talenttrack' ),
                    $remaining
                ) )
            );
        }

        echo '</div>';
    }

    private static function renderOne( object $row ): void {
        $payload  = self::payload( $row );
        $title    = isset( $payload['title'] ) ? (string) $payload['title'] : '';
        $url      = isset( $payload['url'] ) ? (string) $payload['url'] : '';
        $severity = Severity::normalise( (string) ( $row->severity ?? '' ) );

        if ( $title === '' ) return;

        printf(
            '<div class="tt-alert tt-alert-%1$s">'
                . '<span class="tt-alert-sev">%2$s</span>'
                . '<span class="tt-alert-text">%3$s</span>'
                . '%4$s%5$s'
            . '</div>',
            esc_attr( $severity ),
            esc_html( Severity::label( $severity ) ),
            esc_html( $title ),
            $url !== ''
                ? sprintf(
                    '<a class="tt-alert-cta" href="%s">%s</a>',
                    esc_url( $url ),
                    esc_html__( 'Open', 'talenttrack' )
                )
                : '',
            self::snoozeControl( $row )
        );
    }

    /**
     * The "not today" control (#3034).
     *
     * `POST /alerts/{uuid}/snooze` and its repository side have been in place
     * since #2632, and the banner has never had anything wired to them — this
     * class's docblock still said dismissal was pending. A coach who cannot
     * act on an alert right now had no way to clear it short of fixing the
     * underlying thing, which is the correct default for a *permanent* mute
     * and much too blunt for "not this morning".
     *
     * A day is the default because that is what the review asked for, and
     * because an alert is state rather than an event: if the condition is
     * still true tomorrow the reconcile puts it back, which is exactly the
     * behaviour that makes a short snooze safe.
     *
     * Operational alerts get no control. Those are the ones about a child's
     * safety, and `AlertPolicyResolver::lockReason()` already refuses to let
     * a user mute them — offering a snooze here would route around that.
     */
    private static function snoozeControl( object $row ): string {
        $uuid = (string) ( $row->uuid ?? '' );
        $key  = (string) ( $row->alert_key ?? '' );
        if ( $uuid === '' || $key === '' ) return '';

        $definition = AlertRegistry::find( $key );
        if ( $definition !== null && $definition->isOperational() ) return '';

        return sprintf(
            '<button type="button" class="tt-alert-snooze" data-tt-alert-snooze="%1$s" data-tt-alert-duration="day" title="%2$s">%3$s</button>',
            esc_attr( $uuid ),
            esc_attr__( 'Hide this until tomorrow. It comes back if it is still true.', 'talenttrack' ),
            esc_html__( 'Not today', 'talenttrack' )
        );
    }

    /** @return array<string,mixed> */
    private static function payload( object $row ): array {
        $raw = (string) ( $row->payload_json ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
