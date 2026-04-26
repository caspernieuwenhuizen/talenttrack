<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendTileGrid — v2.21.0 tile landing page for the frontend
 * dashboard shortcode.
 *
 * When a user lands on the dashboard without a specific ?tt_view
 * query param, they see a grid of tiles appropriate to their role:
 *
 *   - Player      → my card, my team, my evals, my sessions, my goals, my profile
 *   - Coach       → teams, players, evals, sessions, goals, podium, rate cards, comparison
 *   - Admin       → coach tiles + "Go to admin" + access control
 *   - Observer    → same discovery as coach (cap-gated), write actions blocked inside
 *
 * Tile visibility is driven entirely by WordPress capabilities so the
 * same tile set automatically respects the tt_readonly_observer role
 * (same as coach for viewing; write-blocked at controller level).
 *
 * Tapping a tile appends ?tt_view=<slug> and reloads. Handling of the
 * sub-views is left to the existing Player/Coach dashboard classes —
 * this layer is pure navigation chrome.
 */
class FrontendTileGrid {

    /**
     * Render the tile grid for the current user. Assumes we're inside
     * a `<div class="tt-dashboard">` already.
     */
    public static function render(): void {
        $user_id = get_current_user_id();
        $is_admin    = current_user_can( 'tt_edit_settings' );
        $is_coach    = current_user_can( 'tt_edit_evaluations' );
        $can_report  = current_user_can( 'tt_view_reports' );
        $is_player   = (bool) QueryHelpers::get_player_for_user( $user_id );

        // Determine primary role for context. Admin > Coach > Reports-only > Player.
        $greeting = self::greeting( $user_id );

        $groups = self::buildGroups( [
            'user_id'    => $user_id,
            'is_admin'   => $is_admin,
            'is_coach'   => $is_coach,
            'can_report' => $can_report,
            'is_player'  => $is_player,
        ] );

        ?>
        <style>
        .tt-ftile-greeting {
            font-size: 20px;
            font-weight: 600;
            margin: 20px 0 6px;
            color: #1a1d21;
        }
        .tt-ftile-hint {
            color: #666;
            font-size: 14px;
            margin: 0 0 24px;
        }
        .tt-ftile-section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #8a9099;
            margin: 24px 0 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tt-ftile-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dcdcde;
        }
        .tt-ftile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
        }
        .tt-ftile {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: #fff;
            border: 1px solid #e5e7ea;
            border-radius: 10px;
            padding: 18px 18px;
            text-decoration: none;
            color: #1a1d21;
            min-height: 80px;
            transition: transform 200ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 200ms ease;
        }
        .tt-ftile:hover, .tt-ftile:focus {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            color: #1a1d21;
        }
        .tt-ftile-icon {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 24px;
        }
        .tt-ftile-body {
            flex: 1;
            min-width: 0;
        }
        .tt-ftile-label {
            font-weight: 600;
            font-size: clamp(13px, 1.4vw, 16px);
            line-height: 1.25;
            margin: 0 0 4px;
            color: #1a1d21;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .tt-ftile-desc {
            color: #666;
            font-size: clamp(11px, 1.1vw, 13px);
            line-height: 1.4;
            margin: 0;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        @media (max-width: 640px) {
            .tt-ftile-grid { grid-template-columns: 1fr; }
            .tt-ftile { padding: 14px 14px; min-height: 68px; }
            .tt-ftile-icon { width: 40px; height: 40px; font-size: 20px; }
            .tt-ftile-label { font-size: clamp(14px, 4vw, 16px); }
            .tt-ftile-desc { font-size: 12px; }
        }
        </style>

        <div class="tt-ftile-greeting"><?php echo esc_html( $greeting ); ?></div>
        <p class="tt-ftile-hint">
            <?php esc_html_e( 'Tap a tile to go straight to that section.', 'talenttrack' ); ?>
        </p>

        <?php foreach ( $groups as $group ) :
            $visible = array_filter( $group['tiles'], function ( $t ) { return ! isset( $t['show'] ) || $t['show']; } );
            if ( empty( $visible ) ) continue;
            ?>
            <div class="tt-ftile-section-label">
                <span><?php echo esc_html( $group['label'] ); ?></span>
            </div>
            <div class="tt-ftile-grid">
                <?php foreach ( $visible as $tile ) : ?>
                    <a class="tt-ftile" href="<?php echo esc_url( $tile['url'] ); ?>">
                        <span class="tt-ftile-icon" style="background:<?php echo esc_attr( $tile['color'] ); ?>;">
                            <?php echo esc_html( $tile['emoji'] ?? '•' ); ?>
                        </span>
                        <div class="tt-ftile-body">
                            <div class="tt-ftile-label"><?php echo esc_html( $tile['label'] ); ?></div>
                            <p class="tt-ftile-desc"><?php echo esc_html( $tile['desc'] ); ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php
    }

    /* ═══════════════ Tile definitions ═══════════════ */

    /**
     * Build the tile groups for the current user. Groups are ordered
     * top-to-bottom. Each tile has a `show` boolean gating visibility.
     *
     * @param array{
     *   user_id:int, is_admin:bool, is_coach:bool,
     *   can_report:bool, is_player:bool
     * } $ctx
     * @return array<int, array{label:string, tiles:array}>
     */
    private static function buildGroups( array $ctx ): array {
        $base = self::shortcodeBaseUrl();
        $url  = function ( string $view ) use ( $base ) {
            return add_query_arg( 'tt_view', $view, $base );
        };

        $is_admin  = $ctx['is_admin'];
        $is_coach  = $ctx['is_coach'];
        $can_report= $ctx['can_report'];
        $is_player = $ctx['is_player'];

        // "Me" group — always visible if user is linked to a player record.
        $me_tiles = [
            [
                'label' => __( 'My card', 'talenttrack' ),
                'desc'  => __( 'Your FIFA-style card, ratings, and headline numbers.', 'talenttrack' ),
                'emoji' => '🪪',
                'color' => '#1d7874',
                'url'   => $url( 'overview' ),
                'show'  => $is_player,
            ],
            [
                'label' => __( 'My team', 'talenttrack' ),
                'desc'  => __( 'Your teammates and the team podium.', 'talenttrack' ),
                'emoji' => '🛡',
                'color' => '#2271b1',
                'url'   => $url( 'my-team' ),
                'show'  => $is_player,
            ],
            [
                'label' => __( 'My evaluations', 'talenttrack' ),
                'desc'  => __( 'Ratings and feedback from your coaches.', 'talenttrack' ),
                'emoji' => '📊',
                'color' => '#7c3a9e',
                'url'   => $url( 'my-evaluations' ),
                'show'  => $is_player,
            ],
            [
                'label' => __( 'My sessions', 'talenttrack' ),
                'desc'  => __( 'Training sessions you\'ve attended.', 'talenttrack' ),
                'emoji' => '🗓',
                'color' => '#c9962a',
                'url'   => $url( 'my-sessions' ),
                'show'  => $is_player,
            ],
            [
                'label' => __( 'My goals', 'talenttrack' ),
                'desc'  => __( 'Development goals to work toward.', 'talenttrack' ),
                'emoji' => '🎯',
                'color' => '#b32d2e',
                'url'   => $url( 'my-goals' ),
                'show'  => $is_player,
            ],
            [
                'label' => __( 'My profile', 'talenttrack' ),
                'desc'  => __( 'Your personal details and contact info.', 'talenttrack' ),
                'emoji' => '👤',
                'color' => '#555',
                'url'   => $url( 'profile' ),
                'show'  => $is_player,
            ],
        ];

        // "People" group — teams, players, people records, roles.
        // Mirrors the wp-admin People menu section.
        $people_tiles = [
            [
                'label' => __( 'My teams', 'talenttrack' ),
                'desc'  => __( 'Teams you coach — roster, podium, evaluations.', 'talenttrack' ),
                'emoji' => '🛡',
                'color' => '#2271b1',
                'url'   => $url( 'teams' ),
                'show'  => $is_coach || $is_admin,
            ],
            [
                'label' => __( 'Players', 'talenttrack' ),
                'desc'  => __( 'Roster of all players on your coached teams.', 'talenttrack' ),
                'emoji' => '👥',
                'color' => '#1d7874',
                'url'   => $url( 'players' ),
                'show'  => $is_coach || $is_admin,
            ],
            [
                'label' => __( 'Import players', 'talenttrack' ),
                'desc'  => __( 'Bulk import players from a CSV file.', 'talenttrack' ),
                'emoji' => '⬆',
                'color' => '#1d7874',
                'url'   => $url( 'players-import' ),
                'show'  => $is_admin || current_user_can( 'tt_edit_players' ),
            ],
            [
                'label' => __( 'People', 'talenttrack' ),
                'desc'  => __( 'Staff, parents, scouts and other non-player records.', 'talenttrack' ),
                'emoji' => '🧑‍💼',
                'color' => '#5b6e75',
                'url'   => $url( 'people' ),
                'show'  => current_user_can( 'tt_view_people' ) || current_user_can( 'tt_edit_people' ),
            ],
            [
                'label' => __( 'Functional roles', 'talenttrack' ),
                'desc'  => __( 'Manage role types and team assignments.', 'talenttrack' ),
                'emoji' => '🛠',
                'color' => '#5b6e75',
                'url'   => $url( 'functional-roles' ),
                'show'  => current_user_can( 'tt_manage_functional_roles' ) || current_user_can( 'tt_view_people' ),
            ],
        ];

        // "Performance" group — evaluations, sessions, goals, podium.
        // Mirrors the wp-admin Performance menu section.
        $performance_tiles = [
            [
                'label' => __( 'Evaluations', 'talenttrack' ),
                'desc'  => __( 'Record player ratings, add notes and scores.', 'talenttrack' ),
                'emoji' => '📝',
                'color' => '#7c3a9e',
                'url'   => $url( 'evaluations' ),
                'show'  => $is_coach || $is_admin,
            ],
            [
                'label' => __( 'Sessions', 'talenttrack' ),
                'desc'  => __( 'Log training sessions and attendance.', 'talenttrack' ),
                'emoji' => '🗓',
                'color' => '#c9962a',
                'url'   => $url( 'sessions' ),
                'show'  => $is_coach || $is_admin,
            ],
            [
                'label' => __( 'Goals', 'talenttrack' ),
                'desc'  => __( 'Set and track player development goals.', 'talenttrack' ),
                'emoji' => '🎯',
                'color' => '#b32d2e',
                'url'   => $url( 'goals' ),
                'show'  => $is_coach || $is_admin,
            ],
            [
                'label' => __( 'Podium', 'talenttrack' ),
                'desc'  => __( 'Team rankings and top performers.', 'talenttrack' ),
                'emoji' => '🏆',
                'color' => '#e8b624',
                'url'   => $url( 'podium' ),
                'show'  => $is_coach || $is_admin,
            ],
            [
                'label' => __( 'Methodology', 'talenttrack' ),
                'desc'  => __( 'Principles, formations, positions and set pieces.', 'talenttrack' ),
                'emoji' => '📘',
                'color' => '#1d7874',
                'url'   => $url( 'methodology' ),
                'show'  => current_user_can( 'tt_view_methodology' ),
            ],
        ];

        // "Analytics" — requires tt_view_reports. Covers observer role too.
        // Usage statistics moved here to match wp-admin Analytics section.
        $can_frontend_admin = current_user_can( 'tt_access_frontend_admin' );
        $analytics_tiles = [
            [
                'label' => __( 'Rate cards', 'talenttrack' ),
                'desc'  => __( 'Per-player rating cards with trends.', 'talenttrack' ),
                'emoji' => '📇',
                'color' => '#2271b1',
                'url'   => $url( 'rate-cards' ),
                'show'  => $can_report,
            ],
            [
                'label' => __( 'Player comparison', 'talenttrack' ),
                'desc'  => __( 'Compare up to 4 players side-by-side.', 'talenttrack' ),
                'emoji' => '⚖',
                'color' => '#7c3a9e',
                'url'   => $url( 'compare' ),
                'show'  => $can_report,
            ],
            [
                'label' => __( 'Usage statistics', 'talenttrack' ),
                'desc'  => __( 'Logins, active users, evaluations per day.', 'talenttrack' ),
                'emoji' => '📈',
                'color' => '#555',
                'url'   => $url( 'usage-stats' ),
                'show'  => $can_frontend_admin,
            ],
        ];

        // "Administration" — admins only. v3.11.0 (#0019 Sprint 5)
        // promoted these surfaces from wp-admin only into first-class
        // frontend tiles. Gated by tt_access_frontend_admin (granted to
        // administrator + tt_head_dev by default).
        $admin_tiles = [
            [
                'label' => __( 'Configuration', 'talenttrack' ),
                'desc'  => __( 'Branding, theme inheritance, rating scale.', 'talenttrack' ),
                'emoji' => '⚙',
                'color' => '#555',
                'url'   => $url( 'configuration' ),
                'show'  => $can_frontend_admin,
            ],
            [
                'label' => __( 'Custom fields', 'talenttrack' ),
                'desc'  => __( 'Add per-entity custom fields.', 'talenttrack' ),
                'emoji' => '🧩',
                'color' => '#555',
                'url'   => $url( 'custom-fields' ),
                'show'  => $can_frontend_admin,
            ],
            [
                'label' => __( 'Eval categories', 'talenttrack' ),
                'desc'  => __( 'Manage the evaluation category tree.', 'talenttrack' ),
                'emoji' => '🌳',
                'color' => '#555',
                'url'   => $url( 'eval-categories' ),
                'show'  => $can_frontend_admin,
            ],
            [
                'label' => __( 'Roles', 'talenttrack' ),
                'desc'  => __( 'Reference for the eight TalentTrack roles.', 'talenttrack' ),
                'emoji' => '🛡',
                'color' => '#555',
                'url'   => $url( 'roles' ),
                'show'  => $can_frontend_admin,
            ],
            [
                'label' => __( 'Migrations', 'talenttrack' ),
                'desc'  => __( 'Database migration status (read-only).', 'talenttrack' ),
                'emoji' => '🗄',
                'color' => '#555',
                'url'   => $url( 'migrations' ),
                'show'  => $can_frontend_admin,
            ],
            [
                'label' => __( 'Open wp-admin', 'talenttrack' ),
                'desc'  => __( 'Drop into the full WordPress admin dashboard.', 'talenttrack' ),
                'emoji' => '↗',
                'color' => '#888',
                'url'   => admin_url( 'admin.php?page=talenttrack' ),
                'show'  => $is_admin,
            ],
        ];

        // "Tasks" group — #0022 Sprint 2. The inbox tile shows for every
        // user with tt_view_own_tasks. When the user has open tasks the
        // tile carries a count badge appended to its label.
        $tasks_tiles = [];
        if ( current_user_can( 'tt_view_own_tasks' ) ) {
            $open_count = \TT\Modules\Workflow\Frontend\FrontendMyTasksView::openCountForUser( $ctx['user_id'] );
            $label = $open_count > 0
                ? sprintf(
                    /* translators: %d: number of open tasks */
                    __( 'My tasks (%d)', 'talenttrack' ),
                    $open_count
                )
                : __( 'My tasks', 'talenttrack' );
            $tasks_tiles[] = [
                'label' => $label,
                'desc'  => __( 'Open tasks waiting on you — evaluations, goals, reviews.', 'talenttrack' ),
                'emoji' => '📥',
                'color' => $open_count > 0 ? '#b32d2e' : '#5b6e75',
                'url'   => $url( 'my-tasks' ),
                'show'  => true,
            ];
        }

        return [
            [
                'label' => __( 'Me', 'talenttrack' ),
                'tiles' => $me_tiles,
            ],
            [
                'label' => __( 'Tasks', 'talenttrack' ),
                'tiles' => $tasks_tiles,
            ],
            [
                'label' => __( 'People', 'talenttrack' ),
                'tiles' => $people_tiles,
            ],
            [
                'label' => __( 'Performance', 'talenttrack' ),
                'tiles' => $performance_tiles,
            ],
            [
                'label' => __( 'Analytics', 'talenttrack' ),
                'tiles' => $analytics_tiles,
            ],
            [
                'label' => __( 'Administration', 'talenttrack' ),
                'tiles' => $admin_tiles,
            ],
        ];
    }

    private static function greeting( int $user_id ): string {
        $user = get_userdata( $user_id );
        $name = $user ? $user->display_name : '';
        return $name !== ''
            ? sprintf(
                /* translators: %s is user display name */
                __( 'Welcome, %s', 'talenttrack' ),
                $name
            )
            : __( 'Welcome', 'talenttrack' );
    }

    /**
     * Base URL of the current page without tt_view or any drill-down
     * params. Tiles append ?tt_view=<slug> to this.
     */
    private static function shortcodeBaseUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'session_id', 'goal_id', 'team_id', 'tab' ],
            $current ?: home_url( '/' )
        );
    }
}
