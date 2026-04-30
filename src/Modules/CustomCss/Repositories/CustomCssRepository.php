<?php
namespace TT\Modules\CustomCss\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * CustomCssRepository — read/write helper for the #0064 custom-CSS
 * payload + history. The "live" CSS for each surface lives in
 * `tt_config` (consistent with the existing branding pattern) keyed
 * `custom_css.<surface>.css` / `.enabled` / `.version` /
 * `.visual_settings`. The history table holds the rolling last-10
 * auto-saves + any named presets.
 *
 * Surface ids: `frontend` (the [tt_dashboard] / TT-rendered surfaces)
 * and `admin` (the wp-admin TT pages). Both are independent — the
 * #0023 mutex with theme-inherit is enforced at the UI layer, not
 * here.
 */
final class CustomCssRepository {

    public const SURFACE_FRONTEND = 'frontend';
    public const SURFACE_ADMIN    = 'admin';

    public const KIND_AUTO   = 'auto';
    public const KIND_PRESET = 'preset';

    /** Hard cap on auto-saves retained per surface. */
    public const HISTORY_LIMIT = 10;

    /** @return array{css:string, enabled:bool, version:int, visual_settings:?array} */
    public function getLive( string $surface ): array {
        $surface = self::sanitizeSurface( $surface );
        $css = QueryHelpers::get_config( "custom_css.{$surface}.css", '' );
        $enabled = QueryHelpers::get_config( "custom_css.{$surface}.enabled", '0' ) === '1';
        $version = (int) QueryHelpers::get_config( "custom_css.{$surface}.version", '0' );
        $visual_raw = QueryHelpers::get_config( "custom_css.{$surface}.visual_settings", '' );
        $visual = null;
        if ( $visual_raw !== '' ) {
            $decoded = json_decode( $visual_raw, true );
            if ( is_array( $decoded ) ) $visual = $decoded;
        }
        return [
            'css'             => (string) $css,
            'enabled'         => $enabled,
            'version'         => $version,
            'visual_settings' => $visual,
        ];
    }

    /**
     * Persist the new live CSS payload + bump version + write a
     * history row. Caller is responsible for sanitization upstream.
     */
    public function saveLive(
        string $surface,
        string $css_body,
        bool $enabled,
        ?array $visual_settings,
        int $saved_by_user_id
    ): int {
        $surface = self::sanitizeSurface( $surface );
        $live = $this->getLive( $surface );
        $next_version = $live['version'] + 1;

        QueryHelpers::set_config( "custom_css.{$surface}.css", $css_body );
        QueryHelpers::set_config( "custom_css.{$surface}.enabled", $enabled ? '1' : '0' );
        QueryHelpers::set_config( "custom_css.{$surface}.version", (string) $next_version );
        QueryHelpers::set_config(
            "custom_css.{$surface}.visual_settings",
            $visual_settings === null ? '' : (string) wp_json_encode( $visual_settings )
        );

        $id = $this->writeHistoryRow(
            $surface, self::KIND_AUTO, null, $css_body, $visual_settings, $saved_by_user_id
        );
        $this->trimAutoHistory( $surface );

        return $id;
    }

    /**
     * Save the current live payload as a named preset (doesn't count
     * against the auto-save cap). Caller passes the desired name.
     */
    public function savePreset( string $surface, string $name, int $saved_by_user_id ): int {
        $surface = self::sanitizeSurface( $surface );
        $live = $this->getLive( $surface );
        return $this->writeHistoryRow(
            $surface,
            self::KIND_PRESET,
            $name,
            $live['css'],
            $live['visual_settings'],
            $saved_by_user_id
        );
    }

    /**
     * Restore a history row as the live payload. Returns the new
     * version number. Increments version + adds a fresh auto row so
     * the revert itself is undoable.
     */
    public function revertTo( int $history_id, int $saved_by_user_id ): int {
        $row = $this->findHistoryRow( $history_id );
        if ( $row === null ) return 0;
        $surface = (string) $row->surface;
        $visual = null;
        if ( ! empty( $row->visual_settings ) ) {
            $decoded = json_decode( (string) $row->visual_settings, true );
            if ( is_array( $decoded ) ) $visual = $decoded;
        }
        $live = $this->getLive( $surface );
        $this->saveLive(
            $surface,
            (string) $row->css_body,
            $live['enabled'],
            $visual,
            $saved_by_user_id
        );
        return $this->getLive( $surface )['version'];
    }

    public function deleteHistoryRow( int $history_id ): bool {
        global $wpdb;
        $row = $this->findHistoryRow( $history_id );
        if ( $row === null ) return false;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'tt_custom_css_history',
            [ 'id' => $history_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /** @return object[] */
    public function listHistory( string $surface ): array {
        $surface = self::sanitizeSurface( $surface );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, surface, kind, preset_name, byte_count, saved_by_user_id, saved_at
               FROM {$wpdb->prefix}tt_custom_css_history
              WHERE club_id = %d AND surface = %s
              ORDER BY saved_at DESC, id DESC",
            CurrentClub::id(), $surface
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function findHistoryRow( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_custom_css_history
              WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    private function writeHistoryRow(
        string $surface,
        string $kind,
        ?string $preset_name,
        string $css_body,
        ?array $visual_settings,
        int $saved_by_user_id
    ): int {
        global $wpdb;
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_custom_css_history', [
            'club_id'          => CurrentClub::id(),
            'surface'          => $surface,
            'kind'             => $kind,
            'preset_name'      => $preset_name,
            'css_body'         => $css_body,
            'visual_settings'  => $visual_settings === null ? null : (string) wp_json_encode( $visual_settings ),
            'byte_count'       => strlen( $css_body ),
            'saved_by_user_id' => $saved_by_user_id,
        ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Cap the rolling auto-save history at HISTORY_LIMIT. Named
     * presets are exempt — they live forever until the operator
     * deletes them.
     */
    private function trimAutoHistory( string $surface ): void {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_custom_css_history
              WHERE club_id = %d AND surface = %s AND kind = %s
              ORDER BY saved_at DESC, id DESC",
            CurrentClub::id(), $surface, self::KIND_AUTO
        ) );
        if ( ! is_array( $rows ) || count( $rows ) <= self::HISTORY_LIMIT ) return;
        $stale = array_slice( $rows, self::HISTORY_LIMIT );
        foreach ( $stale as $id ) {
            $wpdb->delete(
                $wpdb->prefix . 'tt_custom_css_history',
                [ 'id' => (int) $id, 'club_id' => CurrentClub::id() ]
            );
        }
    }

    public static function sanitizeSurface( string $surface ): string {
        return $surface === self::SURFACE_ADMIN ? self::SURFACE_ADMIN : self::SURFACE_FRONTEND;
    }
}
