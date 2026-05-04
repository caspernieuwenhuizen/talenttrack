<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * QueryHelpers — shared read-side queries and presentation helpers.
 *
 * Replaces TT\Helpers from v1.x. Config calls route through ConfigService
 * when available; a static fallback is provided for backwards compatibility.
 */
class QueryHelpers {

    /** @var ConfigService|null */
    private static $config = null;

    public static function setConfigService( ConfigService $config ): void {
        self::$config = $config;
    }

    private static function config(): ConfigService {
        if ( self::$config === null ) {
            self::$config = new ConfigService();
        }
        return self::$config;
    }

    // Tenancy scoping (#0052 PR-A)

    /**
     * Build a SQL `WHERE` fragment that scopes a query to the active
     * club. Returns a prepared string suitable for inlining into a
     * larger SQL statement; the int placeholder is filled at format
     * time so the value is injection-safe.
     *
     *   $where = QueryHelpers::clubScopeWhere();           // "club_id = 1"
     *   $where = QueryHelpers::clubScopeWhere( 'p' );      // "p.club_id = 1"
     *
     * Today the int is always `1`. Once a SaaS auth layer hooks into
     * `tt_current_club_id` the value resolves per-request.
     */
    public static function clubScopeWhere( string $alias = '' ): string {
        $col = $alias !== '' ? "{$alias}.club_id" : 'club_id';
        return sprintf( '%s = %d', $col, CurrentClub::id() );
    }

    /**
     * Insert payload fragment carrying `club_id` for the active club.
     * Spread into a `$wpdb->insert()` data array so write-side queries
     * pick up the scope automatically.
     *
     *   $wpdb->insert( $table, array_merge(
     *       $row,
     *       QueryHelpers::clubScopeInsertColumn()
     *   ) );
     *
     * @return array{club_id:int}
     */
    public static function clubScopeInsertColumn(): array {
        return [ 'club_id' => CurrentClub::id() ];
    }

    // Config passthrough

    public static function get_config( string $key, string $default = '' ): string {
        return self::config()->get( $key, $default );
    }

    public static function set_config( string $key, string $value ): void {
        self::config()->set( $key, $value );
    }

    // Lookups

    /** @return object[] */
    public static function get_lookups( string $type ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s AND club_id = %d ORDER BY sort_order ASC, name ASC",
            $type, CurrentClub::id()
        ));
    }

    public static function get_lookup( int $id ): ?object {
        global $wpdb;
        /** @var object|null $r */
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_lookups WHERE id = %d AND club_id = %d", $id, CurrentClub::id()
        ));
        return $r;
    }

    /** @return string[] */
    public static function get_lookup_names( string $type ): array {
        $items = self::get_lookups( $type );
        return wp_list_pluck( $items, 'name' );
    }

    /**
     * v3.85.5 — render-aware companion to `get_lookup_names()`. Returns
     * `[ stored_name => translated_label ]` pairs so dropdowns can
     * keep the raw English name as the form value (preserving DB
     * matching on existing rows) while showing the translated label
     * to the user.
     *
     * Use anywhere a lookup-driven dropdown is rendered. The bug this
     * solves is structural: every site that built a `<select>` from
     * `get_lookup_names()` was rendering the raw DB name as the
     * visible label, bypassing both the inline `tt_i18n` translations
     * column AND the .po-loaded `__()` strings. Reported on the
     * preferred-foot dropdown by a pilot install — same defect on every other
     * lookup-driven dropdown (positions, age groups, goal statuses,
     * goal priorities, attendance statuses, etc.).
     *
     * @return array<string, string>
     */
    public static function get_lookup_label_pairs( string $type ): array {
        $pairs = [];
        foreach ( self::get_lookups( $type ) as $row ) {
            $stored = (string) ( $row->name ?? '' );
            if ( $stored === '' ) continue;
            $pairs[ $stored ] = class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' )
                ? \TT\Infrastructure\Query\LookupTranslator::name( $row )
                : $stored;
        }
        return $pairs;
    }

    /** @return array<string,mixed> */
    public static function lookup_meta( ?object $lookup ): array {
        if ( ! $lookup || empty( $lookup->meta ) ) return [];
        $decoded = json_decode( (string) $lookup->meta, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * #0072 — Activity-type meta.rateable read helper. The new-evaluation
     * wizard's activity picker filters on this; future Reports/Stats can
     * reuse the same flag. Default `true` (unmarked rows stay rateable on
     * upgrade); the well-known non-rateable types (clinic / methodology /
     * team_meeting) are seeded as `false` by migration 0057.
     */
    public static function isActivityTypeRateable( string $type_name ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT meta FROM {$wpdb->prefix}tt_lookups
              WHERE lookup_type = 'activity_type' AND name = %s AND club_id = %d
              LIMIT 1",
            $type_name, CurrentClub::id()
        ) );
        if ( ! $row ) return true; // unknown type — default permissive
        $meta = self::lookup_meta( $row );
        if ( ! array_key_exists( 'rateable', $meta ) ) return true;
        return (bool) $meta['rateable'];
    }

    /**
     * #0072 — Evaluation-category meta.quick_rate read helper. Categories
     * marked quick_rate=true surface as a single-line quick-rate row in
     * RateActorsStep; deeper categories live in the deep-rate panel.
     * Default `false` so existing un-flagged categories remain in the
     * deep panel; migration 0057 seeds the four conventional ones
     * (Technical / Tactical / Physical / Mental) as quick.
     */
    public static function isCategoryQuickRate( int $category_id ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT meta FROM {$wpdb->prefix}tt_eval_categories WHERE id = %d AND club_id = %d LIMIT 1",
            $category_id, CurrentClub::id()
        ) );
        if ( ! $row ) return false;
        $meta = isset( $row->meta ) && $row->meta !== '' ? json_decode( (string) $row->meta, true ) : [];
        return ! empty( $meta['quick_rate'] );
    }

    /** @return object[] */
    public static function get_categories(): array {
        // v2.12.0: main evaluation categories migrated out of tt_lookups
        // into the dedicated tt_eval_categories table. This method keeps
        // the pre-2.12 return shape (->id, ->name, ->description, ->sort_order)
        // so existing consumers don't need to change.
        $repo = new \TT\Infrastructure\Evaluations\EvalCategoriesRepository();
        $cats = $repo->getMainCategoriesLegacyShape();
        /** @var object[] $filtered */
        $filtered = apply_filters( 'tt_modify_categories', $cats );
        return $filtered;
    }

    /** @return object[] */
    public static function get_eval_types(): array {
        return self::get_lookups( 'eval_type' );
    }

    public static function type_requires_match( int $type_id ): bool {
        $t = self::get_lookup( $type_id );
        if ( ! $t ) return false;
        $m = self::lookup_meta( $t );
        return ! empty( $m['requires_match_details'] );
    }

    // Demo-mode scope filter

    /**
     * Site-level scope fragment to append to SELECTs against core tables.
     *
     * Returns an SQL fragment like
     *
     *   AND t.id NOT IN (SELECT entity_id FROM wp_tt_demo_tags WHERE entity_type = 'team')
     *
     * that hides demo rows when demo mode is OFF, or
     *
     *   AND t.id IN (SELECT ...)
     *
     * when demo mode is ON. Returns an empty string when the caller is
     * the demo admin page itself (request-scoped neutral override) or
     * when the tt_demo_tags table doesn't exist yet on very old installs.
     *
     * @see \TT\Modules\DemoData\DemoMode
     */
    public static function apply_demo_scope( string $table_alias, string $entity_type ): string {
        global $wpdb;

        if ( ! class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            return '';
        }
        $mode = \TT\Modules\DemoData\DemoMode::effective();
        if ( $mode === \TT\Modules\DemoData\DemoMode::NEUTRAL ) {
            return '';
        }
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        // Pre-migration safety: if the tag table doesn't exist yet, skip
        // the scope to avoid fatal SQL errors.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tag_table ) ) !== $tag_table ) {
            return '';
        }

        $op     = $mode === \TT\Modules\DemoData\DemoMode::ON ? 'IN' : 'NOT IN';
        $alias  = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_alias ) ?: 't';
        $prepared_type = $wpdb->prepare( '%s', $entity_type );
        $base = " AND {$alias}.id {$op} (SELECT entity_id FROM {$tag_table} WHERE entity_type = {$prepared_type}) ";

        // v3.72.5 — operational integrations (currently Spond) feed
        // real club data into `tt_activities`. In demo-ON mode that
        // data was filtered out, so the activities list / detail
        // showed only seeded demo rows while the team page (which
        // bypasses this scope) still surfaced the Spond events.
        // Coaches landing from the team page got "Activity not
        // found" on click. Spond-sourced activities are exempt
        // from the demo filter; they're real, operationally
        // important, and not part of the demo dataset.
        if ( $entity_type === 'activity' && $mode === \TT\Modules\DemoData\DemoMode::ON ) {
            return " AND ( {$alias}.id {$op} (SELECT entity_id FROM {$tag_table} WHERE entity_type = {$prepared_type}) OR {$alias}.activity_source_key = 'spond' ) ";
        }
        return $base;
    }

    // Entity queries

    /** @return object[] */
    public static function get_teams(): array {
        global $wpdb;
        $scope = self::apply_demo_scope( 't', 'team' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$wpdb->prefix}tt_teams t WHERE 1=1 AND t.club_id = %d {$scope} ORDER BY t.name ASC",
            CurrentClub::id()
        ) );
    }

    public static function get_team( int $id ): ?object {
        global $wpdb;
        $scope = self::apply_demo_scope( 't', 'team' );
        /** @var object|null $r */
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.* FROM {$wpdb->prefix}tt_teams t WHERE t.id = %d AND t.club_id = %d {$scope}",
            $id, CurrentClub::id()
        ) );
        return $r;
    }

    /** @return object[] */
    public static function get_players( int $team_id = 0 ): array {
        global $wpdb;
        $scope = self::apply_demo_scope( 'p', 'player' );
        if ( $team_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT p.* FROM {$wpdb->prefix}tt_players p
                 WHERE p.team_id = %d AND p.status = 'active' AND p.club_id = %d {$scope}
                 ORDER BY p.last_name, p.first_name ASC",
                $team_id, CurrentClub::id()
            ) );
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p
             WHERE p.status = 'active' AND p.club_id = %d {$scope}
             ORDER BY p.last_name, p.first_name ASC",
            CurrentClub::id()
        ) );
    }

    public static function get_player( int $id ): ?object {
        global $wpdb;
        $scope = self::apply_demo_scope( 'p', 'player' );
        /** @var object|null $r */
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p WHERE p.id = %d AND p.club_id = %d {$scope}",
            $id, CurrentClub::id()
        ) );
        return $r;
    }

    public static function player_display_name( object $player ): string {
        return (string) $player->first_name . ' ' . (string) $player->last_name;
    }

    public static function get_player_for_user( int $user_id ): ?object {
        global $wpdb;
        $scope = self::apply_demo_scope( 'p', 'player' );
        /** @var object|null $r */
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}tt_players p
             WHERE p.wp_user_id = %d AND p.status = 'active' AND p.club_id = %d {$scope}
             LIMIT 1",
            $user_id, CurrentClub::id()
        ));
        return $r;
    }

    /**
     * Teams a user coaches. Consults both:
     *
     *   1. Legacy: `tt_teams.head_coach_id` (the v2.x assignment path).
     *   2. Modern: `tt_user_role_scopes` with `scope_type='team'` for any
     *      team-scoped role (head coach, assistant coach, manager). Pre-
     *      v3.x staff panel writes go here, not the legacy column.
     *
     * The two paths union-merge so a user assigned via the new staff
     * panel sees their team under "My teams" / can create PDP files,
     * even though the legacy column wasn't updated. Without this, the
     * staff panel and "My teams" disagree — the player surface uses
     * the modern path (AuthorizationService) but `get_teams_for_coach`
     * was reading only the legacy column.
     *
     * @return object[]
     */
    public static function get_teams_for_coach( int $user_id ): array {
        global $wpdb;
        if ( $user_id <= 0 ) return [];

        $scope = self::apply_demo_scope( 't', 'team' );
        $person_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );

        // Fast path: just the legacy column.
        if ( $person_id <= 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT t.* FROM {$wpdb->prefix}tt_teams t
                 WHERE t.head_coach_id = %d AND t.club_id = %d {$scope}
                 ORDER BY t.name ASC",
                $user_id, CurrentClub::id()
            ));
        }

        // Union path: legacy column OR active team-scoped role.
        $today = current_time( 'Y-m-d' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.* FROM {$wpdb->prefix}tt_teams t
              LEFT JOIN {$wpdb->prefix}tt_user_role_scopes urs
                ON urs.scope_type = 'team' AND urs.scope_id = t.id
                AND urs.person_id = %d
                AND urs.club_id = t.club_id
                AND ( urs.start_date IS NULL OR urs.start_date <= %s )
                AND ( urs.end_date   IS NULL OR urs.end_date   >= %s )
              WHERE ( t.head_coach_id = %d OR urs.id IS NOT NULL )
                AND t.club_id = %d
                {$scope}
              ORDER BY t.name ASC",
            $person_id, $today, $today, $user_id, CurrentClub::id()
        ));
    }

    /**
     * Does this user coach a team that the player is on? Same union-merge
     * as `get_teams_for_coach()` so legacy and modern assignment paths
     * converge.
     */
    public static function coach_owns_player( int $coach_user_id, int $player_id ): bool {
        $player = self::get_player( $player_id );
        if ( ! $player || empty( $player->team_id ) ) return false;
        foreach ( self::get_teams_for_coach( $coach_user_id ) as $t ) {
            if ( (int) $t->id === (int) $player->team_id ) return true;
        }
        return false;
    }

    public static function get_evaluation( int $id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $scope = self::apply_demo_scope( 'e', 'evaluation' );
        /** @var object|null $eval */
        $eval = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, lt.name AS type_name, lt.meta AS type_meta
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id = lt.id AND lt.lookup_type = 'eval_type' AND lt.club_id = e.club_id
             WHERE e.id = %d AND e.club_id = %d {$scope}", $id, CurrentClub::id()
        ));
        if ( $eval ) {
            /** @var array<string,mixed> $tm */
            $tm = json_decode( (string) ( $eval->type_meta ?? '{}' ), true ) ?: [];
            $eval->requires_match_details = ! empty( $tm['requires_match_details'] );
            $eval->ratings = $wpdb->get_results( $wpdb->prepare(
                // v2.12.0: category metadata now lives in tt_eval_categories.
                // The JOIN exposes `category_name` (from tt_eval_categories.label),
                // `parent_id` (so consumers can tell main-vs-sub), and
                // `category_key` for stable identifiers. sort_order is
                // display_order from the new table.
                "SELECT r.*,
                        c.label      AS category_name,
                        c.parent_id  AS category_parent_id,
                        c.category_key AS category_key
                 FROM {$p}tt_eval_ratings r
                 LEFT JOIN {$p}tt_eval_categories c ON r.category_id = c.id AND c.club_id = r.club_id
                 WHERE r.evaluation_id = %d AND r.club_id = %d
                 ORDER BY c.parent_id IS NULL DESC, c.display_order ASC",
                $id, CurrentClub::id()
            ) );
        }
        return $eval;
    }

    // Radar chart SVG

    /**
     * @param string[] $labels
     * @param array<int, array{label:string, values:array<int,float|int>}> $datasets
     */
    public static function radar_chart_svg( array $labels, array $datasets, float $max = 5.0 ): string {
        $n = count( $labels );
        if ( $n < 3 ) return '';

        // Dimensions: wider viewBox + taller footer reserve for legend
        // so long category/date labels don't clip off-screen at the
        // left/right extremes of the web. Radius shrinks a touch to
        // leave label breathing room.
        $w = 400;
        $chart_h = 340;                    // main chart area (plus ring + labels)
        $legend_h = 36;                    // reserved strip for legend
        $h = $chart_h + $legend_h;
        $cx = $w / 2; $cy = $chart_h / 2;
        $radius = 120;
        $step = ( 2 * M_PI ) / $n;
        $colors = [ '#e8b624', '#3a86ff', '#ff595e', '#8ac926', '#6a4c93' ];

        $svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" class="tt-radar" style="max-width:100%;height:auto;font-family:Manrope,system-ui,sans-serif;">';
        // Soft backdrop circle behind the rings for a subtle "instrument" feel.
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ( $radius + 4 ) . '" fill="#fafbfc"/>';

        // Concentric rings — 5 bands. Outermost is darker to emphasise
        // the max. Ring labels on the vertical axis (1, 2, …).
        for ( $ring = 1; $ring <= 5; $ring++ ) {
            $r = $radius * ( $ring / 5 );
            $pts = [];
            for ( $i = 0; $i < $n; $i++ ) {
                $a = -M_PI / 2 + $i * $step;
                $pts[] = round( $cx + $r * cos( $a ), 2 ) . ',' . round( $cy + $r * sin( $a ), 2 );
            }
            $stroke = $ring === 5 ? '#b8bec6' : '#d7dbe0';
            $width  = $ring === 5 ? 1 : 0.75;
            $svg .= '<polygon points="' . implode( ' ', $pts ) . '" fill="none" stroke="' . $stroke . '" stroke-width="' . $width . '"/>';
            if ( $ring > 1 ) {
                $ring_label_y = round( $cy - $r + 2, 2 );
                $svg .= '<text x="' . ( $cx + 2 ) . '" y="' . $ring_label_y . '" font-size="8" fill="#a0a6ae">' . (int) $ring . '</text>';
            }
        }

        // Axis lines from centre to each category, then the category
        // label. Label position uses text-anchor logic tuned for 4- to
        // 8-category webs; the extra label offset (28px) combined with
        // the 400×340 viewBox keeps labels inside.
        for ( $i = 0; $i < $n; $i++ ) {
            $a = -M_PI / 2 + $i * $step;
            $x2 = round( $cx + $radius * cos( $a ), 2 );
            $y2 = round( $cy + $radius * sin( $a ), 2 );
            $svg .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#cfd4da" stroke-width="0.75"/>';
            $lx = round( $cx + ( $radius + 22 ) * cos( $a ), 2 );
            $ly = round( $cy + ( $radius + 22 ) * sin( $a ), 2 );
            $anc = 'middle';
            if ( cos( $a ) < -0.1 ) $anc = 'end';
            elseif ( cos( $a ) > 0.1 ) $anc = 'start';
            $svg .= '<text x="' . $lx . '" y="' . $ly . '" text-anchor="' . $anc . '" dominant-baseline="middle" font-size="11" font-weight="600" fill="#2a2f36">' . esc_html( $labels[ $i ] ) . '</text>';
        }

        // Dataset polygons + value dots.
        foreach ( $datasets as $di => $ds ) {
            $col = $colors[ $di % count( $colors ) ];
            $pts = [];
            foreach ( $ds['values'] as $i => $val ) {
                $r2 = $radius * ( min( (float) $val, $max ) / $max );
                $a = -M_PI / 2 + $i * $step;
                $pts[] = round( $cx + $r2 * cos( $a ), 2 ) . ',' . round( $cy + $r2 * sin( $a ), 2 );
            }
            $svg .= '<polygon points="' . implode( ' ', $pts ) . '" fill="' . $col . '" fill-opacity="0.18" stroke="' . $col . '" stroke-width="2.25" stroke-linejoin="round"/>';
            foreach ( $ds['values'] as $i => $val ) {
                $r2 = $radius * ( min( (float) $val, $max ) / $max );
                $a = -M_PI / 2 + $i * $step;
                $svg .= '<circle cx="' . round( $cx + $r2 * cos( $a ), 2 ) . '" cy="' . round( $cy + $r2 * sin( $a ), 2 ) . '" r="3.5" fill="#fff" stroke="' . $col . '" stroke-width="2"/>';
            }
        }

        // Legend — row-wrapping friendly: computes x per item based on
        // the previous item's label width estimate so it spreads evenly
        // across the footer. Keeps one row for up to ~5 datasets, which
        // is the current colour palette size.
        $legend_y = $chart_h + 20;
        $legend_count = count( $datasets );
        if ( $legend_count > 0 ) {
            $slot = $w / max( $legend_count, 1 );
            foreach ( $datasets as $di => $ds ) {
                $col = $colors[ $di % count( $colors ) ];
                $slot_cx = ( $di * $slot ) + ( $slot / 2 );
                $label   = (string) $ds['label'];
                $lbl_estimate_w = 6 * mb_strlen( $label );
                $rect_x  = $slot_cx - ( $lbl_estimate_w / 2 ) - 14;
                $text_x  = $rect_x + 16;
                $svg .= '<rect x="' . round( $rect_x, 2 ) . '" y="' . ( $legend_y - 8 ) . '" width="11" height="11" fill="' . $col . '" rx="2"/>';
                $svg .= '<text x="' . round( $text_x, 2 ) . '" y="' . ( $legend_y + 1 ) . '" font-size="10.5" fill="#4a5057" dominant-baseline="middle">' . esc_html( $label ) . '</text>';
            }
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * @return array{labels: string[], datasets: array<int, array{label:string, values:array<int,float|int>}>}
     */
    public static function player_radar_datasets( int $player_id, int $limit = 3 ): array {
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date FROM {$p}tt_evaluations WHERE player_id=%d AND club_id = %d ORDER BY eval_date DESC LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ));
        $evals = array_reverse( $evals );
        $categories = self::get_categories();
        // v2.12.2: translate seeded category labels through __() so radar
        // chart legends show in the admin's locale. Untranslated labels
        // (admin-added mains) pass through unchanged.
        $labels = [];
        foreach ( $categories as $cat ) {
            $labels[] = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->name );
        }
        $cat_ids = wp_list_pluck( $categories, 'id' );
        $datasets = [];
        foreach ( $evals as $ev ) {
            $raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT category_id, rating FROM {$p}tt_eval_ratings WHERE evaluation_id=%d AND club_id = %d", $ev->id, CurrentClub::id()
            ));
            $map = [];
            foreach ( $raw as $r ) $map[ (int) $r->category_id ] = (float) $r->rating;
            $values = [];
            foreach ( $cat_ids as $cid ) $values[] = $map[ (int) $cid ] ?? 0;
            $datasets[] = [ 'label' => (string) $ev->eval_date, 'values' => $values ];
        }
        return [ 'labels' => $labels, 'datasets' => $datasets ];
    }
}
