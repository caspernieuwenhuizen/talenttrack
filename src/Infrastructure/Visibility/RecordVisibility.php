<?php
namespace TT\Infrastructure\Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\EventTypeDefinition;
use TT\Modules\Authorization\MatrixGate;

/**
 * Who may see a piece of a player's record.
 *
 * #3392 — a measurement test could not be kept from a player and their
 * family while staying visible to staff. `show_on_profile` looked like
 * the lever and is not: it is viewer-agnostic, so switching it off hides
 * the test from the coach too. The operator's real question — *staff yes,
 * family no* — had no expression.
 *
 * Rather than invent a second axis, measurements take the journey's. That
 * vocabulary already exists on {@see EventTypeDefinition} and already
 * means something specific to an operator, so "who may see this" is one
 * question across the product instead of two that drift.
 *
 * THE LADDER LIVES HERE, ONCE
 *
 * The levels are ordered: everyone who can see `coaching_staff` can also
 * see `public`. What differs per module is only which users count as
 * staff — the journey asks about evaluations, measurements asks about the
 * measurements grant at team or global scope. So the shape is shared and
 * the predicate is passed in, rather than the ladder being copied and
 * then quietly diverging the first time one of them gains a level.
 */
final class RecordVisibility {

    public const LEVEL_PUBLIC         = EventTypeDefinition::VISIBILITY_PUBLIC;
    public const LEVEL_COACHING_STAFF = EventTypeDefinition::VISIBILITY_COACHING_STAFF;
    public const LEVEL_MEDICAL        = EventTypeDefinition::VISIBILITY_MEDICAL;
    public const LEVEL_SAFEGUARDING   = EventTypeDefinition::VISIBILITY_SAFEGUARDING;

    /**
     * The levels an operator may choose from when classifying a record.
     *
     * Safeguarding is deliberately absent for measurements — a
     * safeguarding concern is not a test result, and offering the level
     * on a test-setup form would invite recording one in the wrong place.
     *
     * @return array<string,string> level => operator-facing label
     */
    public static function measurementChoices(): array {
        return [
            self::LEVEL_PUBLIC => __( 'Everyone who can see the player — including the player and their parents', 'talenttrack' ),
            self::LEVEL_COACHING_STAFF => __( 'Staff only — recorded and reported, but not shown to the player or their parents', 'talenttrack' ),
            self::LEVEL_MEDICAL => __( 'Medical staff only', 'talenttrack' ),
        ];
    }

    /**
     * The same levels, without the labels.
     *
     * Kept separate from {@see measurementChoices()} rather than derived
     * from its keys: this one is read on a query path, and building it
     * from the labelled map would pull translation loading in behind a
     * database read for no reason.
     *
     * @var list<string>
     */
    private const MEASUREMENT_LEVELS = [
        self::LEVEL_PUBLIC,
        self::LEVEL_COACHING_STAFF,
        self::LEVEL_MEDICAL,
    ];

    /**
     * Every level a measurement definition may carry.
     *
     * The unfiltered set, for callers that are gated as a whole and must
     * keep seeing every test: reports, exports, the test-setup screens.
     *
     * @return list<string>
     */
    public static function measurementLevels(): array {
        return self::MEASUREMENT_LEVELS;
    }

    /**
     * Levels this user may see on a player's measurement profile.
     *
     * A player and a parent get the public level and nothing else. That is
     * the whole point of the feature, and it is why the staff test is a
     * *scope* test rather than a capability test: a player holds
     * `measurements` at their own scope, so `canAnyScope()` would admit
     * them and quietly return the product to where it started.
     *
     * @return list<string>
     */
    public static function forMeasurements( int $user_id ): array {
        $is_staff = user_can( $user_id, 'tt_edit_settings' )
            || MatrixGate::can( $user_id, 'measurements', 'read', MatrixGate::SCOPE_GLOBAL )
            || MatrixGate::can( $user_id, 'measurements', 'read', MatrixGate::SCOPE_TEAM );

        return self::ladder( $is_staff, $user_id );
    }

    /**
     * Levels this user may see on a player's journey timeline.
     *
     * Behaviour-preserving: this is the ladder
     * `PlayerEventsRepository::visibilitiesForUser()` has always applied,
     * moved here so measurements could reuse it rather than copy it. That
     * method now delegates, and stays the name the journey code calls.
     *
     * @return list<string>
     */
    public static function forJourney( int $user_id ): array {
        $is_staff = user_can( $user_id, 'tt_edit_evaluations' )
            || user_can( $user_id, 'tt_edit_settings' );

        $out = self::ladder( $is_staff, $user_id );

        if ( user_can( $user_id, 'tt_view_player_safeguarding' ) ) {
            $out[] = self::LEVEL_SAFEGUARDING;
        }

        return $out;
    }

    /**
     * Public, plus the staff level when they are staff, plus medical when
     * they hold the medical cap AND the sub-feature is on.
     *
     * #1538 — the medical gate is two conditions, not one: the
     * `journey_medical_visibility` feature switch hides medical entries
     * from the timeline even for staff holding the cap, without touching
     * the cap itself. Measurements inherit that rather than inventing a
     * second switch, so an academy that has turned medical visibility off
     * has turned it off everywhere.
     *
     * @return list<string>
     */
    private static function ladder( bool $is_staff, int $user_id ): array {
        $out = [ self::LEVEL_PUBLIC ];

        if ( $is_staff ) {
            $out[] = self::LEVEL_COACHING_STAFF;
        }

        if ( user_can( $user_id, 'tt_view_player_medical' )
            && \TT\Core\FeatureRegistry::isEnabled( 'journey_medical_visibility' ) ) {
            $out[] = self::LEVEL_MEDICAL;
        }

        return $out;
    }
}
