<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 5 — Attendance roster (#1297).
 *
 * Closes the parity gap with the Goal wizard's PlayerStep cascade: the
 * new-activity wizard never asked which players the activity is for,
 * so coaches had to save the activity and then revisit the edit form
 * to mark expected attendance. This step renders the just-picked
 * team's roster as default-checked checkboxes; operator unchecks
 * anyone known absent (injury, away, called-up to a sibling team).
 *
 * Below the roster sits an opt-in "+ Add guest players" disclosure
 * driving `PlayerSearchPickerComponent` scoped to sibling teams within
 * coach scope — mirroring the post-save edit form's `is_guest=1` UX.
 *
 * State keys:
 *   - `attendance_picks`        list<int>  selected team-roster player ids
 *   - `attendance_guest_picks`  list<int>  selected guest player ids
 *   - `attendance_skip`         bool       operator chose "Set later"
 *
 * On `ReviewStep::submit()` after the `tt_activities` insert: each
 * checked roster player_id becomes a `tt_attendance` row with
 * `record_type='expected'` and `is_guest=0`; each guest gets
 * `is_guest=1`. When `attendance_skip` is true the activity saves
 * with no `tt_attendance` rows — preserving the legacy behaviour.
 *
 * Operator never touches per-player status / minutes here; that's
 * mid/post-session work captured by the existing attendance widget
 * on the activity edit form (`record_type='actual'`).
 */
final class AttendanceRosterStep implements WizardStepInterface {

    public function slug(): string  { return 'attendance-roster'; }
    public function label(): string { return __( 'Roster', 'talenttrack' ); }

    public function render( array $state ): void {
        $team_id = (int) ( $state['team_id'] ?? 0 );
        $is_admin = current_user_can( 'tt_edit_settings' );
        $user_id  = get_current_user_id();

        if ( defined( 'TT_PLUGIN_URL' ) && defined( 'TT_VERSION' ) ) {
            wp_enqueue_script(
                'tt-wizard-attendance-roster',
                TT_PLUGIN_URL . 'assets/js/components/wizard-attendance-roster.js',
                [],
                TT_VERSION,
                true
            );
        }

        if ( $team_id <= 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'Pick a team first; the roster will populate from that pick.', 'talenttrack' ) . '</p>';
            return;
        }

        $players = QueryHelpers::get_players( $team_id );

        // First-render default: every roster player checked. On re-entry
        // (back-step / re-render after validation error) we honour the
        // previously-captured picks so the operator's unchecks survive.
        $previously_visited = ! empty( $state['attendance_visited'] );
        $picks_state = array_map( 'intval', (array) ( $state['attendance_picks'] ?? [] ) );
        $skip        = ! empty( $state['attendance_skip'] );

        $guest_picks = array_map( 'intval', (array) ( $state['attendance_guest_picks'] ?? [] ) );

        echo '<p>' . esc_html__( 'Tick the players expected at this activity. Defaults to the whole team.', 'talenttrack' ) . '</p>';

        if ( empty( $players ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'This team has no active players. Add players to the team first, or skip this step to save without a roster.', 'talenttrack' ) . '</p>';
            echo '<input type="hidden" name="attendance_visited" value="1" />';
            self::renderSkipRow( $skip );
            return;
        }

        // Mark visited so re-renders treat absence-of-checkbox as
        // "explicitly unchecked" rather than "fresh form".
        echo '<input type="hidden" name="attendance_visited" value="1" />';

        echo '<div class="tt-attendance-roster" style="display:flex; flex-direction:column; gap:8px;">';

        // Top controls — Check all / Uncheck all. Buttons (not links)
        // so they meet the 48px tap-target rule and read as actionable
        // affordances. Hidden when the step is in "skip" mode.
        echo '<div class="tt-attendance-roster-controls" data-tt-roster-controls style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:4px;'
            . ( $skip ? ' display:none;' : '' )
            . '">';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-roster-check-all style="min-height:48px; padding:0 16px; touch-action:manipulation;">'
            . esc_html__( 'Check all', 'talenttrack' )
            . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-roster-uncheck-all style="min-height:48px; padding:0 16px; touch-action:manipulation;">'
            . esc_html__( 'Uncheck all', 'talenttrack' )
            . '</button>';
        echo '</div>';

        echo '<ul class="tt-attendance-roster-list" data-tt-roster-list style="list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr; gap:6px;'
            . ( $skip ? ' display:none;' : '' )
            . '">';
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            if ( $pid <= 0 ) continue;
            $name = QueryHelpers::player_display_name( $pl );
            if ( $previously_visited ) {
                $is_checked = in_array( $pid, $picks_state, true );
            } else {
                $is_checked = true; // default: whole team expected
            }
            echo '<li>';
            echo '<label class="tt-attendance-roster-row" style="display:flex; align-items:center; gap:12px; padding:10px 12px; min-height:48px; border-radius:6px; background:' . ( $is_checked ? '#eef4fb' : '#f9fafb' ) . '; cursor:pointer; touch-action:manipulation;">';
            echo '<input type="checkbox" name="attendance_picks[]" value="' . esc_attr( (string) $pid ) . '"'
                . ( $is_checked ? ' checked' : '' )
                . ' data-tt-roster-checkbox style="width:22px; height:22px; flex:0 0 auto;" />';
            echo '<span style="flex:1; font-size:15px; color:#1a1d21;">' . esc_html( $name ) . '</span>';
            echo '</label>';
            echo '</li>';
        }
        echo '</ul>';

        // Guest disclosure. PlayerSearchPickerComponent is scoped to
        // the rest of the coach's scope (cross_team for non-admins;
        // every player for admins), excluding the activity team.
        echo '<details class="tt-attendance-roster-guests" style="margin-top:12px; border:1px solid #d6dadd; border-radius:8px; padding:10px 12px; background:#fff;'
            . ( $skip ? ' display:none;' : '' )
            . '">';
        echo '<summary style="cursor:pointer; font-weight:600; font-size:14px; min-height:24px; padding:6px 0; touch-action:manipulation;">'
            . esc_html__( 'Add guest players', 'talenttrack' )
            . '</summary>';
        echo '<div style="margin-top:10px;">';
        echo '<p style="font-size:13px; color:#5b6e75; margin:0 0 8px;">'
            . esc_html__( 'Pick a player from a sibling team who will join this activity as a guest.', 'talenttrack' )
            . '</p>';

        // Render currently-picked guests as hidden inputs + an
        // unchecking control. PlayerSearchPickerComponent is single-
        // pick by design; we add the picked id to a holding list and
        // reset the picker so the operator can add a second guest.
        if ( ! empty( $guest_picks ) ) {
            echo '<ul class="tt-attendance-guest-picks" style="list-style:none; margin:0 0 8px; padding:0; display:flex; flex-wrap:wrap; gap:6px;">';
            foreach ( $guest_picks as $gpid ) {
                $pl = QueryHelpers::get_player( $gpid );
                $label = $pl ? QueryHelpers::player_display_name( $pl ) : ( '#' . (int) $gpid );
                $team_name = '';
                if ( $pl && ! empty( $pl->team_id ) ) {
                    $t = QueryHelpers::get_team( (int) $pl->team_id );
                    $team_name = $t ? (string) $t->name : '';
                }
                $display = $team_name !== '' ? sprintf( '%s — %s', $label, $team_name ) : $label;
                echo '<li style="display:inline-flex; align-items:center; gap:8px; padding:6px 10px; background:#eef4fb; border-radius:999px; font-size:13px; min-height:32px;">';
                echo '<input type="hidden" name="attendance_guest_picks[]" value="' . esc_attr( (string) $gpid ) . '" />';
                echo esc_html( $display );
                echo '</li>';
            }
            echo '</ul>';
            echo '<p style="font-size:12px; color:#5b6e75; margin:0 0 8px;">'
                . esc_html__( 'To remove a guest, clear the wizard or proceed and edit attendance after creating.', 'talenttrack' )
                . '</p>';
        }

        // The picker writes to `guest_player_id_new`; on next render
        // we promote it into the picks list (see validate()).
        echo PlayerSearchPickerComponent::render( [
            'name'             => 'guest_player_id_new',
            'label'            => __( 'Find a guest player', 'talenttrack' ),
            'user_id'          => $user_id,
            'is_admin'         => $is_admin,
            'cross_team'       => true,
            'exclude_team_id'  => $team_id,
            'show_team_filter' => true,
            'placeholder'      => __( 'Type a name to search…', 'talenttrack' ),
        ] );

        echo '</div></details>';

        echo '</div>';

        self::renderSkipRow( $skip );
    }

    private static function renderSkipRow( bool $skip ): void {
        echo '<div class="tt-field" style="margin-top:14px; padding:10px 12px; background:#f9fafb; border-radius:6px;">';
        echo '<label style="display:flex; gap:10px; align-items:flex-start; min-height:48px; touch-action:manipulation; cursor:pointer;">';
        echo '<input type="checkbox" name="attendance_skip" value="1" data-tt-roster-skip'
            . ( $skip ? ' checked' : '' )
            . ' style="width:22px; height:22px; flex:0 0 auto; margin-top:2px;" />';
        echo '<span style="flex:1; font-size:14px; color:#1a1d21;">'
            . esc_html__( 'Set attendance later — save the activity with no roster.', 'talenttrack' )
            . '</span>';
        echo '</label>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $skip = ! empty( $post['attendance_skip'] );

        // When the operator picks Skip, we drop any prior picks so a
        // back-and-forth doesn't sneak rows through. The save side
        // also short-circuits on `attendance_skip = true`.
        if ( $skip ) {
            return [
                'attendance_visited'     => true,
                'attendance_skip'        => true,
                'attendance_picks'       => [],
                'attendance_guest_picks' => [],
            ];
        }

        $raw_picks = $post['attendance_picks'] ?? [];
        if ( ! is_array( $raw_picks ) ) $raw_picks = [];
        $picks = array_values( array_unique( array_filter( array_map( 'intval', $raw_picks ) ) ) );

        // Carry forward guest picks, then promote any newly-picked
        // guest id into the holding list. The picker is single-pick;
        // the user can re-open the disclosure and pick another guest
        // on a subsequent navigation but for the in-flow case we
        // settle for one guest per render. Coaches who need >1 guest
        // can finish the wizard and add the rest from the post-save
        // guest section.
        $existing_guests = array_map( 'intval', (array) ( $state['attendance_guest_picks'] ?? [] ) );
        $raw_existing    = $post['attendance_guest_picks'] ?? [];
        if ( is_array( $raw_existing ) ) {
            // Form re-submission carries the hidden inputs forward.
            $existing_guests = array_map( 'intval', $raw_existing );
        }
        $new_guest = isset( $post['guest_player_id_new'] ) ? absint( $post['guest_player_id_new'] ) : 0;
        if ( $new_guest > 0 ) {
            $existing_guests[] = $new_guest;
        }
        // Strip the activity team's own players from the guest list
        // (defensive: PSP excludes it already, but a hand-edited POST
        // could slip past) and dedupe.
        $team_id = (int) ( $state['team_id'] ?? 0 );
        $guests = [];
        foreach ( array_unique( array_filter( $existing_guests ) ) as $gpid ) {
            $gpid = (int) $gpid;
            if ( $gpid <= 0 ) continue;
            $pl = QueryHelpers::get_player( $gpid );
            if ( ! $pl ) continue;
            if ( $team_id > 0 && (int) ( $pl->team_id ?? 0 ) === $team_id ) continue;
            $guests[] = $gpid;
        }
        $guests = array_values( array_unique( $guests ) );

        return [
            'attendance_visited'     => true,
            'attendance_skip'        => false,
            'attendance_picks'       => $picks,
            'attendance_guest_picks' => $guests,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
