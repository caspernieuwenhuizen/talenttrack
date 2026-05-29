<?php
/**
 * JourneyEventType — typed constants for the values stored in
 * `tt_player_events.event_type`. Backs the `journey_event_type` lookup
 * (operator-editable, per-club) seeded by migration 0037 with 14 v1
 * canonical types.
 *
 * Each event type carries rendering meta on the lookup row (icon /
 * color / severity / default_visibility / group) — the PHP constant
 * here is the stable key the emitter, the registry, and the dispatcher
 * all agree on; the row carries the operator-facing label and the
 * rendering hints.
 *
 * Use the constants in PHP comparisons:
 *
 *     EventEmitter::emit( $player_id, JourneyEventType::EVALUATION_COMPLETED, ... );
 *     if ( $event->event_type === JourneyEventType::INJURY_STARTED ) { ... }
 *
 * SQL string literals (CASE WHEN event_type='joined_academy' …) stay as
 * literals — those are the canonical stored values and the DB layer is
 * the source of truth, not the PHP layer.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class JourneyEventType {

    public const JOINED_ACADEMY       = 'joined_academy';
    public const TRIAL_STARTED        = 'trial_started';
    public const TRIAL_ENDED          = 'trial_ended';
    public const SIGNED               = 'signed';
    public const RELEASED             = 'released';
    public const GRADUATED            = 'graduated';
    public const TEAM_CHANGED         = 'team_changed';
    public const AGE_GROUP_PROMOTED   = 'age_group_promoted';
    public const POSITION_CHANGED     = 'position_changed';
    public const INJURY_STARTED       = 'injury_started';
    public const INJURY_ENDED         = 'injury_ended';
    public const EVALUATION_COMPLETED = 'evaluation_completed';
    public const PDP_VERDICT_RECORDED = 'pdp_verdict_recorded';
    public const NOTE_ADDED           = 'note_added';
    public const GOAL_SET             = 'goal_set';

    /** @var list<string> */
    public const ALL = [
        self::JOINED_ACADEMY,
        self::TRIAL_STARTED,
        self::TRIAL_ENDED,
        self::SIGNED,
        self::RELEASED,
        self::GRADUATED,
        self::TEAM_CHANGED,
        self::AGE_GROUP_PROMOTED,
        self::POSITION_CHANGED,
        self::INJURY_STARTED,
        self::INJURY_ENDED,
        self::EVALUATION_COMPLETED,
        self::PDP_VERDICT_RECORDED,
        self::NOTE_ADDED,
        self::GOAL_SET,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
