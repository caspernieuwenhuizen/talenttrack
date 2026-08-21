<?php
namespace TT\Modules\Training\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Training\Services\SquadSizeEstimator;

/**
 * Small shared lookups for the training-plan wizard's steps (#2497).
 *
 * Kept out of the steps themselves so two steps asking the same question
 * cannot answer it differently — the theme step and the review step both
 * need to know whether this club has a periodisation calendar, and the
 * shape and proposal steps both need the squad.
 */
final class WizardContext {

    /**
     * The team's age group, in the `U13` shape the age profiles use.
     *
     * Falls back to U13 rather than to nothing: an unset age group would
     * make the engine refuse to compose at all, and a mid-range default
     * that the coach can see in the summary is more useful than a dead
     * end. The review step says which was used.
     */
    public static function ageGroupFor( int $team_id ): string {
        if ( $team_id <= 0 ) return 'U13';

        global $wpdb;
        $age = $wpdb->get_var( $wpdb->prepare(
            "SELECT age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $team_id
        ) );

        $age = trim( (string) $age );
        return preg_match( '/^U\d{1,2}$/i', $age ) ? strtoupper( $age ) : 'U13';
    }

    /**
     * The themes a coach can pick, from the VCT tactical-theme
     * vocabulary — the same list the merged exercise catalogue is tagged
     * against, so a theme always has candidates behind it.
     *
     * @return array<string,string> key => label
     */
    public static function themeOptions(): array {
        global $wpdb;

        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_lookups
              WHERE lookup_type = 'vct_tactical_theme'
                AND ( club_id = %d OR club_id IS NULL )
              ORDER BY sort_order ASC",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $names as $name ) {
            $out[ (string) $name ] = self::themeLabel( (string) $name );
        }
        return $out;
    }

    public static function themeLabel( string $key ): string {
        switch ( $key ) {
            case 'build_up':   return __( 'Building up from the back', 'talenttrack' );
            case 'possession': return __( 'Keeping the ball', 'talenttrack' );
            case 'pressing':   return __( 'Pressing and winning it back', 'talenttrack' );
            case 'defending':  return __( 'Defending as a team', 'talenttrack' );
            case 'transition': return __( 'Switching between attack and defence', 'talenttrack' );
            case 'counter':    return __( 'Countering after winning the ball', 'talenttrack' );
            case 'finishing':  return __( 'Creating and finishing chances', 'talenttrack' );
            case 'set_pieces': return __( 'Set pieces', 'talenttrack' );
            case '1v1_duels':  return __( 'One-against-one duels', 'talenttrack' );
            case 'mixed':      return __( 'A bit of everything', 'talenttrack' );
        }
        return $key;
    }

    /**
     * How many exercises the library can offer for a theme. Shown next to
     * each option so a coach is not sent down a path with nothing behind
     * it — the honest alternative to a generator that returns a thin
     * session and does not say why.
     *
     * @return array<string,int> theme key => candidate count
     */
    public static function candidateCounts(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tactical_theme AS theme, COUNT(*) AS total
               FROM {$wpdb->prefix}tt_exercises
              WHERE club_id = %d
                AND archived_at IS NULL
                AND superseded_by_id IS NULL
                AND tactical_theme IS NOT NULL
           GROUP BY tactical_theme",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row->theme ] = (int) $row->total;
        }
        return $out;
    }

    /**
     * The squad this plan is for, and where the number came from, so the
     * shape step can prefill and say so (epic decision D14).
     *
     * @return array{value:int, source:string, roster:list<int>}
     */
    public static function squadFor( int $team_id ): array {
        $estimator = new SquadSizeEstimator();
        $suggested = $estimator->suggest( $team_id );

        return [
            'value'  => (int) $suggested['value'],
            'source' => (string) $suggested['source'],
            'roster' => $estimator->rosterFor( $team_id ),
        ];
    }

    /** Whether this club has a periodisation calendar to suggest a theme from. */
    public static function hasMacroBlocks(): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_vct_macro_blocks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE club_id = %d",
            CurrentClub::id()
        ) ) > 0;
    }
}
