<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupPill;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\AgeTier;
use TT\Shared\Frontend\Components\EmptyStateCard;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendPlayerDetailView — read-only display of a single player
 * (#0063), reachable via `?tt_view=players&id=N`.
 *
 * Mirrors the v3.62 per-record-detail precedent established by
 * FrontendMyGoalsView::renderDetail and FrontendMyActivitiesView::renderDetail
 * — the manage view's render() method early-branches into here when
 * `?id=N` is present.
 *
 * Cap-gated on `tt_view_players` (existing). The list-side pages
 * already gate at that level; this view re-asserts inside the render
 * so direct URL access also enforces.
 *
 * Composition only: every datum comes from existing repositories /
 * QueryHelpers — no business logic in the render. Per CLAUDE.md § 4
 * SaaS-readiness rule.
 */
final class FrontendPlayerDetailView extends FrontendViewBase {

    private static bool $detail_css_enqueued = false;

    /**
     * #0082 — view-level stylesheet replaces the legacy inline <style>
     * block. Idempotent across the request.
     */
    private static function enqueueDetailCss(): void {
        if ( self::$detail_css_enqueued ) return;
        wp_enqueue_style(
            'tt-frontend-player-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-player-detail.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        self::$detail_css_enqueued = true;
    }

    /**
     * #0077 M8 — tabbed case page. Profile is default; other tabs swap
     * via `?tab=goals|evaluations|activities|pdp|trials`.
     *
     * @return array<string,string>  tab key => human label
     */
    private static function tabs(): array {
        $tabs = [
            'profile'     => __( 'Profile', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'evaluations' => __( 'Evaluations', 'talenttrack' ),
            'activities'  => __( 'Activities', 'talenttrack' ),
            'pdp'         => __( 'PDP cycle', 'talenttrack' ),
            'trials'      => __( 'Trials', 'talenttrack' ),
        ];
        // #0085 — Notes tab is staff-only. The cap check is enough for
        // tab visibility; per-player scope is enforced by
        // `PlayerThreadAdapter::canRead` when the tab renders.
        if ( current_user_can( 'tt_view_player_notes' ) ) {
            $tabs['notes'] = __( 'Notes', 'talenttrack' );
        }
        // #0083 Child 4 — Analytics tab. Discovery surface for the
        // reporting framework: a coach looking at a player can ask
        // "how is Lucas doing on attendance" without navigating to
        // a separate Analytics page.
        if ( class_exists( '\\TT\\Modules\\Analytics\\Frontend\\EntityAnalyticsTabRenderer' ) ) {
            $tabs['analytics'] = __( 'Analytics', 'talenttrack' );
        }
        return $tabs;
    }

    public static function render( int $player_id, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_players' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view player details.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( $player_id );

        if ( ! $player ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Player not found', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ) ]
            );
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That player is no longer available, or you do not have access.', 'talenttrack' ) . '</em></p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueDetailCss();
        $name = QueryHelpers::player_display_name( $player );

        // #0077 F2 — breadcrumb chain replaces the standalone back link.
        $players_url = add_query_arg( [ 'tt_view' => 'players' ], RecordLink::dashboardUrl() );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::render( [
            [ 'label' => __( 'Dashboard', 'talenttrack' ), 'url' => RecordLink::dashboardUrl() ],
            [ 'label' => __( 'Players', 'talenttrack' ),   'url' => $players_url ],
            [ 'label' => $name ],
        ] );
        // v3.92.0 — page title is now "Player file of {name}" instead of
        // just the name. Hero card surfaces the team / status / journey;
        // the page title carries the "this is a player file about ..."
        // framing.
        /* translators: %s: player display name */
        $page_title = sprintf( __( 'Player file of %s', 'talenttrack' ), $name );

        // v3.110.53 — Edit + Archive page-header actions. Edit becomes
        // a FAB bottom-right on mobile via .tt-page-actions__primary;
        // Archive is desktop-only via the secondary class. Archive
        // wires through to REST DELETE players/{id} via the generic
        // tt-frontend-archive-button.js handler, with a confirm()
        // dialog and redirect to the players list on success.
        $actions = [];
        if ( current_user_can( 'tt_edit_players' ) ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'players', 'id' => $player_id, 'action' => 'edit' ],
                RecordLink::dashboardUrl()
            );
            $actions[] = [
                'label'   => __( 'Edit', 'talenttrack' ),
                'href'    => $edit_url,
                'primary' => true,
                'icon'    => '✎',
            ];
            // #0093 — surface "Assign to team" prominently when the player
            // has no team yet. Trial-admit lands players in this state
            // (status=active, team_id=NULL); the affordance jumps the
            // operator to the inline picker on the Profile tab.
            if ( empty( $player->team_id ) ) {
                $assign_href = add_query_arg(
                    [ 'tt_view' => 'players', 'id' => $player_id, 'tab' => 'profile' ],
                    RecordLink::dashboardUrl()
                ) . '#tt-player-assign-team';
                $actions[] = [
                    'label' => __( 'Assign to team', 'talenttrack' ),
                    'href'  => $assign_href,
                    'icon'  => '+',
                ];
            }
            $actions[] = [
                'label'   => __( 'Archive', 'talenttrack' ),
                'variant' => 'danger',
                'data_attrs' => [
                    'tt-archive-rest-path' => 'players/' . $player_id,
                    'tt-archive-confirm'   => __( 'Archive this player? They can be restored later by a site admin.', 'talenttrack' ),
                    'tt-archive-redirect'  => $players_url,
                ],
            ];
        }
        echo '<header class="tt-page-head" style="margin:6px 0 14px;">';
        echo '<h1 class="tt-fview-title" style="font-size:22px; color:#1a1d21;">' . esc_html( $page_title ) . '</h1>';
        if ( $actions ) {
            echo '<div class="tt-page-actions">' . self::pageActionsHtml( $actions ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pageActionsHtml escapes per-action.
        }
        echo '</header>';

        $team       = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $team_url   = $team ? add_query_arg( [ 'tt_view' => 'teams', 'id' => (int) $team->id ], RecordLink::dashboardUrl() ) : '';

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'profile';
        if ( ! array_key_exists( $active_tab, self::tabs() ) ) $active_tab = 'profile';
        $base_url = add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id ], RecordLink::dashboardUrl() );

        $counts = PlayerFileCounts::for( $player_id );
        ?>
        <article class="tt-player-detail">
            <?php self::renderHero( $player, $name, $team, $team_url ); ?>

            <nav class="tt-player-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Player sections', 'talenttrack' ); ?>">
                <?php foreach ( self::tabs() as $key => $label ) :
                    $url = add_query_arg( [ 'tab' => $key ], $base_url );
                    $is_active = $key === $active_tab;
                    $count     = $counts[ $key ] ?? null;
                    $classes   = 'tt-player-tab';
                    if ( $is_active ) $classes .= ' tt-player-tab--active';
                    if ( $key !== 'profile' && $count === 0 ) $classes .= ' tt-player-tab--empty';
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="<?php echo esc_attr( $classes ); ?>"
                       role="tab"
                       aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $key !== 'profile' && (int) $count > 0 ) : ?>
                            <span class="tt-tab-badge"><?php echo (int) $count; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="tt-player-tab-panel">
                <?php
                switch ( $active_tab ) {
                    case 'goals':       self::renderGoalsTab( $player_id ); break;
                    case 'evaluations': self::renderEvaluationsTab( $player_id ); break;
                    case 'activities':  self::renderActivitiesTab( $player_id, $player ); break;
                    case 'pdp':         self::renderPdpTab( $player_id ); break;
                    case 'trials':      self::renderTrialsTab( $player_id, $player ); break;
                    case 'notes':       self::renderNotesTab( $player_id, $user_id ); break;
                    case 'analytics':
                        // #0083 Child 4 — Analytics tab. Renders KPI grid
                        // scoped to this player; cards click through to
                        // the dimension explorer (#0083 Child 3).
                        \TT\Modules\Analytics\Frontend\EntityAnalyticsTabRenderer::render( 'player', $player_id );
                        break;
                    case 'profile':
                    default:            self::renderProfileTab( $player ); break;
                }
                ?>
            </section>
        </article>
        <?php
    }

    /**
     * #0082 — hero card. Photo (or initials placeholder) + structured
     * info block (team / age-tier / status pill / days-in-academy /
     * latest-record chips). Markup is mobile-first; styles in
     * frontend-player-detail.css.
     */
    private static function renderHero( object $player, string $name, ?object $team, string $team_url ): void {
        $player_id  = (int) $player->id;
        $photo      = (string) ( $player->photo_url ?? '' );
        $journey    = self::journeyText( $player );
        $latest     = self::latestRecords( $player_id );
        ?>
        <header class="tt-player-hero">
            <figure class="tt-player-hero__photo-frame">
                <?php if ( $photo !== '' ) : ?>
                    <img class="tt-player-hero__photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
                <?php else : ?>
                    <span class="tt-player-hero__initials" aria-hidden="true"><?php echo esc_html( self::initialsFor( $name ) ); ?></span>
                <?php endif; ?>
            </figure>
            <div class="tt-player-hero__body">
                <div class="tt-player-hero__primary">
                    <?php if ( $team ) : ?>
                        <span class="tt-player-hero__team">
                            <a class="tt-record-link" href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( (string) $team->name ); ?></a>
                            <?php if ( ! empty( $team->age_group ) ) : ?>
                                <span class="tt-muted"> &middot; <?php echo esc_html( (string) $team->age_group ); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( ! empty( $player->status ) ) : ?>
                        <?php echo LookupPill::render( 'player_status', (string) $player->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pill returns escaped html ?>
                    <?php endif; ?>
                </div>
                <?php if ( $journey !== null ) : ?>
                    <div class="tt-player-hero__journey">
                        <?php if ( $journey['days'] !== '' ) : ?>
                            <span class="tt-player-hero__days"><?php echo esc_html( $journey['days'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( $journey['joined'] !== '' ) : ?>
                            <span class="tt-player-hero__joined"><?php echo esc_html( $journey['joined'] ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $latest ) ) : ?>
                    <div class="tt-player-hero__latest">
                        <?php foreach ( $latest as $chip ) : ?>
                            <a class="tt-player-hero__chip" href="<?php echo esc_url( $chip['url'] ); ?>">
                                <span class="tt-player-hero__chip-label"><?php echo esc_html( $chip['label'] ); ?></span>
                                <?php echo esc_html( $chip['value'] ); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>
        <?php
    }

    /**
     * @return array{days:string,joined:string}|null  null when no usable date.
     */
    private static function journeyText( object $player ): ?array {
        $joined_raw = (string) ( $player->date_joined ?? '' );
        $created_raw = (string) ( $player->created_at ?? '' );
        $source = $joined_raw !== '' ? $joined_raw : $created_raw;
        if ( $source === '' ) return null;

        $ts = strtotime( $source );
        if ( $ts === false ) return null;

        $now      = current_time( 'timestamp' );
        $days     = max( 0, (int) floor( ( $now - $ts ) / DAY_IN_SECONDS ) );
        $is_fresh = $joined_raw === '' && $days < 7;

        if ( $is_fresh ) {
            return [ 'days' => __( 'Joined recently', 'talenttrack' ), 'joined' => '' ];
        }

        /* translators: %d: number of days */
        $days_text = sprintf( _n( '%d day in academy', '%d days in academy', $days, 'talenttrack' ), $days );
        /* translators: %s: ISO date the player joined */
        $joined_text = $joined_raw !== ''
            ? sprintf( __( 'Joined %s', 'talenttrack' ), gmdate( 'Y-m-d', $ts ) )
            : '';

        return [ 'days' => $days_text, 'joined' => $joined_text ];
    }

    /**
     * @return list<array{label:string,value:string,url:string}>
     */
    private static function latestRecords( int $player_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $out = [];

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d AND att.is_guest = 0 AND a.archived_at IS NULL
              ORDER BY a.session_date DESC LIMIT 1",
            $player_id
        ) );
        if ( $row ) {
            $title = trim( (string) ( $row->title ?? '' ) );
            $date  = (string) ( $row->session_date ?? '' );
            $out[] = [
                'label' => __( 'Latest activity:', 'talenttrack' ),
                'value' => $title !== '' ? $title : $date,
                'url'   => RecordLink::detailUrlForWithBack( 'activities', (int) $row->id ),
            ];
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, eval_date FROM {$p}tt_evaluations
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY eval_date DESC LIMIT 1",
            $player_id
        ) );
        if ( $row ) {
            $out[] = [
                'label' => __( 'Latest evaluation:', 'talenttrack' ),
                'value' => (string) ( $row->eval_date ?? '—' ),
                'url'   => RecordLink::detailUrlForWithBack( 'evaluations', (int) $row->id ),
            ];
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title FROM {$p}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY created_at DESC LIMIT 1",
            $player_id
        ) );
        if ( $row ) {
            $out[] = [
                'label' => __( 'Latest goal:', 'talenttrack' ),
                'value' => (string) ( $row->title ?? '—' ),
                'url'   => RecordLink::detailUrlForWithBack( 'goals', (int) $row->id ),
            ];
        }

        return $out;
    }

    private static function initialsFor( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name ) ?: [ $name ];
        $first = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 );
        $last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';
        return mb_strtoupper( $first . $last );
    }

    /**
     * Profile tab — two-column Identity / Academy grid + behaviour /
     * potential capture entry. Two-column at ≥ 768px, single column
     * below; layout owned by frontend-player-detail.css.
     */
    private static function renderProfileTab( object $player ): void {
        $player_id  = (int) $player->id;
        $age_tier   = AgeTier::forPlayer( $player_id );
        $tier_label = AgeTier::labels()[ $age_tier ] ?? '';
        $positions  = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
        $team       = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $foot_label = ! empty( $player->preferred_foot )
            ? LookupPill::render( 'foot_options', (string) $player->preferred_foot )
            : '';
        $status_label = ! empty( $player->status )
            ? \TT\Infrastructure\Query\LabelTranslator::playerStatus( (string) $player->status )
            : '';
        $team_html = '';
        if ( $team ) {
            $team_html = esc_html( (string) $team->name );
            if ( ! empty( $team->age_group ) ) {
                $team_html .= ' <span class="tt-muted">&middot; ' . esc_html( (string) $team->age_group ) . '</span>';
            }
        }

        $identity_rows = [];
        if ( ! empty( $player->date_of_birth ) ) {
            $identity_rows[] = [ __( 'Date of birth', 'talenttrack' ), esc_html( (string) $player->date_of_birth ) ];
        }
        if ( is_array( $positions ) && ! empty( $positions ) ) {
            $identity_rows[] = [ __( 'Position(s)', 'talenttrack' ), esc_html( implode( ', ', array_map( 'strval', $positions ) ) ) ];
        }
        if ( $foot_label !== '' ) {
            $identity_rows[] = [ __( 'Preferred foot', 'talenttrack' ), $foot_label ];
        }
        if ( ! empty( $player->jersey_number ) ) {
            $identity_rows[] = [ __( 'Jersey number', 'talenttrack' ), '#' . (int) $player->jersey_number ];
        }
        if ( $status_label !== '' ) {
            $identity_rows[] = [ __( 'Status', 'talenttrack' ), $status_label ];
        }

        $academy_rows = [];
        if ( $team_html !== '' ) {
            $academy_rows[] = [ __( 'Team', 'talenttrack' ), $team_html ];
        } else {
            $academy_rows[] = [
                __( 'Team', 'talenttrack' ),
                '<em class="tt-muted">' . esc_html__( 'Unassigned', 'talenttrack' ) . '</em>',
            ];
        }
        if ( $tier_label !== '' ) {
            $academy_rows[] = [ __( 'Age tier', 'talenttrack' ), esc_html( $tier_label ) ];
        }
        if ( ! empty( $player->date_joined ) ) {
            $academy_rows[] = [ __( 'Date joined', 'talenttrack' ), esc_html( (string) $player->date_joined ) ];
        }
        ?>
        <div class="tt-player-profile-grid">
            <?php self::renderProfileTable( __( 'Identity', 'talenttrack' ), $identity_rows ); ?>
            <?php self::renderProfileTable( __( 'Academy', 'talenttrack' ), $academy_rows ); ?>
        </div>

        <?php
        if ( empty( $player->team_id ) && current_user_can( 'tt_edit_players' ) ) {
            self::renderAssignTeamForm( $player_id );
        }
        if ( current_user_can( 'tt_edit_player_status' ) ) {
            self::renderBehaviourPotentialForm( $player_id );
        }
    }

    /**
     * #0093 — inline "Assign to team" picker, surfaced on the player file
     * when the player holds no team_id yet. Wires through the same PUT
     * /players/{id} endpoint the edit form uses, so the existing
     * permission_callback + payload validator govern; nothing
     * authorization-shaped lives in the view.
     *
     * Anchor `#tt-player-assign-team` is the jump target of the page-
     * header "Assign to team" action.
     */
    private static function renderAssignTeamForm( int $player_id ): void {
        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        ?>
        <section id="tt-player-assign-team" class="tt-player-assign-team-card" style="margin-top:20px; padding:16px 18px; background:#fff7e6; border:1px solid #f0c987; border-radius:8px;">
            <h2 style="margin:0 0 8px; font-size:16px;">
                <?php esc_html_e( 'Assign this player to a team', 'talenttrack' ); ?>
            </h2>
            <p style="margin:0 0 12px; color:#5b6e75; font-size:13px;">
                <?php esc_html_e( 'This player has no team yet — typical after a trial admission. Pick a team to place them on the roster.', 'talenttrack' ); ?>
            </p>
            <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( 'players/' . $player_id ); ?>" data-rest-method="PUT" data-redirect-after-save="reload" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                <?php echo \TT\Shared\Frontend\Components\TeamPickerComponent::render( [
                    'name'     => 'team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'required' => true,
                    'user_id'  => $user_id,
                    'is_admin' => $is_admin,
                    'selected' => 0,
                ] ); ?>
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Assign to team', 'talenttrack' ); ?>
                </button>
            </form>
        </section>
        <?php
    }

    /**
     * Render one of the profile-tab tables (Identity / Academy). $rows
     * is a list of `[ field-label, value-html ]` tuples. Table renders
     * with a section-name header row spanning both columns and per-row
     * field/value cells. Empty $rows → no table emitted.
     *
     * @param array<int,array{0:string,1:string}> $rows
     */
    private static function renderProfileTable( string $section_label, array $rows ): void {
        if ( empty( $rows ) ) return;
        echo '<div class="tt-player-profile-grid__col">';
        echo '<table class="tt-profile-table">';
        echo '<thead><tr><th colspan="2" scope="colgroup">' . esc_html( $section_label ) . '</th></tr></thead>';
        echo '<tbody>';
        foreach ( $rows as $row ) {
            $label = (string) ( $row[0] ?? '' );
            $value = (string) ( $row[1] ?? '' );
            echo '<tr>';
            echo '<th scope="row">' . esc_html( $label ) . '</th>';
            echo '<td>' . $value . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — values pre-escaped at the call site
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    /** Goals tab — paged-list-aware "all goals for this player". */
    private static function renderGoalsTab( int $player_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, due_date FROM {$wpdb->prefix}tt_goals
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY created_at DESC LIMIT 50",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            EmptyStateCard::render( [
                'icon'      => 'goals',
                'headline'  => __( 'No goals yet for this player', 'talenttrack' ),
                'explainer' => __( 'Goals capture what the player is working on this season — start with one.', 'talenttrack' ),
                'cta_label' => __( 'Add first goal', 'talenttrack' ),
                'cta_url'   => add_query_arg(
                    [ 'tt_view' => 'goals', 'action' => 'new', 'player_id' => $player_id ],
                    RecordLink::dashboardUrl()
                ),
                'cta_cap'   => 'tt_edit_goals',
            ] );
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $g ) {
            $url = RecordLink::detailUrlForWithBack( 'goals', (int) $g->id );
            echo '<li>';
            echo '<a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) $g->title ) . '</strong> &middot; ';
            echo LookupPill::render( 'goal_status', (string) $g->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( ! empty( $g->due_date ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $g->due_date ) . '</span>';
            }
            echo '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /** Evaluations tab — every evaluation, linkified (was a flat date list). */
    private static function renderEvaluationsTab( int $player_id ): void {
        global $wpdb;
        // Mirrors `PlayerFileCounts::for()`'s evaluation count query
        // (player_id + club_id + archived_at IS NULL). Without the
        // matching `club_id` clause, the tab and the badge can fall
        // out of sync.
        //
        // v3.110.3 — was LEFT JOINing tt_eval_types to surface a
        // type label, but that table doesn't exist in the schema
        // anymore (eval typing moved to tt_eval_categories), so the
        // JOIN errored silently and $wpdb->get_results returned
        // empty — making the count badge show 1+ while the tab still
        // rendered the "No evaluations yet" empty state.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date
               FROM {$wpdb->prefix}tt_evaluations
              WHERE player_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY eval_date DESC LIMIT 50",
            $player_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        if ( empty( $rows ) ) {
            EmptyStateCard::render( [
                'icon'      => 'evaluations',
                'headline'  => __( 'No evaluations yet for this player', 'talenttrack' ),
                'explainer' => __( 'Evaluations track how the player is developing across your rating categories. Record one after a training or match.', 'talenttrack' ),
                'cta_label' => __( 'Record first evaluation', 'talenttrack' ),
                'cta_url'   => add_query_arg(
                    [ 'tt_view' => 'evaluations', 'action' => 'new', 'player_id' => $player_id ],
                    RecordLink::dashboardUrl()
                ),
                'cta_cap'   => 'tt_edit_evaluations',
            ] );
            return;
        }
        $can_delete = current_user_can( 'tt_edit_evaluations' );
        echo '<ul class="tt-stack">';
        foreach ( $rows as $ev ) {
            $url = RecordLink::detailUrlForWithBack( 'evaluations', (int) $ev->id );
            // Each row gets `data-tt-row` so the generic record-delete
            // handler in public.js (`.tt-record-delete`) can fade and
            // remove it without a full page reload on success.
            echo '<li data-tt-row><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) ( $ev->eval_date ?? '—' ) ) . '</strong>';
            echo '</a>';
            if ( $can_delete ) {
                echo ' <button type="button" class="tt-record-delete tt-btn-link"'
                    . ' data-rest-path="' . esc_attr( 'evaluations/' . (int) $ev->id ) . '"'
                    . ' data-confirm-msg="' . esc_attr__( 'Delete this evaluation? This cannot be undone.', 'talenttrack' ) . '"'
                    . ' data-deleted-msg="' . esc_attr__( 'Evaluation deleted.', 'talenttrack' ) . '"'
                    . ' aria-label="' . esc_attr__( 'Delete evaluation', 'talenttrack' ) . '">×</button>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /** Activities tab — recent activities the player attended.
     *
     * v3.110.3 — restricted to completed activities. Attendance rows
     * for scheduled / in-progress activities default to 'Present' on
     * insert (the form's roster pre-fills every roster player); those
     * are work-in-progress, not real attendance. Filtering on
     * `plan_state = 'completed'` makes the tab read as "actual
     * attendance history" rather than "every row that happens to
     * exist in tt_attendance for this player". */
    private static function renderActivitiesTab( int $player_id, ?object $player = null ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.activity_type_key, att.status
               FROM {$wpdb->prefix}tt_attendance att
               JOIN {$wpdb->prefix}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND a.archived_at IS NULL
                AND a.plan_state = 'completed'
              ORDER BY a.session_date DESC LIMIT 25",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            $team_id = $player !== null ? (int) ( $player->team_id ?? 0 ) : 0;
            if ( $team_id > 0 ) {
                EmptyStateCard::render( [
                    'icon'      => 'activities',
                    'headline'  => __( 'No attended activities yet', 'talenttrack' ),
                    'explainer' => __( 'Activities are recorded at the team level. Schedule one for the player\'s team and the player will appear in the roster.', 'talenttrack' ),
                    'cta_label' => __( 'Plan a team activity', 'talenttrack' ),
                    'cta_url'   => add_query_arg(
                        [ 'tt_view' => 'activities', 'action' => 'new', 'team_id' => $team_id ],
                        RecordLink::dashboardUrl()
                    ),
                    'cta_cap'   => 'tt_edit_activities',
                ] );
            } else {
                EmptyStateCard::render( [
                    'icon'      => 'activities',
                    'headline'  => __( 'No attended activities yet', 'talenttrack' ),
                    'explainer' => __( 'Activities are recorded at the team level. Assign this player to a team first; activities planned for that team will then surface here.', 'talenttrack' ),
                ] );
            }
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $a ) {
            $url = RecordLink::detailUrlForWithBack( 'activities', (int) $a->id );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) ( $a->title ?? '—' ) ) . '</strong>';
            if ( ! empty( $a->session_date ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $a->session_date ) . '</span>';
            }
            if ( ! empty( $a->status ) ) {
                echo ' &middot; ' . LookupPill::render( 'attendance_status', (string) $a->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '</a></li>';
        }
        echo '</ul>';
    }

    /** PDP tab — files + recent conversations. */
    private static function renderPdpTab( int $player_id ): void {
        global $wpdb;
        $files = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, season_id, created_at FROM {$wpdb->prefix}tt_pdp_files
              WHERE player_id = %d ORDER BY created_at DESC LIMIT 10",
            $player_id
        ) );
        if ( empty( $files ) ) {
            EmptyStateCard::render( [
                'icon'      => 'pdp',
                'headline'  => __( 'No PDP cycle yet for this player', 'talenttrack' ),
                'explainer' => __( 'A Personal Development Plan documents what the player is focusing on, agreed with parents and the coach. Open one to start the cycle.', 'talenttrack' ),
                'cta_label' => __( 'Start PDP cycle', 'talenttrack' ),
                'cta_url'   => add_query_arg(
                    [ 'tt_view' => 'pdp', 'action' => 'new', 'player_id' => $player_id ],
                    RecordLink::dashboardUrl()
                ),
                'cta_cap'   => 'tt_edit_pdp',
            ] );
            return;
        }
        echo '<h3>' . esc_html__( 'PDP files', 'talenttrack' ) . '</h3><ul class="tt-stack">';
        foreach ( $files as $f ) {
            echo '<li><strong>' . esc_html( (string) $f->status ) . '</strong>';
            echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $f->created_at ) . '</span></li>';
        }
        echo '</ul>';
    }

    /** Trials tab — every trial case the player has been part of. */
    private static function renderTrialsTab( int $player_id, $player = null ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, start_date, end_date FROM {$wpdb->prefix}tt_trial_cases
              WHERE player_id = %d AND archived_at IS NULL
              ORDER BY start_date DESC LIMIT 10",
            $player_id
        ) );
        if ( empty( $rows ) ) {
            // The "Open trial case" CTA only makes sense for players
            // whose current status is `trial`. For active / contracted /
            // released players it's a dead-end — they don't take trial
            // cases. Drop the CTA in those cases and just explain that
            // there's no trial history.
            $is_trial_player = $player && isset( $player->status ) && (string) $player->status === 'trial';
            $card = [
                'icon'      => 'trials',
                'headline'  => __( 'No trial history for this player', 'talenttrack' ),
                'explainer' => $is_trial_player
                    ? __( 'A trial case tracks the prospect from first training through to the academy decision. Open one when you want to evaluate this player formally.', 'talenttrack' )
                    : __( 'This player is not currently on trial, so there is no trial history to show.', 'talenttrack' ),
            ];
            if ( $is_trial_player ) {
                $card['cta_label'] = __( 'Open trial case', 'talenttrack' );
                $card['cta_url']   = add_query_arg(
                    [ 'tt_view' => 'trials', 'action' => 'new', 'player_id' => $player_id ],
                    RecordLink::dashboardUrl()
                );
                $card['cta_cap']   = 'tt_manage_trials';
            }
            EmptyStateCard::render( $card );
            return;
        }
        echo '<ul class="tt-stack">';
        foreach ( $rows as $t ) {
            $url = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $t->id ], RecordLink::dashboardUrl() );
            echo '<li><a class="tt-record-link" href="' . esc_url( $url ) . '">';
            echo '<strong>' . esc_html( (string) $t->status ) . '</strong>';
            if ( ! empty( $t->start_date ) ) {
                echo ' <span class="tt-muted">&middot; ' . esc_html( (string) $t->start_date );
                if ( ! empty( $t->end_date ) ) echo ' → ' . esc_html( (string) $t->end_date );
                echo '</span>';
            }
            echo '</a></li>';
        }
        echo '</ul>';
    }

    /**
     * #0085 — Notes tab. Staff-only running log on the player file via
     * the existing Threads infrastructure. Cap-gated at the tab-show
     * level (`tt_view_player_notes`); per-player scope enforced by
     * `PlayerThreadAdapter::canRead` when the FrontendThreadView
     * component renders.
     */
    private static function renderNotesTab( int $player_id, int $user_id ): void {
        if ( ! current_user_can( 'tt_view_player_notes' ) ) {
            EmptyStateCard::render( [
                'headline'  => __( 'Notes are staff-only', 'talenttrack' ),
                'explainer' => __( 'A running log of staff observations about this player. You don\'t have access — talk to your academy admin if you should.', 'talenttrack' ),
            ] );
            return;
        }

        $adapter = \TT\Modules\Threads\ThreadTypeRegistry::get( 'player' );
        if ( ! $adapter || ! $adapter->canRead( $user_id, $player_id ) ) {
            EmptyStateCard::render( [
                'headline'  => __( 'Not in scope for this player', 'talenttrack' ),
                'explainer' => __( 'You have notes access in general, but not for this player\'s team. Talk to your academy admin if you should.', 'talenttrack' ),
            ] );
            return;
        }

        echo '<p style="color:var(--tt-muted, #5b6e75); margin: 0 0 14px; font-size: 13px; max-width: 60ch;">'
            . esc_html__( 'A running log staff use to share observations about this player. Notes are visible to staff only — never to the player or their parents.', 'talenttrack' )
            . '</p>';

        \TT\Shared\Frontend\Components\FrontendThreadView::render( 'player', $player_id, $user_id );
    }

    /**
     * Sprint 3 — capture surface for `behaviour` + `potential` ratings.
     * Stub: registers the form skeleton; full save handler wires in
     * Sprint 3 alongside the >100% inline-warning.
     */
    private static function renderBehaviourPotentialForm( int $player_id ): void {
        ?>
        <section class="tt-pde-section">
            <h3><?php esc_html_e( 'Behaviour &amp; potential', 'talenttrack' ); ?></h3>
            <p class="tt-muted" style="font-size:13px; margin: 0 0 8px;">
                <?php esc_html_e( 'Quick capture for the player status calculation. Edit weights and thresholds in Configuration → Player status methodology.', 'talenttrack' ); ?>
            </p>
            <p>
                <?php
                $url = add_query_arg(
                    [ 'tt_view' => 'player-status-capture', 'player_id' => $player_id ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                );
                ?>
                <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $url ); ?>">
                    <?php esc_html_e( 'Capture behaviour and potential', 'talenttrack' ); ?>
                </a>
            </p>
        </section>
        <?php
    }

}
