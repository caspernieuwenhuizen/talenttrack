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
     * v3.110.184 — Save As: duplicate the blueprint row and every
     * assignment row to a fresh `tt_team_blueprints` + paired
     * assignments. The clone starts in `draft` status regardless of
     * the source's status; the caller's name is used (no automatic
     * "Copy of …" prefix — let the operator name it themselves).
     *
     * Returns the new blueprint id, or 0 on failure.
     */
    public function cloneBlueprint( int $source_id, string $new_name, int $created_by ): int {
        $new_name = trim( $new_name );
        if ( $source_id <= 0 || $new_name === '' ) return 0;

        $club_id = CurrentClub::id();
        $source  = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->blueprints} WHERE id = %d AND club_id = %d",
            $source_id, $club_id
        ) );
        if ( ! $source ) return 0;

        $ok = $this->wpdb->insert( $this->blueprints, [
            'club_id'               => $club_id,
            'uuid'                  => self::uuid(),
            'team_id'               => (int) $source->team_id,
            'name'                  => $new_name,
            'flavour'               => (string) $source->flavour,
            'formation_template_id' => (int) $source->formation_template_id,
            'status'                => self::STATUS_DRAFT,
            'notes'                 => (string) ( $source->notes ?? '' ),
            'created_by'            => $created_by > 0 ? $created_by : null,
            'updated_by'            => $created_by > 0 ? $created_by : null,
        ] );
        if ( ! $ok ) return 0;
        $new_id = (int) $this->wpdb->insert_id;

        // Copy every assignment row across.
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT slot_label, tier, player_id FROM {$this->assignments}
              WHERE blueprint_id = %d AND club_id = %d",
            $source_id, $club_id
        ) );
        foreach ( (array) $rows as $r ) {
            $this->wpdb->insert( $this->assignments, [
                'club_id'      => $club_id,
                'blueprint_id' => $new_id,
                'slot_label'   => (string) $r->slot_label,
                'tier'         => (string) $r->tier,
                'player_id'    => (int) $r->player_id,
                'updated_at'   => current_time( 'mysql' ),
            ] );
        }
        return $new_id;
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
        $this->last_error = '';
        $club_id = CurrentClub::id();

        // #1328 — check the wpdb return on the bulk delete. Previously
        // a SQL-layer failure here (schema drift, FK violation) would
        // be swallowed and the caller would think the replace succeeded.
        $deleted = $this->wpdb->delete( $this->assignments, [
            'blueprint_id' => $blueprint_id,
            'club_id'      => $club_id,
        ] );
        if ( $deleted === false ) {
            $this->last_error = (string) ( $this->wpdb->last_error ?: 'wpdb->delete returned false' );
            return false;
        }
        // #953 — value can be a flat int (legacy `player_id`) or an
        // array. When array, inner value can itself be:
        //   - int  (legacy `player_id` for that tier)
        //   - ref-object [ 'kind' => 'player'|'guest'|'custom', … ]
        // No de-duplication across slots — same player legally occupies
        // multiple slots / tiers per the depth-chart contract.
        foreach ( $slot_to_player as $slot => $value ) {
            $slot_label = (string) $slot;
            if ( $slot_label === '' ) continue;
            if ( is_array( $value ) && self::isRef( $value ) ) {
                // Single-tier ref object at the top level (callers that
                // pass `{ kind:..., player_id:... }` per slot).
                $row = self::buildAssignmentRow( $blueprint_id, $club_id, $slot_label, self::TIER_PRIMARY, $value );
                if ( $row !== null && ! $this->insertRowOrCapture( $row ) ) return false;
            } elseif ( is_array( $value ) ) {
                foreach ( $value as $tier => $entry ) {
                    $tier_clean = self::cleanTier( (string) $tier );
                    $ref = self::normaliseRef( $entry );
                    $row = self::buildAssignmentRow( $blueprint_id, $club_id, $slot_label, $tier_clean, $ref );
                    if ( $row !== null && ! $this->insertRowOrCapture( $row ) ) return false;
                }
            } else {
                $ref = self::normaliseRef( $value );
                $row = self::buildAssignmentRow( $blueprint_id, $club_id, $slot_label, self::TIER_PRIMARY, $ref );
                if ( $row !== null && ! $this->insertRowOrCapture( $row ) ) return false;
            }
        }
        $this->wpdb->update( $this->blueprints, [
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $blueprint_id, 'club_id' => $club_id ] );
        return true;
    }

    /**
     * #1328 — bulk-insert helper that captures wpdb errors into
     * `$this->last_error` so `replaceAssignments` can short-circuit
     * loudly instead of silently dropping rows the way #1054 / #1066
     * fixed for `setAssignment`.
     *
     * @param array<string,mixed> $row
     */
    private function insertRowOrCapture( array $row ): bool {
        $inserted = $this->wpdb->insert( $this->assignments, $row );
        if ( $inserted === false ) {
            $this->last_error = (string) ( $this->wpdb->last_error ?: 'wpdb->insert returned false' );
            return false;
        }
        return true;
    }

    /**
     * #953 — coerce a caller payload into the canonical ref shape.
     * Accepts:
     *   - int (legacy player_id)
     *   - null
     *   - [ 'kind' => 'player', 'player_id' => N ]
     *   - [ 'kind' => 'guest', 'name' => '…', 'position' => '…' ]
     *   - [ 'kind' => 'custom', 'label' => '…' ]
     * Returns null when the input is null or cannot be coerced.
     *
     * @return array<string,mixed>|null
     */
    private static function normaliseRef( $value ): ?array {
        if ( $value === null ) return null;
        if ( is_int( $value ) || ctype_digit( (string) $value ) ) {
            $pid = (int) $value;
            return $pid > 0 ? [ 'kind' => 'player', 'player_id' => $pid ] : null;
        }
        if ( is_array( $value ) && self::isRef( $value ) ) return $value;
        return null;
    }

    private static function isRef( array $value ): bool {
        return isset( $value['kind'] ) && in_array( (string) $value['kind'], [ 'player', 'guest', 'custom' ], true );
    }

    /**
     * Builds the row payload for an INSERT into `tt_team_blueprint_assignments`
     * given a normalised ref. Returns null for invalid refs (missing
     * required field for the kind).
     *
     * @param array<string,mixed>|null $ref
     * @return array<string,mixed>|null
     */
    private static function buildAssignmentRow( int $blueprint_id, int $club_id, string $slot_label, string $tier, ?array $ref ): ?array {
        if ( $ref === null ) return null;
        $kind = (string) ( $ref['kind'] ?? 'player' );
        $row  = [
            'club_id'        => $club_id,
            'blueprint_id'   => $blueprint_id,
            'slot_label'     => $slot_label,
            'tier'           => $tier,
            'ref_kind'       => $kind,
            'player_id'      => null,
            'guest_name'     => null,
            'guest_position' => null,
            'custom_label'   => null,
            'updated_at'     => current_time( 'mysql' ),
        ];
        if ( $kind === 'player' ) {
            $pid = isset( $ref['player_id'] ) ? (int) $ref['player_id'] : 0;
            if ( $pid <= 0 ) return null;
            $row['player_id'] = $pid;
        } elseif ( $kind === 'guest' ) {
            $name = trim( (string) ( $ref['name'] ?? '' ) );
            if ( $name === '' ) return null;
            $row['guest_name']     = $name;
            $row['guest_position'] = isset( $ref['position'] ) ? trim( (string) $ref['position'] ) : null;
        } elseif ( $kind === 'custom' ) {
            $label = trim( (string) ( $ref['label'] ?? '' ) );
            if ( $label === '' ) return null;
            $row['custom_label'] = $label;
        } else {
            return null;
        }
        return $row;
    }

    /**
     * Update one (slot, tier) assignment in place. Tier defaults to
     * `primary` so match-day callers don't need to pass it.
     *
     * v4.3.21 (#953) — accepts the new ref shape as the third arg:
     *
     *   setAssignment( id, slot, [ 'kind' => 'player', 'player_id' => N ], tier? )
     *   setAssignment( id, slot, [ 'kind' => 'guest',  'name' => '…' ],     tier? )
     *   setAssignment( id, slot, [ 'kind' => 'custom', 'label' => '…' ],    tier? )
     *
     * Legacy callers passing a bare int (or null to clear) still work
     * via `normaliseRef()`. The cross-slot player dedupe is dropped —
     * a player can legally occupy multiple slots / tiers on one
     * blueprint per the depth-chart contract. Cross-cell uniqueness
     * (one entry per (slot, tier)) stays as the UNIQUE KEY.
     *
     * @param array<string,mixed>|int|null $ref
     */
    public function setAssignment( int $blueprint_id, string $slot_label, $ref, string $tier = self::TIER_PRIMARY ): bool {
        if ( $blueprint_id <= 0 || $slot_label === '' ) return false;
        $this->last_error = '';
        $tier    = self::cleanTier( $tier );
        $club_id = CurrentClub::id();

        $normalised = self::normaliseRef( $ref );
        if ( $normalised === null ) {
            // Null / invalid → delete the cell.
            // #1054 — wpdb->delete returns false on SQL failure (also
            // returns 0 when nothing matched, which is a legitimate
            // no-op). Distinguish via $this->wpdb->last_error.
            $deleted = $this->wpdb->delete( $this->assignments, [
                'club_id'      => $club_id,
                'blueprint_id' => $blueprint_id,
                'slot_label'   => $slot_label,
                'tier'         => $tier,
            ] );
            if ( $deleted === false && $this->wpdb->last_error !== '' ) {
                $this->last_error = (string) $this->wpdb->last_error;
                return false;
            }
        } else {
            $row = self::buildAssignmentRow( $blueprint_id, $club_id, $slot_label, $tier, $normalised );
            if ( $row === null ) return false;

            $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->assignments}
                  WHERE blueprint_id = %d AND club_id = %d AND slot_label = %s AND tier = %s",
                $blueprint_id, $club_id, $slot_label, $tier
            ) );
            // #1054 — check the wpdb return value. Previously the
            // failure mode was: INSERT collides with the UNIQUE on
            // (blueprint_id, slot_label, tier) because a stale row
            // exists with a different club_id; insert silently returns
            // false; setAssignment returns true; endpoint returns 200
            // OK; user sees "I picked a player but it vanished". The
            // new uniq_slot_tier_club constraint (migration 0135) also
            // closes the underlying cross-club collision.
            if ( $existing > 0 ) {
                $updated = $this->wpdb->update( $this->assignments, $row, [ 'id' => $existing ] );
                if ( $updated === false ) {
                    $this->last_error = (string) ( $this->wpdb->last_error ?: 'wpdb->update returned false' );
                    return false;
                }
            } else {
                $inserted = $this->wpdb->insert( $this->assignments, $row );
                if ( $inserted === false ) {
                    $this->last_error = (string) ( $this->wpdb->last_error ?: 'wpdb->insert returned false' );
                    return false;
                }
            }
        }
        $this->wpdb->update( $this->blueprints, [
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $blueprint_id, 'club_id' => $club_id ] );
        return true;
    }

    /**
     * #1054 — last SQL-layer error from `setAssignment`. Empty string
     * when the previous call succeeded or hasn't been called yet. The
     * REST controller surfaces this in the 500 response so the operator
     * can diagnose underlying issues (UNIQUE collision, FK violation,
     * schema drift).
     */
    public function lastError(): string {
        return $this->last_error ?? '';
    }

    /** @var string */
    private $last_error = '';

    /**
     * @return array<string, array{primary?:int, secondary?:int, tertiary?:int}>
     *   One outer key per slot; inner keys are tiers that have a
     *   player. Slots with no assignment are absent.
     *
     * v4.3.21 (#953) — back-compat shape preserved for legacy callers:
     *   the inner value is still an int (player_id) when the row's
     *   `ref_kind = 'player'`. Guest and custom rows are surfaced via
     *   the parallel `loadAssignmentRefs()` method below (returns the
     *   full ref shape per cell). Existing callers (chemistry engine,
     *   share-link view) consume this shape unchanged — they only
     *   care about player IDs for chemistry scoring.
     */
    public function loadAssignments( int $blueprint_id ): array {
        if ( $blueprint_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT slot_label, tier, ref_kind, player_id
               FROM {$this->assignments}
              WHERE blueprint_id = %d AND club_id = %d
                AND ref_kind = 'player' AND player_id IS NOT NULL",
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
     * v4.3.21 (#953) — load every assignment as a normalised ref so the
     * editor view can render player / guest / custom cells uniformly.
     *
     * @return array<string, array<string, array<string,mixed>>>
     *   `slot_label => tier => [ 'kind' => 'player'|'guest'|'custom', … ]`
     */
    public function loadAssignmentRefs( int $blueprint_id ): array {
        if ( $blueprint_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT slot_label, tier, ref_kind, player_id, guest_name, guest_position, custom_label
               FROM {$this->assignments}
              WHERE blueprint_id = %d AND club_id = %d",
            $blueprint_id, CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $slot = (string) $r->slot_label;
            $tier = self::cleanTier( (string) $r->tier );
            $kind = (string) ( $r->ref_kind ?? 'player' );
            if ( $kind === 'player' && (int) $r->player_id > 0 ) {
                $ref = [ 'kind' => 'player', 'player_id' => (int) $r->player_id ];
            } elseif ( $kind === 'guest' && (string) ( $r->guest_name ?? '' ) !== '' ) {
                $ref = [
                    'kind'     => 'guest',
                    'name'     => (string) $r->guest_name,
                    'position' => $r->guest_position !== null ? (string) $r->guest_position : null,
                ];
            } elseif ( $kind === 'custom' && (string) ( $r->custom_label ?? '' ) !== '' ) {
                $ref = [ 'kind' => 'custom', 'label' => (string) $r->custom_label ];
            } else {
                continue; // Skip malformed rows defensively.
            }
            if ( ! isset( $out[ $slot ] ) ) $out[ $slot ] = [];
            $out[ $slot ][ $tier ] = $ref;
        }
        return $out;
    }

    /**
     * v4.3.21 (#953) — slots where tier 2/3 has an entry but tier=primary
     * does not. Surfaces in the editor UI as "tier-1 unassigned" warning
     * chips so the chemistry-score's primary-only contract is visible
     * to the coach (otherwise the score-drop from previous releases
     * would be a silent semantic change). See docs/team-blueprints.md
     * "How chemistry is calculated".
     *
     * @return list<string>  slot_labels missing a primary-tier entry
     */
    public function slotsMissingPrimary( int $blueprint_id ): array {
        $refs = $this->loadAssignmentRefs( $blueprint_id );
        $out  = [];
        foreach ( $refs as $slot => $tiers ) {
            if ( ! isset( $tiers[ self::TIER_PRIMARY ] ) && ( isset( $tiers[ self::TIER_SECONDARY ] ) || isset( $tiers[ self::TIER_TERTIARY ] ) ) ) {
                $out[] = (string) $slot;
            }
        }
        return $out;
    }

    /**
     * Match-day-shaped lineup (slot → player_id) — primary tier only.
     * Used by `BlueprintChemistryEngine` whether the blueprint flavour
     * is match_day (one tier) or squad_plan (multi-tier; chemistry only
     * scores the starting XI).
     *
     * v4.3.21 (#953) — guest and custom refs are NOT included in the
     * lineup map by construction (loadAssignments() filters
     * `ref_kind = 'player'`). The chemistry engine consequently treats
     * cells holding guest / custom entries as empty for scoring.
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
