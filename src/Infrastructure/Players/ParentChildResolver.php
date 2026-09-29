<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ParentChildResolver (#1993) — the single canonical answer to
 * "which children does this parent have, and which one are they
 * looking at?".
 *
 * `tt_player_parents` (via PlayerParentsRepository) is the ONE live
 * source of the parent → child linkage. `tt_players.guardian_email`
 * is no longer queried here — it is demoted to an invite/seed hint
 * that *creates* a pivot row (see PlayerParentsRepository::link), never
 * a runtime linkage source. This keeps the dashboard child switcher,
 * the me-view authorization, and the KPI resolver in agreement: they
 * all read the same pivot, club-scoped (SaaS-ready per CLAUDE.md §4 —
 * the join is on `player_id`, not an email string).
 *
 * Business logic (which children, in what order, which one is the
 * default subject) lives here — not in any view or widget (§4). The
 * REST controllers and the PHP views both call into this layer so a
 * future SaaS front end gets the same answers.
 */
final class ParentChildResolver {

    /**
     * #4089 — what a guardian may do with one linked child, decided by the
     * child's status. One rule, owned here; every parent surface asks it.
     *
     *   - `active`    → full: the whole family view, reads and writes.
     *   - `trial`     → trial: the trial's schedule and attendance, nothing
     *                   else — no evaluations, goals, PDP, potential or any
     *                   other staff judgement until the child is signed.
     *   - `graduated` → read_only: what the family could read before
     *                   graduation, and no writes. No messages either:
     *                   graduation closes the child out for notifications.
     *   - `released`, `inactive`, archived, binned → none, and no messages
     *                   (#4129 brought `inactive` in line, so access and
     *                   messaging agree for every status but trial).
     */
    public const ACCESS_FULL      = 'full';
    public const ACCESS_TRIAL     = 'trial';
    public const ACCESS_READ_ONLY = 'read_only';
    public const ACCESS_NONE      = 'none';

    /**
     * What a caller needs from the guardian link.
     *
     *   - `record`   — read the child's development record (evaluations,
     *                  goals, PDP, journey …). Full and read-only access.
     *                  The default everywhere, so a caller that does not say
     *                  what it needs never reaches a trialist.
     *   - `schedule` — read the child's schedule and attendance. Every
     *                  access level but none, the trial one included.
     *   - `full`     — write on the child's behalf (a thread reply, an
     *                  acknowledgement), or be sent something about their
     *                  development. Full access only.
     */
    public const NEED_RECORD   = 'record';
    public const NEED_SCHEDULE = 'schedule';
    public const NEED_FULL     = 'full';

    /**
     * The matrix entities a trialist's guardian reads: the schedule and the
     * attendance on it. Read only. Anything not listed is refused, so an
     * entity added later reaches a trial family only by being named here.
     */
    public const TRIAL_ENTITIES = [ 'activities', 'my_activities', 'attendance' ];

    /**
     * The Me-view slugs that carry schedule + attendance, the only child
     * surfaces a trialist's guardian opens.
     */
    public const TRIAL_VIEW_SLUGS = [ 'my-activities' ];

    /** The statuses a guardian keeps any access on. */
    private const LINKED_STATUSES = [ PlayerStatus::ACTIVE, PlayerStatus::TRIAL, PlayerStatus::GRADUATED ];

    /**
     * #4089 — the access level a child's status gives their guardian. Pure,
     * so the rule is testable without a database.
     */
    public static function accessForStatus( string $status ): string {
        switch ( $status ) {
            case PlayerStatus::ACTIVE:    return self::ACCESS_FULL;
            case PlayerStatus::TRIAL:     return self::ACCESS_TRIAL;
            case PlayerStatus::GRADUATED: return self::ACCESS_READ_ONLY;
            default:                      return self::ACCESS_NONE;
        }
    }

    /** Does this access level meet this need? Pure. */
    public static function accessMeets( string $access, string $need ): bool {
        switch ( $need ) {
            case self::NEED_FULL:
                return $access === self::ACCESS_FULL;
            case self::NEED_SCHEDULE:
                return in_array( $access, [ self::ACCESS_FULL, self::ACCESS_READ_ONLY, self::ACCESS_TRIAL ], true );
            case self::NEED_RECORD:
            default:
                return in_array( $access, [ self::ACCESS_FULL, self::ACCESS_READ_ONLY ], true );
        }
    }

    /**
     * May a guardian with this access level take this matrix activity on
     * this entity? Pure. The matrix row still has to grant it; this only
     * narrows. `read` is the only activity a read-only or trial guardian
     * holds.
     */
    public static function accessAllowsEntity( string $access, string $entity, string $activity ): bool {
        switch ( $access ) {
            case self::ACCESS_FULL:
                return true;
            case self::ACCESS_READ_ONLY:
                return $activity === 'read';
            case self::ACCESS_TRIAL:
                return $activity === 'read' && in_array( $entity, self::TRIAL_ENTITIES, true );
            default:
                return false;
        }
    }

    /**
     * May a guardian with this access level see this player-controlled
     * section (`PlayerParentVisibilityRepository::SECTIONS`, plus the
     * `activities` key the match-analysis tab uses)? Pure. Every one of them
     * is development data, so a trialist's guardian sees none.
     */
    public static function accessAllowsSection( string $access, string $section ): bool {
        return $section !== '' && self::accessMeets( $access, self::NEED_RECORD );
    }

    /** May a guardian with this access level open this child-scoped view? Pure. */
    public static function accessAllowsViewSlug( string $access, string $view_slug ): bool {
        if ( $access === self::ACCESS_TRIAL ) {
            return in_array( $view_slug, self::TRIAL_VIEW_SLUGS, true );
        }
        return self::accessMeets( $access, self::NEED_RECORD );
    }

    /** The access level a `children()` row carries. */
    public static function accessOf( object $child ): string {
        $row = (array) $child;
        return self::accessForStatus( (string) ( $row['status'] ?? '' ) );
    }

    /**
     * #4089 — this guardian's access to this child. `none` when they are not
     * linked in this club, or the child is archived, binned, released or
     * inactive.
     */
    public static function accessFor( int $parent_user_id, int $player_id ): string {
        if ( $parent_user_id <= 0 || $player_id <= 0 ) return self::ACCESS_NONE;

        foreach ( self::children( $parent_user_id ) as $child ) {
            if ( (int) ( ( (array) $child )['id'] ?? 0 ) === $player_id ) {
                return self::accessOf( $child );
            }
        }
        return self::ACCESS_NONE;
    }

    /**
     * #4089 — may this guardian take this matrix activity on this entity for
     * this child? The guardian half of the matrix `player` scope.
     */
    public static function guardianAllows( int $parent_user_id, int $player_id, string $entity, string $activity ): bool {
        return self::accessAllowsEntity( self::accessFor( $parent_user_id, $player_id ), $entity, $activity );
    }

    /**
     * #4089 — does this guardian have at least one child on whom they may
     * take this matrix activity on this entity? The "any scope" form.
     */
    public static function guardianAllowsAny( int $parent_user_id, string $entity, string $activity ): bool {
        foreach ( self::children( $parent_user_id ) as $child ) {
            if ( self::accessAllowsEntity( self::accessOf( $child ), $entity, $activity ) ) return true;
        }
        return false;
    }

    /**
     * The player records linked to this parent that they keep any access
     * on, most-recently-linked first. The order is the multi-child default
     * rule (#1991 / #1992): the first entry is the child auto-selected when
     * no explicit `?player_id` is supplied.
     *
     * #4089 — since then a child on trial or graduated is listed too, next
     * to an active one; each row's access level is `accessOf( $row )`. This
     * is the list a parent is *shown* (the switcher, the dashboard anchor).
     * What they may *read* is `childIds()` / `isParentOf()` with a need,
     * which default to the development record and so leave a trialist out.
     *
     * Filters to the current club and — since #3937 — to the `active`
     * lifecycle: a child who has been archived or moved to the recycle bin
     * is not a child any guardian surface offers.
     * Reads the pivot only — a parent linked solely via the legacy
     * guardian_email column will not surface until re-linked (the accepted
     * #1993 trade-off: no backfill).
     *
     * ## Why the lifecycle filter is here and not at each caller
     *
     * `status` and the lifecycle answer different questions and a player
     * carries both. `PlayerStatus` says so in its own docblock: an archived
     * player still carries one of the five statuses, so `status = 'active'`
     * on its own let a binned child through every guardian surface — the
     * switcher, the default subject, the matrix's `player` scope — while
     * `ArchiveRepository::filterClause()`'s contract says a trashed row
     * surfaces only through the explicit `trashed` view.
     *
     * Archived is excluded along with trashed, matching {@see ScoutPlayerLinks}
     * exactly (#3928). The two classes answer the same shape of question and
     * sit a few lines apart; one lifecycle rule between them is one thing to
     * remember. If a family should still see a child who has left, that is
     * the player's *status* doing the work — `released`, `graduated` and the
     * surfaces built for them — not the archive flag, which exists to take a
     * record out of the working set.
     *
     * @return list<object> tt_players rows ordered most-recent link first.
     */
    public static function children( int $parent_user_id ): array {
        if ( $parent_user_id <= 0 ) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_parents';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        // Most-recent link first (created_at DESC) so the default subject
        // is the latest child the parent was attached to. The pivot is the
        // authoritative linkage; the JOIN onto tt_players resolves the
        // child's record (name/photo) for the switcher and scoping.
        $players = $wpdb->prefix . 'tt_players';
        // Aliased on purpose: both joined tables are archivable, so an
        // unqualified `trashed_at` is an ambiguous column and the query dies.
        $lifecycle = ArchiveRepository::filterClause( 'active', 'p' );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$table} pp
               INNER JOIN {$players} p ON p.id = pp.player_id
              WHERE pp.parent_user_id = %d
                AND pp.club_id = %d
                AND p.status IN ( %s, %s, %s )
                AND {$lifecycle}
              ORDER BY pp.created_at DESC, pp.player_id DESC",
            $parent_user_id, CurrentClub::id(),
            self::LINKED_STATUSES[0], self::LINKED_STATUSES[1], self::LINKED_STATUSES[2]
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The default child subject for a parent who supplied no explicit
     * `?player_id` — the most-recently linked child, or null when
     * the parent has no linked child. Single-child parents resolve to
     * that child; multi-child parents resolve to their most-recent and
     * the caller offers a switcher to change it. The caller still asks
     * what the parent may open on that child.
     */
    public static function defaultChild( int $parent_user_id ): ?object {
        $children = self::children( $parent_user_id );
        return $children[0] ?? null;
    }

    /** Number of children linked to this parent that they keep any access on. */
    public static function childCount( int $parent_user_id ): int {
        return count( self::children( $parent_user_id ) );
    }

    /**
     * True when this user reaches the player surfaces **purely** as a
     * guardian, and the dashboard should therefore render the child-scoped
     * parent rail instead of the own-player rail.
     *
     * Two things disqualify them.
     *
     * Having their own player record — a player who is also linked as a
     * guardian is still a player on their own dashboard.
     *
     * And, since #3479, holding any staff persona. This used to ask only
     * about the player record, so a coach whose own child plays in the
     * academy — routine in youth football — matched, and `FrontendTileGrid`
     * returned early with the parent rail and none of their coaching tiles.
     * Under the default `classic` shell there is no sidebar to fall back on,
     * so they had no route to any coaching surface at all.
     *
     * The rule is that a staff seat wins: they get their staff dashboard and
     * reach their own child through the Players list like any other player.
     * Nothing here touches their parent-side *data* access — the seed grants
     * and `canViewPlayer()` are a separate path, and a coach-parent keeps
     * whatever the guardian link gives them.
     */
    public static function isParentViewer( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        $own = QueryHelpers::get_player_for_user( $user_id );
        if ( $own && (int) $own->id > 0 ) return false;
        if ( self::holdsStaffPersona( $user_id ) ) return false;
        return self::childCount( $user_id ) > 0;
    }

    /**
     * #3479 — does this user hold a persona whose own dashboard they would
     * lose to the parent rail?
     *
     * Expressed as "anything but the two subject personas" rather than as a
     * list of staff keys, so a persona added later is staff by default —
     * the failure mode to avoid is a new seat silently inheriting the parent
     * rail, which is the bug this method exists to fix.
     */
    private static function holdsStaffPersona( int $user_id ): bool {
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) return false;

        foreach ( \TT\Modules\Authorization\PersonaResolver::personasFor( $user_id ) as $persona ) {
            if ( $persona !== 'parent' && $persona !== 'player' ) return true;
        }
        return false;
    }

    /**
     * The linked player IDs, for callers that don't need the full records.
     *
     * #3476 — derived from `children()` rather than straight from the pivot,
     * so it carries the same club scope, the same `status = 'active'` filter
     * and the same lifecycle filter as everything else here. It used to call
     * `PlayerParentsRepository::playersForParent()`, which is club-scoped but
     * says nothing about status, and that difference was one of the ways the
     * product disagreed with itself about a released child. #3937 added the
     * lifecycle filter for the same reason: the scope question and the
     * display question must not be able to disagree about a binned child.
     *
     * #4089 — filtered by what the caller needs. The default is the
     * development record, which an active or graduated child's guardian
     * reads and a trialist's does not; every caller written before the
     * trial rule therefore still leaves a trialist out. Ask for
     * `NEED_SCHEDULE` to include one, `NEED_FULL` for writes and messages.
     *
     * @return list<int>
     */
    public static function childIds( int $parent_user_id, string $need = self::NEED_RECORD ): array {
        if ( $parent_user_id <= 0 ) return [];

        $ids = [];
        foreach ( self::children( $parent_user_id ) as $child ) {
            $id = (int) ( $child->id ?? 0 );
            if ( $id > 0 && self::accessMeets( self::accessOf( $child ), $need ) ) $ids[] = $id;
        }
        return $ids;
    }

    /**
     * #3476 — **the** answer to "is this user a guardian of this player?".
     *
     * Four places used to ask it with their own inline
     * `SELECT 1 FROM tt_player_parents WHERE player_id = %d AND
     * parent_user_id = %d` — `MatrixGate` twice (the `player` scope, on both
     * the any-scope and the specific-target path), `PdpPrintRouter`, and
     * `PdpConversationsRestController`. None was club-scoped, which on the
     * authorization path is the worst place for a query that does not know
     * what tenant it is in (CLAUDE.md §4).
     *
     * ## Release ends guardian access
     *
     * The copies also disagreed with this class about a released child: they
     * had no status filter, so a guardian whose child had been released got
     * no parent dashboard and no child switcher — `children()` filters on
     * `active` — and could still open that child's record by typing the URL.
     *
     * Settled deliberately (2026-09-16) rather than left to whichever query
     * a surface happened to call: **a release ends the guardian's access.**
     * The club has finished with the player, and the family's link to the
     * academy's record of them ends with it. Everything here now agrees:
     * dashboard, switcher, Me-view subject resolution, matrix scope, PDP
     * print and PDP conversations.
     *
     * If a family needs the history after a release, that is a subject-access
     * export — a deliberate act with a record of who asked — not a login that
     * quietly keeps working.
     *
     * ## Archiving and the recycle bin end it too
     *
     * Settled on #3937, on the same footing as the scout link (#3928): an
     * archived or trashed child answers false here as well. A record in the
     * bin is one the academy has decided to destroy; continuing to serve it
     * to the family is outside the contract the bin rests on.
     *
     * ## Trial and graduation (#4089)
     *
     * The answer depends on what the caller needs. By default it is "may
     * this guardian read the child's development record": yes for an active
     * or graduated child, no for a trialist. `NEED_SCHEDULE` admits a
     * trialist for their schedule and attendance; `NEED_FULL` is the write
     * and messaging question, and a graduated child answers no.
     */
    public static function isParentOf( int $parent_user_id, int $player_id, string $need = self::NEED_RECORD ): bool {
        if ( $parent_user_id <= 0 || $player_id <= 0 ) return false;
        return in_array( $player_id, self::childIds( $parent_user_id, $need ), true );
    }

    /**
     * #4089 — true when this user is the child's guardian with read-only
     * access (a graduated child), so a refused write can say why rather
     * than answer a bare 403.
     */
    public static function isReadOnlyFor( int $parent_user_id, int $player_id ): bool {
        return self::accessFor( $parent_user_id, $player_id ) === self::ACCESS_READ_ONLY;
    }

    /**
     * #4089 — true when this user is the child's guardian while the child is
     * on trial, so a refused surface can say what is still open to them.
     */
    public static function isTrialFor( int $parent_user_id, int $player_id ): bool {
        return self::accessFor( $parent_user_id, $player_id ) === self::ACCESS_TRIAL;
    }

    /**
     * #3979 — who is *sent* something about this player: the guardians a
     * notification path may reach, asked from the child's side.
     *
     * Nobody, once the child is closed out — released, graduated, inactive,
     * archived or in the recycle bin. Released, archived and binned are what
     * #3937 and #3947 end guardian access on; graduated joined them in
     * #4089, which keeps a graduated child's family reading and stops
     * sending to them; inactive joined in #4129, so the one status whose
     * family had no access but was still messaged now gets neither. Staff
     * contact an inactive family directly.
     * There is no exception list: close-out letters (a release letter, a
     * data export, a subject-access response) are sent by staff outside the
     * notification paths.
     *
     * ## Why this is not `status = 'active'` like `children()`
     *
     * `children()` is what a guardian may *open*, and it admits only
     * `active` players. A notification path also sends about a trialist —
     * the trial welcome goes to the family of a child whose status is
     * `trial` by construction — so the send-side rule names the closed-out
     * states instead of the one open one. A child who is on trial still
     * has guardians here.
     *
     * ## Development messages (#4089)
     *
     * A trialist's family is sent the trial's messages — the welcome, a
     * cancelled training — and nothing about the child's development, which
     * they may not read. A send whose content is development data (a goal
     * nudge, a plan being ready) passes `$development = true`, and then only
     * an active child has guardians here.
     *
     * Primary guardian first, then by when they were linked.
     *
     * @return list<int> parent WP user ids
     */
    public static function guardiansOf( int $player_id, bool $development = false ): array {
        if ( self::isClosedOut( $player_id ) ) return [];
        if ( $development && ! self::isDevelopmentOpen( $player_id ) ) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_parents';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT parent_user_id FROM {$table}
              WHERE player_id = %d AND club_id = %d
              ORDER BY is_primary DESC, created_at ASC",
            $player_id, CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! in_array( $id, $out, true ) ) $out[] = $id;
        }
        return $out;
    }

    /**
     * #3979 — has the academy finished with this child, as far as their
     * family is concerned?
     *
     * True when the player is released, graduated, archived or in the
     * recycle bin, and when there is no such player in this club. A
     * notification path asks this before any fallback it keeps, so a legacy
     * contact column cannot reach a family the pivot is no longer allowed to.
     *
     * #4089 — graduated counts as closed out for sending: the family keeps
     * reading what they could read before, and nobody — the player's own
     * account included — is sent anything more about the child.
     *
     * #4129 — inactive counts too. The family already had no access to an
     * inactive child; now nobody, the player's own account included, is
     * sent anything about them either.
     */
    public static function isClosedOut( int $player_id ): bool {
        $row = self::statusRow( $player_id );
        if ( $row === null ) return true;

        return self::isClosedOutStatus( (string) ( $row['status'] ?? '' ) )
            || ! empty( $row['archived_at'] )
            || ! empty( $row['trashed_at'] );
    }

    /** The statuses that close a child out for notifications. Pure. */
    public static function isClosedOutStatus( string $status ): bool {
        return in_array(
            $status,
            [ PlayerStatus::RELEASED, PlayerStatus::GRADUATED, PlayerStatus::INACTIVE ],
            true
        );
    }

    /**
     * #4089 — may the family be sent something about this child's
     * development? Only while the child is active.
     */
    public static function isDevelopmentOpen( int $player_id ): bool {
        $row = self::statusRow( $player_id );
        return $row !== null
            && self::accessMeets( self::accessForStatus( (string) ( $row['status'] ?? '' ) ), self::NEED_FULL );
    }

    /** @return array<string,mixed>|null */
    private static function statusRow( int $player_id ): ?array {
        if ( $player_id <= 0 ) return null;

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, archived_at, trashed_at FROM {$wpdb->prefix}tt_players
              WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }
}
