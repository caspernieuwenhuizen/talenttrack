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
        // #1985 — goal statuses carry their own 'goal status' gettext context
        // so their youth-oriented labels can diverge from the generic
        // Pending/In Progress vocabulary other modules share. The Dutch
        // `pending` reads "In ontwikkeling" here without renaming the generic
        // "Pending" elsewhere.
        //
        // #3471 — `tt_goals.status` does not always hold the snake_case code.
        // The `goal_status` lookup is seeded in TitleCase, and a write path
        // that picks a lookup label stores that label, so the column also
        // carries "In Progress" and "Pending Approval" (and, per
        // GoalPastTargetDateAlert, display-cased strings from older imports).
        // Normalising first is what `bucketFor()` and `statusChipClass()` in
        // FrontendMyGoalsView already do — without it here, the goal landed
        // in the right column wearing an untranslated English chip.
        switch ( self::normaliseCode( $code ) ) {
            case 'pending':          return _x( 'Pending', 'goal status', 'talenttrack' );
            case 'pending_approval': return _x( 'Pending approval', 'goal status', 'talenttrack' );
            case 'in_progress':      return _x( 'In Progress', 'goal status', 'talenttrack' );
            case 'completed':        return _x( 'Completed', 'goal status', 'talenttrack' );
            case 'on_hold':          return _x( 'On Hold', 'goal status', 'talenttrack' );
            case 'cancelled':        return _x( 'Cancelled', 'goal status', 'talenttrack' );
            default:                 return self::humanise( $code );
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
        // #3471 — same normalisation as goalStatus: the `player_status`
        // lookup is seeded in TitleCase too, so a display-cased value must
        // still resolve rather than falling through to humanise().
        switch ( self::normaliseCode( $code ) ) {
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
            case 'Suspended': return __( 'Suspended', 'talenttrack' );
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
            case 'coach':               return _x( 'Coach', 'role label', 'talenttrack' );
            // `head_coach` is the role-key the People module ships out
            // of the box (see `PeopleRepository::ROLE_TYPES`). The
            // earlier translator omitted it, so the team-detail page's
            // staff list rendered the raw key on Dutch installs.
            case 'head_coach':          return _x( 'Head coach', 'role label', 'talenttrack' );
            case 'assistant_coach':     return _x( 'Assistant coach', 'role label', 'talenttrack' );
            case 'manager':             return _x( 'Team manager', 'role label', 'talenttrack' );
            case 'team_manager':        return _x( 'Team manager', 'role label', 'talenttrack' );
            case 'head_of_development': return _x( 'Head of development', 'role label', 'talenttrack' );
            case 'staff':               return _x( 'Staff', 'role label', 'talenttrack' );
            case 'physio':              return _x( 'Physio', 'role label', 'talenttrack' );
            case 'scout':               return _x( 'Scout', 'role label', 'talenttrack' );
            case 'parent':              return _x( 'Parent', 'role label', 'talenttrack' );
            case 'other':               return _x( 'Other', 'role label', 'talenttrack' );
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
            case 'club_admin':          return _x( 'Club admin', 'role label', 'talenttrack' );
            case 'head_of_development': return _x( 'Head of development', 'role label', 'talenttrack' );
            case 'head_coach':          return _x( 'Head coach', 'role label', 'talenttrack' );
            case 'assistant_coach':     return _x( 'Assistant coach', 'role label', 'talenttrack' );
            case 'manager':             return _x( 'Manager', 'role label', 'talenttrack' );
            case 'physio':              return _x( 'Physio', 'role label', 'talenttrack' );
            case 'team_member':         return _x( 'Team member', 'role label', 'talenttrack' );
            case 'scout':               return _x( 'Scout', 'role label', 'talenttrack' );
            case 'parent':              return _x( 'Parent', 'role label', 'talenttrack' );
            // #4523 — RolesPage kept its own copy of this map with this
            // one extra key; it now reads here.
            case 'player':              return _x( 'Player', 'role label', 'talenttrack' );
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
        //
        // #4557 — a club that renamed a seeded role ("Head coach" to
        // "Trainer A-selectie") sees its own name. The stored label counts
        // as renamed only when it differs from the seed's default for that
        // key, ignoring case: installs seeded before #4523 still hold the
        // Title Case original ("Head Coach"), which is not a rename.
        $stored  = self::storedFunctionalRoleLabel( $key, $entity_id );
        $seed    = self::seededFunctionalRoleLabel( $key );
        $renamed = $stored !== '' && $seed !== '' && strcasecmp( $stored, $seed ) !== 0;

        if ( $entity_id !== null && $entity_id > 0 ) {
            $tx = ( new TranslationsRepository() )->translate(
                TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE,
                $entity_id,
                'label',
                self::currentLocale(),
                ''
            );
            // Migration 0085 stored the Dutch of the original seed label
            // ("Hoofdtrainer") as a translation of every seeded row. After a
            // rename that row translates the old name, so it is skipped; a
            // translation somebody entered for the new name still wins.
            if ( $tx !== '' && ! ( $renamed && self::isSeedTranslation( $tx, $seed ) ) ) return $tx;
        }

        if ( $renamed ) return $stored;

        switch ( strtolower( $key ) ) {
            case 'head_coach':          return _x( 'Head coach', 'role label', 'talenttrack' );
            case 'assistant_coach':     return _x( 'Assistant coach', 'role label', 'talenttrack' );
            case 'manager':             return _x( 'Manager', 'role label', 'talenttrack' );
            case 'physio':              return _x( 'Physio', 'role label', 'talenttrack' );
            // #3257 — the kit-manager seat, split out of Staff so the
            // Physio functional role can carry the injury grant alone.
            case 'kit_manager':         return _x( 'Kit manager', 'role label', 'talenttrack' );
            case 'head_of_development': return _x( 'Head of development', 'role label', 'talenttrack' );
            case 'mentor':              return _x( 'Mentor', 'role label', 'talenttrack' );
            case 'other':               return _x( 'Other', 'role label', 'talenttrack' );
            default:                    return null;
        }
    }

    /** @var array<int, array{by_id: array<int, string>, by_key: array<string, string>}> club id => stored labels */
    private static array $functional_role_labels = [];

    /**
     * The label stored on the club's functional role row, by id when the
     * caller has it and by key otherwise; '' when there is no row. One read
     * per club per request.
     */
    private static function storedFunctionalRoleLabel( string $key, ?int $entity_id ): string {
        $club = (int) \TT\Infrastructure\Tenancy\CurrentClub::id();
        if ( ! isset( self::$functional_role_labels[ $club ] ) ) {
            global $wpdb;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, role_key, label FROM {$wpdb->prefix}tt_functional_roles WHERE club_id = %d",
                $club
            ) );
            $labels = [ 'by_id' => [], 'by_key' => [] ];
            foreach ( (array) $rows as $row ) {
                $f     = (array) $row;
                $label = trim( (string) ( $f['label'] ?? '' ) );
                $labels['by_id'][ (int) ( $f['id'] ?? 0 ) ] = $label;
                $labels['by_key'][ strtolower( (string) ( $f['role_key'] ?? '' ) ) ] = $label;
            }
            self::$functional_role_labels[ $club ] = $labels;
        }
        $labels = self::$functional_role_labels[ $club ];
        if ( $entity_id !== null && $entity_id > 0 && isset( $labels['by_id'][ $entity_id ] ) ) {
            return $labels['by_id'][ $entity_id ];
        }
        return $labels['by_key'][ strtolower( $key ) ] ?? '';
    }

    /** The English label the Activator seeds for this functional role key; '' for a key it does not seed. */
    private static function seededFunctionalRoleLabel( string $key ): string {
        foreach ( \TT\Core\Activator::defaultFunctionalRoleDefinitions() as $def ) {
            if ( strtolower( (string) $def['key'] ) === strtolower( $key ) ) return (string) $def['label'];
        }
        return '';
    }

    /**
     * Whether `$tx` is only the translation of a seed label: the sentence-case
     * seed, or the Title Case spelling installs seeded before #4523 hold,
     * either through the plain msgid migration 0085 used or the `role label`
     * context.
     */
    private static function isSeedTranslation( string $tx, string $seed ): bool {
        $title = str_replace( ' Of ', ' of ', ucwords( $seed ) );
        foreach ( array_unique( [ $seed, $title ] ) as $msgid ) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralContext
            if ( $tx === __( $msgid, 'talenttrack' ) || $tx === _x( $msgid, 'role label', 'talenttrack' ) ) return true;
        }
        return false;
    }

    /** Forget the stored functional role labels read this request (after a rename, and in tests). */
    public static function flushFunctionalRoleLabels(): void {
        self::$functional_role_labels = [];
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
     * The human label for a position code, in the reader's locale.
     *
     * Three sources, in this order, and the order is the whole point:
     *
     * 1. **The lookup row's own label.** A position the academy added
     *    through Configuration → Lookups is stored in `tt_lookups` with its
     *    label in `tt_translations`, and until #3276 no read surface asked
     *    for it. Twelve surfaces printed the raw key instead — a profile
     *    reading `Verdedigende middenvelder · rechter_middenvelder` because
     *    two of the four positions were seeded and two were the operator's.
     *    This branch is also what lets an academy rename a seeded position
     *    to its own vocabulary and be obeyed.
     * 2. **The seeded long form through gettext.** The 11 Activator codes
     *    (GK / CB / LB / RB / CDM / CM / CAM / LW / RW / ST / CF) carry no
     *    `tt_translations` row on a stock install — their lookup `name` IS
     *    the code, so step 1 hands back `CDM` and this step turns it into
     *    *Defensive midfielder* / *Verdedigende middenvelder*. Resolving
     *    step 1 first without this guard is what would silently downgrade
     *    every seeded position on every install to its abbreviation.
     * 3. **Humanised.** A code with a row but no label anywhere reads
     *    `Rechter Middenvelder`, never `rechter_middenvelder`. Same last
     *    resort the other translators in this class use.
     *
     * The code itself stays the matching key throughout. Chemistry buckets,
     * formation slots and squad-step matching key on the stored value and
     * must not start keying on this label — see
     * `PositionLabelResolutionTest`.
     */
    public static function positionLabel( string $code ): string {
        $code = trim( $code );
        if ( $code === '' ) return '';

        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $resolved = trim( LookupTranslator::byTypeAndName( 'position', $code ) );
            // A resolver that hands the code straight back has told us
            // nothing — either there is no row, or the row is a seeded one
            // whose name is the code. Both belong to step 2.
            if ( $resolved !== '' && strcasecmp( $resolved, $code ) !== 0 ) {
                return $resolved;
            }
        }

        $longform = self::positionLongForm( $code );
        if ( $longform !== $code ) {
            return __( $longform, 'talenttrack' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- delegated dynamic key resolved from positionLongForm()'s static switch below; the canonical English strings still ship as literal __() calls so the extractor sees them.
        }

        // A multi-word key that reached us upper-cased (the player report
        // folds codes to upper case to match on them) reads the same as its
        // stored lower-case form, not as `RECHTER MIDDENVELDER`.
        if ( preg_match( '/[_\- ]/', $code ) === 1 && strtoupper( $code ) === $code ) {
            $code = strtolower( $code );
        }
        return self::humanise( $code );
    }

    /**
     * The first of a player's stored `preferred_positions`, as its name.
     *
     * The column holds a JSON array (`["CB","RB"]`) on modern rows and a
     * comma-separated string on legacy ones. Splitting the JSON on commas
     * printed `["CB"` on the exports that show one position per player.
     * Empty or unparseable input gives an empty string.
     */
    public static function primaryPositionLabel( string $stored ): string {
        $stored = trim( $stored );
        if ( $stored === '' ) return '';
        $decoded = json_decode( $stored, true );
        $codes   = is_array( $decoded ) ? $decoded : explode( ',', $stored );
        foreach ( $codes as $code ) {
            $code = is_scalar( $code ) ? trim( (string) $code ) : '';
            if ( $code !== '' ) return self::positionLabel( $code );
        }
        return '';
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
            // The tournament squad step (SquadStep::positionCodes()) uses
            // DM / AM for the same roles the seeded vocabulary calls
            // CDM / CAM. Same role, same name; the codes stay distinct.
            case 'CDM':
            case 'DM':  return 'Defensive midfielder';
            case 'CM':  return 'Central midfielder';
            case 'CAM':
            case 'AM':  return 'Attacking midfielder';
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

    /**
     * #3471 — the inverse of `humanise()`: fold a stored value to the
     * snake_case code the switches are keyed on, so a TitleCase lookup label
     * ("In Progress", "Pending Approval") resolves to the same arm as the
     * code ("in_progress", "pending_approval").
     *
     * Same expression `FrontendMyGoalsView::bucketFor()` uses, so the label,
     * the board bucket and the chip class cannot disagree about what a status
     * string is.
     */
    private static function normaliseCode( string $code ): string {
        return strtolower( str_replace( [ ' ', '-' ], '_', trim( $code ) ) );
    }
}
