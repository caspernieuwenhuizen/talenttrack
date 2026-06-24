<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\Kpi;
use TT\Modules\Analytics\Frontend\EntityAnalyticsTabRenderer;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\KpiResolver;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendAnalyticsView — central analytics surface (#0083 Child 5).
 *
 * Reachable at `?tt_view=analytics`. Cap-gated on `tt_view_analytics`
 * which bridges to the `analytics:read` matrix tuple — HoD + Admin
 * by default. Coaches reach analytics through the per-entity tabs
 * (#0083 Child 4) on the players + teams + activities they have
 * access to; they don't get the central exploration view because
 * their analytical work is bounded to their teams.
 *
 * Classified `desktop_only` per #0084 — phone-class user agents see
 * the polite "Open on desktop" page from #0084 Child 1.
 *
 * Child 5 minimum-viable scope: render an academy-wide KPI grid
 * pulling every `ACADEMY`-context KPI plus every KPI without an
 * explicit context (defensive default — uncategorised KPIs surface
 * here rather than disappear). Each card click-throughs to the
 * dimension explorer (#0083 Child 3).
 *
 * **What's deferred** (per spec §`feat-central-analytics-surface`):
 *   - Two-column layout: entity selector on the left, KPI grid on
 *     the right. Today's view renders just the KPI grid; the
 *     entity selector lands in a follow-up.
 *   - Entity-instance picker (e.g. "U13 / U15 / U17" tiles under
 *     "Player").
 *
 * Already operational from earlier children: per-entity drilldown
 * via the existing entity profiles' Analytics tab (Child 4); the
 * dimension explorer (Child 3) where any KPI hand-off lands.
 */
class FrontendAnalyticsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view central analytics.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        // v3.110.152 — entity selection lives in the URL as
        // `?tt_view=analytics&entity_type=X&entity_id=N`. When set, the
        // right rail renders that entity's KPIs in-place; when unset,
        // it renders the academy-wide KPI grid + Standard reports.
        // Entity analytics are NOT surfaced on entity detail pages —
        // operator-only, central by design.
        [ $selected_type, $selected_id ] = self::selectedEntity();
        $entity_label = self::labelForSelection( $selected_type, $selected_id );

        FrontendBreadcrumbs::fromDashboard(
            $entity_label !== '' ? $entity_label : __( 'Analytics', 'talenttrack' ),
            $entity_label !== '' ? [ FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ] : []
        );
        self::renderHeader( __( 'Analytics', 'talenttrack' ) );

        echo '<div class="tt-analytics-shell">';

        echo '<aside class="tt-analytics-rail">';
        echo '<h2 class="tt-analytics-rail-title">' . esc_html__( 'Browse by entity', 'talenttrack' ) . '</h2>';
        self::renderEntitySelector( $selected_type, $selected_id );
        echo '</aside>';

        echo '<section class="tt-analytics-main">';
        if ( $selected_type !== '' && $selected_id > 0 ) {
            self::renderEntityAnalytics( $selected_type, $selected_id, $entity_label );
        } else {
            self::renderAcademyOverview();
        }
        echo '</section>';

        echo '</div>'; // /.tt-analytics-shell
    }

    /**
     * Read + sanitise the entity-selection query params. Returns
     * `[type, id]` where type is one of player/team/activity/scout/season
     * (or '' when no/invalid type) and id is the absint of the
     * provided id (or 0).
     *
     * @return array{0:string,1:int}
     */
    private static function selectedEntity(): array {
        $type = isset( $_GET['entity_type'] ) ? sanitize_key( (string) $_GET['entity_type'] ) : '';
        $id   = isset( $_GET['entity_id'] )   ? absint( $_GET['entity_id'] )                  : 0;
        $valid = [ 'player', 'team', 'activity', 'scout', 'season' ];
        if ( ! in_array( $type, $valid, true ) ) $type = '';
        if ( $type === '' || $id <= 0 ) return [ '', 0 ];
        return [ $type, $id ];
    }

    /**
     * Render the academy-wide overview (default right-rail content).
     */
    private static function renderAcademyOverview(): void {
        $academy_kpis = KpiRegistry::byContext( Kpi::CONTEXT_ACADEMY );
        if ( empty( $academy_kpis ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'No academy-wide KPIs registered yet. Pick an entity from the left to see its analytics.', 'talenttrack' )
                . '</p>';
        } else {
            echo '<p class="tt-analytics-intro">'
                . esc_html__( 'Academy-wide KPIs. Click any card to open the explorer with that KPI loaded; from there you can pivot, group, and filter. Pick an entity from the left rail to switch to its analytics in place.', 'talenttrack' )
                . '</p>';

            echo '<div class="tt-analytics-grid">';
            foreach ( $academy_kpis as $key => $kpi ) {
                $value = KpiResolver::value( $key );
                $explore_url = add_query_arg(
                    [ 'tt_view' => 'explore', 'kpi' => $key ],
                    WizardEntryPoint::dashboardBaseUrl()
                );
                self::renderCard( $kpi, $value, $explore_url );
            }
            echo '</div>';
        }
    }

    /**
     * Render the right rail when an entity instance is selected.
     * Shows a small entity header (type + name + "back to academy view"
     * link) followed by the entity's KPI grid.
     *
     * Player / Team / Activity scopes route through the existing
     * `EntityAnalyticsTabRenderer` which already filters KPIs by
     * `entityScope` + persona `context`. Scout / Season have no
     * registered KPIs yet — they render an honest empty state.
     */
    private static function renderEntityAnalytics( string $type, int $id, string $entity_label ): void {
        $back_url = add_query_arg(
            [ 'tt_view' => 'analytics' ],
            WizardEntryPoint::dashboardBaseUrl()
        );
        $type_label = self::labelForType( $type );

        echo '<div class="tt-analytics-entity-header">';
        echo '<div>';
        echo '<div class="tt-analytics-entity-header-type">' . esc_html( $type_label ) . '</div>';
        echo '<div class="tt-analytics-entity-header-name">' . esc_html( $entity_label !== '' ? $entity_label : ( '#' . (int) $id ) ) . '</div>';
        echo '</div>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $back_url ) . '">'
             . esc_html__( '← Academy view', 'talenttrack' ) . '</a>';
        echo '</div>';

        if ( in_array( $type, [ 'player', 'team', 'activity' ], true ) ) {
            EntityAnalyticsTabRenderer::render( $type, $id );
        } else {
            // Scout / Season — no KPIs registered for these scopes yet.
            echo '<p class="tt-notice">' . esc_html__( 'No analytics registered for this entity type yet.', 'talenttrack' ) . '</p>';
        }
    }

    /**
     * v3.110.151 — left-rail entity selector. Five entity-type
     * sections (Player / Team / Activity / Season / Scout) rendered
     * as native `<details>` disclosures so progressive expansion
     * doesn't need a JS state machine. Each section lists the
     * accessible instances; clicking an instance links to that
     * entity's detail page where the Analytics tab takes over.
     *
     * Instance lists scoped to the current tenant (`club_id`).
     * Player + Team + Activity + Season + Scout each capped at 25
     * rows server-side; an operator with more rows uses the relevant
     * tile's main list view via the dashboard.
     */
    private static function renderEntitySelector( string $selected_type, int $selected_id ): void {
        $base = WizardEntryPoint::dashboardBaseUrl();

        self::renderEntitySection( 'player',   __( 'Players',    'talenttrack' ), self::fetchInstancePlayers(),    $base, $selected_type, $selected_id );
        self::renderEntitySection( 'team',     __( 'Teams',      'talenttrack' ), self::fetchInstanceTeams(),      $base, $selected_type, $selected_id );
        self::renderEntitySection( 'activity', __( 'Activities', 'talenttrack' ), self::fetchInstanceActivities(), $base, $selected_type, $selected_id );
        self::renderEntitySection( 'scout',    __( 'Scouts',     'talenttrack' ), self::fetchInstanceScouts(),     $base, $selected_type, $selected_id );
        self::renderEntitySection( 'season',   __( 'Seasons',    'talenttrack' ), self::fetchInstanceSeasons(),    $base, $selected_type, $selected_id );
    }

    /**
     * Render one entity section in the left rail. Each instance link
     * is an in-page nav back to `?tt_view=analytics&entity_type=…&entity_id=…`
     * — the right rail re-renders with that entity's KPIs.
     *
     * @param list<array{id:int,label:string,meta?:string}> $instances
     */
    private static function renderEntitySection( string $entity_type, string $heading, array $instances, string $base, string $selected_type, int $selected_id ): void {
        $count   = count( $instances );
        $is_open = $selected_type === $entity_type;
        echo '<details class="tt-analytics-entity" data-entity-type="' . esc_attr( $entity_type ) . '"' . ( $is_open ? ' open' : '' ) . '>';
        echo '<summary class="tt-analytics-entity-summary">';
        echo '<span>' . esc_html( $heading ) . '</span>';
        echo '<span class="tt-analytics-entity-count">' . (int) $count . '</span>';
        echo '</summary>';
        if ( $count === 0 ) {
            echo '<p class="tt-analytics-entity-empty">' . esc_html__( 'No entries.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-analytics-entity-list">';
            foreach ( $instances as $row ) {
                $id    = (int) $row['id'];
                $label = (string) ( $row['label'] ?? '#' . $id );
                $meta  = (string) ( $row['meta'] ?? '' );
                $url   = add_query_arg(
                    [ 'tt_view' => 'analytics', 'entity_type' => $entity_type, 'entity_id' => $id ],
                    $base
                );
                $is_current = $is_open && $selected_id === $id;
                echo '<li' . ( $is_current ? ' class="is-current"' : '' ) . '>';
                echo '<a href="' . esc_url( $url ) . '"' . ( $is_current ? ' aria-current="page"' : '' ) . '>';
                echo '<span class="tt-analytics-entity-label">' . esc_html( $label ) . '</span>';
                if ( $meta !== '' ) echo '<span class="tt-analytics-entity-meta">' . esc_html( $meta ) . '</span>';
                echo '</a></li>';
            }
            echo '</ul>';
        }
        echo '</details>';
    }

    private static function labelForType( string $type ): string {
        switch ( $type ) {
            case 'player':   return __( 'Player',   'talenttrack' );
            case 'team':     return __( 'Team',     'talenttrack' );
            case 'activity': return __( 'Activity', 'talenttrack' );
            case 'scout':    return __( 'Scout',    'talenttrack' );
            case 'season':   return __( 'Season',   'talenttrack' );
            default:         return '';
        }
    }

    /**
     * Resolve the entity instance's display name for the header / breadcrumb.
     * Returns '' when type+id don't resolve to a row.
     */
    private static function labelForSelection( string $type, int $id ): string {
        if ( $type === '' || $id <= 0 ) return '';
        global $wpdb; $p = $wpdb->prefix;
        switch ( $type ) {
            case 'player':
                $r = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$p}tt_players WHERE id = %d AND club_id = %d",
                    $id, \TT\Infrastructure\Tenancy\CurrentClub::id()
                ) );
                if ( ! $r ) return '';
                return trim( ( (string) $r->first_name ) . ' ' . ( (string) $r->last_name ) ) ?: ( '#' . $id );
            case 'team':
                $name = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
                    $id, \TT\Infrastructure\Tenancy\CurrentClub::id()
                ) );
                return $name !== '' ? $name : ( '#' . $id );
            case 'activity':
                $title = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT title FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
                    $id, \TT\Infrastructure\Tenancy\CurrentClub::id()
                ) );
                return $title !== '' ? $title : ( '#' . $id );
            case 'scout':
                $r = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$p}tt_people WHERE id = %d AND club_id = %d",
                    $id, \TT\Infrastructure\Tenancy\CurrentClub::id()
                ) );
                if ( ! $r ) return '';
                return trim( ( (string) $r->first_name ) . ' ' . ( (string) $r->last_name ) ) ?: ( '#' . $id );
            case 'season':
                $name = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_seasons WHERE id = %d AND club_id = %d",
                    $id, \TT\Infrastructure\Tenancy\CurrentClub::id()
                ) );
                return $name !== '' ? $name : ( '#' . $id );
            default:
                return '';
        }
    }

    /** @return list<array{id:int,label:string,meta?:string}> */
    private static function fetchInstancePlayers(): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, t.name AS team_name
               FROM {$p}tt_players pl
          LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
              WHERE pl.club_id = %d AND pl.archived_at IS NULL
           ORDER BY pl.last_name ASC, pl.first_name ASC
              LIMIT 25",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) ) ?: [];
        $out = [];
        foreach ( (array) $rows as $r ) {
            $name = trim( ( (string) ( $r->first_name ?? '' ) ) . ' ' . ( (string) ( $r->last_name ?? '' ) ) );
            if ( $name === '' ) $name = '#' . (int) $r->id;
            $out[] = [
                'id'    => (int) $r->id,
                'label' => $name,
                'meta'  => (string) ( $r->team_name ?? '' ),
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,label:string,meta?:string}> */
    private static function fetchInstanceTeams(): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, age_group FROM {$p}tt_teams
              WHERE club_id = %d AND archived_at IS NULL
           ORDER BY name ASC
              LIMIT 25",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) ) ?: [];
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'    => (int) $r->id,
                'label' => (string) $r->name,
                'meta'  => (string) ( $r->age_group ?? '' ),
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,label:string,meta?:string}> */
    private static function fetchInstanceActivities(): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, t.name AS team_name
               FROM {$p}tt_activities a
          LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.club_id = %d AND a.archived_at IS NULL
           ORDER BY a.session_date DESC, a.id DESC
              LIMIT 25",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) ) ?: [];
        $out = [];
        foreach ( (array) $rows as $r ) {
            $title = (string) ( $r->title ?? '' );
            if ( $title === '' ) $title = '#' . (int) $r->id;
            $out[] = [
                'id'    => (int) $r->id,
                'label' => $title,
                'meta'  => trim( ( (string) ( $r->team_name ?? '' ) ) . ( ! empty( $r->session_date ) ? ' · ' . (string) $r->session_date : '' ), ' ·' ),
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,label:string,meta?:string}> */
    private static function fetchInstanceScouts(): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$p}tt_people
              WHERE club_id = %d AND role_type = 'scout' AND archived_at IS NULL
           ORDER BY last_name ASC, first_name ASC
              LIMIT 25",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) ) ?: [];
        $out = [];
        foreach ( (array) $rows as $r ) {
            $name = trim( ( (string) ( $r->first_name ?? '' ) ) . ' ' . ( (string) ( $r->last_name ?? '' ) ) );
            if ( $name === '' ) $name = '#' . (int) $r->id;
            $out[] = [
                'id'    => (int) $r->id,
                'label' => $name,
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,label:string,meta?:string}> */
    private static function fetchInstanceSeasons(): array {
        global $wpdb; $p = $wpdb->prefix;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'tt_seasons' ) ) !== $p . 'tt_seasons' ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, start_date, end_date FROM {$p}tt_seasons
              WHERE club_id = %d
           ORDER BY start_date DESC
              LIMIT 25",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) ) ?: [];
        $out = [];
        foreach ( (array) $rows as $r ) {
            $name = (string) ( $r->name ?? '' );
            if ( $name === '' ) $name = '#' . (int) $r->id;
            $meta = '';
            if ( ! empty( $r->start_date ) || ! empty( $r->end_date ) ) {
                $meta = trim( (string) ( $r->start_date ?? '' ) . ' – ' . (string) ( $r->end_date ?? '' ), ' –' );
            }
            $out[] = [
                'id'    => (int) $r->id,
                'label' => $name,
                'meta'  => $meta,
            ];
        }
        return $out;
    }

    /**
     * B3 — enqueue the per-view 2026 stylesheet on top of the shared
     * frontend assets. The sheet styles the two-column shell, the
     * <details> entity rail, and the academy KPI grid; it depends on
     * the app-chrome sheet for the brand tokens + .tt-kpi tile styling.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-analytics',
            TT_PLUGIN_URL . 'assets/css/frontend-analytics.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    /**
     * B3 — render one academy KPI as a shared KPI tile. Below-threshold
     * values flag red via the helper's `flag` argument so the 2026 tile
     * carries the same alert signal the old inline card used.
     */
    private static function renderCard( Kpi $kpi, ?float $value, string $explore_url ): void {
        echo \TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile( [
            'label' => $kpi->label,
            'value' => self::formatValue( $kpi, $value ),
            'flag'  => self::thresholdFlag( $kpi, $value ),
            'href'  => $explore_url,
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile escapes its parts.
    }

    /**
     * Return the KPI tile flag ('red' when the value breaches the
     * goal-direction threshold, '' otherwise). Pure presentation hint
     * derived from the domain Kpi — no business logic.
     */
    private static function thresholdFlag( Kpi $kpi, ?float $value ): string {
        if ( $kpi->threshold === null || $value === null ) return '';
        $is_red = ( $kpi->goalDirection === Kpi::GOAL_HIGHER_BETTER && $value < $kpi->threshold )
               || ( $kpi->goalDirection === Kpi::GOAL_LOWER_BETTER  && $value > $kpi->threshold );
        return $is_red ? 'red' : '';
    }

    private static function formatValue( Kpi $kpi, ?float $value ): string {
        if ( $value === null ) return '—';
        $fact = \TT\Modules\Analytics\FactRegistry::find( $kpi->factKey );
        $measure = $fact ? $fact->measure( $kpi->measureKey ) : null;
        $unit = $measure ? ( $measure->unit ?? '' ) : '';
        if ( $unit === 'percent' ) return number_format_i18n( $value, 1 ) . '%';
        if ( $unit === 'minutes' ) {
            $h = (int) floor( $value / 60 );
            $m = (int) round( fmod( $value, 60 ) );
            return $h > 0 ? ( $h . 'h ' . $m . 'm' ) : ( $m . 'm' );
        }
        if ( $unit === 'rating' ) return number_format_i18n( $value, 2 );
        if ( fmod( $value, 1.0 ) === 0.0 ) return number_format_i18n( $value, 0 );
        return number_format_i18n( $value, 1 );
    }
}
