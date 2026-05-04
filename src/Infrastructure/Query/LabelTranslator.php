<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LabelTranslator — convert internal status/priority codes into translated
 * human-readable labels.
 *
 * Replaces the ucwords(str_replace('_',' ',$value)) pattern used in v2.3.0
 * and earlier, which was untranslatable.
 *
 * Falls back to the humanised raw code if an unknown value is passed —
 * never breaks rendering, just loses the translation for that case.
 */
class LabelTranslator {

    public static function goalStatus( string $code ): string {
        switch ( $code ) {
            case 'pending':      return __( 'Pending', 'talenttrack' );
            case 'in_progress':  return __( 'In Progress', 'talenttrack' );
            case 'completed':    return __( 'Completed', 'talenttrack' );
            case 'on_hold':      return __( 'On Hold', 'talenttrack' );
            case 'cancelled':    return __( 'Cancelled', 'talenttrack' );
            default:             return self::humanise( $code );
        }
    }

    public static function goalPriority( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'low':    return __( 'Low', 'talenttrack' );
            case 'medium': return __( 'Medium', 'talenttrack' );
            case 'high':   return __( 'High', 'talenttrack' );
            default:       return self::humanise( $code );
        }
    }

    public static function playerStatus( string $code ): string {
        switch ( $code ) {
            case 'active':   return __( 'Active', 'talenttrack' );
            case 'inactive': return __( 'Inactive', 'talenttrack' );
            case 'trial':    return __( 'Trial', 'talenttrack' );
            case 'released': return __( 'Released', 'talenttrack' );
            case 'deleted':  return __( 'Deleted', 'talenttrack' );
            default:         return self::humanise( $code );
        }
    }

    public static function attendanceStatus( string $name ): string {
        switch ( $name ) {
            case 'Present': return __( 'Present', 'talenttrack' );
            case 'Absent':  return __( 'Absent', 'talenttrack' );
            case 'Late':    return __( 'Late', 'talenttrack' );
            case 'Injured': return __( 'Injured', 'talenttrack' );
            case 'Excused': return __( 'Excused', 'talenttrack' );
            default:        return $name;
        }
    }

    /**
     * #0063 — translate `tt_people.role_type` codes for the People
     * page roles column. The codes match the allowlist in
     * `PeopleRepository::ROLE_TYPES`.
     */
    public static function roleType( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'coach':               return __( 'Coach', 'talenttrack' );
            case 'assistant_coach':     return __( 'Assistant coach', 'talenttrack' );
            case 'manager':             return __( 'Team manager', 'talenttrack' );
            case 'head_of_development': return __( 'Head of Development', 'talenttrack' );
            case 'staff':               return __( 'Staff', 'talenttrack' );
            case 'physio':              return __( 'Physio', 'talenttrack' );
            case 'scout':               return __( 'Scout', 'talenttrack' );
            case 'parent':              return __( 'Parent', 'talenttrack' );
            case 'other':               return __( 'Other', 'talenttrack' );
            default:                    return self::humanise( $code );
        }
    }

    /**
     * #0063 — translate `tt_people.status`. Mirrors `playerStatus()`
     * but covers the people-specific values.
     */
    public static function personStatus( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'active':   return __( 'Active', 'talenttrack' );
            case 'inactive': return __( 'Inactive', 'talenttrack' );
            case 'archived': return __( 'Archived', 'talenttrack' );
            default:         return self::humanise( $code );
        }
    }

    /**
     * i18n audit (May 2026) Bundle 4 — translate `tt_roles.label` for
     * the 9 system roles seeded by `Activator::defaultRoleDefinitions()`.
     * Custom roles added by clubs render their typed name; only the
     * stable seeded labels resolve here.
     */
    public static function authRoleLabel( string $key ): ?string {
        switch ( strtolower( $key ) ) {
            case 'club_admin':          return __( 'Club Admin', 'talenttrack' );
            case 'head_of_development': return __( 'Head of Development', 'talenttrack' );
            case 'head_coach':          return __( 'Head Coach', 'talenttrack' );
            case 'assistant_coach':     return __( 'Assistant Coach', 'talenttrack' );
            case 'manager':             return __( 'Manager', 'talenttrack' );
            case 'physio':              return __( 'Physio', 'talenttrack' );
            case 'team_member':         return __( 'Team Member', 'talenttrack' );
            case 'scout':               return __( 'Scout', 'talenttrack' );
            case 'parent':              return __( 'Parent', 'talenttrack' );
            default:                    return null;
        }
    }

    /**
     * i18n audit (May 2026) Bundle 4 — translate `tt_functional_roles.label`
     * for the 6 system functional roles seeded by Activator + 0048.
     * Returns null for unknown keys so callers can fall back to the
     * row's typed `label` for custom roles a club has added.
     */
    public static function functionalRoleLabel( string $key ): ?string {
        switch ( strtolower( $key ) ) {
            case 'head_coach':          return __( 'Head Coach', 'talenttrack' );
            case 'assistant_coach':     return __( 'Assistant Coach', 'talenttrack' );
            case 'manager':             return __( 'Manager', 'talenttrack' );
            case 'physio':              return __( 'Physio', 'talenttrack' );
            case 'head_of_development': return __( 'Head of Development', 'talenttrack' );
            case 'mentor':              return __( 'Mentor', 'talenttrack' );
            case 'other':               return __( 'Other', 'talenttrack' );
            default:                    return null;
        }
    }

    /**
     * v3.92.7 — translate `tt_lookups.name` for the seeded
     * activity_type rows (training / match / clinic / team_meeting /
     * methodology, plus game subtypes added by migration 0046).
     * Returns null for unknown keys so callers can fall back to the
     * row's typed `label` (or a humanised key like
     * `ucfirst(str_replace('_', ' ', $key))` for stale data).
     *
     * The activity-type pill on the activities list is rendered via
     * `LookupPill::render('activity_type', $key)` which already routes
     * through the translation layer; this helper is for surfaces that
     * render the type label inline (e.g. activity-detail card meta
     * row, journey-event summaries, cohort transitions).
     */
    public static function activityType( string $key ): ?string {
        switch ( strtolower( $key ) ) {
            case 'training':      return __( 'Training', 'talenttrack' );
            case 'match':         return __( 'Match', 'talenttrack' );
            case 'clinic':        return __( 'Clinic', 'talenttrack' );
            case 'team_meeting':  return __( 'Team meeting', 'talenttrack' );
            case 'methodology':   return __( 'Methodology', 'talenttrack' );
            // Game subtypes seeded by migration 0046 (#0027).
            case 'friendly':      return __( 'Friendly match', 'talenttrack' );
            case 'cup':           return __( 'Cup match', 'talenttrack' );
            case 'league':        return __( 'League match', 'talenttrack' );
            case 'tournament':    return __( 'Tournament', 'talenttrack' );
            default:              return null;
        }
    }

    /**
     * i18n audit (May 2026) Bundle 7 — translate `tt_trial_tracks.name`
     * for the 3 system tracks seeded by 0036. Substituted into the
     * parent-facing trial letters via `{track_name}`, so getting Dutch
     * here makes the letter body read in Dutch too.
     */
    public static function trialTrackName( string $name ): string {
        switch ( $name ) {
            case 'Standard':   return __( 'Standard', 'talenttrack' );
            case 'Scout':      return __( 'Scout', 'talenttrack' );
            case 'Goalkeeper': return __( 'Goalkeeper', 'talenttrack' );
            default:           return $name;
        }
    }

    /**
     * i18n audit (May 2026) Bundle 7 — translate `tt_formation_templates.name`
     * for the 4 system formations seeded by 0032 + Activator.
     */
    public static function formationName( string $name ): string {
        switch ( $name ) {
            case 'Neutral 4-3-3':     return __( 'Neutral 4-3-3', 'talenttrack' );
            case 'Possession 4-3-3':  return __( 'Possession 4-3-3', 'talenttrack' );
            case 'Counter 4-3-3':     return __( 'Counter 4-3-3', 'talenttrack' );
            case 'Press-heavy 4-3-3': return __( 'Press-heavy 4-3-3', 'talenttrack' );
            default:                  return $name;
        }
    }

    /**
     * Fallback: "in_progress" → "In Progress".
     */
    private static function humanise( string $code ): string {
        return ucwords( str_replace( [ '_', '-' ], ' ', $code ) );
    }
}
