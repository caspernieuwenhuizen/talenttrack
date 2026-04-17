<?php
namespace TT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Roles {
    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'ensure_caps' ] );
    }
    public static function ensure_caps() {
        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( 'tt_manage_players' ) ) {
            $admin->add_cap( 'tt_manage_players' );
            $admin->add_cap( 'tt_evaluate_players' );
            $admin->add_cap( 'tt_manage_settings' );
        }
    }
}
