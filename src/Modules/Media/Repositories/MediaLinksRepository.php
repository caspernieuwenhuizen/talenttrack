<?php
namespace TT\Modules\Media\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Media\MediaEntityType;

/**
 * MediaLinksRepository (#2590, epic #2589) — the attachments.
 *
 * A link is what makes one upload appear on several records. The rule
 * that governs this table: **a media item with no links is garbage.**
 * `unlink()` therefore deletes the media and its bytes when it removes
 * the last link, rather than leaving a row nothing can reach and a blob
 * nothing accounts for.
 */
final class MediaLinksRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_media_links';
    }

    /**
     * Attach a media item to a record. Idempotent — the unique key means
     * a second attach of the same pair is a no-op, not a duplicate tile.
     *
     * @return int Link id, or 0 when the arguments are not valid.
     */
    public function link( int $media_id, string $entity_type, int $entity_id, bool $is_primary = false ): int {
        global $wpdb;

        if ( $media_id <= 0 || $entity_id <= 0 || ! MediaEntityType::isValid( $entity_type ) ) return 0;

        $existing = $this->findLink( $media_id, $entity_type, $entity_id );
        if ( $existing ) {
            if ( $is_primary ) $this->markPrimary( (int) $existing->id );
            return (int) $existing->id;
        }

        $ok = $wpdb->insert( $this->table(), array_merge( [
            'media_id'    => $media_id,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'is_primary'  => $is_primary ? 1 : 0,
            'sort_order'  => $this->nextSortOrder( $entity_type, $entity_id ),
            'created_by'  => get_current_user_id(),
        ], QueryHelpers::clubScopeInsertColumn() ) );

        if ( $ok === false ) return 0;

        $id = (int) $wpdb->insert_id;
        if ( $is_primary ) $this->markPrimary( $id );
        return $id;
    }

    /**
     * Detach, and clean up behind it.
     *
     * @param bool $delete_orphan Delete the media + bytes when this was
     *                            its last link. Only pass false when the
     *                            caller is about to delete the media itself.
     */
    public function unlink( int $link_id, bool $delete_orphan = true ): bool {
        global $wpdb;

        $link = $this->find( $link_id );
        if ( ! $link ) return false;

        $deleted = $wpdb->delete( $this->table(), [ 'id' => $link_id, 'club_id' => CurrentClub::id() ] );
        if ( $deleted === false ) return false;

        if ( $delete_orphan && $this->countFor( (int) $link->media_id ) === 0 ) {
            ( new MediaRepository() )->deleteWithBlobs( (int) $link->media_id );
        }

        return true;
    }

    /**
     * Remove every link to one record — what a record's own deletion
     * cascade calls. Any media left attached to nothing goes with it.
     *
     * @return int Links removed.
     */
    public function unlinkEntity( string $entity_type, int $entity_id ): int {
        global $wpdb;
        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return 0;

        $media_ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT media_id FROM {$this->table()} WHERE entity_type = %s AND entity_id = %d AND club_id = %d",
            $entity_type,
            $entity_id,
            CurrentClub::id()
        ) );

        if ( $media_ids === [] ) return 0;

        $removed = (int) $wpdb->delete( $this->table(), [
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'club_id'     => CurrentClub::id(),
        ] );

        $media_repo = new MediaRepository();
        foreach ( $media_ids as $media_id ) {
            if ( $this->countFor( (int) $media_id ) === 0 ) {
                $media_repo->deleteWithBlobs( (int) $media_id );
            }
        }

        return $removed;
    }

    /** @return object[] */
    public function listForMedia( int $media_id ): array {
        global $wpdb;
        if ( $media_id <= 0 ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE media_id = %d AND club_id = %d ORDER BY entity_type ASC, entity_id ASC",
            $media_id,
            CurrentClub::id()
        ) );
    }

    public function find( int $link_id ): ?object {
        global $wpdb;
        if ( $link_id <= 0 ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d",
            $link_id,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function findLink( int $media_id, string $entity_type, int $entity_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE media_id = %d AND entity_type = %s AND entity_id = %d AND club_id = %d",
            $media_id,
            $entity_type,
            $entity_id,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** How many records this media item is still attached to. */
    public function countFor( int $media_id ): int {
        global $wpdb;
        if ( $media_id <= 0 ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE media_id = %d AND club_id = %d",
            $media_id,
            CurrentClub::id()
        ) );
    }

    /**
     * Exactly one primary per record — the flag is cleared on the record's
     * other links, not on the media item's links elsewhere. The same team
     * photo can be primary for the team and ordinary for each player in it.
     */
    public function markPrimary( int $link_id ): bool {
        global $wpdb;

        $link = $this->find( $link_id );
        if ( ! $link ) return false;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()} SET is_primary = 0
              WHERE entity_type = %s AND entity_id = %d AND club_id = %d AND id <> %d",
            (string) $link->entity_type,
            (int) $link->entity_id,
            CurrentClub::id(),
            $link_id
        ) );

        return false !== $wpdb->update(
            $this->table(),
            [ 'is_primary' => 1 ],
            [ 'id' => $link_id, 'club_id' => CurrentClub::id() ]
        );
    }

    private function nextSortOrder( string $entity_type, int $entity_id ): int {
        global $wpdb;
        return 1 + (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) FROM {$this->table()}
              WHERE entity_type = %s AND entity_id = %d AND club_id = %d",
            $entity_type,
            $entity_id,
            CurrentClub::id()
        ) );
    }
}
