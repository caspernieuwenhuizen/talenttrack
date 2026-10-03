<?php
/**
 * Make the dashboard page the static front page of the wp-env tests site.
 *
 * Run with WP-CLI from the plugin directory:
 *
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack \
 *       wp eval-file tests/e2e/setup/dashboard-front-page.php
 *
 * Every frontend spec loads `/?tt_view=<slug>`. That URL only renders the
 * dashboard when the front page IS the dashboard page:
 * `DashboardShortcode::forceFrontPageForViews()` is guarded on
 * `show_on_front` / `page_on_front`. A fresh wp-env site has neither, so
 * WordPress rendered the blog index, no `.tt-dashboard` appeared, and every
 * frontend spec skipped itself (#4195). Onboarding sets both on a real
 * install; this mirrors it without finishing the onboarding wizard.
 *
 * Idempotent: a page already holding the shortcode is reused.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$tt_e2e_existing = get_posts( [
	'post_type'   => 'page',
	'post_status' => [ 'publish', 'draft', 'private' ],
	'numberposts' => 1,
	's'           => '[talenttrack_dashboard]',
	'fields'      => 'ids',
] );

if ( ! empty( $tt_e2e_existing ) ) {
	$tt_e2e_page_id = (int) $tt_e2e_existing[0];
	wp_update_post( [ 'ID' => $tt_e2e_page_id, 'post_status' => 'publish' ] );
} else {
	$tt_e2e_page_id = (int) wp_insert_post( [
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Dashboard',
		'post_content' => "<!-- wp:group {\"align\":\"full\"} -->\n<div class=\"wp-block-group alignfull\">[talenttrack_dashboard]</div>\n<!-- /wp:group -->",
	] );
}

if ( $tt_e2e_page_id <= 0 ) {
	WP_CLI::error( 'Could not create the dashboard page.' );
}

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $tt_e2e_page_id );

if ( class_exists( '\TT\Infrastructure\Query\QueryHelpers' ) ) {
	\TT\Infrastructure\Query\QueryHelpers::set_config( 'dashboard_page_id', (string) $tt_e2e_page_id );
}

WP_CLI::success( sprintf( 'Dashboard page %d is the static front page.', $tt_e2e_page_id ) );
