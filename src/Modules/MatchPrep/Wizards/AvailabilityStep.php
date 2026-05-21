<?php
namespace TT\Modules\MatchPrep\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * AvailabilityStep — Present/Absent/Excused/Injured toggle per
 * roster player. `Late` is filtered out via `tt_lookups.meta.hide_from_prep`.
 *
 * The step seeds state with every player on the activity's team
 * marked Present on first render. Operator marks exceptions; reason
 * field stays visible for non-Present rows.
 */
final class AvailabilityStep implements WizardStepInterface {

    public function slug(): string  { return 'availability'; }
    public function label(): string { return __( 'Availability', 'talenttrack' ); }

    public function render( array $state ): void {
        $activity_id = (int) ( $state['activity_id']
            ?? ( isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0 ) );

        if ( $activity_id <= 0 ) {
            echo '<p class="tt-notice tt-notice-error">'
                . esc_html__( 'Match prep needs an activity_id. Open the wizard from a match activity\'s detail page.', 'talenttrack' )
                . '</p>';
            return;
        }

        $players = self::rosterForActivity( $activity_id );
        if ( empty( $players ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'No players on this team yet. Add players to the team before planning a match.', 'talenttrack' )
                . '</p>';
            return;
        }

        $statuses = self::prepStatuses();
        $existing = isset( $state['availability'] ) && is_array( $state['availability'] )
            ? $state['availability']
            : [];

        echo '<input type="hidden" name="activity_id" value="' . esc_attr( (string) $activity_id ) . '" />';
        echo '<p style="margin: 0 0 12px; color:#5b6e75;">'
            . esc_html__( 'Mark who can play. Everyone starts as Present; only flag exceptions.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-match-prep-availability" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px;">';
        foreach ( $players as $pl ) {
            $pid    = (int) $pl->id;
            $name   = (string) QueryHelpers::player_display_name( $pl );
            $row    = $existing[ $pid ] ?? [ 'status' => 'Present', 'reason' => '' ];
            $status = (string) ( $row['status'] ?? 'Present' );
            $reason = (string) ( $row['reason'] ?? '' );

            echo '<div class="tt-card" style="padding:12px; border:1px solid var(--tt-line,#e5e7ea); border-radius:8px;">';
            echo '<div style="font-weight:600; margin-bottom:6px;">' . esc_html( $name ) . '</div>';
            echo '<div style="display:flex; gap:6px; flex-wrap:wrap;">';
            foreach ( $statuses as $opt ) {
                $opt_name  = (string) $opt->name;
                $opt_label = LookupTranslator::name( $opt );
                $checked   = $status === $opt_name ? 'checked' : '';
                $id_attr   = 'tt-mp-avail-' . $pid . '-' . sanitize_key( $opt_name );
                echo '<label style="display:inline-flex; align-items:center; gap:4px; padding:6px 10px; border:1px solid #d6dadd; border-radius:14px; cursor:pointer; min-height:32px;">';
                echo '<input type="radio" id="' . esc_attr( $id_attr ) . '" name="availability[' . $pid . '][status]" value="' . esc_attr( $opt_name ) . '" ' . $checked . ' />';
                echo '<span>' . esc_html( $opt_label ) . '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo '<input type="text" name="availability[' . $pid . '][reason]" value="' . esc_attr( $reason ) . '" placeholder="' . esc_attr__( 'Optional reason', 'talenttrack' ) . '" style="width:100%; margin-top:8px;" />';
            echo '</div>';
        }
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $activity_id = (int) ( $post['activity_id'] ?? $state['activity_id'] ?? 0 );
        if ( $activity_id <= 0 ) {
            return new \WP_Error( 'no_activity', __( 'Missing activity_id.', 'talenttrack' ) );
        }

        $raw = $post['availability'] ?? [];
        if ( ! is_array( $raw ) ) $raw = [];

        $availability = [];
        $available_count = 0;
        foreach ( $raw as $pid => $entry ) {
            $pid = (int) $pid;
            if ( $pid <= 0 ) continue;
            $status = (string) ( $entry['status'] ?? 'Present' );
            $reason = (string) ( $entry['reason'] ?? '' );
            $availability[ $pid ] = [
                'status' => sanitize_text_field( $status ),
                'reason' => sanitize_text_field( $reason ),
            ];
            if ( strcasecmp( $status, 'Present' ) === 0 ) $available_count++;
        }

        if ( $available_count < 11 ) {
            return new \WP_Error( 'too_few_available', sprintf(
                /* translators: %d = number of Present players */
                __( 'Only %d players are marked Present. You need at least 11 to field a starting XI.', 'talenttrack' ),
                $available_count
            ) );
        }

        return [
            'activity_id'  => $activity_id,
            'availability' => $availability,
        ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $activity_id  = (int) ( $state['activity_id'] ?? 0 );
        $availability = isset( $state['availability'] ) && is_array( $state['availability'] )
            ? $state['availability']
            : [];

        if ( $activity_id <= 0 ) {
            return new \WP_Error( 'no_activity', __( 'Missing activity_id.', 'talenttrack' ) );
        }

        $repo    = new MatchPrepRepository();
        $prep_id = $repo->ensureForActivity( $activity_id );
        if ( $prep_id <= 0 ) {
            return new \WP_Error( 'db_error', __( 'Match prep could not be created.', 'talenttrack' ) );
        }
        $repo->replaceAvailability( $prep_id, $availability );

        return [ 'redirect_url' => add_query_arg( [
            'tt_view'     => 'match-prep',
            'activity_id' => $activity_id,
        ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
    }

    /** @return object[] */
    private static function rosterForActivity( int $activity_id ): array {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( ! $row ) return [];
        $team_id = (int) $row->team_id;
        if ( $team_id <= 0 ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.*
               FROM {$p}tt_team_players tp
               JOIN {$p}tt_players pl ON pl.id = tp.player_id
              WHERE tp.team_id = %d AND tp.club_id = %d AND pl.club_id = %d
              ORDER BY pl.last_name ASC, pl.first_name ASC",
            $team_id, CurrentClub::id(), CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return object[] attendance_status rows minus `hide_from_prep` */
    private static function prepStatuses(): array {
        $rows = QueryHelpers::get_lookups( 'attendance_status' );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $row ) {
            $meta = QueryHelpers::lookup_meta( $row );
            if ( ! empty( $meta['hide_from_prep'] ) ) continue;
            $out[] = $row;
        }
        return $out;
    }
}
