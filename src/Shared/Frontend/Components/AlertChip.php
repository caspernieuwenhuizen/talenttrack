<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertChip (#2633, epic #2629) — the `inline` surface.
 *
 * A compact, severity-coloured chip that says how many open alert
 * occurrences a record carries, and links into the alerts inbox filtered to
 * that record.
 *
 * `inline` is the one surface in the epic's ladder a user cannot mute, and
 * the reason is worth stating where the code lives: this is not a
 * notification. It is the record's own current state, rendered next to the
 * record. Muting it would mean hiding a row's real condition from the
 * person looking straight at that row, which is a different and worse thing
 * than declining to be interrupted about it.
 *
 * ## One query per list, never one per row
 *
 * The only way an inline surface stays affordable is batching. A caller
 * rendering a list primes the whole page's subjects first:
 *
 *     AlertChip::prime( 'activity', $activity_ids );   // one query
 *     foreach ( $rows as $row ) {
 *         echo AlertChip::html( 'activity', (int) $row->id );  // cache hits
 *     }
 *
 * `html()` will lazily prime a single subject when it has not been resolved
 * yet, so a detail view needs no ceremony. That fallback is a convenience
 * for the one-record case, NOT a substitute for `prime()` on a list — fifty
 * unprimed rows are fifty queries, which is exactly the failure mode this
 * component exists to avoid.
 *
 * ## What it does not do
 *
 * It does not evaluate. Like the banner and the bell, it reads persisted
 * rows (epic decision 2), so adding an alert definition can never make a
 * list slower to render.
 *
 * It renders nothing for resolved occurrences. On the player record that is
 * epic decision 12 in force: open alerts are a gap in the record worth
 * showing; resolved ones are operational exhaust, they are never shown, and
 * nothing about them is written to the player's journey.
 */
final class AlertChip {

    /** Subject types this component understands (`$type . ':' . $id` cache keys). */
    public const SUBJECT_PLAYER = 'player';

    /** @var array<string, list<object>> "type:id" => occurrences (empty list = resolved, no alerts) */
    private static $cache = [];

    /** @var bool */
    private static $css_enqueued = false;

    /**
     * Reset the per-request caches. Tests only.
     *
     * Clears the memoised gate and dashboard URL too: a test that switches
     * user or capability between cases would otherwise inherit the previous
     * case's answer and pass for the wrong reason.
     */
    public static function flush(): void {
        self::$cache          = [];
        self::$inboxReachable = null;
        self::$dashboardBase  = null;
    }

    /**
     * Enqueue the chip stylesheet.
     *
     * Called from `DashboardShortcode` for every dashboard render rather
     * than lazily from `html()`: a chip can appear inside markup that is
     * built as a string long after `wp_enqueue_scripts` has run, and a
     * stylesheet that arrives too late is a stylesheet that does not apply.
     */
    public static function enqueue(): void {
        if ( self::$css_enqueued ) return;
        if ( ! function_exists( 'wp_enqueue_style' ) ) return;
        wp_enqueue_style(
            'tt-frontend-alert-chips',
            TT_PLUGIN_URL . 'assets/css/frontend-alert-chips.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        self::$css_enqueued = true;
    }

    /**
     * Resolve every subject on the page in one query.
     *
     * Already-cached ids are skipped, so calling this twice on a page that
     * renders two lists of the same type costs one query for the second
     * list's new ids and nothing for the overlap.
     *
     * @param list<int> $ids
     */
    public static function prime( string $subjectType, array $ids ): void {
        $subjectType = sanitize_key( $subjectType );
        if ( $subjectType === '' ) return;

        $wanted = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) continue;
            if ( isset( self::$cache[ $subjectType . ':' . $id ] ) ) continue;
            $wanted[ $id ] = true;
        }
        if ( empty( $wanted ) ) return;

        $wanted = array_keys( $wanted );

        // An operator who switched the Alerts module off must stop seeing
        // alerts, including the ones already in the table. The guard lives
        // here rather than in each call site because `prime()` is the single
        // door every read goes through — a placement added later inherits
        // the off-switch without knowing it exists (#2599).
        if ( ! self::moduleEnabled() ) {
            foreach ( $wanted as $id ) {
                self::$cache[ $subjectType . ':' . $id ] = [];
            }
            return;
        }

        $found = $subjectType === self::SUBJECT_PLAYER
            ? ( new AlertOccurrencesRepository() )->openByPlayers( $wanted )
            : ( new AlertOccurrencesRepository() )->openBySubjects( $subjectType, $wanted );

        // Seed the misses too. A subject with no open occurrence is a real
        // answer ("this record is clean"), and caching it is what stops a
        // second `html()` call re-querying for a row that has no chip.
        foreach ( $wanted as $id ) {
            self::$cache[ $subjectType . ':' . $id ] = $found[ $id ] ?? [];
        }
    }

    /**
     * Prime the player surface. Thin alias so a caller reads as what it
     * means rather than passing a magic subject-type string.
     *
     * @param list<int> $playerIds
     */
    public static function primePlayers( array $playerIds ): void {
        self::prime( self::SUBJECT_PLAYER, $playerIds );
    }

    /**
     * The chip's HTML, or '' when the record carries no open alert.
     *
     * @param array<string,mixed> $opts 'class' => extra CSS classes
     */
    public static function html( string $subjectType, int $subjectId, array $opts = [] ): string {
        $subjectType = sanitize_key( $subjectType );
        if ( $subjectType === '' || $subjectId <= 0 ) return '';

        $key = $subjectType . ':' . $subjectId;
        if ( ! isset( self::$cache[ $key ] ) ) {
            self::prime( $subjectType, [ $subjectId ] );
        }

        $rows = self::$cache[ $key ] ?? [];
        if ( empty( $rows ) ) return '';

        // The chip must not be a link to a view the user cannot open. The
        // inbox is per-recipient and these rows are already theirs, so the
        // gate passes for anyone who has a chip at all — but routing the
        // decision through the shared registry is what keeps it true if the
        // inbox ever grows a capability of its own (CLAUDE.md §7).
        if ( ! self::inboxReachable() ) return '';

        $count = count( $rows );
        // Loudest first out of the repository, so row zero is the severity
        // the chip should wear.
        $severity = Severity::normalise( (string) ( $rows[0]->severity ?? '' ) );

        $args = $subjectType === self::SUBJECT_PLAYER
            ? [ 'tt_view' => 'alerts', 'player_id' => $subjectId ]
            : [ 'tt_view' => 'alerts', 'subject_type' => $subjectType, 'subject_id' => $subjectId ];
        $url = BackLink::appendTo( (string) add_query_arg( $args, self::dashboardBase() ) ); /* tt-xview-ok */

        $label = Severity::label( $severity );
        $aria  = sprintf(
            /* translators: 1: number of open alerts, 2: severity label, e.g. "Needs attention" */
            _n(
                '%1$d open alert (%2$s). Open the alerts list.',
                '%1$d open alerts (%2$s). Open the alerts list.',
                $count,
                'talenttrack'
            ),
            $count,
            $label
        );

        $classes = 'tt-alert-chip tt-alert-chip--' . $severity;
        $extra   = trim( (string) ( $opts['class'] ?? '' ) );
        if ( $extra !== '' ) $classes .= ' ' . $extra;

        return sprintf(
            '<a class="%1$s" href="%2$s" aria-label="%3$s">'
                . '<span class="tt-alert-chip__dot" aria-hidden="true"></span>'
                . '<span class="tt-alert-chip__count">%4$s</span>'
                . '<span class="tt-alert-chip__label">%5$s</span>'
                . '</a>',
            esc_attr( $classes ),
            esc_url( $url ),
            esc_attr( $aria ),
            esc_html( (string) $count ),
            // The visible label carries the meaning on its own — no tooltip,
            // no hover, no colour-only signal (CLAUDE.md §2).
            esc_html( $label )
        );
    }

    /** Player-record variant. */
    public static function playerHtml( int $playerId, array $opts = [] ): string {
        return self::html( self::SUBJECT_PLAYER, $playerId, $opts );
    }

    /** @var bool|null per-request memo */
    private static $inboxReachable = null;

    /**
     * Whether this user can open the alerts inbox.
     *
     * Memoised per request, and that is not an optimisation detail — it is
     * what makes the batching claim true. `prime()` removes the per-row
     * occurrence read, but the gate and the dashboard-page lookup below were
     * still resolved inside every `html()` call, so a fifty-row list paid
     * forty-nine queries that had nothing to do with alerts. Neither answer
     * can change within a request.
     */
    private static function inboxReachable(): bool {
        if ( self::$inboxReachable === null ) {
            self::$inboxReachable = CrossViewLink::allows( 'alerts' );
        }
        return self::$inboxReachable;
    }

    /** @var string|null per-request memo */
    private static $dashboardBase = null;

    /** The dashboard page URL. Resolved once; see `inboxReachable()`. */
    private static function dashboardBase(): string {
        if ( self::$dashboardBase === null ) {
            self::$dashboardBase = RecordLink::dashboardUrl();
        }
        return self::$dashboardBase;
    }

    /**
     * Whether the Alerts module is switched on for this install.
     *
     * Resolved once per request: the state cache behind `isEnabled()` is
     * cheap, but a fifty-row list would still consult it fifty times for an
     * answer that cannot change mid-request.
     */
    public static function moduleEnabled(): bool {
        static $enabled = null;
        if ( $enabled !== null ) return $enabled;

        if ( ! class_exists( '\\TT\\Core\\ModuleRegistry' ) ) {
            $enabled = true;
            return $enabled;
        }
        // Keyed exactly as `config/modules.php` declares it — no leading
        // backslash. `isEnabled()` defaults an unknown key to true, so a
        // mismatch here would silently disable the off-switch rather than
        // failing.
        $enabled = \TT\Core\ModuleRegistry::isEnabled( 'TT\\Modules\\Alerts\\AlertsModule' );
        return $enabled;
    }

    /** Echo helper for view files that are already emitting markup. */
    public static function render( string $subjectType, int $subjectId, array $opts = [] ): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
        echo self::html( $subjectType, $subjectId, $opts );
    }
}
