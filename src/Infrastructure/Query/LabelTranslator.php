<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

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
            case PlayerStatus::ACTIVE:    return __( 'Active', 'talenttrack' );
            case PlayerStatus::INACTIVE:  return __( 'Inactive', 'talenttrack' );
            case PlayerStatus::TRIAL:     return __( 'Trial', 'talenttrack' );
            case PlayerStatus::RELEASED:  return __( 'Released', 'talenttrack' );
            case PlayerStatus::GRADUATED: return __( 'Graduated', 'talenttrack' );
            case 'deleted':               return __( 'Deleted', 'talenttrack' );
            default:                      return self::humanise( $code );
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
            // `head_coach` is the role-key the People module ships out
            // of the box (see `PeopleRepository::ROLE_TYPES`). The
            // earlier translator omitted it, so the team-detail page's
            // staff list rendered the raw key on Dutch installs.
            case 'head_coach':          return __( 'Head coach', 'talenttrack' );
            case 'assistant_coach':     return __( 'Assistant coach', 'talenttrack' );
            case 'manager':             return __( 'Team manager', 'talenttrack' );
            case 'team_manager':        return __( 'Team manager', 'talenttrack' );
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
    public static function authRoleLabel( string $key, ?int $entity_id = null ): ?string {
        // #0090 Phase 4 — consult tt_translations first when caller
        // passes the row id. Falls through to the gettext switch for
        // string-only callers and seeded keys without translations
        // backfilled yet.
        if ( $entity_id !== null && $entity_id > 0 ) {
            $tx = ( new TranslationsRepository() )->translate(
                TranslatableFieldRegistry::ENTITY_ROLE,
                $entity_id,
                'label',
                self::currentLocale(),
                ''
            );
            if ( $tx !== '' ) return $tx;
        }
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
    public static function functionalRoleLabel( string $key, ?int $entity_id = null ): ?string {
        // #0090 Phase 4 — consult tt_translations first when caller
        // passes the row id. Falls through to the gettext switch for
        // string-only callers and seeded keys without translations
        // backfilled yet.
        if ( $entity_id !== null && $entity_id > 0 ) {
            $tx = ( new TranslationsRepository() )->translate(
                TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE,
                $entity_id,
                'label',
                self::currentLocale(),
                ''
            );
            if ( $tx !== '' ) return $tx;
        }
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

    private static function currentLocale(): string {
        if ( function_exists( 'determine_locale' ) ) return (string) determine_locale();
        if ( function_exists( 'get_locale' ) ) return (string) get_locale();
        return 'en_US';
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
        if ( $key === '' ) return null;
        // #1121 — route through the lookup translator so operator-
        // added activity_type rows (e.g. `meeting` → "Bespreking" in
        // the live `activity_type` lookup) render their Dutch label.
        // The hardcoded switch below was stale vs. the seeded
        // `activity_type` lookup ('game', 'training', 'other',
        // 'tournament', 'meeting', …) and returned null for any key
        // outside the legacy list, which made the Team-detail
        // Aankomende-activiteiten table show blank cells.
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'activity_type', $key );
            if ( is_string( $label ) && $label !== '' && $label !== $key ) {
                return $label;
            }
        }
        // Legacy keys that pre-date the unified `activity_type` lookup.
        // Kept so journey-event summaries + cohort transitions referencing
        // archived keys keep rendering their canonical English.
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
        }
        // Final fallback: humanise the key (`meeting` → "Meeting").
        // Better than a blank cell when both lookup + legacy switch miss.
        return ucfirst( str_replace( '_', ' ', strtolower( $key ) ) );
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
     * Translate `tt_formation_templates.name` for the system formations
     * seeded by 0032 / 0065 / Activator. The shape digits stay literal;
     * only the descriptive word is localised (#1477). Custom formations a
     * club adds fall through to their typed name unchanged.
     */
    public static function formationName( string $name ): string {
        switch ( $name ) {
            case 'Neutral 4-3-3':     return __( 'Neutral 4-3-3', 'talenttrack' );
            case 'Possession 4-3-3':  return __( 'Possession 4-3-3', 'talenttrack' );
            case 'Counter 4-3-3':     return __( 'Counter 4-3-3', 'talenttrack' );
            case 'Press-heavy 4-3-3': return __( 'Press-heavy 4-3-3', 'talenttrack' );
            // #1477 — the 4-4-2 / 3-5-2 / 4-2-3-1 top-ups (0065) and the
            // new offensive 3-4-3 diamond were never localised.
            case 'Neutral 4-4-2':     return __( 'Neutral 4-4-2', 'talenttrack' );
            case 'Neutral 3-5-2':     return __( 'Neutral 3-5-2', 'talenttrack' );
            case 'Neutral 4-2-3-1':   return __( 'Neutral 4-2-3-1', 'talenttrack' );
            case 'Offensive 3-4-3 (diamond)': return __( 'Offensive 3-4-3 (diamond)', 'talenttrack' );
            default:                  return $name;
        }
    }

    /**
     * v3.110.101 — translate `tt_lookups.name` for the 11 position
     * codes seeded by the Activator (GK / CB / LB / RB / CDM / CM /
     * CAM / LW / RW / ST / CF). The codes are stored uppercase; the
     * long name is what operators want to read in dropdowns.
     *
     * Returns the long form. Unknown codes fall through unchanged
     * (custom positions an admin added stay verbatim).
     */
    public static function positionLabel( string $code ): string {
        $longform = self::positionLongForm( $code );
        return $longform === $code ? $code : __( $longform, 'talenttrack' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- delegated dynamic key resolved from positionLongForm()'s static switch above; the canonical English strings still ship as literal __() calls so the extractor sees them.
    }

    /**
     * #902 — return the canonical English long form for a position
     * code, without going through gettext. Migration 0141 needs this
     * so it can drive `switch_to_locale() → __()` from the long form
     * (the operator-facing `tt_translations` store).
     */
    public static function positionLongForm( string $code ): string {
        switch ( strtoupper( $code ) ) {
            case 'GK':  return 'Goalkeeper';
            case 'CB':  return 'Centre back';
            case 'LB':  return 'Left back';
            case 'RB':  return 'Right back';
            case 'CDM': return 'Defensive midfielder';
            case 'CM':  return 'Central midfielder';
            case 'CAM': return 'Attacking midfielder';
            case 'LW':  return 'Left winger';
            case 'RW':  return 'Right winger';
            case 'ST':  return 'Striker';
            case 'CF':  return 'Centre forward';
        }
        return $code;
    }

    /**
     * #902 — extractor anchors for the 11 position long forms.
     *
     * `positionLabel()` resolves the long form dynamically via
     * `positionLongForm()` and then calls `__($longform)` — but the
     * gettext extractor needs to see the canonical English literals
     * to generate `.po` msgids. This unreachable block keeps the
     * literals visible. Anchoring matches the convention used by
     * other dynamic-key translators in this file (e.g.
     * `vctExerciseCategory`'s sibling extractor anchor).
     */
    private static function positionLabelExtractorAnchors(): void {
        __( 'Goalkeeper',           'talenttrack' );
        __( 'Centre back',          'talenttrack' );
        __( 'Left back',            'talenttrack' );
        __( 'Right back',           'talenttrack' );
        __( 'Defensive midfielder', 'talenttrack' );
        __( 'Central midfielder',   'talenttrack' );
        __( 'Attacking midfielder', 'talenttrack' );
        __( 'Left winger',          'talenttrack' );
        __( 'Right winger',         'talenttrack' );
        __( 'Striker',              'talenttrack' );
        __( 'Centre forward',       'talenttrack' );
    }

    /**
     * #0095 VCT — exercise category translations. Wraps canonical
     * English in `__()` so the .pot extractor picks them up (the
     * companion to migration 0124's direct tt_translations writes; see
     * memory `feedback_lookup_seed_translations`). Unknown codes fall
     * back to humanise().
     */
    public static function vctExerciseCategory( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'warmup':       return __( 'Warm-up',     'talenttrack' );
            case 'technical':    return __( 'Technical',   'talenttrack' );
            case 'sided_game':   return __( 'Sided game',  'talenttrack' );
            case 'conditioning': return __( 'Conditioning','talenttrack' );
            case 'finishing':    return __( 'Finishing',   'talenttrack' );
            case 'cool_down':    return __( 'Cool-down',   'talenttrack' );
            default:             return self::humanise( $code );
        }
    }

    /**
     * #902 — translates a `tt_lookups.player_value` row name. The 8
     * seed values (Commitment / Coachability / Leadership /
     * Resilience / Communication / Work ethic / Fair play / Ambition)
     * land in migration 0031 but were never wrapped in `__()`, so the
     * .pot extractor never saw them. This translator anchors them so
     * the .po files have msgids and migration 0142 can backfill
     * `tt_translations` from the canonical English long forms.
     *
     * Unknown values fall through to `humanise()` so operator-added
     * custom values render reasonably without a translator pass.
     */
    public static function playerValueLabel( string $name ): string {
        switch ( $name ) {
            case 'Commitment':    return __( 'Commitment',    'talenttrack' );
            case 'Coachability':  return __( 'Coachability',  'talenttrack' );
            case 'Leadership':    return __( 'Leadership',    'talenttrack' );
            case 'Resilience':    return __( 'Resilience',    'talenttrack' );
            case 'Communication': return __( 'Communication', 'talenttrack' );
            case 'Work ethic':    return __( 'Work ethic',    'talenttrack' );
            case 'Fair play':     return __( 'Fair play',     'talenttrack' );
            case 'Ambition':      return __( 'Ambition',      'talenttrack' );
            default:              return self::humanise( $name );
        }
    }

    /**
     * #0095 VCT — tactical theme translations.
     */
    public static function vctTacticalTheme( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'build_up':    return __( 'Build-up',       'talenttrack' );
            case 'pressing':    return __( 'Pressing',       'talenttrack' );
            case 'transition':  return __( 'Transition',     'talenttrack' );
            case 'counter':     return __( 'Counter-attack', 'talenttrack' );
            case 'defending':   return __( 'Defending',      'talenttrack' );
            case 'finishing':   return __( 'Finishing',      'talenttrack' );
            case 'set_pieces':  return __( 'Set pieces',     'talenttrack' );
            case '1v1_duels':   return __( '1v1 duels',      'talenttrack' );
            case 'possession':  return __( 'Possession',     'talenttrack' );
            case 'mixed':       return __( 'Mixed',          'talenttrack' );
            default:            return self::humanise( $code );
        }
    }

    /**
     * #0095 VCT — match-day context translations. Most codes are
     * universal football abbreviations (MD-N / MD+N) that stay the
     * same in every locale; the bare `MD` token and the `NONE`
     * sentinel get longer translations.
     */
    public static function vctMdContext( string $code ): string {
        switch ( $code ) {
            case 'MD-4':
            case 'MD-3':
            case 'MD-2':
            case 'MD-1':
            case 'MD+1':
            case 'MD+2':
                return $code;
            case 'MD':   return __( 'Match day',           'talenttrack' );
            case 'NONE': return __( 'No match context',    'talenttrack' );
            default:     return self::humanise( $code );
        }
    }

    /**
     * #0095 VCT — intensity band labels. Numeric value (1–10) stays
     * the same in every locale; the prefix is localised.
     */
    public static function vctIntensityBand( string $code ): string {
        if ( preg_match( '/^band_(\d{1,2})$/', strtolower( $code ), $m ) ) {
            $n = (int) $m[1];
            if ( $n >= 1 && $n <= 10 ) {
                /* translators: %d is the band number (1-10). */
                return sprintf( __( 'Intensity band %d', 'talenttrack' ), $n );
            }
        }
        return self::humanise( $code );
    }

    /**
     * #0095 VCT — VCT-session lifecycle status. Values match the ENUM
     * on tt_vct_sessions.status (migration 0122).
     */
    public static function vctStatus( string $code ): string {
        switch ( strtolower( $code ) ) {
            case 'draft':     return __( 'Draft',     'talenttrack' );
            case 'published': return __( 'Published', 'talenttrack' );
            case 'completed': return __( 'Completed', 'talenttrack' );
            case 'archived':  return __( 'Archived',  'talenttrack' );
            default:          return self::humanise( $code );
        }
    }

    /**
     * Fallback: "in_progress" → "In Progress".
     */
    private static function humanise( string $code ): string {
        return ucwords( str_replace( [ '_', '-' ], ' ', $code ) );
    }
}
