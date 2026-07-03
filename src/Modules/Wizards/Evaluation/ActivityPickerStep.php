<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * ActivityPickerStep (#0072; #2246 explicit-fork rework) — the activity
 * landing on the unified evaluation wizard's `mode=activity` branch.
 * Lists the coach's recent rateable activities (last 90 days).
 *
 * #2246 removed the implicit branching this step used to carry: the
 * "auto-skip to PlayerPicker when empty" smart-default and the
 * "→ Rate a player directly" escape-hatch link are gone — the explicit
 * EvaluationModeStep now owns the activity-vs-player choice. This step
 * is skipped only when a caller pre-seeded an `activity_id` (the
 * dashboard hero, the activity-completion doors, the `mark-attendance`
 * alias). When the coach picks "Evaluate an activity" but has no
 * rateable activities, the empty-state guidance renders — never a
 * silent jump to the player path.
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
     * #2246 — skip only when a caller pre-seeded an `activity_id` (the
     * dashboard hero / activity-completion doors / mark-attendance
     * alias); otherwise always render, even with an empty list, so the
     * coach who chose "Evaluate an activity" sees guidance rather than a
     * silent redirect to the player path.
     */
    public function notApplicableFor( array $state ): bool {
        // Never applies on the player branch.
        if ( ( $state['_path'] ?? '' ) === 'player-first' ) return true;

        // Pre-seeded activity → the picker has nothing to add.
        if ( ( $state['_path'] ?? '' ) === 'activity-first'
             && (int) ( $state['activity_id'] ?? 0 ) > 0 ) {
            return true;
        }

        return false;
    }

    public function render( array $state ): void {
        if ( defined( 'TT_PLUGIN_URL' ) && defined( 'TT_VERSION' ) ) {
            wp_enqueue_style( 'tt-evaluation-mode', TT_PLUGIN_URL . 'assets/css/evaluation-mode.css', [], TT_VERSION );
        }
        $rows = self::recentRateableActivities( get_current_user_id(), self::DEFAULT_DAYS );
        ?>
        <p class="tt-eval-mode-intro">
            <?php esc_html_e( 'Pick an activity from the last 90 days to rate the players who attended. Scheduled activities appear from their planned date; activities with every present player rated drop off the list.', 'talenttrack' ); ?>
        </p>

        <?php if ( empty( $rows ) ) : ?>
            <p class="tt-notice"><?php esc_html_e( 'No rateable activities in the last 90 days. Schedule or complete an activity with a rateable type to see it here. To rate a player without an activity, go back and choose "Evaluate 1 player".', 'talenttrack' ); ?></p>
        <?php else : ?>
            <div role="radiogroup" class="tt-activity-picker">
                <?php foreach ( $rows as $r ) :
                    $when = (string) ( $r->session_date ?? '' );
                    $when_pretty = $when !== '' ? date_i18n( get_option( 'date_format', 'Y-m-d' ), strtotime( $when ) ) : '';
                    $checked = (int) ( $state['activity_id'] ?? 0 ) === (int) $r->id;
                    ?>
                    <label class="tt-activity-row" style="display:flex;align-items:center;gap:8px;padding:12px;border:1px solid var(--tt-line);border-radius:6px;margin-bottom:6px;cursor:pointer;min-height:48px;">
                        <input type="radio" name="activity_id" value="<?php echo (int) $r->id; ?>" <?php checked( $checked ); ?> required />
                        <span>
                            <strong><?php echo esc_html( (string) $r->title ); ?></strong>
                            <span style="color:var(--tt-muted);font-size:14px;">— <?php echo esc_html( (string) $r->team_name ); ?> · <?php echo esc_html( $when_pretty ); ?></span>
                            <?php if ( (int) ( $r->rated_count ?? 0 ) > 0 && (int) ( $r->unrated_present ?? 0 ) > 0 ) : ?>
                                <span style="display:block;color:var(--tt-muted);font-size:14px;">
                                    <?php
                                    /* translators: %d = number of present players without a rating yet */
                                    echo esc_html( sprintf( _n( '%d player still unrated', '%d players still unrated', (int) $r->unrated_present, 'talenttrack' ), (int) $r->unrated_present ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    public function validate( array $post, array $state ) {
        $aid = isset( $post['activity_id'] ) ? absint( $post['activity_id'] ) : 0;
        if ( $aid <= 0 ) {
            return new \WP_Error( 'no_activity', __( 'Pick an activity to continue.', 'talenttrack' ) );
        }
        return [ 'activity_id' => $aid, '_path' => 'activity-first' ];
    }

    public function nextStep( array $state ): ?string {
        // #2246 — this step only ever runs on the activity branch now
        // (the mode step routes the player branch straight to the
        // player picker). Always advance to attendance.
        return 'attendance';
    }

    public function submit( array $state ) { return null; }

    /**
     * Activities the coach can evaluate against:
     *
     *   - Past `$days` days (default 90 since v3.110.4 — was 30, but
     *     pilot cadences regularly missed two-week windows).
     *   - `plan_state` completed, OR scheduled / in_progress with the
     *     session date arrived (#1349 — planner-created sessions stay
     *     'scheduled' until a wizard run flips them; restricting to
     *     'completed' dead-ended the flow for coaches who plan ahead).
     *   - **Not fully evaluated** (#1349, supersedes the v3.110.87
     *     all-or-nothing rule): an activity drops out only when every
     *     present/late player has an eval row. Partially-rated ones
     *     stay listed with an `unrated_present` count the picker
     *     renders as "N players still unrated".
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
        // v3.110.186 (#792) — also include `a.team_id, a.location` in
        // the SELECT so the MarkAttendanceHero can reuse this method
        // via `UpcomingActivityRepository::latestRateableForCoach()`.
        // The picker itself ignores the new fields; the hero needs them
        // for `buildDetail()`.
        // #1349 — two eligibility fixes:
        //   1. plan_state widened from 'completed'-only to also accept
        //      'scheduled' / 'in_progress' sessions whose date has
        //      arrived. The Team planner creates plan_state='scheduled'
        //      and nothing flipped it before the wizard looked, so
        //      coaches who plan ahead found the flagship 1-tap flow
        //      dead-ended on training day. The wizard's own terminal-
        //      completion helper flips state to 'completed' on finish.
        //   2. The all-or-nothing NOT EXISTS eval filter is replaced by
        //      an unrated-present count (same present/late + linked-
        //      guest semantics as RateActorsStep): activities with
        //      partial evaluations stay listed, annotated with how many
        //      present players still lack a rating; fully-evaluated
        //      ones drop out as before.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.activity_type_key, a.team_id, a.location, t.name AS team_name,
                    (SELECT COUNT(DISTINCT e.player_id) FROM {$p}tt_evaluations e
                      WHERE e.activity_id = a.id AND e.club_id = a.club_id) AS rated_count,
                    (SELECT COUNT(DISTINCT pl.id)
                       FROM {$p}tt_attendance att
                       INNER JOIN {$p}tt_players pl
                           ON pl.id = COALESCE( att.guest_player_id, att.player_id )
                           AND pl.club_id = att.club_id
                      WHERE att.activity_id = a.id AND att.club_id = a.club_id
                        AND LOWER(att.status) IN ( 'present', 'late' )
                        AND ( att.is_guest = 0 OR att.guest_player_id IS NOT NULL )
                        AND NOT EXISTS (
                            SELECT 1 FROM {$p}tt_evaluations e2
                             WHERE e2.activity_id = att.activity_id
                               AND e2.player_id   = pl.id
                               AND e2.club_id     = att.club_id
                          )) AS unrated_present
               FROM {$p}tt_activities a
               INNER JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.club_id = %d
                AND a.archived_at IS NULL
                AND a.plan_state IN ('completed', 'scheduled', 'in_progress')
                AND a.session_date < CURDATE() + INTERVAL 1 DAY
                AND a.session_date >= CURDATE() - INTERVAL %d DAY
                AND COALESCE(a.evaluation_skipped, 0) = 0
                AND ( a.team_id IN (
                    SELECT tp.team_id FROM {$p}tt_team_people tp
                     INNER JOIN {$p}tt_people pe ON pe.id = tp.person_id
                     WHERE pe.wp_user_id = %d AND pe.club_id = %d
                  ) OR EXISTS (
                    SELECT 1 FROM {$p}usermeta um
                     WHERE um.user_id = %d AND um.meta_key = 'wp_capabilities'
                       AND ( um.meta_value LIKE '%administrator%' OR um.meta_value LIKE '%tt_head_dev%' OR um.meta_value LIKE '%tt_club_admin%' )
                  ) )
              GROUP BY a.id, a.title, a.session_date, a.activity_type_key, a.team_id, a.location, t.name
             HAVING rated_count = 0 OR unrated_present > 0
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
