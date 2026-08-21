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
    /**
     * #2500 — `training` joins the list. A player's training exposure is
     * a per-principle ledger of what they have and have not been taught,
     * which reads as a list of their shortfalls. If a young person may
     * withhold their measurements and their PDP from a parent, this
     * belongs in the same bracket rather than being the one development
     * surface they cannot close.
     *
     * A section absent from this list is always visible — `isVisible()`
     * returns true for an unknown key — so adding it here is what makes
     * the control real rather than decorative.
     */
    public const SECTIONS = [ 'evaluations', 'goals', 'journey', 'measurements', 'pdp', 'training' ];

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
