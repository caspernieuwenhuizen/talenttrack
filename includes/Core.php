<?php
namespace TT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Core {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init() {
        Roles::init();

        if ( is_admin() ) {
            Admin\Menu::init();
            Admin\Teams::init();
            Admin\Players::init();
            Admin\Evaluations::init();
            Admin\Sessions::init();
            Admin\Goals::init();
            Admin\Configuration::init();
            Admin\Reports::init();
            Admin\Documentation::init();
        }

        Frontend\App::init();
        Frontend\Styles::init();
        Frontend\Ajax::init();

        REST\Players_Controller::init();
        REST\Evaluations_Controller::init();
        REST\Config_Controller::init();
        REST\Sessions_Controller::init();
        REST\Goals_Controller::init();
    }
}
