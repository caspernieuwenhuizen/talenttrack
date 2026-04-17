<?php
namespace TT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Helpers {

    private static $config_cache = [];

    /* ═══ Config (key-value) ══════════════════════════════ */

    public static function get_config( $key, $default = '' ) {
        if ( isset( self::$config_cache[ $key ] ) ) return self::$config_cache[ $key ];
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE config_key = %s", $key
        ));
        $result = ( $val !== null ) ? $val : $default;
        self::$config_cache[ $key ] = $result;
        return $result;
    }

    public static function set_config( $key, $value ) {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'tt_config', [
            'config_key' => $key, 'config_value' => $value,
        ]);
        self::$config_cache[ $key ] = $value;
    }

    /* ═══ Lookups (polymorphic list table) ════════════════ */

    public static function get_lookups( $type ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s ORDER BY sort_order ASC, name ASC",
            $type
        ));
    }

    public static function get_lookup( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_lookups WHERE id = %d", $id
        ));
    }

    public static function get_lookup_names( $type ) {
        $items = self::get_lookups( $type );
        return wp_list_pluck( $items, 'name' );
    }

    public static function lookup_meta( $lookup ) {
        if ( ! $lookup || empty( $lookup->meta ) ) return [];
        $decoded = json_decode( $lookup->meta, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Shorthand for evaluation categories.
     */
    public static function get_categories() {
        $cats = self::get_lookups( 'eval_category' );
        return apply_filters( 'tt_modify_categories', $cats );
    }

    /**
     * Shorthand for evaluation types.
     */
    public static function get_eval_types() {
        return self::get_lookups( 'eval_type' );
    }

    /**
     * Check if an eval type requires match details.
     */
    public static function type_requires_match( $type_id ) {
        $type = self::get_lookup( $type_id );
        if ( ! $type ) return false;
        $meta = self::lookup_meta( $type );
        return ! empty( $meta['requires_match_details'] );
    }

    /* ═══ Entity Queries ═════════════════════════════════ */

    public static function get_teams() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tt_teams ORDER BY name ASC" );
    }

    public static function get_team( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id=%d", $id ) );
    }

    public static function get_players( $team_id = 0 ) {
        global $wpdb;
        $where = $team_id
            ? $wpdb->prepare( "WHERE team_id = %d AND status='active'", $team_id )
            : "WHERE status='active'";
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}tt_players $where ORDER BY last_name, first_name ASC" );
    }

    public static function get_player( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_players WHERE id=%d", $id ) );
    }

    public static function player_display_name( $player ) {
        return $player->first_name . ' ' . $player->last_name;
    }

    public static function get_player_for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND status='active' LIMIT 1", $user_id
        ));
    }

    public static function get_teams_for_coach( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_teams WHERE head_coach_id = %d ORDER BY name ASC", $user_id
        ));
    }

    public static function coach_owns_player( $coach_user_id, $player_id ) {
        $player = self::get_player( $player_id );
        if ( ! $player || ! $player->team_id ) return false;
        $team = self::get_team( $player->team_id );
        return $team && (int) $team->head_coach_id === (int) $coach_user_id;
    }

    /* ═══ Evaluation helpers ═════════════════════════════ */

    public static function get_evaluation( $id ) {
        global $wpdb; $p = $wpdb->prefix;
        $eval = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, lt.name AS type_name, lt.meta AS type_meta
             FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id = lt.id AND lt.lookup_type = 'eval_type'
             WHERE e.id = %d", $id
        ));
        if ( $eval ) {
            $type_meta = json_decode( $eval->type_meta ?? '{}', true );
            $eval->requires_match_details = ! empty( $type_meta['requires_match_details'] );
            $eval->ratings = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.*, lc.name AS category_name
                 FROM {$p}tt_eval_ratings r
                 LEFT JOIN {$p}tt_lookups lc ON r.category_id = lc.id AND lc.lookup_type = 'eval_category'
                 WHERE r.evaluation_id = %d ORDER BY lc.sort_order ASC", $id
            ));
        }
        return $eval;
    }

    /* ═══ Radar Chart SVG ════════════════════════════════ */

    public static function radar_chart_svg( $labels, $datasets, $max = 5 ) {
        $n = count( $labels );
        if ( $n < 3 ) return '';
        $size   = 300;
        $cx     = $size / 2;
        $cy     = $size / 2;
        $radius = 115;
        $step   = ( 2 * M_PI ) / $n;
        $colors = [ '#e8b624', '#3a86ff', '#ff595e', '#8ac926', '#6a4c93' ];

        $svg = '<svg viewBox="0 0 ' . $size . ' ' . $size . '" xmlns="http://www.w3.org/2000/svg" class="tt-radar">';

        // Grid rings
        for ( $ring = 1; $ring <= 5; $ring++ ) {
            $r = $radius * ( $ring / 5 );
            $pts = [];
            for ( $i = 0; $i < $n; $i++ ) {
                $a = -M_PI / 2 + $i * $step;
                $pts[] = round( $cx + $r * cos( $a ), 2 ) . ',' . round( $cy + $r * sin( $a ), 2 );
            }
            $svg .= '<polygon points="' . implode( ' ', $pts ) . '" fill="none" stroke="#d0d0d0" stroke-width="0.5"/>';
        }

        // Axes + labels
        for ( $i = 0; $i < $n; $i++ ) {
            $a  = -M_PI / 2 + $i * $step;
            $x2 = round( $cx + $radius * cos( $a ), 2 );
            $y2 = round( $cy + $radius * sin( $a ), 2 );
            $svg .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#ccc" stroke-width="0.5"/>';
            $lx = round( $cx + ( $radius + 18 ) * cos( $a ), 2 );
            $ly = round( $cy + ( $radius + 18 ) * sin( $a ), 2 );
            $anc = 'middle';
            if ( cos( $a ) < -0.1 ) $anc = 'end';
            elseif ( cos( $a ) > 0.1 ) $anc = 'start';
            $svg .= '<text x="' . $lx . '" y="' . $ly . '" text-anchor="' . $anc . '" dominant-baseline="middle" font-size="10" fill="#444">' . esc_html( $labels[ $i ] ) . '</text>';
        }

        // Data polygons
        foreach ( $datasets as $di => $ds ) {
            $col = $colors[ $di % count( $colors ) ];
            $pts = [];
            foreach ( $ds['values'] as $i => $val ) {
                $r2 = $radius * ( min( $val, $max ) / $max );
                $a  = -M_PI / 2 + $i * $step;
                $pts[] = round( $cx + $r2 * cos( $a ), 2 ) . ',' . round( $cy + $r2 * sin( $a ), 2 );
            }
            $svg .= '<polygon points="' . implode( ' ', $pts ) . '" fill="' . $col . '" fill-opacity="0.15" stroke="' . $col . '" stroke-width="2"/>';
            foreach ( $ds['values'] as $i => $val ) {
                $r2 = $radius * ( min( $val, $max ) / $max );
                $a  = -M_PI / 2 + $i * $step;
                $svg .= '<circle cx="' . round( $cx + $r2 * cos( $a ), 2 ) . '" cy="' . round( $cy + $r2 * sin( $a ), 2 ) . '" r="3" fill="' . $col . '"/>';
            }
        }

        // Legend
        $ly = $size - 8;
        foreach ( $datasets as $di => $ds ) {
            $col = $colors[ $di % count( $colors ) ];
            $lx  = 10 + $di * 110;
            $svg .= '<rect x="' . $lx . '" y="' . ( $ly - 6 ) . '" width="10" height="10" fill="' . $col . '" rx="2"/>';
            $svg .= '<text x="' . ( $lx + 14 ) . '" y="' . ( $ly + 3 ) . '" font-size="9" fill="#555">' . esc_html( $ds['label'] ) . '</text>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    public static function player_radar_datasets( $player_id, $limit = 3 ) {
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date FROM {$p}tt_evaluations WHERE player_id=%d ORDER BY eval_date DESC LIMIT %d",
            $player_id, $limit
        ));
        $evals = array_reverse( $evals );
        $categories = self::get_categories();
        $labels  = wp_list_pluck( $categories, 'name' );
        $cat_ids = wp_list_pluck( $categories, 'id' );
        $datasets = [];
        foreach ( $evals as $ev ) {
            $raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT category_id, rating FROM {$p}tt_eval_ratings WHERE evaluation_id=%d", $ev->id
            ));
            $map = [];
            foreach ( $raw as $r ) $map[ $r->category_id ] = (float) $r->rating;
            $values = [];
            foreach ( $cat_ids as $cid ) $values[] = $map[ $cid ] ?? 0;
            $datasets[] = [ 'label' => $ev->eval_date, 'values' => $values ];
        }
        return [ 'labels' => $labels, 'datasets' => $datasets ];
    }
}
