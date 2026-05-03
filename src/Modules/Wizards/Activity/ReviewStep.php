<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Review + create.
 *
 * Mirrors the REST `create_session` payload so the wizard ships an
 * activity row identical in shape to one created from the flat form.
 * Source is hardcoded to `manual`; demo-tag bookkeeping is delegated
 * to the helper used everywhere else (see ActivitiesRestController).
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $type   = (string) ( $state['activity_type_key'] ?? 'training' );
        $status = (string) ( $state['activity_status_key'] ?? 'planned' );

        echo '<p>' . esc_html__( 'Looks good? Create the activity to start logging attendance.', 'talenttrack' ) . '</p>';
        echo '<dl class="tt-wizard-review">';

        $team_name = '—';
        $tid = (int) ( $state['team_id'] ?? 0 );
        if ( $tid > 0 ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT name, age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
                $tid, CurrentClub::id()
            ) );
            if ( $row ) {
                $team_name = (string) $row->name;
                if ( ! empty( $row->age_group ) ) $team_name .= ' (' . $row->age_group . ')';
            }
        }
        echo '<dt>' . esc_html__( 'Team', 'talenttrack' ) . '</dt><dd>' . esc_html( $team_name ) . '</dd>';

        echo '<dt>' . esc_html__( 'Type', 'talenttrack' ) . '</dt><dd>' . esc_html( self::translateLookup( 'activity_type', $type ) ) . '</dd>';
        echo '<dt>' . esc_html__( 'Status', 'talenttrack' ) . '</dt><dd>' . esc_html( self::translateLookup( 'activity_status', $status ) ) . '</dd>';

        if ( $type === 'game' && ! empty( $state['game_subtype_key'] ) ) {
            echo '<dt>' . esc_html__( 'Game subtype', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $state['game_subtype_key'] ) . '</dd>';
        }
        if ( $type === 'other' && ! empty( $state['other_label'] ) ) {
            echo '<dt>' . esc_html__( 'Other label', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $state['other_label'] ) . '</dd>';
        }

        echo '<dt>' . esc_html__( 'Title', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) ( $state['title'] ?? '' ) ) . '</dd>';
        echo '<dt>' . esc_html__( 'Date', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) ( $state['session_date'] ?? '' ) ) . '</dd>';
        $loc = (string) ( $state['location'] ?? '' );
        echo '<dt>' . esc_html__( 'Location', 'talenttrack' ) . '</dt><dd>' . esc_html( $loc !== '' ? $loc : '—' ) . '</dd>';
        $notes = (string) ( $state['notes'] ?? '' );
        if ( $notes !== '' ) {
            echo '<dt>' . esc_html__( 'Notes', 'talenttrack' ) . '</dt><dd>' . esc_html( $notes ) . '</dd>';
        }
        echo '</dl>';
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        global $wpdb;

        $title = (string) ( $state['title'] ?? '' );
        $date  = (string) ( $state['session_date'] ?? '' );
        $tid   = (int) ( $state['team_id'] ?? 0 );
        if ( $title === '' || $date === '' || $tid <= 0 ) {
            return new \WP_Error( 'incomplete', __( 'The activity is missing required fields.', 'talenttrack' ) );
        }

        $type           = (string) ( $state['activity_type_key'] ?? 'training' );
        $status         = (string) ( $state['activity_status_key'] ?? 'planned' );
        $valid_types    = QueryHelpers::get_lookup_names( 'activity_type' );
        $valid_statuses = QueryHelpers::get_lookup_names( 'activity_status' );
        if ( ! in_array( $type,   $valid_types,    true ) ) $type   = 'training';
        if ( ! in_array( $status, $valid_statuses, true ) ) $status = 'planned';

        $row = [
            'club_id'             => CurrentClub::id(),
            'team_id'             => $tid,
            'coach_id'            => get_current_user_id(),
            'title'               => $title,
            'session_date'        => $date,
            'location'            => (string) ( $state['location'] ?? '' ),
            'notes'               => (string) ( $state['notes'] ?? '' ),
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'activity_source_key' => 'manual',
            'game_subtype_key'    => $type === 'game'  && ! empty( $state['game_subtype_key'] ) ? (string) $state['game_subtype_key'] : null,
            'other_label'         => $type === 'other' && ! empty( $state['other_label'] )       ? (string) $state['other_label']    : null,
        ];

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_activities', $row );
        if ( $ok === false ) {
            Logger::error( 'wizard.activity.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $row ] );
            return new \WP_Error( 'db_error', __( 'The activity could not be saved.', 'talenttrack' ) );
        }
        $activity_id = (int) $wpdb->insert_id;

        // v3.85.2 — was an inline demo-tag write that duplicated the
        // central helper's logic. Operator reported activities created
        // via the wizard sometimes disappearing post-save; switching to
        // DemoMode::tagIfActive uses the same idempotent + table-safe
        // path every other create site shares (REST controllers, admin
        // pages, CSV importer). The helper short-circuits when demo
        // mode is off and when tt_demo_tags doesn't exist.
        if ( class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            \TT\Modules\DemoData\DemoMode::tagIfActive( 'activity', $activity_id );
        }

        if ( class_exists( '\\TT\\Modules\\Translations\\TranslationLayer' ) ) {
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'activity', $activity_id, 'title',    (string) $row['title'] );
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'activity', $activity_id, 'notes',    (string) $row['notes'] );
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'activity', $activity_id, 'location', (string) $row['location'] );
        }

        return [ 'redirect_url' => add_query_arg( [
            'tt_view' => 'activities',
            'id'      => $activity_id,
        ], \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() ) ];
    }

    private static function translateLookup( string $type, string $name ): string {
        foreach ( QueryHelpers::get_lookups( $type ) as $row ) {
            if ( (string) $row->name === $name ) return LookupTranslator::name( $row );
        }
        return $name;
    }
}
