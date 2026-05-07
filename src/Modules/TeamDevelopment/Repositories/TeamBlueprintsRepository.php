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

    public const TIER_PRIMARY   = 'primary';
    public const TIER_SECONDARY = 'secondary';
    public const TIER_TERTIARY  = 'tertiary';

    /** @var list<string> */
    public const TIERS = [ self::TIER_PRIMARY, self::TIER_SECONDARY, self::TIER_TERTIARY ];

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

    public function create( int $team_id, string $name, int $formation_template_id, int $created_by, string $flavour = self::FLAVOUR_MATCH_DAY ): int {
        $name = trim( $name );
        if ( $team_id <= 0 || $formation_template_id <= 0 || $name === '' ) return 0;
        if ( ! in_array( $flavour, [ self::FLAVOUR_MATCH_DAY, self::FLAVOUR_SQUAD_PLAN ], true ) ) {
            $flavour = self::FLAVOUR_MATCH_DAY;
        }

        $ok = $this->wpdb->insert( $this->blueprints, [
            'club_id'               => CurrentClub::id(),
            'uuid'                  => self::uuid(),
            'team_id'               => $team_id,
            'name'                  => $name,
            'flavour'               => $flavour,
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
     * Bulk-replace the slot assignments. Match-day flavour: caller
     * passes `slot => player_id`. Squad-plan flavour: caller passes
     * `slot => [tier => player_id]` (one entry per tier present).
     *
     * @param array<string, mixed> $slot_to_player
     */
    public function replaceAssignments( int $blueprint_id, array $slot_to_player ): bool {
        if ( $blueprint_id <= 0 ) return false;
        $club_id = CurrentClub::id();

        $this->wpdb->delete( $this->assignments, [
            'blueprint_id' => $blueprint_id,
            'club_id'      => $club_id,
        ] );
        foreach ( $slot_to_player as $slot => $value ) {
            $slot_label = (string) $slot;
            if ( $slot_label === '' ) continue;
            if ( is_array( $value ) ) {
                foreach ( $value as $tier => $pid ) {
                    $tier_clean = self::cleanTier( (string) $tier );
                    $player_id  = $pid !== null ? (int) $pid : 0;
                    if ( $player_id <= 0 ) continue;
                    $this->wpdb->insert( $this->assignments, [
                        'club_id'      => $club_id,
                        'blueprint_id' => $blueprint_id,
                        'slot_label'   => $slot_label,
                        'tier'         => $tier_clean,
                        'player_id'    => $player_id,
                        'updated_at'   => current_time( 'mysql' ),
                    ] );
                }
            } else {
                $player_id = $value !== null ? (int) $value : 0;
                if ( $player_id <= 0 ) continue;
                $this->wpdb->insert( $this->assignments, [
                    'club_id'      => $club_id,
                    'blueprint_id' => $blueprint_id,
                    'slot_label'   => $slot_label,
                    'tier'         => self::TIER_PRIMARY,
                    'player_id'    => $player_id,
                    'updated_at'   => current_time( 'mysql' ),
                ] );
            }
        }
        $this->wpdb->update( $this->blueprints, [
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $blueprint_id, 'club_id' => $club_id ] );
        return true;
    }

    /**
     * Update one (slot, tier) assignment in place. Tier defaults to
     * `primary` so match-day callers don't need to pass it.
     */
    public function setAssignment( int $blueprint_id, string $slot_label, ?int $player_id, string $tier = self::TIER_PRIMARY ): bool {
        if ( $blueprint_id <= 0 || $slot_label === '' ) return false;
        $tier    = self::cleanTier( $tier );
        $club_id = CurrentClub::id();

        if ( $player_id === null || $player_id <= 0 ) {
            $this->wpdb->delete( $this->assignments, [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'slot_label'   => $slot_label,
                'tier'         => $tier,
            ] );
        } else {
            // Same player can't sit in two slots/tiers on one blueprint
            // — strip any prior assignment for this player first so a
            // drag from one slot to another doesn't double-book.
            $this->wpdb->delete( $this->assignments, [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'player_id'    => (int) $player_id,
            ] );

            $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->assignments}
                  WHERE blueprint_id = %d AND club_id = %d AND slot_label = %s AND tier = %s",
                $blueprint_id, $club_id, $slot_label, $tier
            ) );
            $payload = [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'slot_label'   => $slot_label,
                'tier'         => $tier,
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

    /**
     * @return array<string, array{primary?:int, secondary?:int, tertiary?:int}>
     *   One outer key per slot; inner keys are tiers that have a
     *   player. Slots with no assignment are absent.
     */
    public function loadAssignments( int $blueprint_id ): array {
        if ( $blueprint_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT slot_label, tier, player_id FROM {$this->assignments}
              WHERE blueprint_id = %d AND club_id = %d AND player_id IS NOT NULL",
            $blueprint_id, CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $slot = (string) $r->slot_label;
            $tier = self::cleanTier( (string) $r->tier );
            if ( ! isset( $out[ $slot ] ) ) $out[ $slot ] = [];
            $out[ $slot ][ $tier ] = (int) $r->player_id;
        }
        return $out;
    }

    /**
     * Match-day-shaped lineup (slot → player_id) — primary tier only.
     * Used by `BlueprintChemistryEngine` whether the blueprint flavour
     * is match_day (one tier) or squad_plan (multi-tier; chemistry only
     * scores the starting XI).
     *
     * @return array<string, int>
     */
    public function loadPrimaryLineup( int $blueprint_id ): array {
        $by_slot = $this->loadAssignments( $blueprint_id );
        $out = [];
        foreach ( $by_slot as $slot => $tiers ) {
            if ( isset( $tiers[ self::TIER_PRIMARY ] ) ) {
                $out[ $slot ] = (int) $tiers[ self::TIER_PRIMARY ];
            }
        }
        return $out;
    }

    private static function cleanTier( string $tier ): string {
        $tier = strtolower( trim( $tier ) );
        return in_array( $tier, self::TIERS, true ) ? $tier : self::TIER_PRIMARY;
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
            // #0068 Phase 4 — public share-link signing seed. Migration
            // 0078 adds the column; old rows return empty string until
            // ensureShareTokenSeed() lazily populates on first use.
            'share_token_seed'      => isset( $row->share_token_seed ) ? (string) $row->share_token_seed : '',
            'created_by'            => $row->created_by !== null ? (int) $row->created_by : null,
            'created_at'            => (string) $row->created_at,
            'updated_by'            => $row->updated_by !== null ? (int) $row->updated_by : null,
            'updated_at'            => (string) $row->updated_at,
        ];
    }

    /**
     * Lazily seed the share-link HMAC secret on first share-link build.
     * Default fall-back is the row's own `uuid` (cryptographically
     * random by construction). Returns the resolved seed.
     *
     * #0068 Phase 4. Mirrors the seed-on-first-use pattern so the
     * Phase 1 migration doesn't have to touch every blueprint row.
     */
    public function ensureShareTokenSeed( int $blueprint_id ): string {
        $row = $this->find( $blueprint_id );
        if ( $row === null ) return '';
        $seed = (string) ( $row['share_token_seed'] ?? '' );
        if ( $seed !== '' ) return $seed;
        $seed = (string) ( $row['uuid'] ?? '' );
        if ( $seed === '' ) return '';
        $this->wpdb->update( $this->blueprints, [
            'share_token_seed' => $seed,
            'updated_at'       => current_time( 'mysql' ),
        ], [
            'id'      => $blueprint_id,
            'club_id' => CurrentClub::id(),
        ] );
        return $seed;
    }

    /**
     * Operator-driven rotation. Sets a fresh random seed; every prior
     * share URL for this blueprint immediately fails verification.
     * Cap-gated upstream on `tt_manage_team_chemistry`.
     */
    public function rotateShareTokenSeed( int $blueprint_id, int $updated_by ): string {
        $seed = wp_generate_password( 16, false, false );
        $this->wpdb->update( $this->blueprints, [
            'share_token_seed' => $seed,
            'updated_by'       => $updated_by > 0 ? $updated_by : null,
            'updated_at'       => current_time( 'mysql' ),
        ], [
            'id'      => $blueprint_id,
            'club_id' => CurrentClub::id(),
        ] );
        return $seed;
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
