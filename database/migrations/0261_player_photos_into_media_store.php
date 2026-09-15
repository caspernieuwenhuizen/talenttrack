<?php
/**
 * Migration 0261 — move existing player photographs into the private
 * media store, and take the public copies away (#3399).
 *
 * The per-photo work lives in `PlayerPhotoImporter`, which the admin
 * player form also calls the moment an operator picks a new photo. One
 * implementation on purpose: a separate copy here is how the sweep would
 * go on being right while new uploads quietly went back to being public.
 *
 * DELETING THE PUBLIC COPY IS THE POINT, NOT A TIDY-UP
 *
 * The public URL is the defect. Rendering from the store while leaving the
 * file in `uploads/` would fix the screen and leave every existing link
 * working forever — so anyone who already had a URL, or who finds one in a
 * backup, a referrer log or a search index, keeps a photograph of a child.
 * Decided 2026-09-15: the old URLs break, and that is the fix rather than
 * a side effect.
 *
 * WHAT IT WILL NOT TOUCH
 *
 *   - A `photo_url` pointing off-install (a club CDN, a gravatar). Not
 *     ours to move or delete; the column keeps it and `PlayerPhoto` keeps
 *     rendering it.
 *   - A row that already has `photo_media_id`. Re-running changes nothing.
 *   - A file that is missing, unreadable, or not an image. The row is left
 *     exactly as it was. A migration that cannot move a photo must not
 *     also lose it.
 *
 * Forward-only, idempotent per player. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Players\Services\PlayerPhotoImporter;

return new class extends Migration {

    public function getName(): string {
        return '0261_player_photos_into_media_store';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }
        // 0260 adds the column; without it there is nothing to write to.
        if ( $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}tt_players LIKE 'photo_media_id'" ) === null ) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            "SELECT id, photo_url
               FROM {$wpdb->prefix}tt_players
              WHERE photo_url IS NOT NULL AND photo_url != ''
                AND ( photo_media_id IS NULL OR photo_media_id = 0 )"
        );
        if ( $rows === [] ) return;

        $moved = 0;
        $left  = 0;

        foreach ( $rows as $row ) {
            $media_id = PlayerPhotoImporter::fromUploadsUrl( (int) $row->id, (string) $row->photo_url );

            if ( $media_id <= 0 ) {
                // Off-install, unreadable or not an image — the importer
                // has logged which. Leave the row exactly as it was.
                $left++;
                continue;
            }

            $wpdb->update(
                $table,
                [ 'photo_media_id' => $media_id, 'photo_url' => '' ],
                [ 'id' => (int) $row->id ]
            );
            $moved++;
        }

        Logger::info( 'player_photo.migrate.done', [ 'moved' => $moved, 'left' => $left ] );
    }
};
