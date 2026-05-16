<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * AttendanceStep (#0072) — captures attendance for the activity if it
 * isn't already recorded. Skipped silently when `tt_attendance` already
 * has rows for the picked activity.
 *
 * Writes are real `tt_attendance` rows — not a wizard-only side store —
 * so revisiting the activity later shows the attendance as expected.
 * Only `present` and `late` players flow forward to RateActorsStep.
 */
final class AttendanceStep implements WizardStepInterface {

    public function slug(): string  { return 'attendance'; }
    public function label(): string { return __( 'Attendance', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        if ( ( $state['_path'] ?? '' ) !== 'activity-first' ) return true;
        $aid = (int) ( $state['activity_id'] ?? 0 );
        if ( $aid <= 0 ) return true;
        // v3.110.73 — mark-attendance wizard sets this so the step always
        // renders (coach explicitly wants the roster, even if rows exist).
        // The eval wizard leaves it unset and keeps the original
        // "skip when already recorded" optimisation.
        if ( ! empty( $state['_attendance_force_render'] ) ) return false;
        return self::activityHasAttendance( $aid );
    }

    public function render( array $state ): void {
        // v3.110.120 — card-UI stylesheet. Enqueued inside render so the
        // step pulls its own dependency without the wizard view needing
        // step-specific knowledge. WordPress prints in the footer even
        // for styles enqueued mid-content.
        if ( defined( 'TT_PLUGIN_URL' ) && defined( 'TT_VERSION' ) ) {
            wp_enqueue_style( 'tt-attendance-cards', TT_PLUGIN_URL . 'assets/css/attendance-cards.css', [], TT_VERSION );
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $aid = (int) ( $state['activity_id'] ?? 0 );

        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $aid, CurrentClub::id()
        ) );

        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, jersey_number FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY last_name, first_name",
            $team_id, CurrentClub::id()
        ) );

        $statuses = $wpdb->get_results( $wpdb->prepare(
            "SELECT name FROM {$p}tt_lookups WHERE lookup_type = 'attendance_status' AND club_id = %d ORDER BY sort_order",
            CurrentClub::id()
        ) );
        $names_raw = array_map( static fn( $r ) => (string) $r->name, (array) $statuses );
        if ( empty( $names_raw ) ) $names_raw = [ 'present', 'late', 'absent', 'excused' ];
        $names_lower = array_map( 'strtolower', $names_raw );

        // v3.110.120 — pre-fill from existing `tt_attendance` rows so a
        // coach who re-enters the wizard for an already-processed
        // activity sees the saved state, not a reset to "all present".
        // Wizard state in-flight takes precedence; existing rows fall
        // back; final default is 'present'.
        $existing_by_player = [];
        $existing_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, status FROM {$p}tt_attendance
              WHERE activity_id = %d AND club_id = %d",
            $aid, CurrentClub::id()
        ) );
        foreach ( (array) $existing_rows as $row ) {
            $existing_by_player[ (int) $row->player_id ] = (string) $row->status;
        }

        // v3.110.120 — card UI requires the canonical 5-status vocabulary
        // (or a subset). Clubs that customised `attendance_status` with
        // additional values fall back to the legacy radio matrix so no
        // status is silently dropped.
        $canonical = [ 'present', 'late', 'absent', 'excused', 'injured' ];
        $card_ui_ok = true;
        foreach ( $names_lower as $n ) {
            if ( ! in_array( $n, $canonical, true ) ) { $card_ui_ok = false; break; }
        }
        if ( ! $card_ui_ok ) {
            self::renderLegacyMatrix( $players, $names_raw, $state, $existing_by_player );
            return;
        }

        // Status presence flags drive which sub-controls render.
        $has_late     = in_array( 'late', $names_lower, true );
        $has_excused  = in_array( 'excused', $names_lower, true );
        $has_injured  = in_array( 'injured', $names_lower, true );
        ?>
        <p class="tt-att-intro">
            <?php esc_html_e( "Mark each player's attendance. Present is the default — tap a card only if it differs. Only present + late players appear in the rating step.", 'talenttrack' ); ?>
        </p>

        <div class="tt-att-toolbar">
            <button type="button" class="tt-button tt-button-secondary" data-tt-mark-all-present>
                <?php esc_html_e( 'Mark all present', 'talenttrack' ); ?>
            </button>
        </div>

        <div class="tt-att-roster" data-tt-att-roster>
            <?php foreach ( (array) $players as $pl ) :
                $pid    = (int) $pl->id;
                $stored = strtolower( (string) (
                    $state['attendance'][ $pid ]
                    ?? $existing_by_player[ $pid ]
                    ?? 'present'
                ) );
                if ( ! in_array( $stored, $canonical, true ) ) $stored = 'present';
                $jersey = (int) ( $pl->jersey_number ?? 0 );
                $name   = trim( (string) $pl->first_name . ' ' . (string) $pl->last_name );
                ?>
                <article class="tt-att-card" data-tt-att-card data-tt-att-pid="<?php echo $pid; ?>" data-tt-att-status="<?php echo esc_attr( $stored ); ?>">
                    <header class="tt-att-card-head">
                        <?php if ( $jersey > 0 ) : ?>
                            <span class="tt-att-jersey">#<?php echo $jersey; ?></span>
                        <?php endif; ?>
                        <span class="tt-att-name"><?php echo esc_html( $name ); ?></span>
                        <span class="tt-att-badge tt-att-badge-late"     <?php if ( $stored !== 'late' )    echo 'hidden'; ?>><?php esc_html_e( '⏰ LATE', 'talenttrack' ); ?></span>
                        <span class="tt-att-badge tt-att-badge-absent"   <?php if ( $stored !== 'absent' )  echo 'hidden'; ?>><?php esc_html_e( '✕ ABSENT', 'talenttrack' ); ?></span>
                        <span class="tt-att-badge tt-att-badge-excused"  <?php if ( $stored !== 'excused' ) echo 'hidden'; ?>><?php esc_html_e( '🛡 EXCUSED', 'talenttrack' ); ?></span>
                        <span class="tt-att-badge tt-att-badge-injured"  <?php if ( $stored !== 'injured' ) echo 'hidden'; ?>><?php esc_html_e( '🩹 INJURED', 'talenttrack' ); ?></span>
                    </header>

                    <div class="tt-att-toggle" role="group" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: player name */ __( 'Attendance for %s', 'talenttrack' ), $name ) ); ?>">
                        <button type="button" class="tt-att-toggle-btn<?php echo in_array( $stored, [ 'present', 'late' ], true ) ? ' is-active' : ''; ?>" data-tt-att-toggle="present"><?php esc_html_e( 'Present', 'talenttrack' ); ?></button>
                        <button type="button" class="tt-att-toggle-btn<?php echo in_array( $stored, [ 'absent', 'excused', 'injured' ], true ) ? ' is-active' : ''; ?>" data-tt-att-toggle="absent"><?php esc_html_e( 'Absent', 'talenttrack' ); ?></button>
                    </div>

                    <?php if ( $has_late ) : ?>
                        <div class="tt-att-late-row" <?php if ( ! in_array( $stored, [ 'present', 'late' ], true ) ) echo 'hidden'; ?>>
                            <button type="button" class="tt-att-late-btn"    data-tt-att-late        <?php if ( $stored === 'late' ) echo 'hidden'; ?>><?php esc_html_e( '+ Mark late', 'talenttrack' ); ?></button>
                            <button type="button" class="tt-att-late-chip"   data-tt-att-late-on     <?php if ( $stored !== 'late' ) echo 'hidden'; ?>><?php esc_html_e( '✓ Late', 'talenttrack' ); ?></button>
                            <button type="button" class="tt-att-late-revert" data-tt-att-late-revert <?php if ( $stored !== 'late' ) echo 'hidden'; ?>><?php esc_html_e( '× Revert to on-time', 'talenttrack' ); ?></button>
                        </div>
                    <?php endif; ?>

                    <?php if ( $has_excused || $has_injured ) : ?>
                        <div class="tt-att-reason-row" <?php if ( ! in_array( $stored, [ 'absent', 'excused', 'injured' ], true ) ) echo 'hidden'; ?>>
                            <span class="tt-att-reason-label"><?php esc_html_e( 'Reason (optional):', 'talenttrack' ); ?></span>
                            <?php if ( $has_excused ) : ?>
                                <button type="button" class="tt-att-reason-btn<?php echo $stored === 'excused' ? ' is-active' : ''; ?>" data-tt-att-reason="excused"><?php esc_html_e( '🛡 Excused', 'talenttrack' ); ?></button>
                            <?php endif; ?>
                            <?php if ( $has_injured ) : ?>
                                <button type="button" class="tt-att-reason-btn<?php echo $stored === 'injured' ? ' is-active' : ''; ?>" data-tt-att-reason="injured"><?php esc_html_e( '🩹 Injured', 'talenttrack' ); ?></button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="attendance[<?php echo $pid; ?>]" value="<?php echo esc_attr( $stored ); ?>" data-tt-att-input />
                </article>
            <?php endforeach; ?>
        </div>

        <script>
        (function () {
            // v3.110.120 — attendance card UI state machine.
            //
            // Each `.tt-att-card` carries data-tt-att-status as the source
            // of truth and mirrors it into the hidden input that the wizard
            // form posts. Sub-control visibility is toggled here; the CSS
            // colours the badge that matches the current status.
            //
            // Transitions:
            //   present <-> absent   (toggle)
            //   present  -> late     (late button)
            //   late     -> present  (late-revert button)
            //   absent  <-> excused  (excused chip — tap again to clear)
            //   absent  <-> injured  (injured chip — tap again to clear)
            //   excused/injured -> present  (toggle back to Present)
            //
            // Tested manually on Chrome iOS 17, Safari iOS 17, Chrome
            // Android (Moto G5+).

            var roster = document.querySelector( '[data-tt-att-roster]' );
            if ( ! roster ) return;

            function show( el ) { if ( el ) el.removeAttribute( 'hidden' ); }
            function hide( el ) { if ( el ) el.setAttribute( 'hidden', '' ); }

            function setStatus( card, next ) {
                card.setAttribute( 'data-tt-att-status', next );
                var input = card.querySelector( '[data-tt-att-input]' );
                if ( input ) input.value = next;

                // Toggle pills
                card.querySelectorAll( '[data-tt-att-toggle]' ).forEach( function ( btn ) {
                    var key = btn.getAttribute( 'data-tt-att-toggle' );
                    var isPresentBranch = ( next === 'present' || next === 'late' );
                    var isAbsentBranch  = ! isPresentBranch;
                    btn.classList.toggle( 'is-active', ( key === 'present' && isPresentBranch ) || ( key === 'absent' && isAbsentBranch ) );
                } );

                // Late row (only visible on present branch)
                var lateRow = card.querySelector( '.tt-att-late-row' );
                if ( lateRow ) {
                    if ( next === 'present' || next === 'late' ) {
                        show( lateRow );
                        var lateBtn    = card.querySelector( '[data-tt-att-late]' );
                        var lateChip   = card.querySelector( '[data-tt-att-late-on]' );
                        var lateRevert = card.querySelector( '[data-tt-att-late-revert]' );
                        if ( next === 'late' ) { hide( lateBtn ); show( lateChip ); show( lateRevert ); }
                        else                    { show( lateBtn ); hide( lateChip ); hide( lateRevert ); }
                    } else {
                        hide( lateRow );
                    }
                }

                // Reason row (only visible on absent branch)
                var reasonRow = card.querySelector( '.tt-att-reason-row' );
                if ( reasonRow ) {
                    if ( next === 'absent' || next === 'excused' || next === 'injured' ) {
                        show( reasonRow );
                        card.querySelectorAll( '[data-tt-att-reason]' ).forEach( function ( btn ) {
                            btn.classList.toggle( 'is-active', btn.getAttribute( 'data-tt-att-reason' ) === next );
                        } );
                    } else {
                        hide( reasonRow );
                    }
                }

                // Status badges
                [ 'late', 'absent', 'excused', 'injured' ].forEach( function ( s ) {
                    var b = card.querySelector( '.tt-att-badge-' + s );
                    if ( b ) ( next === s ? show( b ) : hide( b ) );
                } );
            }

            // Toggle (Present <-> Absent)
            roster.addEventListener( 'click', function ( e ) {
                var t = e.target;

                var toggle = t.closest && t.closest( '[data-tt-att-toggle]' );
                if ( toggle ) {
                    var card = toggle.closest( '[data-tt-att-card]' );
                    var key  = toggle.getAttribute( 'data-tt-att-toggle' );
                    setStatus( card, key === 'absent' ? 'absent' : 'present' );
                    return;
                }

                var late = t.closest && t.closest( '[data-tt-att-late]' );
                if ( late ) { setStatus( late.closest( '[data-tt-att-card]' ), 'late' ); return; }

                var lateOn = t.closest && t.closest( '[data-tt-att-late-on]' );
                if ( lateOn ) { setStatus( lateOn.closest( '[data-tt-att-card]' ), 'present' ); return; }

                var revert = t.closest && t.closest( '[data-tt-att-late-revert]' );
                if ( revert ) { setStatus( revert.closest( '[data-tt-att-card]' ), 'present' ); return; }

                var reason = t.closest && t.closest( '[data-tt-att-reason]' );
                if ( reason ) {
                    var card2 = reason.closest( '[data-tt-att-card]' );
                    var key2  = reason.getAttribute( 'data-tt-att-reason' );
                    var cur   = card2.getAttribute( 'data-tt-att-status' );
                    // Tap the active chip → revert to plain absent.
                    setStatus( card2, cur === key2 ? 'absent' : key2 );
                    return;
                }
            } );

            // Mark all present — bulk reset to the present default for
            // every card on the page. Preserves the v3.92.4 / v3.110.78
            // single-click affordance.
            var markAll = document.querySelector( '[data-tt-mark-all-present]' );
            if ( markAll ) {
                markAll.addEventListener( 'click', function () {
                    roster.querySelectorAll( '[data-tt-att-card]' ).forEach( function ( c ) {
                        setStatus( c, 'present' );
                    } );
                } );
            }
        })();
        </script>
        <?php
    }

    /**
     * Legacy radio matrix — kept as a fallback for installs whose
     * `attendance_status` lookup vocabulary contains values outside the
     * canonical [present, late, absent, excused, injured] set. The card
     * UI's toggle + reason chips can't represent custom statuses; rather
     * than drop them silently, render the original 5+-column matrix.
     *
     * @param array<int,object> $players
     * @param array<int,string> $names_raw     ordered status names from the lookups table
     * @param array<string,mixed> $state       wizard state (in-flight edits)
     * @param array<int,string> $existing_by_player  player_id => stored status
     */
    private static function renderLegacyMatrix( array $players, array $names_raw, array $state, array $existing_by_player ): void {
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Mark each player\'s attendance. Only present + late players will appear in the rating step.', 'talenttrack' ); ?>
        </p>
        <p style="margin: 0 0 var(--tt-sp-2);">
            <button type="button" class="tt-button tt-button-secondary" data-tt-mark-all-present>
                <?php esc_html_e( 'Mark all present', 'talenttrack' ); ?>
            </button>
        </p>
        <script>
        (function () {
            var btn = document.querySelector( '[data-tt-mark-all-present]' );
            if ( ! btn ) return;
            btn.addEventListener( 'click', function () {
                var groups = {};
                document.querySelectorAll( 'input[type=radio][name^="attendance["]' ).forEach( function ( r ) {
                    var key = r.name;
                    if ( ! groups[ key ] ) groups[ key ] = [];
                    groups[ key ].push( r );
                } );
                Object.keys( groups ).forEach( function ( k ) {
                    var g = groups[ k ];
                    if ( g.length > 0 ) {
                        g[ 0 ].checked = true;
                        g[ 0 ].dispatchEvent( new Event( 'change', { bubbles: true } ) );
                    }
                } );
            } );
        })();
        </script>
        <div class="tt-table-wrap">
            <table class="tt-table" style="width:100%;">
                <thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><?php foreach ( $names_raw as $n ) : ?><th style="text-align:center;"><?php echo esc_html( \TT\Infrastructure\Query\LabelTranslator::attendanceStatus( ucfirst( $n ) ) ); ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ( $players as $pl ) :
                        $pid_int = (int) $pl->id;
                        $row_default = (string) (
                            $state['attendance'][ $pid_int ]
                            ?? $existing_by_player[ $pid_int ]
                            ?? 'present'
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html( trim( (string) $pl->first_name . ' ' . (string) $pl->last_name ) ); ?></td>
                            <?php foreach ( $names_raw as $n ) : ?>
                                <td style="text-align:center;">
                                    <input type="radio" name="attendance[<?php echo (int) $pl->id; ?>]" value="<?php echo esc_attr( $n ); ?>" <?php checked( strtolower( $row_default ), strtolower( $n ) ); ?> />
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $att = isset( $post['attendance'] ) && is_array( $post['attendance'] )
            ? array_map( 'sanitize_key', wp_unslash( $post['attendance'] ) )
            : [];

        // Persist attendance rows now — this is a real attendance update.
        $aid = (int) ( $state['activity_id'] ?? 0 );
        if ( $aid > 0 && ! empty( $att ) ) {
            global $wpdb;
            $p = $wpdb->prefix;
            foreach ( $att as $player_id => $status ) {
                $player_id = (int) $player_id;
                if ( $player_id <= 0 ) continue;
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_attendance WHERE activity_id = %d AND player_id = %d AND club_id = %d LIMIT 1",
                    $aid, $player_id, CurrentClub::id()
                ) );
                if ( $existing ) {
                    $wpdb->update( "{$p}tt_attendance", [ 'status' => $status ], [ 'id' => (int) $existing ] );
                } else {
                    $wpdb->insert( "{$p}tt_attendance", [
                        'club_id'     => CurrentClub::id(),
                        'activity_id' => $aid,
                        'player_id'   => $player_id,
                        'status'      => $status,
                    ] );
                }
            }

            // v3.110.81 — the auto-flip to `completed` used to fire
            // here on every attendance save. That's wrong: a coach
            // who marks attendance, hits Next, then Cancels on the
            // confirm step had their activity flipped to completed
            // mid-flow and the hero hid it on the next page load —
            // even though the wizard wasn't finished. Moved the flip
            // to the terminal step handlers
            // (`RateConfirmStep::submit` on Skip + `ReviewStep::submitActivityFirst`
            // on the rate-and-submit path) via the public helper
            // below. AttendanceStep now only persists the
            // `tt_attendance` rows; status transition is deferred.
        }

        return [ 'attendance' => $att ];
    }

    /**
     * v3.110.81 — terminal-completion helper. Mark-attendance wizard's
     * final steps (`RateConfirmStep::submit` for Skip, `ReviewStep::submitActivityFirst`
     * for the rate-and-submit path) call this to flip the activity to
     * `completed` AFTER the coach has actually finished the flow. Skips
     * the flip if the activity is already `completed` or `cancelled` —
     * the coach has been explicit and we shouldn't override.
     *
     * Mirrors the auto-transition in `ActivitiesRestController` (around
     * line 626-640) which the wizard's direct DB writes bypassed.
     * Idempotent and safe for the new-evaluation wizard to call too —
     * its `ActivityPicker` filters to `plan_state = 'completed'` so the
     * activity is already in terminal state and this is a no-op.
     */
    public static function completeActivityIfNotTerminal( int $activity_id ): void {
        if ( $activity_id <= 0 ) return;
        global $wpdb;
        $p = $wpdb->prefix;
        $current = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_status_key FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( $current === 'completed' || $current === 'cancelled' ) return;
        $wpdb->update(
            "{$p}tt_activities",
            [
                'activity_status_key' => 'completed',
                'plan_state'          => 'completed',
            ],
            [ 'id' => $activity_id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function nextStep( array $state ): ?string {
        // #0092 — wizards using this step can route the next step via
        // state. Default 'rate-actors' keeps the new-evaluation chain
        // unchanged; mark-attendance sets `_attendance_next = 'rate-confirm'`.
        $hint = isset( $state['_attendance_next'] ) ? (string) $state['_attendance_next'] : '';
        return $hint !== '' ? $hint : 'rate-actors';
    }
    public function submit( array $state ) { return null; }

    private static function activityHasAttendance( int $activity_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_attendance WHERE activity_id = %d AND club_id = %d LIMIT 1",
            $activity_id, CurrentClub::id()
        ) );
    }
}
