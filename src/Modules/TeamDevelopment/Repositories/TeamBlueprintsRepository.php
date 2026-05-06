<?php
namespace TT\Modules\TeamDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamBlueprintsRepository — CRUD on `tt_team_blueprints` and the
 * paired `tt_team_blueprint_assignments` table.
 *
 * All reads + writes scope by `club_id = CurrentClub::id()`. The
 * caller is expected to have already cap-checked the team-level
 * access; this layer does not duplicate that check (REST controller
 * is the gate).
 *
 * Status flow:
 *   draft  → shared → locked
 *   locked → shared (reopen, requires manage cap upstream)
 */
class TeamBlueprintsRepository {

    public const STATUS_DRAFT  = 'draft';
    public const STATUS_SHARED = 'shared';
    public const STATUS_LOCKED = 'locked';

    public const FLAVOUR_MATCH_DAY  = 'match_day';
    public const FLAVOUR_SQUAD_PLAN = 'squad_plan';

    private \wpdb $wpdb;
    private string $blueprints;
    private string $assignments;

    public function __construct() {
        global $wpdb;
        $this->wpdb        = $wpdb;
        $this->blueprints  = $wpdb->prefix . 'tt_team_blueprints';
        $this->assignments = $wpdb->prefix . 'tt_team_blueprint_assignments';
    }

    /** @return list<array<string,mixed>> */
    public function listForTeam( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT b.*, t.name AS template_name, t.formation_shape
               FROM {$this->blueprints} b
               LEFT JOIN {$this->wpdb->prefix}tt_formation_templates t ON t.id = b.formation_template_id
              WHERE b.team_id = %d AND b.club_id = %d
              ORDER BY b.updated_at DESC",
            $team_id, CurrentClub::id()
        ) );
        return array_map( [ __CLASS__, 'hydrateMeta' ], (array) $rows );
    }

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT b.*, t.name AS template_name, t.formation_shape, t.slots_json
               FROM {$this->blueprints} b
               LEFT JOIN {$this->wpdb->prefix}tt_formation_templates t ON t.id = b.formation_template_id
              WHERE b.id = %d AND b.club_id = %d",
            $id, CurrentClub::id()
        ) );
        if ( ! $row ) return null;
        $out = self::hydrateMeta( $row );
        $out['slots']       = is_array( $decoded = json_decode( (string) ( $row->slots_json ?? '[]' ), true ) ) ? $decoded : [];
        $out['assignments'] = $this->loadAssignments( (int) $row->id );
        return $out;
    }

    public function create( int $team_id, string $name, int $formation_template_id, int $created_by ): int {
        $name = trim( $name );
        if ( $team_id <= 0 || $formation_template_id <= 0 || $name === '' ) return 0;

        $ok = $this->wpdb->insert( $this->blueprints, [
            'club_id'               => CurrentClub::id(),
            'uuid'                  => self::uuid(),
            'team_id'               => $team_id,
            'name'                  => $name,
            'flavour'               => self::FLAVOUR_MATCH_DAY,
            'formation_template_id' => $formation_template_id,
            'status'                => self::STATUS_DRAFT,
            'created_by'            => $created_by > 0 ? $created_by : null,
            'updated_by'            => $created_by > 0 ? $created_by : null,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /** @param array<string,mixed> $patch */
    public function updateMeta( int $id, array $patch, int $updated_by ): bool {
        if ( $id <= 0 ) return false;
        $allowed = [ 'name', 'formation_template_id', 'notes' ];
        $data = [];
        foreach ( $allowed as $k ) {
            if ( array_key_exists( $k, $patch ) ) {
                $data[ $k ] = $patch[ $k ];
            }
        }
        if ( empty( $data ) ) return false;
        $data['updated_by'] = $updated_by > 0 ? $updated_by : null;
        $data['updated_at'] = current_time( 'mysql' );
        $ok = $this->wpdb->update( $this->blueprints, $data, [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] );
        return $ok !== false;
    }

    public function setStatus( int $id, string $status, int $updated_by ): bool {
        if ( ! in_array( $status, [ self::STATUS_DRAFT, self::STATUS_SHARED, self::STATUS_LOCKED ], true ) ) {
            return false;
        }
        $ok = $this->wpdb->update( $this->blueprints, [
            'status'     => $status,
            'updated_by' => $updated_by > 0 ? $updated_by : null,
            'updated_at' => current_time( 'mysql' ),
        ], [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] );
        return $ok !== false;
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        $this->wpdb->delete( $this->assignments, [ 'blueprint_id' => $id, 'club_id' => CurrentClub::id() ] );
        $ok = $this->wpdb->delete( $this->blueprints, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return $ok !== false;
    }

    /**
     * Bulk-replace the slot assignments. Caller passes a complete map;
     * any slot not present is removed. Empty player_id removes that
     * slot's assignment.
     *
     * @param array<string, ?int> $slot_to_player
     */
    public function replaceAssignments( int $blueprint_id, array $slot_to_player ): bool {
        if ( $blueprint_id <= 0 ) return false;
        $club_id = CurrentClub::id();

        $this->wpdb->delete( $this->assignments, [
            'blueprint_id' => $blueprint_id,
            'club_id'      => $club_id,
        ] );
        foreach ( $slot_to_player as $slot => $pid ) {
            $slot_label = (string) $slot;
            $player_id  = $pid !== null ? (int) $pid : 0;
            if ( $slot_label === '' || $player_id <= 0 ) continue;
            $this->wpdb->insert( $this->assignments, [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'slot_label'   => $slot_label,
                'player_id'    => $player_id,
                'updated_at'   => current_time( 'mysql' ),
            ] );
        }
        $this->wpdb->update( $this->blueprints, [
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $blueprint_id, 'club_id' => $club_id ] );
        return true;
    }

    /**
     * Update one slot's assignment in place. Used by the drag-drop
     * editor's per-drop REST call so a 50-row replace doesn't run on
     * every gesture.
     */
    public function setAssignment( int $blueprint_id, string $slot_label, ?int $player_id ): bool {
        if ( $blueprint_id <= 0 || $slot_label === '' ) return false;
        $club_id = CurrentClub::id();

        if ( $player_id === null || $player_id <= 0 ) {
            $this->wpdb->delete( $this->assignments, [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'slot_label'   => $slot_label,
            ] );
        } else {
            $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->assignments}
                  WHERE blueprint_id = %d AND club_id = %d AND slot_label = %s",
                $blueprint_id, $club_id, $slot_label
            ) );
            $payload = [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'slot_label'   => $slot_label,
                'player_id'    => (int) $player_id,
                'updated_at'   => current_time( 'mysql' ),
            ];
            if ( $existing > 0 ) {
                $this->wpdb->update( $this->assignments, $payload, [ 'id' => $existing ] );
            } else {
                $this->wpdb->insert( $this->assignments, $payload );
            }
        }
        $this->wpdb->update( $this->blueprints, [
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $blueprint_id, 'club_id' => $club_id ] );
        return true;
    }

    /** @return array<string, int> slot_label → player_id */
    public function loadAssignments( int $blueprint_id ): array {
        if ( $blueprint_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT slot_label, player_id FROM {$this->assignments}
              WHERE blueprint_id = %d AND club_id = %d AND player_id IS NOT NULL",
            $blueprint_id, CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r->slot_label ] = (int) $r->player_id;
        }
        return $out;
    }

    /**
     * @param object $row
     * @return array<string, mixed>
     */
    private static function hydrateMeta( $row ): array {
        return [
            'id'                    => (int) $row->id,
            'uuid'                  => $row->uuid !== null ? (string) $row->uuid : null,
            'team_id'               => (int) $row->team_id,
            'name'                  => (string) $row->name,
            'flavour'               => (string) $row->flavour,
            'formation_template_id' => (int) $row->formation_template_id,
            'template_name'         => isset( $row->template_name ) ? (string) $row->template_name : null,
            'formation_shape'       => isset( $row->formation_shape ) ? (string) $row->formation_shape : null,
            'status'                => (string) $row->status,
            'notes'                 => $row->notes !== null ? (string) $row->notes : null,
            'created_by'            => $row->created_by !== null ? (int) $row->created_by : null,
            'created_at'            => (string) $row->created_at,
            'updated_by'            => $row->updated_by !== null ? (int) $row->updated_by : null,
            'updated_at'            => (string) $row->updated_at,
        ];
    }

    private static function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}
