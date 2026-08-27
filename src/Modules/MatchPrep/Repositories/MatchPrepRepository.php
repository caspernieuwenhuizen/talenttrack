<?php
namespace TT\Modules\MatchPrep\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;

/**
 * MatchPrepRepository — CRUD across the four match-prep tables
 * introduced by migration 0118. One concrete class for the whole
 * module's data; the surface is small enough that separate per-table
 * repositories would be overkill.
 */
class MatchPrepRepository {

    private \wpdb $wpdb;
    private string $t_prep;
    private string $t_avail;
    private string $t_lineup;
    private string $t_pgoals;
    private string $t_roles;

    /**
     * Canonical set of role keys persisted on the roles table. The
     * REST controller and the view both reject keys outside this set.
     * Operators may extend the list in code without a schema change.
     *
     * @var list<string>
     */
    public const ROLE_KEYS = [ 'captain', 'corner_l', 'corner_r', 'fk_l', 'fk_r', 'penalty' ];

    public function __construct() {
        global $wpdb;
        $this->wpdb     = $wpdb;
        $this->t_prep   = $wpdb->prefix . 'tt_match_prep';
        $this->t_avail  = $wpdb->prefix . 'tt_match_prep_availability';
        $this->t_lineup = $wpdb->prefix . 'tt_match_prep_lineup';
        $this->t_pgoals = $wpdb->prefix . 'tt_match_prep_player_goals';
        $this->t_roles  = $wpdb->prefix . 'tt_match_prep_roles';
    }

    public function findByActivity( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_prep} WHERE activity_id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** #2892 — one prep by its own id, club-scoped like every other read. */
    public function find( int $prep_id ): ?object {
        if ( $prep_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_prep} WHERE id = %d AND club_id = %d",
            $prep_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * #2892 — lookup by uuid, for the share route.
     *
     * Deliberately NOT club-scoped: the share link is resolved before
     * login, so there is no current club to scope to — `CurrentClub::id()`
     * on a logged-out request would answer for the wrong tenant or for
     * none. The uuid is unique across the table (`uniq_uuid`, migration
     * 0118), and the HMAC is what actually authorises the read; the caller
     * verifies it before doing anything with this row.
     */
    public function findByUuid( string $uuid ): ?object {
        $uuid = trim( $uuid );
        if ( $uuid === '' ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_prep} WHERE uuid = %s",
            $uuid
        ) );
        return $row ?: null;
    }

    /**
     * #2892 — the prep's share-token seed, initialising it on first read.
     *
     * Lazily rather than at insert: a prep nobody has shared should not be
     * carrying a secret. Seeded from the uuid, which is cryptographically
     * random by construction, exactly as the analysis and blueprint
     * repositories do.
     */
    public function ensureShareTokenSeed( int $prep_id ): string {
        $row = $this->find( $prep_id );
        if ( ! $row ) return '';

        $seed = (string) ( $row->share_token_seed ?? '' );
        if ( $seed !== '' ) return $seed;

        $seed = (string) ( $row->uuid ?? '' );
        if ( $seed === '' ) return '';

        $this->wpdb->update(
            $this->t_prep,
            [ 'share_token_seed' => $seed ],
            [ 'id' => $prep_id, 'club_id' => CurrentClub::id() ]
        );
        return $seed;
    }

    /**
     * #2892 — replace the seed, invalidating every previously issued share
     * URL for this prep. Returns the new seed.
     *
     * This is the revocation the seed exists for: a shared prep names
     * minors and says which of them is expected to start, so a coach who
     * sent a link to the wrong group needs a way to make it stop working.
     */
    public function rotateShareTokenSeed( int $prep_id ): string {
        if ( $prep_id <= 0 ) return '';

        $seed = wp_generate_password( 16, false );
        $this->wpdb->update(
            $this->t_prep,
            [ 'share_token_seed' => $seed ],
            [ 'id' => $prep_id, 'club_id' => CurrentClub::id() ]
        );
        return $seed;
    }

    /**
     * Find-or-create the prep row for an activity. Returns the row's id.
     *
     * #1727 — when the caller doesn't pass an explicit half length
     * (`$half_length <= 0`), the default is resolved from the
     * per-age-category setting (`MatchLengthResolver`), falling back to
     * 35 minutes per half. Pass a positive value to override.
     */
    public function ensureForActivity( int $activity_id, int $half_length = 0 ): int {
        $existing = $this->findByActivity( $activity_id );
        if ( $existing ) return (int) $existing->id;

        if ( $half_length <= 0 ) {
            $half_length = ( new MatchLengthResolver() )->halfMinutesForActivity( $activity_id );
        }

        $this->wpdb->insert( $this->t_prep, [
            'uuid'                => wp_generate_uuid4(),
            'club_id'             => CurrentClub::id(),
            'activity_id'         => $activity_id,
            'half_length_minutes' => $half_length,
            'created_by'          => get_current_user_id(),
        ] );
        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function updatePrep( int $prep_id, array $patch ): bool {
        if ( $prep_id <= 0 || empty( $patch ) ) return false;

        $allowed = [
            'formation_template_id', 'half_length_minutes',
            'goals_general', 'goals_attack', 'goals_defend',
            'goals_attack_setpiece', 'goals_defend_setpiece',
        ];
        $clean = [];
        foreach ( $patch as $k => $v ) {
            if ( in_array( $k, $allowed, true ) ) $clean[ $k ] = $v;
        }
        if ( empty( $clean ) ) return false;

        return false !== $this->wpdb->update(
            $this->t_prep,
            $clean,
            [ 'id' => $prep_id, 'club_id' => CurrentClub::id() ]
        );
    }

    // -----------------------------------------------------------------
    // Availability
    // -----------------------------------------------------------------

    /** @return object[] */
    public function listAvailability( int $prep_id ): array {
        if ( $prep_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_avail}
              WHERE match_prep_id = %d AND club_id = %d
              ORDER BY id ASC",
            $prep_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Replace the availability set for a prep. `$rows` is keyed by
     * player_id => [ 'status' => '...', 'reason' => '...' ].
     *
     * @param array<int, array{status:string, reason?:string}> $rows
     */
    public function replaceAvailability( int $prep_id, array $rows ): void {
        if ( $prep_id <= 0 ) return;
        $this->wpdb->delete( $this->t_avail, [
            'match_prep_id' => $prep_id,
            'club_id'       => CurrentClub::id(),
        ] );
        foreach ( $rows as $player_id => $entry ) {
            $pid = (int) $player_id;
            if ( $pid <= 0 ) continue;
            $this->wpdb->insert( $this->t_avail, [
                'club_id'       => CurrentClub::id(),
                'match_prep_id' => $prep_id,
                'player_id'     => $pid,
                'status'        => (string) ( $entry['status'] ?? 'Present' ),
                'reason'        => isset( $entry['reason'] ) ? (string) $entry['reason'] : null,
            ] );
        }
    }

    // -----------------------------------------------------------------
    // Lineup
    // -----------------------------------------------------------------

    /** @return object[] */
    public function listLineup( int $prep_id ): array {
        if ( $prep_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_lineup}
              WHERE match_prep_id = %d AND club_id = %d
              ORDER BY half ASC, slot_number ASC",
            $prep_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Replace the lineup for one half. `$slots` keyed by slot_number =>
     * player_id. Pass an empty array to clear that half.
     *
     * @param array<int,int> $slots
     */
    public function replaceLineupForHalf( int $prep_id, int $half, array $slots ): void {
        if ( $prep_id <= 0 || ! in_array( $half, [ 1, 2 ], true ) ) return;
        $this->wpdb->delete( $this->t_lineup, [
            'match_prep_id' => $prep_id,
            'half'          => $half,
            'club_id'       => CurrentClub::id(),
        ] );
        foreach ( $slots as $slot => $player_id ) {
            $slot = (int) $slot;
            $pid  = (int) $player_id;
            if ( $slot < 1 || $slot > 11 || $pid <= 0 ) continue;
            $this->wpdb->insert( $this->t_lineup, [
                'club_id'       => CurrentClub::id(),
                'match_prep_id' => $prep_id,
                'half'          => $half,
                'slot_number'   => $slot,
                'player_id'     => $pid,
            ] );
        }
    }

    // -----------------------------------------------------------------
    // Per-player notes + flags
    // -----------------------------------------------------------------

    /** @return object[] */
    public function listPlayerGoals( int $prep_id ): array {
        if ( $prep_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_pgoals}
              WHERE match_prep_id = %d AND club_id = %d",
            $prep_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The prep-flagged ("tracked") players for a prep: a player is tracked
     * when `is_specific_goal` is set OR `attention_text` is non-empty. The
     * `attention_text` is the action label the live match-execution +/-
     * counter records. Used by the match-execution surface to know which
     * players get a tracked-action counter and what to call the action.
     *
     * @return array<int, array{attention_text:string, is_specific_goal:bool, analyst_appointed:bool}>
     */
    public function listTrackedPlayers( int $prep_id ): array {
        $out = [];
        foreach ( $this->listPlayerGoals( $prep_id ) as $row ) {
            $pid       = (int) $row->player_id;
            $attention = (string) ( $row->attention_text ?? '' );
            $specific  = (int) ( $row->is_specific_goal ?? 0 ) === 1;
            if ( $pid <= 0 ) continue;
            if ( ! $specific && trim( $attention ) === '' ) continue;
            $out[ $pid ] = [
                'attention_text'   => $attention,
                'is_specific_goal' => $specific,
                'analyst_appointed'=> (int) ( $row->analyst_appointed ?? 0 ) === 1,
            ];
        }
        return $out;
    }

    /**
     * Upsert all player-goal rows in one pass.
     *
     * @param array<int, array{attention_text?:string, is_specific_goal?:bool, analyst_appointed?:bool}> $rows
     */
    public function replacePlayerGoals( int $prep_id, array $rows ): void {
        if ( $prep_id <= 0 ) return;
        $this->wpdb->delete( $this->t_pgoals, [
            'match_prep_id' => $prep_id,
            'club_id'       => CurrentClub::id(),
        ] );
        foreach ( $rows as $player_id => $entry ) {
            $pid = (int) $player_id;
            if ( $pid <= 0 ) continue;
            $this->wpdb->insert( $this->t_pgoals, [
                'club_id'           => CurrentClub::id(),
                'match_prep_id'     => $prep_id,
                'player_id'         => $pid,
                'attention_text'    => isset( $entry['attention_text'] ) ? (string) $entry['attention_text'] : '',
                'is_specific_goal'  => ! empty( $entry['is_specific_goal'] ) ? 1 : 0,
                'analyst_appointed' => ! empty( $entry['analyst_appointed'] ) ? 1 : 0,
            ] );
        }
    }

    // -----------------------------------------------------------------
    // Roles + set-piece takers (captain, corner_l/r, fk_l/r, penalty)
    // -----------------------------------------------------------------

    /**
     * Return all role assignments for a prep as a plain array of
     * objects: `{ role_key, player_id, uuid }`. Empty when nothing is
     * assigned.
     *
     * @return object[]
     */
    public function listRoles( int $prep_id ): array {
        if ( $prep_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_roles}
              WHERE match_prep_id = %d AND club_id = %d
              ORDER BY id ASC",
            $prep_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Set (insert-or-update) a single role assignment. Idempotent:
     * passing the same player_id is a no-op; passing a different one
     * replaces. Returns true on success.
     *
     * Caller is expected to validate `$role_key` against
     * `self::ROLE_KEYS` before calling — the repository accepts any
     * non-empty string so operators can opt into custom keys without
     * a code change here.
     */
    public function setRole( int $prep_id, string $role_key, int $player_id ): bool {
        if ( $prep_id <= 0 || $role_key === '' || $player_id <= 0 ) return false;

        $club_id = CurrentClub::id();

        $existing = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT id FROM {$this->t_roles}
              WHERE match_prep_id = %d AND role_key = %s AND club_id = %d
              LIMIT 1",
            $prep_id, $role_key, $club_id
        ) );

        if ( $existing && isset( $existing->id ) ) {
            return false !== $this->wpdb->update(
                $this->t_roles,
                [ 'player_id' => $player_id ],
                [ 'id' => (int) $existing->id, 'club_id' => $club_id ]
            );
        }

        return false !== $this->wpdb->insert( $this->t_roles, [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => $club_id,
            'match_prep_id' => $prep_id,
            'role_key'      => $role_key,
            'player_id'     => $player_id,
        ] );
    }

    /**
     * Clear a single role assignment. Idempotent — returns true even
     * if no row existed.
     */
    public function clearRole( int $prep_id, string $role_key ): bool {
        if ( $prep_id <= 0 || $role_key === '' ) return false;
        $this->wpdb->delete( $this->t_roles, [
            'match_prep_id' => $prep_id,
            'role_key'      => $role_key,
            'club_id'       => CurrentClub::id(),
        ] );
        return true;
    }

    /**
     * Clear every role assignment for a player on this prep. Used when
     * the availability drawer marks a player Absent — they're pulled
     * out of all role rows too, mirroring the lineup pull-out.
     */
    public function clearRolesForPlayer( int $prep_id, int $player_id ): void {
        if ( $prep_id <= 0 || $player_id <= 0 ) return;
        $this->wpdb->delete( $this->t_roles, [
            'match_prep_id' => $prep_id,
            'player_id'     => $player_id,
            'club_id'       => CurrentClub::id(),
        ] );
    }
}
