<?php
/**
 * Migration 0314 — regenerate stored visual-editor CSS (#4839).
 *
 * The visual editor posts every field on save, and until #4839 it wrote
 * every field into the stored stylesheet. Some of its defaults differed
 * from tokens.css, so an academy that saved the editor without changing a
 * colour still carried borders, muted text, status colours, radii and
 * shadows nobody chose. `generateCss()` now skips a value equal to a token's
 * current or legacy default. This migration runs it once over the visual
 * settings each academy already saved, so those installs follow tokens.css
 * without anyone opening the editor again.
 *
 * Only a surface whose live CSS came from the visual editor is touched:
 * saving in the code editor, applying a starter template or uploading a file
 * clears `custom_css.<surface>.visual_settings`, so hand-written CSS is never
 * rewritten. A value an academy did change is generated exactly as before.
 * Every club's rows are read, not only the current one. A second run
 * produces the same CSS and writes nothing. Forward-only: the previous
 * stylesheet stays in the surface's history.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\CustomCss\Sanitizer\CssSanitizer;
use TT\Modules\CustomCss\VisualEditor;

return new class extends Migration {

    public function getName(): string {
        return '0314_visual_editor_regenerate_css';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_config';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_config is missing' );
            return;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT club_id, config_key, config_value FROM {$table}
              WHERE config_key LIKE %s AND config_value <> ''",
            'custom\\_css.%.visual\\_settings'
        ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $sanitizer = new CssSanitizer();
        $rewritten = 0;
        $dropped   = 0;
        $clubs     = [];

        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) ) continue;
            if ( ! preg_match( '/^custom_css\.([a-z]+)\.visual_settings$/', (string) $row->config_key, $m ) ) continue;
            $settings = json_decode( (string) $row->config_value, true );
            if ( ! is_array( $settings ) ) continue;

            $club_id = (int) $row->club_id;
            $css_key = 'custom_css.' . $m[1] . '.css';
            $old     = $wpdb->get_var( $wpdb->prepare(
                "SELECT config_value FROM {$table} WHERE club_id = %d AND config_key = %s",
                $club_id, $css_key
            ) );
            if ( $old === null ) continue;

            $new = $sanitizer->sanitize( VisualEditor::generateCss( $settings ) );
            if ( ! is_string( $new ) || $new === (string) $old ) continue;

            $this->exec( $wpdb->prepare(
                "UPDATE {$table} SET config_value = %s WHERE club_id = %d AND config_key = %s",
                $new, $club_id, $css_key
            ) );

            $rewritten++;
            $clubs[ $club_id ] = true;
            $dropped += max( 0, self::declarations( (string) $old ) - self::declarations( $new ) );
        }

        $this->report( $rewritten, sprintf(
            '%d stylesheet(s) in %d club(s) regenerated; %d token declaration(s) left to tokens.css',
            $rewritten, count( $clubs ), $dropped
        ) );
    }

    private static function declarations( string $css ): int {
        return (int) preg_match_all( '/--tt-[a-z0-9-]+\s*:/', $css );
    }

    public function down(): void {
        // Forward-only.
    }
};
