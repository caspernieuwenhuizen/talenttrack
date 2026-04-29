<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\SupportsCancelAsDraft;
use TT\Shared\Wizards\WizardInterface;

/**
 * NewActivityWizard (#0061) — four-step record creation flow for
 * `tt_activities`.
 *
 * Steps:
 *   1. Team — pick the team the activity is for.
 *   2. Type + status — pick activity_type (training / game / etc),
 *      conditional game-subtype dropdown, status defaults to `planned`.
 *   3. Details — date, title, optional location + notes.
 *   4. Review — summary + create.
 *
 * Implements `SupportsCancelAsDraft` so the framework renders the
 * "Save as draft" button. A draft row is written with the `draft`
 * status (seeded with `meta.hidden_from_form = 1` in v3.59.0) so the
 * user can resume editing from the activities list.
 */
final class NewActivityWizard implements WizardInterface, SupportsCancelAsDraft {

    public function slug(): string { return 'new-activity'; }
    public function label(): string { return __( 'New activity', 'talenttrack' ); }
    public function requiredCap(): string { return 'tt_edit_activities'; }
    public function firstStepSlug(): string { return 'team'; }

    /** @return array<int, \TT\Shared\Wizards\WizardStepInterface> */
    public function steps(): array {
        return [
            new TeamStep(),
            new TypeStatusStep(),
            new DetailsStep(),
            new ReviewStep(),
        ];
    }

    /**
     * Save the wizard state as a draft activity row. We write whatever
     * the user has filled in so far; missing required fields fall back
     * to safe placeholders (Untitled / today's date) so the row can be
     * inserted at all. The user reopens the draft from the activities
     * list and finishes it on the flat-form edit page.
     */
    public function cancelAsDraft( array $state ) {
        global $wpdb;

        $tid = (int) ( $state['team_id'] ?? 0 );
        if ( $tid <= 0 ) {
            // Nothing meaningful to save yet — let the framework
            // fall through to its default cancel behaviour.
            return new \WP_Error( 'nothing_to_draft', __( 'Pick a team before saving as draft.', 'talenttrack' ) );
        }

        $type = (string) ( $state['activity_type_key'] ?? 'training' );
        $row  = [
            'club_id'             => CurrentClub::id(),
            'team_id'             => $tid,
            'coach_id'            => get_current_user_id(),
            'title'               => (string) ( $state['title'] ?? __( 'Untitled draft', 'talenttrack' ) ),
            'session_date'        => (string) ( $state['session_date'] ?? current_time( 'Y-m-d' ) ),
            'location'            => (string) ( $state['location'] ?? '' ),
            'notes'               => (string) ( $state['notes'] ?? '' ),
            'activity_type_key'   => $type,
            'activity_status_key' => 'draft',
            'activity_source_key' => 'manual',
            'game_subtype_key'    => $type === 'game'  && ! empty( $state['game_subtype_key'] ) ? (string) $state['game_subtype_key'] : null,
            'other_label'         => $type === 'other' && ! empty( $state['other_label'] )       ? (string) $state['other_label']    : null,
        ];

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_activities', $row );
        if ( $ok === false ) {
            Logger::error( 'wizard.activity.draft.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $row ] );
            return new \WP_Error( 'db_error', __( 'The draft could not be saved.', 'talenttrack' ) );
        }
        $activity_id = (int) $wpdb->insert_id;

        return [ 'redirect_url' => add_query_arg( [
            'tt_view' => 'activities',
            'id'      => $activity_id,
        ], \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() ) ];
    }
}
