<?php
namespace TT\Modules\Teams;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FootballFormResolver — how many a side does this team actually play? (#3044)
 *
 * A team's football form — 6v6 without keepers on a quarter pitch, 8v8 with a
 * keeper on half a pitch, 11v11 on a full one — was recorded nowhere, so every
 * surface that needed it either assumed eleven-a-side or kept a private copy.
 * The team blueprint offered a U9 coach a back four and a front three; the
 * tournament wizard promised an inference that did not exist; the demo
 * generator carried the only correct answer in a constant the product could
 * not read.
 *
 * Resolution order, most specific first:
 *
 *   1. `tt_teams.football_form` — the club that runs its U13 at 8v8, or its
 *      U12 already at 11v11, says so on the team.
 *   2. The age-group default, an operator-maintained map in `tt_config`
 *      (Configuration → Football form).
 *   3. {@see FALLBACK_BANDS} — the bands a federation would recognise,
 *      applied to the number in the age-group name. This is what a newly
 *      added age group resolves through before anybody edits the map, not a
 *      hardcoded product rule: the map above overrides it entirely.
 *
 * The vocabulary itself is a `football_form` lookup, so a club whose
 * federation plays 4v4, 7v7 or 9v9 adds those rather than being told what
 * football looks like.
 */
final class FootballFormResolver {

    /** `tt_lookups` vocabulary holding the forms this academy plays. */
    public const LOOKUP_TYPE = 'football_form';

    /** `tt_config` key holding the JSON age-group -> form map. */
    public const CONFIG_KEY = 'football_form_by_age_group';

    /** The form used when nothing resolves — a full-size adult game. */
    public const FALLBACK_FORM = '11v11';

    /**
     * Oldest age each form applies to, for an age group nobody has mapped.
     *
     * Keyed by the oldest age the form covers, ascending. The numbers come
     * from the small-sided ladder most European federations run; a club that
     * disagrees edits the map in Configuration rather than this constant.
     */
    public const FALLBACK_BANDS = [
        9  => '6v6',
        12 => '8v8',
        99 => '11v11',
    ];

    /**
     * The form for one team, honouring its own override first.
     *
     * Reads the row through an array cast rather than property access: the
     * callers hand over whatever their `SELECT t.*` produced, and a row from
     * a query that predates the column simply resolves through the age group.
     */
    public static function forTeamRow( object $team ): string {
        $row = (array) $team;

        $own = trim( (string) ( $row['football_form'] ?? '' ) );
        if ( $own !== '' ) return $own;

        return self::forAgeGroup( (string) ( $row['age_group'] ?? '' ) );
    }

    /** The form for one team id. Club-scoped; falls back when unknown. */
    public static function forTeam( int $team_id ): string {
        if ( $team_id <= 0 ) return self::FALLBACK_FORM;

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT football_form, age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
            $team_id,
            CurrentClub::id()
        ) );

        return is_object( $row ) ? self::forTeamRow( $row ) : self::FALLBACK_FORM;
    }

    /** The configured default for an age group, or the band fallback. */
    public static function forAgeGroup( string $age_group ): string {
        $age_group = trim( $age_group );
        if ( $age_group === '' ) return self::FALLBACK_FORM;

        $map = self::configuredMap();
        if ( isset( $map[ $age_group ] ) ) return $map[ $age_group ];

        return self::bandFor( $age_group );
    }

    /**
     * The operator-maintained age-group -> form map, decoded and cleaned.
     *
     * @return array<string,string>
     */
    public static function configuredMap(): array {
        $raw = (string) QueryHelpers::get_config( self::CONFIG_KEY, '' );
        if ( $raw === '' ) return [];

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];

        $out = [];
        foreach ( $decoded as $group => $form ) {
            $group = trim( (string) $group );
            $form  = trim( (string) $form );
            if ( $group !== '' && $form !== '' ) $out[ $group ] = $form;
        }
        return $out;
    }

    /**
     * Normalise a stored map to JSON, keeping only age groups that exist and
     * forms this academy actually plays. Called on save so a stale payload
     * cannot leave a team resolving to a form nobody has heard of.
     */
    public static function normaliseStored( string $raw ): string {
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return '';

        $groups = array_map( 'strval', QueryHelpers::get_lookup_names( 'age_group' ) );
        $forms  = self::forms();

        $out = [];
        foreach ( $decoded as $group => $form ) {
            $group = trim( (string) $group );
            $form  = trim( (string) $form );
            if ( $group === '' || $form === '' ) continue;
            if ( ! in_array( $group, $groups, true ) ) continue;
            if ( ! in_array( $form, $forms, true ) ) continue;
            $out[ $group ] = $form;
        }
        return $out === [] ? '' : (string) wp_json_encode( $out );
    }

    /**
     * The forms this academy plays, from the `football_form` vocabulary.
     *
     * @return list<string>
     */
    public static function forms(): array {
        return array_values( array_map( 'strval', QueryHelpers::get_lookup_names( self::LOOKUP_TYPE ) ) );
    }

    /**
     * Players a side for a form.
     *
     * Read off the front of the name — `8v8` is eight a side — so a club that
     * adds 4v4, 7v7 or 9v9 is understood without an entry anywhere. Falls
     * back to eleven for a name that carries no leading number.
     */
    public static function playersASide( string $form ): int {
        if ( preg_match( '/^(\d+)/', trim( $form ), $m ) ) {
            $n = (int) $m[1];
            if ( $n > 0 && $n <= 11 ) return $n;
        }
        return 11;
    }

    /** Players a side for one team. */
    public static function squadSizeForTeam( int $team_id ): int {
        return self::playersASide( self::forTeam( $team_id ) );
    }

    /** Players a side for an age group, through that group's default form. */
    public static function squadSizeForAgeGroup( string $age_group ): int {
        return self::playersASide( self::forAgeGroup( $age_group ) );
    }

    /**
     * The band a group's name falls in — "U9", "JO9", "Onder 9" all read 9.
     *
     * Only reached when the operator map has no entry for the group.
     */
    public static function bandFor( string $age_group ): string {
        $age = 0;
        if ( preg_match( '/(\d+)/', $age_group, $m ) ) $age = (int) $m[1];
        if ( $age <= 0 ) return self::FALLBACK_FORM;

        foreach ( self::FALLBACK_BANDS as $max_age => $form ) {
            if ( $age <= $max_age ) return $form;
        }
        return self::FALLBACK_FORM;
    }
}
