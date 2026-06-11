<?php
namespace TT\Modules\Configuration;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PdpVerdictDecision;
use TT\Domain\Vocabularies\Lookups\TournamentFormation;
use TT\Domain\Vocabularies\Lookups\TournamentOpponentLevel;
use TT\Domain\Vocabularies\Lookups\TrialCaseStatus;

/**
 * LookupCanonicalSeeds (#987 v4.12.0) — single source of truth for the
 * canonical English internal-key values per `lookup_type`.
 *
 * Background. `tt_lookups.name` is supposed to be a stable, English,
 * internal-key-style identifier. The architecture treats it as such:
 * `LookupTranslator::name($row)` resolves through `tt_translations` first
 * (per-locale display labels) and only falls back to the `name` column
 * as the immovable backstop. But across the lifetime of the plugin,
 * admins were able to type whatever they wanted into `name` and never
 * populate `tt_translations`. Result: pilot installs end up with rows
 * like `name = 'Aanwezig'` (Dutch) sitting next to `name = 'present'`
 * (lowercase English), with nothing in `tt_translations` to fall back to.
 *
 * This class is the lookup-table of canonical English values per
 * `lookup_type` extracted from every seed migration that has shipped
 * (0001, 0027, 0033, 0037, 0042, 0047, 0048, 0051, 0058, 0060, 0091,
 * 0093, 0098, 0110-0117, 0124). It powers:
 *
 *   1. Migration 0132's drift audit — for every row in `tt_lookups`
 *      whose `name` doesn't match any value listed here for that
 *      `lookup_type`, an audit-log entry is written so an operator
 *      can review and accept.
 *
 *   2. The drift-review admin tool — when the operator clicks
 *      "Accept", we rewrite `tt_lookups.name` to the suggested
 *      canonical, then preserve the operator-visible label in
 *      `tt_translations` for the heuristic-detected locale.
 *
 * The map is intentionally a static array. No DB lookups, no IO. If a
 * new lookup_type ships, add it here; if a row in production drifts
 * from the canonical, the migration flags it.
 *
 * Reverse map (`reverseFor()`) is used by the heuristic: a drifted
 * value like 'Aanwezig' maps back to the canonical 'Present' via the
 * NL -> EN translation table built from migration 0060's seeds.
 */
final class LookupCanonicalSeeds {

    /**
     * Canonical English values per lookup_type.
     *
     * @return array<string, list<string>>
     */
    public static function canonicalMap(): array {
        return [
            // 0001_initial_schema — foundational vocab
            'eval_category' => [ 'Technical', 'Tactical', 'Physical', 'Mental' ],
            'eval_type'     => [ 'Training', 'Match', 'Friendly' ],
            'position'      => [ 'GK', 'CB', 'LB', 'RB', 'CDM', 'CM', 'CAM', 'LW', 'RW', 'ST', 'CF' ],
            'foot_option'   => [ 'Left', 'Right', 'Both' ],
            'age_group'     => [ 'U8', 'U10', 'U12', 'U14', 'U16', 'U19', 'Senior', 'U7', 'U9', 'U11', 'U13', 'U15', 'U17', 'U18', 'U20', 'U21', 'U23' ],
            'goal_status'   => [ 'Pending', 'In Progress', 'Completed', 'On Hold', 'Cancelled', 'Proposed', 'Approved', 'Rejected' ],
            'goal_priority' => [ 'Low', 'Medium', 'High' ],

            // 0001 + 0047 + 0093
            'attendance_status' => [ 'Present', 'Absent', 'Late', 'Injured', 'Excused' ],

            // 0033_activity_type_lookup + 0040
            'activity_type' => [ 'Training', 'Match', 'Friendly', 'Tournament', 'Trial', 'Other' ],

            // 0040 + 0049 — activity status (kebab keys)
            'activity_status' => [
                'draft', 'planned', 'scheduled', 'in_progress', 'completed',
                'cancelled', 'postponed', 'no_show',
            ],

            // 0013_competition_type_lookup
            'competition_type' => [
                'League', 'Cup', 'Tournament', 'Friendly', 'Indoor',
            ],

            // 0027 — game subtype
            'game_subtype' => [ 'Eleven-a-side', 'Seven-a-side', 'Futsal', 'Indoor' ],

            // 0037_player_journey
            'journey_event_type' => [
                'Trial', 'Signing', 'Age-group promotion', 'Position change',
                'Injury', 'Return to play', 'Release', 'Graduation', 'Transfer',
                'Loan', 'Recall',
            ],

            // 0031 — player_value (deprecated label?)
            'player_value' => [ 'Respect', 'Teamwork', 'Discipline', 'Effort', 'Fair play' ],

            // 0048 — cert_type
            'cert_type' => [
                'UEFA-A', 'UEFA-B', 'UEFA-C',
                'First aid', 'GDPR awareness', 'Child safeguarding',
            ],

            // 0042 — behaviour rating labels (#1377 growth-framed rewording)
            'behaviour_rating_label' => [
                'Needs support', 'Developing', 'Meeting expectations',
                'Above expectations', 'Exemplary',
            ],

            // 0042 — potential bands
            'potential_band' => [
                'Far below club level', 'Below club level', 'Club level',
                'Above club level', 'Elite potential',
            ],

            // 0058 — goal approval decision
            'goal_approval_decision' => [ 'Pending', 'Approved', 'Rejected', 'Changes requested' ],

            // 0098 — tournament lookups
            'tournament_format'    => [ 'Knockout', 'Round-robin', 'Group + knockout', 'League' ],
            // #1022 — drift fix: pull from the typed-constants source
            // of truth so this map can't drift away from migration 0098
            // + REST validation in the future.
            'tournament_formation' => TournamentFormation::ALL,
            'opponent_level'       => TournamentOpponentLevel::ALL,

            // 0110-0117 — workflow + status lookups (these ones use
            // lowercase internal keys, per migration design)
            'invitation_status' => [
                'pending', 'sent', 'opened', 'accepted', 'declined', 'expired', 'revoked',
            ],
            // #1022 — drift fix: was 'On track / Behind / Ahead / At risk
            // / Released' (none of which are actually seeded by migration
            // 0112 or accepted by PdpVerdictsRepository::ALLOWED_DECISIONS).
            // Source of truth is PdpVerdictDecision::ALL (promote / retain
            // / release / transfer).
            'pdp_verdict_decision' => PdpVerdictDecision::ALL,
            'task_status' => [
                'open', 'in_progress', 'completed', 'overdue', 'skipped', 'cancelled',
            ],
            'audience_type' => [ 'Coaches', 'Parents', 'Players', 'Staff', 'Scouts' ],
            'idea_status' => [
                'submitted', 'refining', 'ready-for-approval', 'rejected',
                'promoting', 'promoted', 'promotion-failed', 'in-progress', 'done',
            ],
            // #1022 — drift fix: was 'Open / In progress / Decision
            // pending / Accepted / Rejected' (the wrong vocabulary — those
            // look like decision outcomes, not case statuses). Source of
            // truth is TrialCaseStatus::ALL (open / extended / decided /
            // archived) per migration 0116.
            'trial_case_status' => TrialCaseStatus::ALL,
            'medium_batch_status' => [ 'Pending', 'Processing', 'Completed', 'Failed' ],

            // 0124 — VCT
            'vct_theme_status' => [ 'Draft', 'Planned', 'Active', 'Completed', 'Archived' ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function canonicalFor( string $lookup_type ): array {
        $map = self::canonicalMap();
        return $map[ $lookup_type ] ?? [];
    }

    /**
     * Reverse map (drifted-value -> canonical English) per lookup_type,
     * built from migration 0060's English+Dutch seed pairs. Used as a
     * heuristic suggestion when a row's `name` is a known Dutch
     * translation of a canonical English value.
     *
     * Lowercase comparison is the caller's responsibility — keys here
     * are the literal drifted strings the migration may encounter.
     *
     * @return array<string, array<string, string>>  lookup_type => drifted-name => canonical-name
     */
    public static function reverseMap(): array {
        return [
            'foot_option' => [
                'Links'  => 'Left',
                'Rechts' => 'Right',
                'Beide'  => 'Both',
            ],
            'age_group' => [
                'Senioren' => 'Senior',
            ],
            'goal_status' => [
                'Wachtend'     => 'Pending',
                'Bezig'        => 'In Progress',
                'Voltooid'     => 'Completed',
                'In de wacht'  => 'On Hold',
                'Geannuleerd'  => 'Cancelled',
            ],
            'goal_priority' => [
                'Laag'   => 'Low',
                'Middel' => 'Medium',
                'Hoog'   => 'High',
            ],
            'attendance_status' => [
                'Aanwezig'         => 'Present',
                'Afwezig'          => 'Absent',
                'Te laat'          => 'Late',
                'Geblesseerd'      => 'Injured',
                'Verontschuldigd'  => 'Excused',
            ],
            'eval_type' => [
                'Wedstrijd'      => 'Match',
                'Oefenwedstrijd' => 'Friendly',
            ],
            'cert_type' => [
                'EHBO'              => 'First aid',
                'AVG-bewustzijn'    => 'GDPR awareness',
                'Kinderbescherming' => 'Child safeguarding',
            ],
            'activity_type' => [
                'Training'   => 'Training',
                'Wedstrijd'  => 'Match',
                'Oefenwedstrijd' => 'Friendly',
                'Toernooi'   => 'Tournament',
                'Proeftraining' => 'Trial',
                'Overig'     => 'Other',
            ],
            'competition_type' => [
                'Competitie' => 'League',
                'Beker'      => 'Cup',
                'Toernooi'   => 'Tournament',
                'Oefenwedstrijd' => 'Friendly',
                'Zaal'       => 'Indoor',
            ],
        ];
    }

    /**
     * Best-effort canonical suggestion for a drifted name. Returns the
     * suggested English value when the heuristic matches; empty string
     * when no suggestion can be inferred (operator picks one from the
     * canonical list manually).
     */
    public static function suggestCanonicalFor( string $lookup_type, string $current_name ): string {
        $current_name = trim( $current_name );
        if ( $current_name === '' ) return '';

        // 1. Direct hit on the Dutch -> English reverse map.
        $rev = self::reverseMap();
        if ( isset( $rev[ $lookup_type ][ $current_name ] ) ) {
            return $rev[ $lookup_type ][ $current_name ];
        }

        // 2. Case-insensitive match against the canonical list — covers
        //    drift like 'present' vs 'Present' or 'GK' vs 'gk'.
        $canonical = self::canonicalFor( $lookup_type );
        if ( ! empty( $canonical ) ) {
            $needle = mb_strtolower( $current_name );
            foreach ( $canonical as $candidate ) {
                if ( mb_strtolower( $candidate ) === $needle ) {
                    return $candidate;
                }
            }
            // Whitespace / punctuation forgiveness — drop non-alnum.
            $norm_needle = preg_replace( '/[^a-z0-9]/', '', $needle );
            foreach ( $canonical as $candidate ) {
                $norm = preg_replace( '/[^a-z0-9]/', '', mb_strtolower( $candidate ) );
                if ( $norm === $norm_needle && $norm !== '' ) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * Detect whether a value is canonical for its lookup_type. Used by
     * the audit migration to decide which rows to flag.
     *
     * A value is canonical if it matches one of the canonical entries
     * exactly (case-sensitive). Empty values are treated as canonical
     * (nothing to flag — the row's a no-op).
     */
    public static function isCanonical( string $lookup_type, string $current_name ): bool {
        if ( $current_name === '' ) return true;
        $canonical = self::canonicalFor( $lookup_type );
        if ( empty( $canonical ) ) {
            // Unknown lookup_type — don't flag. We'd rather under-flag
            // than spam operators with rows we can't suggest a fix for.
            return true;
        }
        return in_array( $current_name, $canonical, true );
    }

    /**
     * Heuristic locale detection for a drifted value. Powers the
     * accept-flow: when the operator accepts a rename from 'Aanwezig'
     * to 'Present', we preserve 'Aanwezig' as the nl_NL translation
     * so dashboards still show the Dutch label.
     *
     * Returns one of the supported locales or '' if undetectable.
     */
    public static function detectLocaleForValue( string $lookup_type, string $current_name ): string {
        $rev = self::reverseMap();
        if ( isset( $rev[ $lookup_type ][ $current_name ] ) ) {
            return 'nl_NL';
        }

        // Default: if the value contains non-ASCII letters or characters
        // common to NL/DE/FR/ES that English typically doesn't use,
        // tentatively assume the site default locale.
        if ( preg_match( '/[\x{00C0}-\x{017F}]/u', $current_name ) === 1 ) {
            return (string) ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );
        }

        // Lowercase-only values that look like an English noun are
        // almost always lowercase drifts (e.g. 'present', 'match') —
        // preserve as en_US.
        if ( preg_match( '/^[a-z][a-z0-9 _-]*$/', $current_name ) === 1 ) {
            return 'en_US';
        }

        return '';
    }
}
