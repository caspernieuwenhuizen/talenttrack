<?php
namespace TT\Modules\Media\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\Repositories\MediaLinksRepository;

/**
 * MediaVisibilityService (#2591, epic #2589) — who may see a photograph.
 *
 * Domain layer, deliberately. The REST controller and every PHP view ask
 * this class the same question and get the same answer, which is what
 * keeps a future non-WordPress front end from re-deriving the rule and
 * getting it subtly wrong (CLAUDE.md §4).
 *
 * **The rule**: a user may act on a media item if they may act on *any*
 * record it is attached to. Attachment is the unit of access, because a
 * media item on its own has no subject — it is the link to a player, a
 * team or an activity that says whose photograph this is.
 *
 * Scope resolution is delegated to `MatrixGate` rather than reimplemented:
 * it already knows that `player` scope means the player themselves or
 * their parent, and that `team` scope means an assignment in
 * `tt_user_role_scopes`. Two mappings are this class's own work:
 *
 *   - a **player** link is also reachable by staff scoped to that
 *     player's team, so the player's `team_id` is checked as a team-scope
 *     grant. Without this a coach would be refused their own squad's
 *     photographs, because they are neither the player nor its parent.
 *   - an **activity** link resolves to the activity's `team_id`, for the
 *     same reason.
 *
 * **Co-depiction is allowed** (epic #2589 decision D5). A clip linked to
 * players A, B and C is visible to all three families. This falls out of
 * the any-link rule rather than being special-cased, and it is deliberate:
 * team sport is photographed in groups, and the alternative — showing a
 * family only frames in which their child appears alone — would hide
 * nearly every training photograph from everyone. The docs state the
 * policy so an academy's consent wording can match it; `MediaVisibilityTest`
 * pins it so nobody later "fixes" it into a leak of a different kind.
 */
final class MediaVisibilityService {

    /** Matrix entity name. Owned solely by the media feature. */
    public const ENTITY = 'media';

    /** @var array<int, array<string, mixed>> per-request scope cache, keyed by user id */
    private static $scopeCache = [];

    public function canView( int $user_id, object $media ): bool {
        return $this->can( $user_id, $media, MatrixGate::READ );
    }

    public function canEdit( int $user_id, object $media ): bool {
        return $this->can( $user_id, $media, MatrixGate::CHANGE );
    }

    /**
     * May this user delete the item — or, on a candidate row, upload one
     * attached to these records? `create_delete` is a single verb in the
     * matrix vocabulary, so both questions have the same answer.
     */
    public function canDelete( int $user_id, object $media ): bool {
        return $this->can( $user_id, $media, MatrixGate::CREATE_DELETE );
    }

    /**
     * May this user attach new media to this record? Asked before an
     * upload exists, so it takes a target rather than a media row.
     */
    public function canAttachTo( int $user_id, string $entity_type, int $entity_id ): bool {
        if ( $user_id <= 0 || ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return false;
        if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL ) ) {
            return true;
        }
        return $this->linkGrants( $user_id, $entity_type, $entity_id, MatrixGate::CREATE_DELETE );
    }

    /**
     * Filter a list to what this user may see.
     *
     * Exists so a gallery of 100 items does not run 100 independent
     * permission checks, each re-resolving the same team assignments. The
     * user's global grant and scope sets are resolved once and the list is
     * filtered in memory.
     *
     * @param  object[] $items
     * @return object[]
     */
    public function filterVisible( int $user_id, array $items ): array {
        if ( $user_id <= 0 || $items === [] ) return [];

        if ( MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) {
            return array_values( $items );
        }

        $links = $this->linksForMany( array_map(
            static function ( $item ) { return (int) ( $item->id ?? 0 ); },
            $items
        ) );

        $out = [];
        foreach ( $items as $item ) {
            $id = (int) ( $item->id ?? 0 );
            foreach ( $links[ $id ] ?? [] as $link ) {
                if ( $this->linkGrants( $user_id, (string) $link->entity_type, (int) $link->entity_id, MatrixGate::READ ) ) {
                    $out[] = $item;
                    break;
                }
            }
        }

        return $out;
    }

    /** Drop the per-request scope cache. Test seam. */
    public static function flush(): void {
        self::$scopeCache = [];
    }

    // Internals

    private function can( int $user_id, object $media, string $activity ): bool {
        if ( $user_id <= 0 ) return false;

        $media_id = (int) ( $media->id ?? 0 );
        if ( $media_id <= 0 ) return false;

        // A global grant answers without touching the links at all.
        if ( MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_GLOBAL ) ) {
            return true;
        }

        foreach ( ( new MediaLinksRepository() )->listForMedia( $media_id ) as $link ) {
            if ( $this->linkGrants( $user_id, (string) $link->entity_type, (int) $link->entity_id, $activity ) ) {
                return true;
            }
        }

        // No links means nothing owns this item, so nobody below global
        // scope has a route to it. Repositories delete link-less media, so
        // this should not arise; refusing is the safe answer if it does.
        return false;
    }

    /**
     * Does one attachment grant this activity?
     *
     * A player link is satisfied either by player scope (the player, or
     * their parent) or by team scope over the player's team (their staff).
     */
    private function linkGrants( int $user_id, string $entity_type, int $entity_id, string $activity ): bool {
        if ( ! MediaEntityType::isValid( $entity_type ) || $entity_id <= 0 ) return false;

        switch ( $entity_type ) {
            case MediaEntityType::TEAM:
                return MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $entity_id );

            case MediaEntityType::ACTIVITY:
                $team_id = $this->teamForActivity( $entity_id );
                return $team_id > 0
                    && MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $team_id );

            case MediaEntityType::PLAYER:
            default:
                if ( MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_PLAYER, $entity_id ) ) {
                    return true;
                }
                $team_id = $this->teamForPlayer( $entity_id );
                return $team_id > 0
                    && MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $team_id );
        }
    }

    private function teamForPlayer( int $player_id ): int {
        return $this->lookupTeam( 'tt_players', $player_id );
    }

    private function teamForActivity( int $activity_id ): int {
        return $this->lookupTeam( 'tt_activities', $activity_id );
    }

    /** Per-request memo — a gallery asks about the same handful of records repeatedly. */
    private function lookupTeam( string $table, int $id ): int {
        global $wpdb;

        $key = $table . ':' . $id;
        if ( isset( self::$scopeCache[ $key ] ) ) return (int) self::$scopeCache[ $key ];

        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}{$table} WHERE id = %d AND club_id = %d",
            $id,
            CurrentClub::id()
        ) );

        self::$scopeCache[ $key ] = $team_id;
        return $team_id;
    }

    /**
     * Links for a batch of media ids, in one query.
     *
     * @param  int[] $media_ids
     * @return array<int, object[]> media_id => links
     */
    private function linksForMany( array $media_ids ): array {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );
        if ( $ids === [] ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT media_id, entity_type, entity_id
               FROM {$wpdb->prefix}tt_media_links
              WHERE media_id IN ({$placeholders}) AND club_id = %d",
            ...array_merge( $ids, [ CurrentClub::id() ] )
        ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row->media_id ][] = $row;
        }
        return $out;
    }
}
