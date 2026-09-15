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
 * THE VOCABULARY IS SHARED; THE TWO RESOLVERS ARE NOT THE SAME LADDER
 *
 * The levels and their meaning live here once. The resolvers deliberately
 * do not share an implementation, because the two modules disagree about
 * one rung — and flattening that disagreement would be a privacy bug in
 * the feature built to prevent one.
 *
 * `tt_view_player_medical` does not mean "is medical staff". It bridges
 * from `player_injuries:read`, which the authorization seed grants a
 * **player** at `self` scope and a **parent** at `player` scope, so that a
 * player can see their own injuries and a parent their child's.
 *
 * For the journey that is the right answer: `timelineForPlayer()` is
 * already scoped to one player the caller was authorised to view, so a
 * medical entry on your own timeline is yours to read.
 *
 * For measurements it inverts the feature. A medical-level test is one the
 * academy decided the family should meet in a conversation; granting it to
 * the player it is about, because they hold the cap over their own record,
 * would hand them the exact figure the level exists to withhold. So here
 * the medical rung sits *above* the staff rung rather than beside it.
 *
 * The first draft of this class did share one ladder and CI caught it.
 * That is the note worth leaving: the two look identical and are not.
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

        $out = [ self::LEVEL_PUBLIC ];
        if ( ! $is_staff ) {
            return $out;
        }

        $out[] = self::LEVEL_COACHING_STAFF;

        // Staff FIRST, then the cap. Reversing these two is the bug the
        // class docblock describes: a player holds the medical cap over
        // their own record, so a cap-only check hands them the test the
        // level exists to keep from them.
        //
        // #1538's feature switch applies on top, as it does on the
        // timeline, so an academy that turned medical visibility off has
        // turned it off in both places rather than discovering a second
        // switch here.
        if ( user_can( $user_id, 'tt_view_player_medical' )
            && \TT\Core\FeatureRegistry::isEnabled( 'journey_medical_visibility' ) ) {
            $out[] = self::LEVEL_MEDICAL;
        }

        return $out;
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
        $out = [ self::LEVEL_PUBLIC ];

        if ( user_can( $user_id, 'tt_edit_evaluations' ) || user_can( $user_id, 'tt_edit_settings' ) ) {
            $out[] = self::LEVEL_COACHING_STAFF;
        }

        // Cap alone, no staff test — see the class docblock. The timeline
        // is already scoped to one authorised player, so a player reading a
        // medical entry here is reading their own, which is the point of
        // the seed granting them `player_injuries:read` at self scope.
        //
        // #1538 — two conditions, not one: the feature switch hides medical
        // entries even from staff holding the cap, without touching the cap.
        if ( user_can( $user_id, 'tt_view_player_medical' )
            && \TT\Core\FeatureRegistry::isEnabled( 'journey_medical_visibility' ) ) {
            $out[] = self::LEVEL_MEDICAL;
        }

        if ( user_can( $user_id, 'tt_view_player_safeguarding' ) ) {
            $out[] = self::LEVEL_SAFEGUARDING;
        }

        return $out;
    }
}
