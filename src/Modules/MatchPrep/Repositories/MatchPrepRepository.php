<?php
namespace TT\Modules\MatchPrep\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

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

    public function __construct() {
        global $wpdb;
        $this->wpdb     = $wpdb;
        $this->t_prep   = $wpdb->prefix . 'tt_match_prep';
        $this->t_avail  = $wpdb->prefix . 'tt_match_prep_availability';
        $this->t_lineup = $wpdb->prefix . 'tt_match_prep_lineup';
        $this->t_pgoals = $wpdb->prefix . 'tt_match_prep_player_goals';
    }

    public function findByActivity( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->t_prep} WHERE activity_id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Find-or-create the prep row for an activity. Returns the row's id.
     */
    public function ensureForActivity( int $activity_id, int $half_length = 35 ): int {
        $existing = $this->findByActivity( $activity_id );
        if ( $existing ) return (int) $existing->id;

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
}
