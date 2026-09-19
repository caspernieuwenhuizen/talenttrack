<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Services\AlertOversight;
use TT\Shared\Frontend\Components\AlertChip;
use TT\Shared\Frontend\Components\FilterBar;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAlertsInboxView (#2633, epic #2629) — `?tt_view=alerts`.
 *
 * The full list behind every other alert surface. The banner shows three;
 * the bell shows a number; a chip shows one record's worth. This is where a
 * coach goes when they want the whole picture, and it is the deep-link
 * target every chip points at, which is why it carries a subject filter.
 *
 * ## Navigation (CLAUDE.md §5)
 *
 * Exactly two affordances: the breadcrumb chain ending at Dashboard, and
 * the `tt_back` pill the chain renders above itself when the entry URL
 * carried one. No third back link, no module nav of its own, no
 * hand-rolled tab strip — the state filter is a status-pill group inside
 * the shared FilterBar, which is a filter, not navigation. The chain is
 * emitted on **every** path, the not-logged-in early return included: a
 * dead end with no way out is the failure mode §5 exists to prevent.
 *
 * ## What it does not do
 *
 * It never evaluates (epic decision 2) and it never writes. Read / snooze /
 * dismiss controls belong to the preference wave (#2632); until then the
 * only way to clear an alert is to fix the thing it points at, which is the
 * behaviour the engine was designed for anyway.
 */
final class FrontendAlertsInboxView extends FrontendViewBase {

    /** Occurrences listed before the list is cut off. */
    private const PAGE_SIZE = 100;

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        AlertChip::enqueue();
        wp_enqueue_style(
            'tt-frontend-alerts',
            TT_PLUGIN_URL . 'assets/css/frontend-alerts.css',
            [ 'tt-public' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id ): void {
        $title = __( 'Alerts', 'talenttrack' );

        if ( $user_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You need to be signed in to see your alerts.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( $title );
        echo '<h1 class="tt-fview-title">' . esc_html( $title ) . '</h1>';

        $repo = new AlertOccurrencesRepository();
        // Same off-switch the chips honour (#2599): an operator who turned
        // the module off gets the "not available" notice rather than a list
        // of alerts the module is no longer maintaining.
        if ( ! AlertChip::moduleEnabled() || ! $repo->tableExists() ) {
            // The migration has not run on this install yet. Say so plainly
            // rather than rendering an empty list that reads as "you are all
            // caught up" when the truth is "nothing has been checked".
            echo '<p class="tt-notice">' . esc_html__( 'Alerts are not available on this site yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $filters = self::filtersFromQuery();

        self::renderRollup( $user_id );
        self::renderFilters( $filters );

        $args = [
            'state'        => $filters['state'],
            'alert_keys'   => self::alertKeysFor( $filters ),
            'severity'     => $filters['severity'],
            'subject_type' => $filters['subject_type'],
            'subject_id'   => $filters['subject_id'],
            'player_id'    => $filters['player_id'],
        ];

        // #3665 — the total, not `count( $rows )`. Past the page size the
        // two are different numbers, and the one worth showing is how many
        // alerts there are, not how many fitted on this page.
        $total = $repo->countForUser( $user_id, $args );
        $pages = (int) max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
        $page  = min( max( 1, $filters['page'] ), $pages );

        $rows = $repo->listForUser( $user_id, $args + [
            'limit'  => self::PAGE_SIZE,
            'offset' => ( $page - 1 ) * self::PAGE_SIZE,
        ] );

        // #3339 — the region the filters govern (epic #3335). The empty
        // state is inside it: filtering down to nothing has to replace the
        // list with "nothing needs your attention", not leave the previous
        // rows sitting there.
        printf( '<div data-tt-filter-region data-tt-filter-count="%d">', $total );

        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Nothing needs your attention right now.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<ul class="tt-alert-list">';
        foreach ( $rows as $row ) {
            self::renderRow( $row );
        }
        echo '</ul>';
        self::renderPager( $filters, $page, $pages );
        echo '</div>';
    }

    /**
     * The key filter behind the module select and an `alert_key` deep link.
     *
     * A module that registers nothing, or a key outside the chosen module,
     * has to narrow the list to nothing. Returning `[]` would mean "no key
     * filter" in the repository and show everything, which is the opposite
     * answer to the one asked.
     *
     * @param array<string,mixed> $filters
     * @return list<string>
     */
    private static function alertKeysFor( array $filters ): array {
        $key    = (string) $filters['alert_key'];
        $module = (string) $filters['module'];

        if ( $key !== '' ) return [ $key ];
        if ( $module === '' ) return [];

        return array_keys( AlertRegistry::forModule( $module ) );
    }

    /**
     * Previous / next below the list (#3665).
     *
     * Inside `data-tt-filter-region` so a filter change replaces it along
     * with the rows, and every link carries the current filters minus the
     * page: narrowing the list has to start again at page 1, or a coach
     * who filters while on page 4 lands on an empty list that reads as
     * "no alerts" when it means "no page 4".
     *
     * @param array<string,mixed> $filters
     */
    private static function renderPager( array $filters, int $page, int $pages ): void {
        if ( $pages <= 1 ) return;

        $base = RecordLink::dashboardUrl();
        $qs   = self::pageQueryArgs( $filters );

        echo '<nav class="tt-alert-pager" aria-label="' . esc_attr__( 'Alert pages', 'talenttrack' ) . '">';

        if ( $page > 1 ) {
            printf(
                '<a class="tt-btn tt-btn-secondary tt-alert-pager__link" href="%s" rel="prev">%s</a>',
                esc_url( add_query_arg( $qs + [ 'alerts_page' => $page - 1 ], $base ) ), /* tt-xview-ok */
                esc_html__( 'Previous', 'talenttrack' )
            );
        }

        printf(
            '<span class="tt-alert-pager__status">%s</span>',
            esc_html( sprintf(
                /* translators: 1: current page number, 2: total number of pages */
                __( 'Page %1$d of %2$d', 'talenttrack' ),
                $page,
                $pages
            ) )
        );

        if ( $page < $pages ) {
            printf(
                '<a class="tt-btn tt-btn-secondary tt-alert-pager__link" href="%s" rel="next">%s</a>',
                esc_url( add_query_arg( $qs + [ 'alerts_page' => $page + 1 ], $base ) ), /* tt-xview-ok */
                esc_html__( 'Next', 'talenttrack' )
            );
        }

        echo '</nav>';
    }

    /**
     * The current filter state as query args, for a pager link.
     *
     * @param array<string,mixed> $filters
     * @return array<string,string>
     */
    private static function pageQueryArgs( array $filters ): array {
        $out = [ 'tt_view' => 'alerts', 'state' => (string) $filters['state'] ];

        foreach ( [ 'module', 'severity', 'alert_key', 'subject_type' ] as $key ) {
            if ( (string) $filters[ $key ] !== '' ) $out[ $key ] = (string) $filters[ $key ];
        }
        foreach ( [ 'subject_id', 'player_id' ] as $key ) {
            if ( (int) $filters[ $key ] > 0 ) $out[ $key ] = (string) (int) $filters[ $key ];
        }

        return $out;
    }

    /**
     * Normalised filter state from the query string.
     *
     * @return array{module:string,severity:string,state:string,alert_key:string,page:int,subject_type:string,subject_id:int,player_id:int}
     */
    private static function filtersFromQuery(): array {
        $state = isset( $_GET['state'] ) ? sanitize_key( (string) $_GET['state'] ) : 'open';
        if ( ! in_array( $state, [ 'open', 'unread', 'resolved' ], true ) ) $state = 'open';

        $severity = isset( $_GET['severity'] ) ? sanitize_key( (string) $_GET['severity'] ) : '';
        if ( ! in_array( $severity, Severity::all(), true ) ) $severity = '';

        $module = isset( $_GET['module'] ) ? sanitize_key( (string) $_GET['module'] ) : '';
        if ( $module !== '' && empty( AlertRegistry::forModule( $module ) ) ) $module = '';

        // #3665 — an alert key carries a dot, so `sanitize_key` is wrong
        // here. It is validated against the registry instead: an unknown
        // key drops to "no key filter" rather than reaching SQL.
        $alert_key = isset( $_GET['alert_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['alert_key'] ) ) : '';
        if ( $alert_key !== '' && AlertRegistry::find( $alert_key ) === null ) $alert_key = '';
        if ( $alert_key !== '' && $module !== ''
            && ! array_key_exists( $alert_key, AlertRegistry::forModule( $module ) ) ) {
            $alert_key = '';
        }

        // `alerts_page`, not `page` or `paged`: both are WordPress query
        // vars, and reusing one here hands the request to the wrong
        // template before this view ever runs.
        $page = isset( $_GET['alerts_page'] ) ? absint( (string) $_GET['alerts_page'] ) : 1;

        return [
            'module'       => $module,
            'severity'     => $severity,
            'state'        => $state,
            'alert_key'    => $alert_key,
            'page'         => max( 1, $page ),
            'subject_type' => isset( $_GET['subject_type'] ) ? sanitize_key( (string) $_GET['subject_type'] ) : '',
            'subject_id'   => isset( $_GET['subject_id'] ) ? absint( (string) $_GET['subject_id'] ) : 0,
            'player_id'    => isset( $_GET['player_id'] ) ? absint( (string) $_GET['player_id'] ) : 0,
        ];
    }

    /**
     * Module / severity selects plus the state pills, through the shared
     * FilterBar so this surface gets the same 360px bottom-sheet treatment
     * as every other list rather than a bespoke row of controls.
     *
     * @param array<string,mixed> $filters
     */
    private static function renderFilters( array $filters ): void {
        $base = RecordLink::dashboardUrl();

        $modules = [];
        foreach ( AlertRegistry::all() as $alert ) {
            $modules[ $alert->module() ] = self::moduleLabel( $alert->module() );
        }
        ksort( $modules );

        $severities = [];
        foreach ( array_reverse( Severity::all() ) as $value ) {
            $severities[ $value ] = Severity::label( $value );
        }

        // The state group is link-based, so each option carries the rest of
        // the current filter state in its own URL. Preserving the subject
        // filter matters most: a chip deep-link lands here scoped to one
        // record, and switching to "Resolved" must stay on that record.
        //
        // #3665 — `alerts_page` is deliberately NOT carried here. Changing
        // the state changes how many rows there are, so the page number
        // from the previous state means nothing; starting again at page 1
        // is the only answer that cannot land on an empty list.
        $state_base = array_filter( [
            'tt_view'      => 'alerts',
            'module'       => $filters['module'],
            'severity'     => $filters['severity'],
            'alert_key'    => $filters['alert_key'],
            'subject_type' => $filters['subject_type'],
            'subject_id'   => $filters['subject_id'] > 0 ? $filters['subject_id'] : '',
            'player_id'    => $filters['player_id'] > 0 ? $filters['player_id'] : '',
        ], static fn( $v ): bool => $v !== '' && $v !== 0 );

        $state_labels = [
            'open'     => __( 'Open', 'talenttrack' ),
            'unread'   => __( 'Unread', 'talenttrack' ),
            'resolved' => __( 'Resolved', 'talenttrack' ),
        ];
        $state_options = [];
        foreach ( $state_labels as $value => $label ) {
            $state_options[] = [
                'value'  => $value,
                'label'  => $label,
                'url'    => add_query_arg( array_merge( $state_base, [ 'state' => $value ] ), $base ), /* tt-xview-ok */
                'active' => $filters['state'] === $value,
            ];
        }

        $groups = [
            [
                'type'        => 'select',
                'key'         => 'module',
                'label'       => __( 'Area', 'talenttrack' ),
                'name'        => 'module',
                'options'     => $modules,
                'selected'    => $filters['module'],
                'placeholder' => __( 'All areas', 'talenttrack' ),
            ],
            [
                'type'        => 'select',
                'key'         => 'severity',
                'label'       => __( 'Severity', 'talenttrack' ),
                'name'        => 'severity',
                'options'     => $severities,
                'selected'    => $filters['severity'],
                'placeholder' => __( 'Any severity', 'talenttrack' ),
            ],
            [
                'type'    => 'status',
                'key'     => 'state',
                'param'   => 'state',
                'label'   => __( 'State', 'talenttrack' ),
                // #3320 — the state the list opens on. Without it the bar
                // treats every state as a filter, so the default chipped as
                // "State: Open" on arrival: a chip for the one thing that is
                // NOT filtered, whose ✕ pointed at the option it was already
                // on. The view already knew the default — its own count read
                // `state !== 'open'` — the group config just never said so.
                'default_value' => 'open',
                'options' => $state_options,
            ],
        ];

        $hidden = [ 'tt_view' => 'alerts', 'state' => $filters['state'] ];
        if ( $filters['alert_key'] !== '' )    $hidden['alert_key']    = $filters['alert_key'];
        if ( $filters['subject_type'] !== '' ) $hidden['subject_type'] = $filters['subject_type'];
        if ( $filters['subject_id'] > 0 )      $hidden['subject_id']   = (string) $filters['subject_id'];
        if ( $filters['player_id'] > 0 )       $hidden['player_id']    = (string) $filters['player_id'];

        // #3320 — no hand-rolled `active_count`. This is the one surface that
        // lets FilterBar derive its own chips, and passing a count alongside
        // them re-opened exactly the drift the derivation closes: the bar
        // keeps the caller's number whenever one is given, so the badge and
        // the chips had different sources and could disagree by construction.
        // With the count omitted, the badge IS the chip count.
        FilterBar::render( [
            'groups'      => $groups,
            'hidden'      => $hidden,
            'form_action' => $base,
            'reset_url'   => add_query_arg( [ 'tt_view' => 'alerts' ], $base ), /* tt-xview-ok */
            // #3339 — filter in place (epic #3335).
            'refresh'     => true,
        ] );

        // A chip deep-link scopes the whole list to one record. Say so, and
        // offer the way back to everything — without that line the list
        // reads as "you have one alert" when it means "you have one alert
        // about this record".
        if ( $filters['subject_id'] > 0 || $filters['player_id'] > 0 ) {
            printf(
                '<p class="tt-alert-scope">%s <a href="%s">%s</a></p>',
                esc_html__( 'Showing alerts for one record only.', 'talenttrack' ),
                esc_url( add_query_arg( [ 'tt_view' => 'alerts' ], $base ) ), /* tt-xview-ok */
                esc_html__( 'Show all alerts', 'talenttrack' )
            );
        }

        // #3665 — the same courtesy for an `alert_key` deep link. Without
        // it a list narrowed to one definition is indistinguishable from
        // an academy with one kind of problem.
        if ( $filters['alert_key'] !== '' ) {
            $definition = AlertRegistry::find( (string) $filters['alert_key'] );
            printf(
                '<p class="tt-alert-scope">%s <a href="%s">%s</a></p>',
                esc_html( sprintf(
                    /* translators: %s: the name of one kind of alert, e.g. "Coaching certificate expiring" */
                    __( 'Showing one kind of alert: %s.', 'talenttrack' ),
                    $definition !== null ? $definition->label() : (string) $filters['alert_key']
                ) ),
                esc_url( add_query_arg( [ 'tt_view' => 'alerts' ], $base ) ), /* tt-xview-ok */
                esc_html__( 'Show all alerts', 'talenttrack' )
            );
        }
    }

    /**
     * The oversight roll-up (epic decision 7's counterpart).
     *
     * Renders only for a viewer who oversees more than one team, and only
     * from teams the capability model already grants them. It reads a
     * GROUP BY over occurrences that already exist — a Head of Development
     * never receives a row of their own, and this surface is what makes
     * that hold up in practice.
     */
    private static function renderRollup( int $user_id ): void {
        if ( ! AlertOversight::isAvailableTo( $user_id ) ) return;

        $rows = AlertOversight::forUser( $user_id );
        if ( empty( $rows ) ) return;

        echo '<section class="tt-alert-rollup" aria-labelledby="tt-alert-rollup-title">';
        printf(
            '<h2 class="tt-alert-rollup__title" id="tt-alert-rollup-title">%s</h2>',
            esc_html( sprintf(
                /* translators: %d: number of teams with open alerts */
                _n(
                    '%d team has records that need attention',
                    '%d teams have records that need attention',
                    count( $rows ),
                    'talenttrack'
                ),
                count( $rows )
            ) )
        );
        echo '<ul class="tt-alert-rollup__list">';
        foreach ( $rows as $row ) {
            $team_id = (int) $row['team_id'];
            printf(
                '<li class="tt-alert-rollup__row"><span class="tt-alert-chip tt-alert-chip--%1$s tt-alert-chip--static">'
                    . '<span class="tt-alert-chip__dot" aria-hidden="true"></span>'
                    . '<span class="tt-alert-chip__count">%2$s</span></span>%3$s</li>',
                esc_attr( (string) $row['severity'] ),
                esc_html( (string) $row['count'] ),
                $team_id > 0
                    ? \TT\Shared\Frontend\Components\RecordLink::inline(
                        (string) $row['team_name'],
                        \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', $team_id )
                    )
                    : esc_html( (string) $row['team_name'] )
            );
        }
        echo '</ul>';
        echo '<p class="tt-alert-rollup__note">'
            . esc_html__( 'These are the conditions your teams\' coaches have been told about. You are not sent one alert per team.', 'talenttrack' )
            . '</p>';
        echo '</section>';
    }

    private static function renderRow( object $row ): void {
        $payload  = [];
        $raw      = (string) ( $row->payload_json ?? '' );
        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $payload = $decoded;
        }

        $severity = Severity::normalise( (string) ( $row->severity ?? '' ) );
        $title    = isset( $payload['title'] ) ? (string) $payload['title'] : '';
        $url      = isset( $payload['url'] ) ? (string) $payload['url'] : '';
        $resolved = ! empty( $row->resolved_at );

        if ( $title === '' ) {
            $definition = AlertRegistry::find( (string) ( $row->alert_key ?? '' ) );
            $title      = $definition !== null ? $definition->label() : __( 'Alert', 'talenttrack' );
        }

        printf(
            '<li class="tt-alert tt-alert-%1$s%2$s"><span class="tt-alert-sev">%3$s</span><span class="tt-alert-text">%4$s</span>%5$s</li>',
            esc_attr( $severity ),
            $resolved ? ' tt-alert--resolved' : '',
            esc_html( $resolved ? __( 'Resolved', 'talenttrack' ) : Severity::label( $severity ) ),
            esc_html( $title ),
            ( $url !== '' && ! $resolved )
                ? sprintf(
                    '<a class="tt-alert-cta" href="%s">%s</a>',
                    esc_url( \TT\Shared\Frontend\Components\BackLink::appendTo( $url ) ),
                    esc_html__( 'Open', 'talenttrack' )
                )
                : ''
        );
    }

    /**
     * Human label for an alert definition's module slug.
     *
     * `ModuleMetadata` is keyed by module CLASS, and an alert's `module()`
     * is a grouping slug that need not correspond to one — a definition
     * about goals lives in the PDP module. Rather than invent a
     * slug-to-class mapping that would drift the first time a definition
     * moves, translate the slugs that exist and let anything new fall
     * through readably. A new module's alerts appear in the filter on the
     * day they ship; only their label waits for a translation.
     *
     * The `tt_alert_module_label` filter lets a module that ships its own
     * definitions supply its own label without editing this method.
     */
    private static function moduleLabel( string $slug ): string {
        if ( $slug === '' ) return '';

        switch ( $slug ) {
            case 'activities':   $label = __( 'Activities', 'talenttrack' ); break;
            case 'evaluations':  $label = __( 'Evaluations', 'talenttrack' ); break;
            case 'goals':        $label = __( 'Goals', 'talenttrack' ); break;
            case 'people':       $label = __( 'People', 'talenttrack' ); break;
            case 'measurements': $label = __( 'Measurements', 'talenttrack' ); break;
            case 'workflow':     $label = __( 'Tasks', 'talenttrack' ); break;
            default:             $label = ucfirst( str_replace( '_', ' ', $slug ) );
        }

        return (string) apply_filters( 'tt_alert_module_label', $label, $slug );
    }
}
