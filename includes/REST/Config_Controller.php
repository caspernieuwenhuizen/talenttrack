<?php
namespace TT\REST;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Config_Controller {
    const NS = 'talenttrack/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_rest_route( self::NS, '/config', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_config' ], 'permission_callback' => function () { return is_user_logged_in(); } ],
        ]);
        register_rest_route( self::NS, '/config/categories', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_categories' ], 'permission_callback' => function () { return is_user_logged_in(); } ],
        ]);
        register_rest_route( self::NS, '/teams', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_teams' ], 'permission_callback' => function () { return is_user_logged_in(); } ],
        ]);
    }

    public static function get_config() {
        return rest_ensure_response( [
            'rating_min'      => (float) Helpers::get_config( 'rating_min', 1 ),
            'rating_max'      => (float) Helpers::get_config( 'rating_max', 5 ),
            'rating_step'     => (float) Helpers::get_config( 'rating_step', '0.5' ),
            'categories'      => Helpers::get_categories(),
            'eval_types'      => Helpers::get_eval_types(),
            'positions'       => Helpers::get_lookup_names( 'position' ),
            'foot_options'    => Helpers::get_lookup_names( 'foot_option' ),
            'age_groups'      => Helpers::get_lookup_names( 'age_group' ),
            'primary_color'   => Helpers::get_config( 'primary_color' ),
            'secondary_color' => Helpers::get_config( 'secondary_color' ),
            'academy_name'    => Helpers::get_config( 'academy_name' ),
            'logo_url'        => Helpers::get_config( 'logo_url' ),
        ]);
    }

    public static function get_categories() {
        return rest_ensure_response( Helpers::get_categories() );
    }

    public static function get_teams() {
        return rest_ensure_response( Helpers::get_teams() );
    }
}
