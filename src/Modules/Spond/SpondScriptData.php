<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondScriptData — the config `assets/js/frontend-spond.js` is handed,
 * in one place.
 *
 * Two views enqueue that script: the club-wide integration page
 * (`?tt_view=spond`) and the per-team link screen
 * (`?tt_view=team-spond`). Each used to inline its own copy of the i18n
 * bag, and the copies had already drifted — the group-picker strings
 * existed on one and not the other, so a control that rendered on both
 * fell back to hard-coded English on one of them.
 *
 * #3247 added a third set of strings, which is one copy too many to keep
 * in step by hand.
 */
class SpondScriptData {

    /**
     * @return array<string,mixed>
     */
    public static function config(): array {
        return [
            // #2284 — the dry-run monitor, linked from the inline preview
            // when there is more to see than the summary shows.
            'monitor_url' => add_query_arg(
                [ 'tt_view' => 'spond-monitor' ],
                remove_query_arg( [ 'tt_view', 'id', 'team_id' ] )
            ),
            'i18n' => [
                'saved'                 => __( 'Credentials saved.', 'talenttrack' ),
                'test_ok'               => __( 'Spond login successful.', 'talenttrack' ),
                'test_failed'           => __( 'Spond login failed.', 'talenttrack' ),
                'disconnected'          => __( 'Spond disconnected.', 'talenttrack' ),
                'base_url_saved'        => __( 'API endpoint saved.', 'talenttrack' ),
                'refreshing'            => __( 'Refreshing…', 'talenttrack' ),
                'refreshed'             => __( 'Sync triggered. Reload to see the updated status.', 'talenttrack' ),
                'error'                 => __( 'Could not save. Please try again.', 'talenttrack' ),
                'network_error'         => __( 'Network error. Please try again.', 'talenttrack' ),
                'disconnect_confirm'    => __( 'Disconnect Spond? Existing imported activities are kept; per-team group selections stay on file.', 'talenttrack' ),
                'team_saved'            => __( 'Team account saved.', 'talenttrack' ),
                'team_cleared'          => __( 'Team now uses the club account.', 'talenttrack' ),
                'team_use_club_confirm' => __( 'Use the club account for this team? The team\'s own Spond login will be removed.', 'talenttrack' ),
                // #2399 — group picker.
                'group_saved'           => __( 'Spond group saved.', 'talenttrack' ),
                /* translators: %s: the other team already linked to this Spond group. */
                'group_shared'          => __( 'Heads up: %s is already linked to this Spond group. Saving is allowed — both teams will import the same calendar.', 'talenttrack' ),
                // #3247 — what Test shows once the login works.
                'testing'               => __( 'Testing…', 'talenttrack' ),
                'preview_loading'       => __( 'Checking what would sync…', 'talenttrack' ),
                /* translators: 1: events that would be created 2: events that would be updated 3: activities that would be archived */
                'preview_counts'        => __( '%1$d new · %2$d updated · %3$d archived', 'talenttrack' ),
                'preview_none'          => __( 'Spond returned no events in the sync window (30 days back, 180 days ahead).', 'talenttrack' ),
                'preview_safe'          => __( 'Nothing has been saved. This is what a sync would do.', 'talenttrack' ),
                /* translators: %d: events not listed above. */
                'preview_more'          => __( '%d more not listed.', 'talenttrack' ),
                'preview_monitor'       => __( 'Open monitor for the full comparison', 'talenttrack' ),
                'preview_failed'        => __( 'Login works, but the calendar could not be read.', 'talenttrack' ),
                'status_new'            => _x( 'New', 'a Spond event that would be created', 'talenttrack' ),
                'status_update'         => _x( 'Update', 'a Spond event that would update an existing activity', 'talenttrack' ),
            ],
        ];
    }
}
