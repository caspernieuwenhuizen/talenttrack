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
 *   - a **course submission** link (#2648) is not a matrix scope at all.
 *     A submission is a coach's own coursework, with no player and no team
 *     behind it, so the participants are named directly: the author, the
 *     reviewer it is routed to, and holders of `tt_manage_knowledge`.
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

    /** @var array<string, int> per-request record → team_id memo */
    private static $teamCache = [];

    /** @var array<int, int> per-request wp_user_id → person_id memo */
    private static $personCache = [];

    /**
     * Per-request memo of decided grants, keyed by
     * user:entity_type:entity_id:activity.
     *
     * `MatrixGate::can()` runs a scope query on every call, so a gallery
     * of thirty photographs attached to the same team would otherwise ask
     * the database the same question thirty times. The decision cannot
     * change within a request, so caching it is safe; `flush()` clears it
     * for tests and long-running processes.
     *
     * @var array<string, bool>
     */
    private static $grantCache = [];

    /**
     * Coarse authority: does this user have a media grant *anywhere*?
     *
     * This is what a REST `permission_callback` asks, and it is the only
     * question answerable before the request has been routed to a record.
     * It resolves through `MatrixGate` directly rather than through
     * `current_user_can()`, for the same reason `TeamChemistryAccess` does
     * (#1922): the `user_has_cap` bridge is dormant unless an admin has
     * switched the matrix on, and a family's access lives only in a matrix
     * row — parent and player roles hold no raw `tt_*` media capability.
     * Going through the cap would silently deny every family on a
     * dormant-matrix install.
     *
     * It is a gate, never the decision. Every callback that reaches a
     * record still asks `canView()` / `canEdit()` / `canDelete()`.
     */
    public static function hasReadAuthority( int $user_id ): bool {
        return MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::READ );
    }

    public static function hasEditAuthority( int $user_id ): bool {
        return MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::CHANGE );
    }

    /**
     * The upload gate has a second arm, for targets that grant by
     * ownership instead of by scope.
     *
     * Every original target — player, team, activity — is reached through
     * a matrix scope, so any-scope was the whole question. A course
     * submission is not: it is the author's own coursework, and the author
     * may be a staff member with no team assignment and therefore no media
     * scope anywhere. Asking only the matrix would refuse them the
     * attachment on their own assignment while `canAttachTo()` was ready
     * to allow it — a denial from the gate that the decision disagrees
     * with.
     *
     * Widening it costs nothing against the matrix: this is explicitly not
     * the decision, and `canAttachTo()` still establishes that the
     * submission is theirs.
     *
     * It does have to respect the switches, though. `canAnyScope()`
     * short-circuits on the owning module and the owning feature, so a
     * second arm that skipped them would make "module off" stop meaning
     * off — which is what `MediaToggleTest` exists to prevent. Hence the
     * explicit guard rather than the bare capability.
     */
    public static function hasUploadAuthority( int $user_id ): bool {
        return MatrixGate::canAnyScope( $user_id, self::ENTITY, MatrixGate::CREATE_DELETE )
            || ( self::mediaSwitchedOn() && user_can( $user_id, 'tt_view_knowledge' ) );
    }

    /**
     * Is the media feature available at all on this install?
     *
     * The two switches the matrix path consults for itself: the owning
     * module, and the sub-feature that owns the `media` entity. Both are
     * `class_exists`-guarded for the same reason `MatrixGate` guards them
     * — the registries may not have loaded on an install mid-upgrade.
     */
    private static function mediaSwitchedOn(): bool {
        // `MediaModule::class` rather than a string literal: the registry
        // keys on the unprefixed FQCN, and a hand-written one with a
        // leading backslash would silently never match.
        if ( class_exists( '\\TT\\Core\\ModuleRegistry' )
            && ! \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\Media\MediaModule::class ) ) {
            return false;
        }

        if ( class_exists( '\\TT\\Core\\FeatureRegistry' )
            && \TT\Core\FeatureRegistry::entityDisabled( self::ENTITY ) ) {
            return false;
        }

        return true;
    }

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

    /** Drop the per-request memos. Test seam. */
    public static function flush(): void {
        self::$teamCache   = [];
        self::$grantCache  = [];
        self::$personCache = [];
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

        $key = $user_id . ':' . $entity_type . ':' . $entity_id . ':' . $activity;
        if ( isset( self::$grantCache[ $key ] ) ) return self::$grantCache[ $key ];

        return self::$grantCache[ $key ] = $this->resolveLinkGrant( $user_id, $entity_type, $entity_id, $activity );
    }

    private function resolveLinkGrant( int $user_id, string $entity_type, int $entity_id, string $activity ): bool {
        switch ( $entity_type ) {
            case MediaEntityType::TEAM:
                return MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $entity_id );

            case MediaEntityType::ACTIVITY:
                $team_id = $this->teamForActivity( $entity_id );
                return $team_id > 0
                    && MatrixGate::can( $user_id, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $team_id );

            case MediaEntityType::COURSE_SUBMISSION:
                return $this->submissionGrants( $user_id, $entity_id );

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

    /**
     * A course submission's attachments: the coach who handed it in, the
     * reviewer it is routed to, and whoever may review generally.
     *
     * The only branch here that is not a matrix scope, because a submission
     * has no team and no player behind it — it is somebody's own coursework.
     * Running it through `SCOPE_PLAYER` (which is where the `default:` arm
     * would have sent it) would compare a submission id against player ids
     * and answer a question nobody asked.
     *
     * The assigned reviewer is checked as well as the capability because
     * mentorship and `tt_manage_knowledge` do not overlap: a coach
     * mentoring a colleague is routed their submission without holding the
     * management capability, and refusing them the attachment would leave
     * them reviewing a plan they cannot open.
     *
     * Read and write are not distinguished. Both mean "take part in this
     * review", the participants are the same two people either way, and a
     * split would only invite a later widening of one of them.
     */
    private function submissionGrants( int $user_id, int $submission_id ): bool {
        if ( user_can( $user_id, 'tt_manage_knowledge' ) ) return true;

        $person_id = $this->personForUser( $user_id );
        if ( $person_id <= 0 ) return false;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.reviewer_person_id, e.person_id AS author_person_id
               FROM {$p}tt_course_submissions s
         INNER JOIN {$p}tt_course_enrolments e
                 ON e.id = s.enrolment_id AND e.club_id = s.club_id
              WHERE s.id = %d AND s.club_id = %d",
            $submission_id,
            CurrentClub::id()
        ) );

        if ( ! $row ) return false;

        return (int) $row->author_person_id === $person_id
            || (int) $row->reviewer_person_id === $person_id;
    }

    /** Per-request memo; the same user is asked about repeatedly. */
    private function personForUser( int $user_id ): int {
        if ( isset( self::$personCache[ $user_id ] ) ) return self::$personCache[ $user_id ];

        global $wpdb;

        $person_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id = %d AND club_id = %d AND archived_at IS NULL
              LIMIT 1",
            $user_id,
            CurrentClub::id()
        ) );

        return self::$personCache[ $user_id ] = $person_id;
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
        if ( isset( self::$teamCache[ $key ] ) ) return self::$teamCache[ $key ];

        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}{$table} WHERE id = %d AND club_id = %d",
            $id,
            CurrentClub::id()
        ) );

        return self::$teamCache[ $key ] = $team_id;
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
