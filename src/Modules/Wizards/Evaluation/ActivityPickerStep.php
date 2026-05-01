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

        $user_id = get_current_user_id();
        $rows = self::recentRateableActivities( $user_id, 30 );
        return empty( $rows );
    }

    public function render( array $state ): void {
        $rows = self::recentRateableActivities( get_current_user_id(), 30 );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Pick a recently-completed activity to rate the players who attended. Or rate a player directly without an activity context.', 'talenttrack' ); ?>
        </p>

        <p style="margin: var(--tt-sp-3) 0;">
            <button type="submit" name="_path" value="player-first" class="tt-button tt-button-secondary">
                <?php esc_html_e( '→ Rate a player directly', 'talenttrack' ); ?>
            </button>
        </p>

        <?php if ( empty( $rows ) ) : ?>
            <p class="tt-notice"><?php esc_html_e( 'No recent rateable activities. Pick a player to rate ad-hoc.', 'talenttrack' ); ?></p>
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
     * Activities the coach can evaluate against — past 30 days by
     * default (extendable to 90 via `Show older` follow-up), on teams
     * the coach is assigned to via `tt_team_people`, of an activity_type
     * with `meta.rateable` true (or unset — defaults true).
     *
     * @return list<object>
     */
    public static function recentRateableActivities( int $user_id, int $days ): array {
        if ( $user_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.activity_type_key, t.name AS team_name
               FROM {$p}tt_activities a
               INNER JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.club_id = %d
                AND a.archived_at IS NULL
                AND a.session_date < CURDATE() + INTERVAL 1 DAY
                AND a.session_date >= CURDATE() - INTERVAL %d DAY
                AND ( a.team_id IN (
                    SELECT tp.team_id FROM {$p}tt_team_people tp
                     INNER JOIN {$p}tt_people pe ON pe.id = tp.person_id
                     WHERE pe.wp_user_id = %d AND pe.club_id = %d
                  ) OR EXISTS (
                    SELECT 1 FROM {$p}usermeta um
                     WHERE um.user_id = %d AND um.meta_key = 'wp_capabilities'
                       AND ( um.meta_value LIKE '%administrator%' OR um.meta_value LIKE '%tt_head_dev%' OR um.meta_value LIKE '%tt_club_admin%' )
                  ) )
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
