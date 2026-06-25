<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerParentVisibilityRepository (#1867) — a player's per-section
 * choice about what a linked parent may see. Default-visible: a section
 * with no row is visible, so existing parents keep today's access.
 *
 * Scope is deliberately narrow — only the development sections a child
 * might reasonably keep to themselves. Card / team are always visible;
 * safeguarding / medical stay cap-gated and are NOT player-controllable.
 */
class PlayerParentVisibilityRepository {

    /** Sections a player can hide from a parent. */
    public const SECTIONS = [ 'evaluations', 'goals', 'journey', 'measurements', 'pdp' ];

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_player_parent_visibility';
    }

    /**
     * The player's visibility map: section_key => visible (bool). Absent
     * rows default to true. Always returns every in-scope section.
     *
     * @return array<string,bool>
     */
    public function preferencesForPlayer( int $player_id ): array {
        $out = array_fill_keys( self::SECTIONS, true );
        if ( $player_id <= 0 ) return $out;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT section_key, visible FROM {$this->table()} WHERE player_id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
        foreach ( $rows ?: [] as $r ) {
            $key = (string) $r->section_key;
            if ( array_key_exists( $key, $out ) ) {
                $out[ $key ] = (int) $r->visible === 1;
            }
        }
        return $out;
    }

    /** Is a single section visible to the player's parents? Unknown (un-gateable) sections are always visible. */
    public function isVisible( int $player_id, string $section ): bool {
        if ( ! in_array( $section, self::SECTIONS, true ) ) return true;
        return $this->preferencesForPlayer( $player_id )[ $section ] ?? true;
    }

    /** Upsert one section's visibility. Returns true on success. */
    public function setVisibility( int $player_id, string $section, bool $visible ): bool {
        if ( $player_id <= 0 || ! in_array( $section, self::SECTIONS, true ) ) return false;

        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE player_id = %d AND section_key = %s AND club_id = %d",
            $player_id, $section, CurrentClub::id()
        ) );
        if ( $existing ) {
            return false !== $wpdb->update(
                $this->table(),
                [ 'visible' => $visible ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $existing ]
            );
        }
        return false !== $wpdb->insert( $this->table(), [
            'club_id'     => CurrentClub::id(),
            'player_id'   => $player_id,
            'section_key' => $section,
            'visible'     => $visible ? 1 : 0,
        ] );
    }
}
