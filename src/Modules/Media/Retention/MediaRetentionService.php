<?php
namespace TT\Modules\Media\Retention;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;

/**
 * MediaRetentionService (#2666, epic #2589) — when a child's photographs
 * should stop being held.
 *
 * Keeping a minor's photograph indefinitely, with no stated period and no
 * review, is hard to defend under GDPR's storage-limitation principle. An
 * academy asked "how long do you keep photos of my child?" needs an
 * answer the product supports.
 *
 * Four decisions shape everything here (#2666):
 *
 *   **R1 — the clock starts when the player leaves**, not when the photo
 *   was taken or uploaded. That is when the justification for holding it
 *   ends. A player who stays eight years keeps their whole file; the
 *   alternative would delete the early years of a current player's
 *   development record, which is the longitudinal picture the product
 *   exists to build.
 *
 *   **R2 — expiry acts on the attachment, not the item.** A photo tagged
 *   to three players is not one player's to expire. Their link goes; the
 *   photo stays for the others and for the training it came from, and is
 *   deleted only when nothing links it any more.
 *
 *   **R3 — nothing is deleted automatically.** Expired attachments
 *   surface for a person to confirm. That is also where the legal-hold
 *   case lives: an academy can keep something for a safeguarding matter
 *   and record why.
 *
 *   **R4 — a default period ships** (three years), configurable, with an
 *   explicit "keep indefinitely". Safe to default precisely because of
 *   R3: the period populates a queue, it does not destroy anything. An
 *   upgrade starts no deletion clock.
 *
 * Nothing is materialised. Expiry is derived from the player's dated
 * departure event and the club's period, so changing the period
 * re-evaluates everything, and a player who returns falls out of the
 * queue on their own.
 */
final class MediaRetentionService {

    /** Config key holding the period, in years. `0` means keep indefinitely. */
    public const CONFIG_KEY = 'tt_media_retention_years';

    /** Shipped default (R4). */
    public const DEFAULT_YEARS = 3;

    /** Journey events that mean the player has left. */
    private const DEPARTURE_EVENTS = [ 'status_released', 'status_graduated' ];

    /** Statuses that mean the player is currently gone. */
    private const DEPARTED_STATUSES = [ PlayerStatus::RELEASED, PlayerStatus::GRADUATED ];

    /**
     * Configured period in years, or 0 for keep-indefinitely.
     *
     * Clamped rather than trusted: a negative or absurd value in config
     * would otherwise expire everything the moment it was saved.
     */
    public static function years(): int {
        $raw = QueryHelpers::get_config( self::CONFIG_KEY, (string) self::DEFAULT_YEARS );
        if ( $raw === '' ) return self::DEFAULT_YEARS;

        $years = (int) $raw;
        if ( $years <= 0 ) return 0;
        return min( $years, 50 );
    }

    public static function isEnabled(): bool {
        return self::years() > 0;
    }

    /**
     * Attachments whose player left long enough ago to be reviewed.
     *
     * Only `player` links are considered. A squad photo belongs to the
     * team, and the team does not leave — so team and activity links
     * never expire, and an item attached to a training survives every
     * departure.
     *
     * @return list<array{
     *   link_id:int, media_id:int, uuid:string, title:string, kind:string,
     *   player_id:int, player_name:string, departed_on:string, estimated:bool
     * }>
     */
    public function candidates( int $limit = 200 ): array {
        if ( ! self::isEnabled() ) return [];

        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::years() . ' years' ) ?: time() );
        $p      = $wpdb->prefix;

        $statuses = implode( ',', array_fill( 0, count( self::DEPARTED_STATUSES ), '%s' ) );
        $events   = implode( ',', array_fill( 0, count( self::DEPARTURE_EVENTS ), '%s' ) );

        // The departure date is the player's most recent departure event.
        // Players with no such event fall back to when their row last
        // changed — see `estimated` below.
        $sql = "
            SELECT
                l.id           AS link_id,
                m.id           AS media_id,
                m.uuid         AS uuid,
                m.title        AS title,
                m.kind         AS kind,
                pl.id          AS player_id,
                pl.first_name  AS first_name,
                pl.last_name   AS last_name,
                pl.updated_at  AS player_updated_at,
                (
                    SELECT MAX(e.event_date) FROM {$p}tt_player_events e
                     WHERE e.player_id = pl.id
                       AND e.event_type IN ({$events})
                       AND e.superseded_at IS NULL
                ) AS departed_event
            FROM {$p}tt_media_links l
            INNER JOIN {$p}tt_media m   ON m.id  = l.media_id
            INNER JOIN {$p}tt_players pl ON pl.id = l.entity_id
            WHERE l.entity_type = %s
              AND l.club_id = %d
              AND m.club_id = %d
              AND l.retention_hold_at IS NULL
              AND pl.status IN ({$statuses})
            ORDER BY departed_event ASC, l.id ASC
            LIMIT %d";

        $args = array_merge(
            self::DEPARTURE_EVENTS,
            [ MediaEntityType::PLAYER, CurrentClub::id(), CurrentClub::id() ],
            self::DEPARTED_STATUSES,
            [ max( 1, $limit ) ]
        );

        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

        $out = [];
        foreach ( $rows as $row ) {
            $estimated = empty( $row->departed_event );
            $departed  = $estimated ? (string) $row->player_updated_at : (string) $row->departed_event;

            if ( $departed === '' || $departed >= $cutoff ) continue;

            $name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );

            $out[] = [
                'link_id'     => (int) $row->link_id,
                'media_id'    => (int) $row->media_id,
                'uuid'        => (string) $row->uuid,
                'title'       => (string) $row->title,
                'kind'        => (string) $row->kind,
                'player_id'   => (int) $row->player_id,
                'player_name' => $name !== '' ? $name : __( 'Unnamed player', 'talenttrack' ),
                'departed_on' => substr( $departed, 0, 10 ),
                'estimated'   => $estimated,
            ];
        }

        return $out;
    }

    /** How many attachments are waiting. Cheap enough for a badge. */
    public function pendingCount(): int {
        return count( $this->candidates( 500 ) );
    }

    /**
     * Remove one expired attachment (R2).
     *
     * The link goes. `MediaLinksRepository::unlink()` deletes the item and
     * its bytes only if that was the last thing pointing at it — so a
     * squad photo survives, and a portrait of just this player does not.
     *
     * @return array{removed:bool, media_deleted:bool}
     */
    public function removeAttachment( int $link_id ): array {
        $links = new MediaLinksRepository();
        $link  = $links->find( $link_id );

        if ( ! $link ) return [ 'removed' => false, 'media_deleted' => false ];

        $media_id = (int) $link->media_id;
        $removed  = $links->unlink( $link_id );

        return [
            'removed'       => $removed,
            'media_deleted' => $removed && ( new MediaRepository() )->find( $media_id ) === null,
        ];
    }

    /**
     * Keep this attachment despite its date, and say why.
     *
     * A held link never appears in the queue again. The reason is
     * required rather than optional: "we kept it" without a reason is
     * indistinguishable from nobody having looked.
     */
    public function hold( int $link_id, string $reason ): bool {
        global $wpdb;

        $reason = trim( $reason );
        if ( $link_id <= 0 || $reason === '' ) return false;

        return false !== $wpdb->update(
            $wpdb->prefix . 'tt_media_links',
            [
                'retention_hold_at'     => current_time( 'mysql', true ),
                'retention_hold_reason' => mb_substr( $reason, 0, 255 ),
                'retention_hold_by'     => get_current_user_id(),
            ],
            [ 'id' => $link_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /** Undo a hold, putting the attachment back in the queue. */
    public function releaseHold( int $link_id ): bool {
        global $wpdb;

        return false !== $wpdb->update(
            $wpdb->prefix . 'tt_media_links',
            [ 'retention_hold_at' => null, 'retention_hold_reason' => null, 'retention_hold_by' => null ],
            [ 'id' => $link_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Attachments somebody chose to keep, with their reasons.
     *
     * Shown beside the queue rather than hidden: a retention policy with
     * an invisible list of exceptions is not a policy anyone can audit.
     *
     * @return object[]
     */
    public function held( int $limit = 200 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id AS link_id, l.retention_hold_at, l.retention_hold_reason, l.retention_hold_by,
                    m.uuid, m.title, m.kind,
                    pl.first_name, pl.last_name
               FROM {$p}tt_media_links l
               INNER JOIN {$p}tt_media m    ON m.id  = l.media_id
               INNER JOIN {$p}tt_players pl ON pl.id = l.entity_id
              WHERE l.entity_type = %s
                AND l.club_id = %d
                AND l.retention_hold_at IS NOT NULL
              ORDER BY l.retention_hold_at DESC
              LIMIT %d",
            MediaEntityType::PLAYER,
            CurrentClub::id(),
            max( 1, $limit )
        ) );
    }
}
