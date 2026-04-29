<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerParentsRepository — many-to-many pivot between players and
 * parent WP users.
 *
 * Replaces the single-column `tt_players.parent_user_id` for new rows.
 * The column is preserved as a derived "primary parent" shortcut: every
 * write here re-projects the `is_primary = 1` row into the column so
 * `PlayerOrParentResolver` (#0022) keeps working without rewrites.
 */
class PlayerParentsRepository {

    private \wpdb $wpdb;
    private string $table;
    private string $players_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->table         = $wpdb->prefix . 'tt_player_parents';
        $this->players_table = $wpdb->prefix . 'tt_players';
    }

    /**
     * Link a parent WP user to a player. If `is_primary` is true (or if
     * no other parents exist for the player), demotes any other primary
     * for that player and re-projects to `tt_players.parent_user_id`.
     */
    public function link( int $playerId, int $parentUserId, bool $isPrimary = false ): bool {
        if ( $playerId <= 0 || $parentUserId <= 0 ) return false;

        $existing = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE player_id = %d AND club_id = %d",
            $playerId, CurrentClub::id()
        ) );
        $becomes_primary = $isPrimary || (int) $existing === 0;

        if ( $becomes_primary ) {
            // Demote whoever was primary before.
            $this->wpdb->update(
                $this->table,
                [ 'is_primary' => 0 ],
                [ 'player_id' => $playerId, 'is_primary' => 1, 'club_id' => CurrentClub::id() ]
            );
        }

        $this->wpdb->query( $this->wpdb->prepare(
            "INSERT INTO {$this->table} (club_id, player_id, parent_user_id, is_primary)
             VALUES (%d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary)",
            CurrentClub::id(),
            $playerId,
            $parentUserId,
            $becomes_primary ? 1 : 0
        ) );

        $this->reprojectPrimary( $playerId );
        return true;
    }

    public function unlink( int $playerId, int $parentUserId ): bool {
        if ( $playerId <= 0 || $parentUserId <= 0 ) return false;

        $was_primary = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT is_primary FROM {$this->table} WHERE player_id = %d AND parent_user_id = %d AND club_id = %d",
            $playerId,
            $parentUserId,
            CurrentClub::id()
        ) );

        $this->wpdb->delete( $this->table, [
            'player_id'      => $playerId,
            'parent_user_id' => $parentUserId,
            'club_id'        => CurrentClub::id(),
        ] );

        if ( $was_primary === 1 ) {
            // Promote another linked parent (oldest first) to primary.
            $next = (int) $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT parent_user_id FROM {$this->table}
                  WHERE player_id = %d AND club_id = %d
                  ORDER BY created_at ASC
                  LIMIT 1",
                $playerId, CurrentClub::id()
            ) );
            if ( $next > 0 ) {
                $this->wpdb->update(
                    $this->table,
                    [ 'is_primary' => 1 ],
                    [ 'player_id' => $playerId, 'parent_user_id' => $next, 'club_id' => CurrentClub::id() ]
                );
            }
        }

        $this->reprojectPrimary( $playerId );
        return true;
    }

    /** @return list<int> player IDs linked to this parent. */
    public function playersForParent( int $parentUserId ): array {
        if ( $parentUserId <= 0 ) return [];
        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT player_id FROM {$this->table} WHERE parent_user_id = %d AND club_id = %d",
            $parentUserId, CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /** @return list<int> parent WP user IDs linked to this player. */
    public function parentsForPlayer( int $playerId ): array {
        if ( $playerId <= 0 ) return [];
        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT parent_user_id FROM {$this->table} WHERE player_id = %d AND club_id = %d",
            $playerId, CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * Re-project the `is_primary = 1` row into `tt_players.parent_user_id`.
     * Keeps #0022's PlayerOrParentResolver working unchanged.
     */
    private function reprojectPrimary( int $playerId ): void {
        $primary = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT parent_user_id FROM {$this->table}
              WHERE player_id = %d AND is_primary = 1 AND club_id = %d
              LIMIT 1",
            $playerId, CurrentClub::id()
        ) );
        $this->wpdb->update(
            $this->players_table,
            [ 'parent_user_id' => $primary !== null ? (int) $primary : null ],
            [ 'id' => $playerId, 'club_id' => CurrentClub::id() ]
        );
    }
}
