<?php
/**
 * Migration 0325 — Branding colours nobody chose are unset (#4803).
 *
 * The Branding form stored all its colours on every save, the ones the
 * academy never touched included, so `tt_config` cannot tell a chosen colour
 * from a default. Some installs also hold `#000000` for status colours
 * nobody picked. Since #4803 the form stores a colour only when it is
 * chosen; this clears what the old form left behind:
 *
 *  - a colour stored equal to its form default (compared case-insensitively);
 *  - a status colour (danger, warning, success, info) stored as `#000000`.
 *
 * Clearing deletes the row, so readers fall back to their own default and the
 * page to `tokens.css`. Nothing renders differently: since #4797 these values
 * already counted as unset on the page. Any other value is a colour the
 * academy chose and is never touched; neither is the accent colour, whose
 * form default is the colour a saved install renders.
 *
 * The defaults are written out here, not read from `BrandStyles`, so what
 * this migration did does not change if the form's defaults ever do. Every
 * cleared row is logged per club and key. Data only, idempotent: a second
 * run finds nothing to clear. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    /** The Branding form's defaults at #4803, key => colour. */
    private const FORM_DEFAULTS = [
        'primary_color'   => '#0b3d2e',
        'secondary_color' => '#e8b624',
        'color_danger'    => '#b32d2e',
        'color_warning'   => '#dba617',
        'color_success'   => '#00a32a',
        'color_info'      => '#2271b1',
        'color_focus'     => '#1e88e5',
    ];

    private const STATUS_KEYS = [ 'color_danger', 'color_warning', 'color_success', 'color_info' ];

    public function getName(): string {
        return '0325_branding_unset_default_colours';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_config';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_config is missing' );
            return;
        }

        $keys = array_keys( self::FORM_DEFAULTS );
        $in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT club_id, config_key, config_value FROM `{$table}` WHERE config_key IN ({$in}) ORDER BY club_id, config_key", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ...$keys
        ), ARRAY_A );

        $cleared = 0;
        foreach ( (array) $rows as $row ) {
            $key   = (string) $row['config_key'];
            $value = strtolower( trim( (string) $row['config_value'] ) );
            $is_default = $value === self::FORM_DEFAULTS[ $key ];
            $is_black   = $value === '#000000' && in_array( $key, self::STATUS_KEYS, true );
            if ( ! $is_default && ! $is_black ) continue;

            $this->exec( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE club_id = %d AND config_key = %s AND config_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $row['club_id'], $key, (string) $row['config_value']
            ) );
            Logger::info( 'migration.0325.colour_unset', [
                'club_id' => (int) $row['club_id'],
                'key'     => $key,
                'value'   => (string) $row['config_value'],
                'reason'  => $is_black ? 'status colour stored as #000000' : 'equal to the form default',
            ] );
            $cleared++;
        }

        $this->report( $cleared, $cleared > 0 ? 'Branding colours unset' : 'no Branding colour was a stored default' );
    }
};
