<?php
namespace TT\Modules\DataBrowser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SemanticRegistry — the human-friendly layer over the raw schema.
 *
 * Curated labels + descriptions for the core player-centric tables; every
 * other table and column falls back to a humanised version of its
 * snake_case name. Adding a table is one array block here — no schema
 * change, no migration. The registry is intentionally flat and additive
 * so the friendly layer can grow table-by-table over time (#1859).
 *
 * Sensitive tables (medical, safeguarding, family) are flagged so the
 * frontend can badge them and the service can audit-log each view.
 */
class SemanticRegistry {

    /**
     * Tables holding sensitive data about minors. Viewing one is
     * badged in the UI and written to tt_audit_log. Keyed by table key.
     *
     * @var array<string,bool>
     */
    private const SENSITIVE = [
        'tt_player_injuries' => true,
        'tt_player_parents'  => true,
        'tt_player_notes'    => true,
        'tt_safeguarding'    => true,
    ];

    /**
     * Curated metadata for core tables. Each entry:
     *   label, description, columns => [ col => [label, description] ].
     * Unlisted tables/columns fall back to humanise().
     *
     * @return array<string,array{label:string,description:string,columns:array<string,array{label:string,description:string}>}>
     */
    private static function curated(): array {
        return [
            'tt_players' => [
                'label'       => __( 'Players', 'talenttrack' ),
                'description' => __( 'The player record — the spine of the system. One row per player.', 'talenttrack' ),
                'columns'     => [
                    'first_name'    => [ 'label' => __( 'First name', 'talenttrack' ),    'description' => __( 'Player\'s given name.', 'talenttrack' ) ],
                    'last_name'     => [ 'label' => __( 'Last name', 'talenttrack' ),     'description' => __( 'Player\'s family name.', 'talenttrack' ) ],
                    'date_of_birth' => [ 'label' => __( 'Date of birth', 'talenttrack' ), 'description' => __( 'Used to derive age group.', 'talenttrack' ) ],
                    'team_id'       => [ 'label' => __( 'Team', 'talenttrack' ),          'description' => __( 'Current team the player is assigned to.', 'talenttrack' ) ],
                    'status'        => [ 'label' => __( 'Status', 'talenttrack' ),        'description' => __( 'Lifecycle status (active, trial, released…).', 'talenttrack' ) ],
                    'wp_user_id'    => [ 'label' => __( 'Login account', 'talenttrack' ), 'description' => __( 'Linked WordPress login, if the player has one.', 'talenttrack' ) ],
                ],
            ],
            'tt_teams' => [
                'label'       => __( 'Teams', 'talenttrack' ),
                'description' => __( 'Age groups and selections players are organised into.', 'talenttrack' ),
                'columns'     => [
                    'name'         => [ 'label' => __( 'Name', 'talenttrack' ),       'description' => __( 'Team name, e.g. "U17-1".', 'talenttrack' ) ],
                    'age_group'    => [ 'label' => __( 'Age group', 'talenttrack' ),  'description' => __( 'Birth-year band the team plays in.', 'talenttrack' ) ],
                ],
            ],
            'tt_activities' => [
                'label'       => __( 'Activities', 'talenttrack' ),
                'description' => __( 'Trainings and matches — the activities players take part in.', 'talenttrack' ),
                'columns'     => [
                    'activity_type' => [ 'label' => __( 'Type', 'talenttrack' ),     'description' => __( 'Training or match. Value from the activity_type lookup.', 'talenttrack' ) ],
                    'title'         => [ 'label' => __( 'Title', 'talenttrack' ),    'description' => __( 'Free-text description of the activity.', 'talenttrack' ) ],
                    'team_id'       => [ 'label' => __( 'Team', 'talenttrack' ),     'description' => __( 'Team the activity belongs to.', 'talenttrack' ) ],
                    'scheduled_at'  => [ 'label' => __( 'Date', 'talenttrack' ),     'description' => __( 'Planned date and time.', 'talenttrack' ) ],
                ],
            ],
            'tt_evaluations' => [
                'label'       => __( 'Evaluations', 'talenttrack' ),
                'description' => __( 'Performance assessments per player, scored by category.', 'talenttrack' ),
                'columns'     => [
                    'player_id' => [ 'label' => __( 'Player', 'talenttrack' ),     'description' => __( 'The player being assessed.', 'talenttrack' ) ],
                    'eval_type' => [ 'label' => __( 'Type', 'talenttrack' ),       'description' => __( 'Kind of evaluation. Value from the eval_type lookup.', 'talenttrack' ) ],
                ],
            ],
            'tt_eval_categories' => [
                'label'       => __( 'Evaluation categories', 'talenttrack' ),
                'description' => __( 'The rating dimensions (Technical, Tactical, Physical…).', 'talenttrack' ),
                'columns'     => [],
            ],
            'tt_eval_ratings' => [
                'label'       => __( 'Evaluation ratings', 'talenttrack' ),
                'description' => __( 'Individual category scores within an evaluation.', 'talenttrack' ),
                'columns'     => [
                    'evaluation_id' => [ 'label' => __( 'Evaluation', 'talenttrack' ), 'description' => __( 'Parent evaluation this score belongs to.', 'talenttrack' ) ],
                    'category_id'   => [ 'label' => __( 'Category', 'talenttrack' ),   'description' => __( 'The rated dimension.', 'talenttrack' ) ],
                    'rating'        => [ 'label' => __( 'Rating', 'talenttrack' ),     'description' => __( 'The score on the academy\'s rating scale.', 'talenttrack' ) ],
                ],
            ],
            'tt_goals' => [
                'label'       => __( 'Goals', 'talenttrack' ),
                'description' => __( 'Development goals (PDP) per player, with progress.', 'talenttrack' ),
                'columns'     => [
                    'player_id' => [ 'label' => __( 'Player', 'talenttrack' ), 'description' => __( 'The player the goal belongs to.', 'talenttrack' ) ],
                ],
            ],
            'tt_attendance' => [
                'label'       => __( 'Attendance', 'talenttrack' ),
                'description' => __( 'Player attendance per activity.', 'talenttrack' ),
                'columns'     => [
                    'activity_id'    => [ 'label' => __( 'Activity', 'talenttrack' ), 'description' => __( 'The activity attendance was recorded for.', 'talenttrack' ) ],
                    'player_id'      => [ 'label' => __( 'Player', 'talenttrack' ),   'description' => __( 'The player present or absent.', 'talenttrack' ) ],
                    'status'         => [ 'label' => __( 'Status', 'talenttrack' ),   'description' => __( 'Present, absent, injured… from the attendance lookup.', 'talenttrack' ) ],
                    // #2160 — surface the canonical minutes store + its
                    // guards so the operator can verify a reported total
                    // against the raw rows.
                    'minutes_played' => [ 'label' => __( 'Minutes played', 'talenttrack' ), 'description' => __( 'Match minutes for this player. The canonical store the minutes reports sum.', 'talenttrack' ) ],
                    'record_type'    => [ 'label' => __( 'Record type', 'talenttrack' ),    'description' => __( 'expected (planned roster) or actual (recorded). Only actual rows count toward minutes.', 'talenttrack' ) ],
                    'is_guest'       => [ 'label' => __( 'Guest', 'talenttrack' ),          'description' => __( 'Guest attendance (1) is excluded from team minutes; squad rows are 0.', 'talenttrack' ) ],
                ],
            ],
            'tt_player_events' => [
                'label'       => __( 'Player events', 'talenttrack' ),
                'description' => __( 'The player\'s journey timeline: trial, signing, promotion, injury, return, release.', 'talenttrack' ),
                'columns'     => [
                    'player_id'  => [ 'label' => __( 'Player', 'talenttrack' ),     'description' => __( 'The player this event belongs to.', 'talenttrack' ) ],
                    'event_type' => [ 'label' => __( 'Event type', 'talenttrack' ), 'description' => __( 'The kind of transition.', 'talenttrack' ) ],
                ],
            ],
            'tt_player_injuries' => [
                'label'       => __( 'Injuries', 'talenttrack' ),
                'description' => __( 'Medical record of injuries and recovery per player.', 'talenttrack' ),
                'columns'     => [
                    'player_id'   => [ 'label' => __( 'Player', 'talenttrack' ),      'description' => __( 'The injured player.', 'talenttrack' ) ],
                    'injury_type' => [ 'label' => __( 'Injury type', 'talenttrack' ),  'description' => __( 'Nature of the injury.', 'talenttrack' ) ],
                ],
            ],
            'tt_lookups' => [
                'label'       => __( 'Lookups', 'talenttrack' ),
                'description' => __( 'Editable reference lists (positions, statuses, activity types…).', 'talenttrack' ),
                'columns'     => [
                    'lookup_type' => [ 'label' => __( 'List', 'talenttrack' ),        'description' => __( 'Which vocabulary this value belongs to.', 'talenttrack' ) ],
                    'name'        => [ 'label' => __( 'Stored value', 'talenttrack' ), 'description' => __( 'The canonical code stored on records.', 'talenttrack' ) ],
                    'description' => [ 'label' => __( 'Description', 'talenttrack' ),  'description' => __( 'Human-readable explanation of the value.', 'talenttrack' ) ],
                ],
            ],
            'tt_config' => [
                'label'       => __( 'Configuration', 'talenttrack' ),
                'description' => __( 'Academy-wide settings (name, rating scale, branding…).', 'talenttrack' ),
                'columns'     => [
                    'config_key'   => [ 'label' => __( 'Setting', 'talenttrack' ), 'description' => __( 'The setting name.', 'talenttrack' ) ],
                    'config_value' => [ 'label' => __( 'Value', 'talenttrack' ),   'description' => __( 'The stored value.', 'talenttrack' ) ],
                ],
            ],
        ];
    }

    /** Friendly label for a table key. */
    public static function tableLabel( string $key ): string {
        $curated = self::curated();
        if ( isset( $curated[ $key ]['label'] ) ) return $curated[ $key ]['label'];
        return self::humanise( self::stripPrefix( $key ) );
    }

    /** One-line description for a table key, '' when none is curated. */
    public static function tableDescription( string $key ): string {
        $curated = self::curated();
        return (string) ( $curated[ $key ]['description'] ?? '' );
    }

    /** Whether the table has a curated (hand-authored) description. */
    public static function isCurated( string $key ): bool {
        return isset( self::curated()[ $key ] );
    }

    /** Whether the table holds sensitive data about minors. */
    public static function isSensitive( string $key ): bool {
        return ! empty( self::SENSITIVE[ $key ] );
    }

    /** Friendly label for a column within a table. */
    public static function columnLabel( string $key, string $column ): string {
        $curated = self::curated();
        if ( isset( $curated[ $key ]['columns'][ $column ]['label'] ) ) {
            return $curated[ $key ]['columns'][ $column ]['label'];
        }
        return self::humanise( $column );
    }

    /** Curated column description, '' when none. */
    public static function columnDescription( string $key, string $column ): string {
        $curated = self::curated();
        return (string) ( $curated[ $key ]['columns'][ $column ]['description'] ?? '' );
    }

    /** Drop the leading `tt_` for display fallbacks. */
    private static function stripPrefix( string $key ): string {
        return strpos( $key, 'tt_' ) === 0 ? substr( $key, 3 ) : $key;
    }

    /**
     * snake_case → Title Case, with a few well-known overrides so common
     * columns read naturally instead of "Id" / "Uuid" / "Club Id".
     */
    public static function humanise( string $raw ): string {
        $overrides = [
            'id'         => 'ID',
            'uuid'       => 'UUID',
            'club_id'    => __( 'Club', 'talenttrack' ),
            'wp_user_id' => __( 'WordPress account', 'talenttrack' ),
            'created_at' => __( 'Created', 'talenttrack' ),
            'updated_at' => __( 'Updated', 'talenttrack' ),
            'created_by' => __( 'Created by', 'talenttrack' ),
            'updated_by' => __( 'Updated by', 'talenttrack' ),
        ];
        if ( isset( $overrides[ $raw ] ) ) return $overrides[ $raw ];

        $words = explode( ' ', str_replace( '_', ' ', $raw ) );
        $words = array_map( static function ( string $w ): string {
            if ( $w === 'id' )  return 'ID';
            if ( $w === 'ug' )  return $w;
            return ucfirst( $w );
        }, $words );
        return implode( ' ', $words );
    }
}
