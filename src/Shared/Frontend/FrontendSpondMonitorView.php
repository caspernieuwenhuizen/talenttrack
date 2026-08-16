<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Spond\SpondSyncHealth;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendSpondMonitorView (#2284) — a diagnostic surface reachable at
 * `?tt_view=spond-monitor`.
 *
 * Answers the academy question "why does the printed activity differ from
 * what I set in Spond?" — an operator picks a Spond-linked team, fetches
 * Spond LIVE (dry-run, no DB writes), and sees exactly what's coming in
 * and how it would map onto tt_activities: which events are new, which
 * would update (with a per-field from→to diff of the Spond-wins schedule
 * fields), and which stored rows would be archived because their UID has
 * disappeared from the feed.
 *
 * Read-only by construction — the preview POSTs to
 * `POST /teams/{id}/spond/preview` (SpondRestController::route_preview),
 * which never writes. The view only COMPOSES: the fetch, parse, classify
 * and diff all live in the Spond module + the REST controller, so a
 * future SaaS frontend gets identical answers.
 *
 * Capability: view gates on `tt_edit_teams` (matches the sync + preview
 * endpoints). Breadcrumbs emit on every path, including the denial.
 */
class FrontendSpondMonitorView extends FrontendViewBase {

    private const VIEW_CAP = 'tt_edit_teams';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::VIEW_CAP ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Spond monitor', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'spond', __( 'Spond', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueViewAssets();
        FrontendBreadcrumbs::fromDashboard(
            __( 'Spond monitor', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'spond', __( 'Spond', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Spond integration monitor', 'talenttrack' ) );

        $health = SpondSyncHealth::check();

        global $wpdb;
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, age_group, spond_group_id
               FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d
                AND " . \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active' ) . "
                AND spond_group_id IS NOT NULL AND spond_group_id <> ''
              ORDER BY name ASC",
            CurrentClub::id()
        ) );
        $teams = is_array( $teams ) ? $teams : [];
        ?>
        <div class="tt-spm" data-tt-spm>
            <p class="tt-spm__intro">
                <?php esc_html_e( 'Fetch Spond live and preview how each event would map onto activities — nothing is saved. Use this to diagnose why a printed activity differs from what you set in Spond.', 'talenttrack' ); ?>
            </p>

            <?php self::renderHealthPanel( $health ); ?>

            <section class="tt-spm__section tt-spm__controls">
                <?php if ( empty( $teams ) ) : ?>
                    <p class="tt-spm__empty">
                        <?php esc_html_e( 'No teams are linked to a Spond group yet. Pick a Spond group on a team edit form first, then come back to preview it here.', 'talenttrack' ); ?>
                    </p>
                <?php else : ?>
                    <div class="tt-spm__field">
                        <label class="tt-spm__label" for="tt-spm-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                        <select id="tt-spm-team" class="tt-spm__select" data-tt-spm-team>
                            <?php foreach ( $teams as $team ) :
                                $label = (string) $team->name;
                                if ( ! empty( $team->age_group ) ) {
                                    $label .= ' · ' . (string) $team->age_group;
                                }
                                ?>
                                <option value="<?php echo (int) $team->id; ?>" data-team-id="<?php echo (int) $team->id; ?>">
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="tt-btn tt-btn-primary tt-spm__fetch" data-tt-spm-fetch>
                        <?php esc_html_e( 'Fetch now', 'talenttrack' ); ?>
                    </button>
                <?php endif; ?>
            </section>

            <div class="tt-spm__results" data-tt-spm-results role="status" aria-live="polite"></div>
        </div>
        <?php
    }

    /**
     * Render the sync-health panel (state badge + last-sync + counts).
     *
     * @param array{state:string,last_sync:string,failed_count:int,linked_count:int} $health
     */
    private static function renderHealthPanel( array $health ): void {
        $state = (string) ( $health['state'] ?? 'disabled' );
        $map   = [
            'ok'       => [ 'ok',      __( 'Healthy', 'talenttrack' ) ],
            'stale'    => [ 'partial', __( 'Stale', 'talenttrack' ) ],
            'failed'   => [ 'error',   __( 'Failing', 'talenttrack' ) ],
            'disabled' => [ 'muted',   __( 'Not configured', 'talenttrack' ) ],
        ];
        [ $variant, $label ] = $map[ $state ] ?? $map['disabled'];

        $last_sync = (string) ( $health['last_sync'] ?? '' );
        $last_html = '—';
        if ( $last_sync !== '' ) {
            $ts = strtotime( $last_sync );
            if ( $ts ) {
                $last_html = sprintf(
                    /* translators: %s: human-readable elapsed time, e.g. "3 hours". */
                    esc_html__( '%s ago', 'talenttrack' ),
                    esc_html( human_time_diff( $ts, time() ) )
                );
            } else {
                $last_html = esc_html( $last_sync );
            }
        }
        ?>
        <section class="tt-spm__section tt-spm__health">
            <div class="tt-spm__health-row">
                <span class="tt-spm__chip tt-spm__chip--<?php echo esc_attr( $variant ); ?>"><?php echo esc_html( $label ); ?></span>
                <span class="tt-spm__health-meta">
                    <?php
                    printf(
                        /* translators: 1: linked team count 2: failed team count */
                        esc_html__( '%1$d linked · %2$d failing', 'talenttrack' ),
                        (int) ( $health['linked_count'] ?? 0 ),
                        (int) ( $health['failed_count'] ?? 0 )
                    );
                    ?>
                </span>
                <span class="tt-spm__health-meta">
                    <?php esc_html_e( 'Last sync:', 'talenttrack' ); ?>
                    <?php echo wp_kses_post( $last_html ); ?>
                </span>
            </div>
        </section>
        <?php
    }

    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-spond-monitor',
            TT_PLUGIN_URL . 'assets/css/frontend-spond-monitor.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-spond-monitor',
            TT_PLUGIN_URL . 'assets/js/frontend-spond-monitor.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-spond-monitor',
            'TT_SpondMonitor',
            [
                'rest_root' => rest_url( 'talenttrack/v1/teams/' ),
                'nonce'     => wp_create_nonce( 'wp_rest' ),
                'i18n'      => [
                    'fetching'      => __( 'Fetching…', 'talenttrack' ),
                    'fetch'         => __( 'Fetch now', 'talenttrack' ),
                    'no_team'       => __( 'Pick a team first.', 'talenttrack' ),
                    'network_error' => __( 'Network error. Please try again.', 'talenttrack' ),
                    'error'         => __( 'Could not fetch. Please try again.', 'talenttrack' ),
                    'summary'       => __( '%1$d new · %2$d update · %3$d archive', 'talenttrack' ),
                    'fetched'       => __( '%d events fetched from Spond.', 'talenttrack' ),
                    'nothing'       => __( 'Spond returned no events for this group.', 'talenttrack' ),
                    'status_new'    => __( 'NEW', 'talenttrack' ),
                    'status_update' => __( 'UPDATE', 'talenttrack' ),
                    'col_type'      => __( 'Type', 'talenttrack' ),
                    'col_when'      => __( 'When', 'talenttrack' ),
                    'col_location'  => __( 'Location', 'talenttrack' ),
                    'changes'       => __( 'Would change:', 'talenttrack' ),
                    'no_changes'    => __( 'No schedule changes — already in step with Spond.', 'talenttrack' ),
                    'description'   => __( 'Description', 'talenttrack' ),
                    'from'          => __( 'stored', 'talenttrack' ),
                    'to'            => __( 'Spond', 'talenttrack' ),
                    'archive_title' => __( 'Would be archived', 'talenttrack' ),
                    'archive_hint'  => __( 'These stored activities are no longer in the Spond feed and a real sync would archive them.', 'talenttrack' ),
                    'activity'      => __( 'Activity', 'talenttrack' ),
                ],
            ]
        );
    }
}
