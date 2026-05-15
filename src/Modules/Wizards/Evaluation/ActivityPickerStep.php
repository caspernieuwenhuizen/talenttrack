<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * ActivityPickerStep (#0072) — primary landing for the new-evaluation
 * wizard. Lists the coach's recent rateable activities (last 30 days,
 * extendable to 90), grouped by week.
 *
 * The framework auto-skips this step via `notApplicableFor()` when the
 * coach has zero recent rateable activities — they go straight to
 * `PlayerPickerStep` (the player-first fallback). Either landing
 * surfaces an escape-hatch link to the other path so neither is a
 * dead end.
 */
final class ActivityPickerStep implements WizardStepInterface {

    /**
     * v3.110.4 — bumped from 30 to 90 days. Pilots reported
     * recently-completed games not appearing because their cadence
     * (one match every 2-3 weeks) means a single missed login window
     * already pushed the activity past the cutoff. 90 days lines up
     * with a typical season half-block.
     */
    private const DEFAULT_DAYS = 90;

    public function slug(): string  { return 'activity-picker'; }
    public function label(): string { return __( 'Activity', 'talenttrack' ); }

    /**
     * Skip the activity-picker entirely when the coach has no rateable
     * activities in the last 30 days — straight to PlayerPickerStep.
     * #0063's `FrontendWizardView` honours this opt-in.
     */
    public function notApplicableFor( array $state ): bool {
        // If the coach explicitly chose the "rate a player directly"
        // escape hatch, skip the activity picker even if they have
        // rateable activities.
        if ( ! empty( $state['_path'] ) && $state['_path'] === 'player-first' ) return true;

        // #0092 — when the wizard was entered with `activity_id`
        // pre-seeded (e.g. from the mark-attendance dashboard widget)
        // the picker has nothing to add; skip straight to attendance.
        if ( ( $state['_path'] ?? '' ) === 'activity-first'
             && (int) ( $state['activity_id'] ?? 0 ) > 0 ) {
            return true;
        }

        // v3.110.83 — when entered from the mark-attendance wizard
        // with no preselected activity, ALWAYS render the picker (or
        // its empty-state notice) instead of falling through to the
        // next step. The eval wizard's "auto-skip when empty →
        // PlayerPicker fallback" only fits eval-style flows; the
        // mark-attendance wizard has no PlayerPicker, so a fall-through
        // would land the coach on RateConfirmStep with no context.
        // Symptom (pilot): coach completed the wizard, returned to
        // dashboard (now showing the empty hero), clicked **Pick a
        // session**, was dropped on the confirm step of the
        // already-finished run.
        if ( ! empty( $state['_attendance_force_render'] ) ) {
            return false;
        }

        $user_id = get_current_user_id();
        $rows = self::recentRateableActivities( $user_id, self::DEFAULT_DAYS );
        return empty( $rows );
    }

    public function render( array $state ): void {
        $rows = self::recentRateableActivities( get_current_user_id(), self::DEFAULT_DAYS );
        // v3.110.83 — render-time context check. When entered from
        // the mark-attendance wizard, the eval-wizard's intro copy
        // and the "Rate a player directly" escape hatch don't fit:
        // there's no PlayerPicker in that wizard, and the coach is
        // here to mark attendance, not to rate ad-hoc. Show a
        // narrower intro + a context-specific empty state.
        $is_mark_attendance = ! empty( $state['_attendance_force_render'] );
        ?>
        <?php if ( $is_mark_attendance ) : ?>
            <p style="color:var(--tt-muted);max-width:60ch;">
                <?php esc_html_e( 'Pick a completed activity from the last 90 days to mark attendance for. Activities only appear here once they are marked completed and their type is rateable.', 'talenttrack' ); ?>
            </p>
        <?php else : ?>
            <p style="color:var(--tt-muted);max-width:60ch;">
                <?php esc_html_e( 'Pick a completed activity from the last 90 days to rate the players who attended, or rate a player directly without an activity context. Activities only appear here once they are marked completed and their type is rateable.', 'talenttrack' ); ?>
            </p>

            <p style="margin: var(--tt-sp-3) 0;">
                <button type="submit" name="_path" value="player-first" class="tt-button tt-button-secondary">
                    <?php esc_html_e( '→ Rate a player directly', 'talenttrack' ); ?>
                </button>
            </p>
        <?php endif; ?>

        <?php if ( empty( $rows ) ) : ?>
            <?php if ( $is_mark_attendance ) : ?>
                <p class="tt-notice"><?php esc_html_e( 'No activities to mark attendance for. Schedule a training or match via the Activities tile, then come back here.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <p class="tt-notice"><?php esc_html_e( 'No completed rateable activities in the last 90 days. Mark an activity as completed (and use a rateable activity type) to see it here, or pick a player below to rate ad-hoc.', 'talenttrack' ); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <div role="radiogroup" class="tt-activity-picker">
                <?php foreach ( $rows as $r ) :
                    $when = (string) ( $r->session_date ?? '' );
                    $when_pretty = $when !== '' ? date_i18n( get_option( 'date_format', 'Y-m-d' ), strtotime( $when ) ) : '';
                    $checked = (int) ( $state['activity_id'] ?? 0 ) === (int) $r->id;
                    ?>
                    <label class="tt-activity-row" style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid var(--tt-line);border-radius:6px;margin-bottom:6px;cursor:pointer;">
                        <input type="radio" name="activity_id" value="<?php echo (int) $r->id; ?>" <?php checked( $checked ); ?> required />
                        <span>
                            <strong><?php echo esc_html( (string) $r->title ); ?></strong>
                            <span style="color:var(--tt-muted);font-size:13px;">— <?php echo esc_html( (string) $r->team_name ); ?> · <?php echo esc_html( $when_pretty ); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    public function validate( array $post, array $state ) {
        // The "→ Rate a player directly" button posts _path=player-first.
        $path = isset( $post['_path'] ) ? sanitize_key( (string) $post['_path'] ) : '';
        if ( $path === 'player-first' ) {
            return [ '_path' => 'player-first' ];
        }
        $aid = isset( $post['activity_id'] ) ? absint( $post['activity_id'] ) : 0;
        if ( $aid <= 0 ) {
            return new \WP_Error( 'no_activity', __( 'Pick an activity, or use "Rate a player directly".', 'talenttrack' ) );
        }
        return [ 'activity_id' => $aid, '_path' => 'activity-first' ];
    }

    public function nextStep( array $state ): ?string {
        if ( ( $state['_path'] ?? '' ) === 'player-first' ) return 'player-picker';
        return 'attendance';
    }

    public function submit( array $state ) { return null; }

    /**
     * Activities the coach can evaluate against:
     *
     *   - Past `$days` days (default 90 since v3.110.4 — was 30, but
     *     pilot cadences regularly missed two-week windows).
     *   - `plan_state = 'completed'` (since v3.110.4) so the picker
     *     shows activities that actually happened, not scheduled-but-
     *     not-yet-played ones.
     *   - **NOT already evaluated** (v3.110.87) — `NOT EXISTS` on
     *     `tt_evaluations` for the activity. Once the wizard has
     *     written any eval row for the activity, the picker treats
     *     the run as done and stops surfacing it. Coaches who want
     *     to add more ratings to an already-rated activity use the
     *     player-first eval path or the activity detail page; the
     *     picker is for fresh runs only.
     *   - On teams the coach is assigned to via `tt_team_people` (or
     *     OR'd open for site administrators / HoD / club admins).
     *   - Of an `activity_type` with `meta.rateable` true (or unset —
     *     defaults true).
     *
     * @return list<object>
     */
    public static function recentRateableActivities( int $user_id, int $days ): array {
        if ( $user_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        // v3.92.2 — `GROUP BY a.id` defensively dedupes when the
        // `IN (sub-SELECT)` over `tt_team_people` matches a coach who
        // holds multiple functional-role rows on the same team. The
        // pilot install reported the same activity rendering twice in
        // the picker; the most plausible cause is the multi-FR-on-same-
        // team case multiplying the row set during planner evaluation.
        // Grouping by the primary key collapses duplicates regardless of
        // which OR branch fired.
        // v3.110.87 — exclude activities that already carry at least
        // one `tt_evaluations` row. Pilot symptom: coach completed the
        // mark-attendance wizard (attendance + rating + Submit) for
        // tonight's training, returned to the dashboard, clicked the
        // empty-state **Pick an activity** CTA, and the picker still
        // listed the activity they'd just finished. The picker treats
        // "completed activity in the last 90 days" as eligible without
        // checking evaluation-completion, so freshly-rated rows kept
        // surfacing. The NOT EXISTS filter checks for ANY eval on the
        // activity — same rule for both wizards (eval + mark-attendance).
        // Coach who wants to add more ratings to an already-rated
        // activity can use the player-first eval path or the activity
        // detail page; the wizard picker is for fresh runs.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.activity_type_key, t.name AS team_name
               FROM {$p}tt_activities a
               INNER JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.club_id = %d
                AND a.archived_at IS NULL
                AND a.plan_state = 'completed'
                AND a.session_date < CURDATE() + INTERVAL 1 DAY
                AND a.session_date >= CURDATE() - INTERVAL %d DAY
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_evaluations e
                     WHERE e.activity_id = a.id AND e.club_id = a.club_id
                  )
                AND ( a.team_id IN (
                    SELECT tp.team_id FROM {$p}tt_team_people tp
                     INNER JOIN {$p}tt_people pe ON pe.id = tp.person_id
                     WHERE pe.wp_user_id = %d AND pe.club_id = %d
                  ) OR EXISTS (
                    SELECT 1 FROM {$p}usermeta um
                     WHERE um.user_id = %d AND um.meta_key = 'wp_capabilities'
                       AND ( um.meta_value LIKE '%administrator%' OR um.meta_value LIKE '%tt_head_dev%' OR um.meta_value LIKE '%tt_club_admin%' )
                  ) )
              GROUP BY a.id, a.title, a.session_date, a.activity_type_key, t.name
              ORDER BY a.session_date DESC
              LIMIT 30",
            CurrentClub::id(), $days, $user_id, CurrentClub::id(), $user_id
        ) );

        if ( ! is_array( $rows ) ) return [];

        // Filter on meta.rateable — keep rows where the type is rateable
        // (or unset, which defaults to true).
        return array_values( array_filter( $rows, static function ( $r ): bool {
            $type = (string) ( $r->activity_type_key ?? '' );
            if ( $type === '' ) return true;
            return QueryHelpers::isActivityTypeRateable( $type );
        } ) );
    }
}
