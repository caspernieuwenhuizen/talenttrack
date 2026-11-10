<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\BrandStyles;

/**
 * #4803 — the Branding form stores only the colours an academy changed.
 *
 * The form submitted every colour on every save, so `tt_config` held the
 * defaults (and on some installs `#000000` status colours) as if somebody had
 * chosen them. Saving now stores a colour only when it is chosen, "Reset to
 * default" clears one, and migration 0325 clears what the old form stored.
 */
final class BrandingStoredColoursTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0325_branding_unset_default_colours.php';

    /** What the form submits when the academy changed only the primary colour. */
    private const UNTOUCHED = [
        'secondary_color' => '#e8b624',
        'color_accent'    => '#1e88e5',
        'color_danger'    => '#b32d2e',
        'color_warning'   => '#dba617',
        'color_success'   => '#00a32a',
        'color_info'      => '#2271b1',
        'color_focus'     => '#1e88e5',
    ];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        foreach ( array_keys( self::UNTOUCHED ) + [ 'x' => 'primary_color' ] as $key ) {
            QueryHelpers::delete_config( $key );
        }
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_saving_with_only_the_primary_changed_stores_only_the_primary(): void {
        $response = $this->save( [ 'primary_color' => '#aa0000' ] + self::UNTOUCHED );
        $this->assertSame( 200, $response->get_status() );

        $this->assertSame( '#aa0000', $this->stored( 'primary_color' ) );
        foreach ( [ 'secondary_color', 'color_danger', 'color_warning', 'color_success', 'color_info', 'color_focus' ] as $key ) {
            $this->assertNull( $this->stored( $key ), "{$key} left at its default is not stored" );
        }
        // The accent has no default apart from the form's: its #1e88e5 is the
        // colour a saved install renders, so it stays a stored value (#4797).
        $this->assertSame( '#1e88e5', $this->stored( 'color_accent' ) );
    }

    public function test_a_cleared_or_black_status_colour_is_not_stored(): void {
        $this->save( [ 'color_danger' => '#000000', 'color_info' => '' ] );
        $this->assertNull( $this->stored( 'color_danger' ) );
        $this->assertNull( $this->stored( 'color_info' ) );
    }

    public function test_resetting_a_colour_clears_it(): void {
        $this->save( [ 'color_warning' => '#123456' ] );
        $this->assertSame( '#123456', $this->stored( 'color_warning' ), 'precondition: a chosen colour is stored' );

        // "Reset to default" puts the default back in the field; Save clears it.
        $this->save( [ 'color_warning' => '#dba617' ] );
        $this->assertNull( $this->stored( 'color_warning' ), 'reset clears the stored colour' );
        $this->assertSame( '#dba617', QueryHelpers::get_config( 'color_warning', '#dba617' ), 'and the field shows the default again' );
        $this->assertStringNotContainsString( '--tt-warning', BrandStyles::css(), 'so the page follows tokens.css' );
    }

    public function test_every_colour_field_has_a_reset_button(): void {
        $method = new \ReflectionMethod( \TT\Shared\Frontend\FrontendConfigurationView::class, 'renderAppearanceForm' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null );
        $html = (string) ob_get_clean();

        foreach ( [ 'primary-color', 'secondary-color', 'color_accent', 'color_danger', 'color_warning', 'color_success', 'color_info', 'color_focus' ] as $field ) {
            $this->assertStringContainsString( 'data-tt-color-reset="tt-cfg-' . $field . '"', $html, "{$field} has its reset" );
        }
        $this->assertStringContainsString( 'aria-label="Reset Danger color to default"', $html, 'the button names its colour' );
    }

    public function test_the_migration_clears_empty_rows_stored_defaults_and_black_status_colours(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_config';
        $club  = (int) CurrentClub::id();
        $other = $club + 1;
        $rows  = [
            [ $club,  'color_danger',    '#000000' ],  // a black status colour
            [ $club,  'secondary_color', '#E8B624' ],  // the form default, any case
            [ $club,  'primary_color',   '#ff6600' ],  // a colour the academy chose
            [ $club,  'color_accent',    '#1e88e5' ],  // the accent stays
            [ $club,  'color_focus',     '#000000' ],  // black is a choice outside the status colours
            [ $club,  'color_info',      '#123abc' ],  // a chosen status colour
            [ $club,  'color_success',   '' ],         // stored empty
            [ $other, 'color_accent',    '  ' ],       // stored empty, the accent too
            [ $other, 'color_warning',   '#000000' ],  // another club, the same rule
        ];
        foreach ( $rows as [ $c, $k, $v ] ) {
            $wpdb->replace( $table, [ 'club_id' => $c, 'config_key' => $k, 'config_value' => $v ] );
        }

        $this->migration()->up();

        $left = $this->rows( $table, [ $club, $other ] );
        $this->assertArrayNotHasKey( "{$club}:color_danger", $left, 'the black status colour is unset' );
        $this->assertArrayNotHasKey( "{$club}:secondary_color", $left, 'the stored default is unset' );
        $this->assertArrayNotHasKey( "{$other}:color_warning", $left, 'in every club' );
        $this->assertArrayNotHasKey( "{$club}:color_success", $left, 'an empty row is unset' );
        $this->assertArrayNotHasKey( "{$other}:color_accent", $left, 'an empty accent is unset too' );
        $this->assertSame( '#123abc', $left[ "{$club}:color_info" ] ?? null, 'a chosen status colour survives' );
        $this->assertSame( '#ff6600', $left[ "{$club}:primary_color" ] ?? null, 'a chosen colour is never touched' );
        $this->assertSame( '#1e88e5', $left[ "{$club}:color_accent" ] ?? null );
        $this->assertSame( '#000000', $left[ "{$club}:color_focus" ] ?? null );

        $this->migration()->up();
        $this->assertSame( $left, $this->rows( $table, [ $club, $other ] ), 'a second run changes nothing' );
    }

    public function test_after_the_data_step_alerts_use_the_tokens_colours(): void {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'tt_config', [ 'club_id' => (int) CurrentClub::id(), 'config_key' => 'color_success', 'config_value' => '#000000' ] );
        $this->migration()->up();
        $this->assertNull( $this->stored( 'color_success' ) );
        $this->assertStringNotContainsString( '--tt-success', BrandStyles::css(), 'the success colour comes from tokens.css' );
    }

    /** @param array<string,string> $config */
    private function save( array $config ): \WP_REST_Response {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/config' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'config' => $config ] ) );
        $response = rest_get_server()->dispatch( $request );
        $this->resetConfigCache();
        return $response;
    }

    private function stored( string $key ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = %d AND config_key = %s",
            (int) CurrentClub::id(), $key
        ) );
        return $value === null ? null : (string) $value;
    }

    /**
     * @param list<int> $clubs
     * @return array<string,string> "club:key" => value
     */
    private function rows( string $table, array $clubs ): array {
        global $wpdb;
        $out = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT club_id, config_key, config_value FROM `{$table}` WHERE club_id IN (%d, %d) AND config_key IN ('primary_color','secondary_color','color_accent','color_danger','color_warning','color_success','color_info','color_focus') ORDER BY club_id, config_key", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $clubs[0], $clubs[1]
        ), ARRAY_A ) as $row ) {
            $out[ $row['club_id'] . ':' . $row['config_key'] ] = (string) $row['config_value'];
        }
        return $out;
    }

    private function migration(): object {
        $migration = require dirname( __DIR__, 2 ) . self::MIGRATION;
        $this->resetConfigCache();
        return $migration;
    }

    /** The per-request config cache, so a read after a direct write sees it. */
    private function resetConfigCache(): void {
        $prop = new \ReflectionProperty( QueryHelpers::class, 'config' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }
}
