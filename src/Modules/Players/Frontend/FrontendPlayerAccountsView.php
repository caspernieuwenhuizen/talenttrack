<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Players\PlayerAccountService;
use TT\Modules\Invitations\Frontend\InviteButton;

/**
 * FrontendPlayerAccountsView (#1771) — `?tt_view=player-accounts`.
 *
 * The academy admin's account-mapping surface: every player with their
 * account-link status (No account / Invited / Linked) and a one-click
 * **link / unlink** of an existing WP user — the primary mapping
 * workflow. Invitations stay the secondary self-service path (the Invite
 * button reuses the existing flow).
 *
 * Player-centric (CLAUDE.md §1): the player is the row anchor (name +
 * photo); the account is a relationship to the player, surfaced where the
 * admin reasons about the player, not in a separate user-admin silo.
 *
 * Cap: `tt_manage_players` (academy / club admin via the matrix) — the
 * same capability that gates creating/deleting player records. Link /
 * unlink mutate through PlayerAccountService + its REST endpoints, so no
 * business logic lives in this view (CLAUDE.md §4).
 */
final class FrontendPlayerAccountsView extends FrontendViewBase {

    /** Safety cap on rows rendered in one page (pilot clubs are well under). */
    private const MAX_ROWS = 500;

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Player accounts', 'talenttrack' );

        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_players' ) && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage player accounts.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueuePageAssets();

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $status = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : '';
        $search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
        if ( ! in_array( $status, [ '', PlayerAccountService::STATUS_NONE, PlayerAccountService::STATUS_INVITED, PlayerAccountService::STATUS_LINKED ], true ) ) {
            $status = '';
        }

        self::renderFilters( $status, $search );
        self::renderBulkInvite();
        self::renderList( $status, $search );
    }

    /**
     * #1770 — bulk invite every unlinked player on a team in one action.
     * Posts to admin-post.php (InvitationBulkCreateHandler) for a clean
     * redirect + flash summary. Only shown to users who can send invites.
     */
    private static function renderBulkInvite(): void {
        if ( ! current_user_can( 'tt_send_invitation' ) ) return;

        global $wpdb;
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND archived_at IS NULL
              ORDER BY name ASC",
            CurrentClub::id()
        ) );
        if ( empty( $teams ) ) return;

        $self_url = add_query_arg( [ 'tt_view' => 'player-accounts' ], RecordLink::dashboardUrl() );
        ?>
        <form class="tt-pa-filters tt-pa-bulk" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="tt_invitation_bulk_create" />
            <input type="hidden" name="kind" value="player" />
            <input type="hidden" name="_redirect" value="<?php echo esc_url( $self_url ); ?>" />
            <?php wp_nonce_field( 'tt_invitation_bulk_create' ); ?>
            <label class="tt-pa-filter">
                <span class="tt-pa-filter-label"><?php esc_html_e( 'Bulk invite a team', 'talenttrack' ); ?></span>
                <select name="team_id" class="tt-input" required>
                    <option value="0"><?php esc_html_e( '— choose a team —', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( (string) $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="tt-btn tt-btn-secondary">
                <?php esc_html_e( 'Generate invites', 'talenttrack' ); ?>
            </button>
        </form>
        <?php
    }

    private static function enqueuePageAssets(): void {
        wp_enqueue_style(
            'tt-player-accounts',
            TT_PLUGIN_URL . 'assets/css/components/player-accounts.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-player-accounts',
            TT_PLUGIN_URL . 'assets/js/components/player-accounts.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-player-accounts', 'TT_PLAYER_ACCOUNTS', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/players/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'       => [
                'pick_user'       => __( 'Choose an account to link first.', 'talenttrack' ),
                'confirm_unlink'  => __( 'Unlink this account from the player?', 'talenttrack' ),
                'error_generic'   => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'network_error'   => __( 'Network error. Please try again.', 'talenttrack' ),
            ],
        ] );
    }

    private static function renderFilters( string $status, string $search ): void {
        $opts = [
            ''                                  => __( 'All statuses', 'talenttrack' ),
            PlayerAccountService::STATUS_NONE    => __( 'No account', 'talenttrack' ),
            PlayerAccountService::STATUS_INVITED => __( 'Invited', 'talenttrack' ),
            PlayerAccountService::STATUS_LINKED  => __( 'Linked', 'talenttrack' ),
        ];
        ?>
        <form class="tt-pa-filters" method="get">
            <?php foreach ( self::passthroughQueryArgs() as $k => $v ) : ?>
                <input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
            <?php endforeach; ?>
            <label class="tt-pa-filter">
                <span class="tt-pa-filter-label"><?php esc_html_e( 'Status', 'talenttrack' ); ?></span>
                <select name="status" class="tt-input" onchange="this.form.submit()">
                    <?php foreach ( $opts as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tt-pa-filter tt-pa-filter--search">
                <span class="tt-pa-filter-label"><?php esc_html_e( 'Search', 'talenttrack' ); ?></span>
                <input type="search" name="q" class="tt-input" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search player name…', 'talenttrack' ); ?>" inputmode="search" />
            </label>
            <button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
        </form>
        <?php
    }

    private static function renderList( string $status, string $search ): void {
        global $wpdb;
        $club = CurrentClub::id();

        $where  = 'p.club_id = %d AND p.archived_at IS NULL';
        $params = [ $club ];
        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where   .= " AND ( p.first_name LIKE %s OR p.last_name LIKE %s OR CONCAT(p.first_name,' ',p.last_name) LIKE %s )";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $params[] = self::MAX_ROWS + 1;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.photo_url, p.wp_user_id,
                    t.name AS team_name, t.age_group
               FROM {$wpdb->prefix}tt_players p
          LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
              WHERE {$where}
           ORDER BY p.last_name ASC, p.first_name ASC
              LIMIT %d",
            ...$params
        ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $truncated = count( $rows ) > self::MAX_ROWS;
        if ( $truncated ) array_pop( $rows );

        $svc       = new PlayerAccountService();
        $eligible  = $svc->eligibleUsers();
        $dash      = RecordLink::dashboardUrl();
        $self_url  = add_query_arg( [ 'tt_view' => 'player-accounts' ], $dash );

        // Filter by computed status (needs the invitations probe) in PHP.
        $visible = [];
        foreach ( $rows as $r ) {
            $st = $svc->accountStatus( $r );
            if ( $status !== '' && $st !== $status ) continue;
            $visible[] = [ 'row' => $r, 'status' => $st ];
        }

        if ( empty( $visible ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players match these filters.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-pa-list">';
        foreach ( $visible as $item ) {
            self::renderRow( $item['row'], $item['status'], $eligible, $self_url );
        }
        echo '</ul>';

        if ( $truncated ) {
            echo '<p class="tt-notice tt-pa-truncated">' . esc_html(
                sprintf(
                    /* translators: %d: row cap */
                    __( 'Showing the first %d players — narrow the search to see the rest.', 'talenttrack' ),
                    self::MAX_ROWS
                )
            ) . '</p>';
        }
    }

    /**
     * @param object              $r        player row
     * @param string              $status   linked|invited|none
     * @param array<int,object>   $eligible eligible WP users (for the link picker)
     */
    private static function renderRow( object $r, string $status, array $eligible, string $self_url ): void {
        $player_id = (int) $r->id;
        $name      = trim( (string) $r->first_name . ' ' . (string) $r->last_name );
        $team      = trim( (string) ( $r->team_name ?? '' ) );
        $age       = trim( (string) ( $r->age_group ?? '' ) );
        $meta      = array_filter( [ $team, $age ] );

        // Cross-entity link to the player detail with a back-hint, so the
        // detail view renders the contextual "← Back to Player accounts"
        // pill (CLAUDE.md §5 / DoD).
        $player_url = RecordLink::detailUrlForWithBack( 'players', $player_id );

        echo '<li class="tt-pa-row" data-player-id="' . esc_attr( (string) $player_id ) . '">';

        // Anchor: photo + name + meta.
        echo '<div class="tt-pa-id">';
        if ( ! empty( $r->photo_url ) ) {
            echo '<img class="tt-pa-avatar" src="' . esc_url( (string) $r->photo_url ) . '" alt="" width="40" height="40" loading="lazy" />';
        } else {
            echo '<span class="tt-pa-avatar tt-pa-avatar--initials" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
        }
        echo '<span class="tt-pa-id-text">';
        echo '<a class="tt-pa-name" href="' . esc_url( $player_url ) . '">' . esc_html( $name !== '' ? $name : __( '(unnamed player)', 'talenttrack' ) ) . '</a>';
        if ( $meta ) {
            echo '<span class="tt-pa-meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
        }
        echo '</span></div>';

        // Status chip.
        echo '<div class="tt-pa-status">' . self::statusChip( $status, $r ) . '</div>';

        // Actions by state.
        echo '<div class="tt-pa-actions">';
        if ( $status === PlayerAccountService::STATUS_LINKED ) {
            echo '<button type="button" class="tt-btn tt-btn-danger tt-pa-unlink" data-player-id="' . esc_attr( (string) $player_id ) . '">'
                . esc_html__( 'Unlink', 'talenttrack' ) . '</button>';
        } else {
            // No account / invited — offer link + invite.
            self::renderLinkPicker( $player_id, $eligible );
            InviteButton::render( [
                'kind'             => 'player',
                'target_player_id' => $player_id,
                'redirect'         => $self_url,
            ] );
        }
        echo '</div>';

        echo '<p class="tt-pa-msg" data-player-id="' . esc_attr( (string) $player_id ) . '" role="status" aria-live="polite"></p>';
        echo '</li>';
    }

    /** @param array<int,object> $eligible */
    private static function renderLinkPicker( int $player_id, array $eligible ): void {
        $sel_id = 'tt-pa-user-' . $player_id;
        echo '<span class="tt-pa-link">';
        echo '<label class="tt-sr-only" for="' . esc_attr( $sel_id ) . '">' . esc_html__( 'WordPress user to link', 'talenttrack' ) . '</label>';
        echo '<select id="' . esc_attr( $sel_id ) . '" class="tt-input tt-pa-user-select">';
        echo '<option value="0">' . esc_html__( '— Choose account —', 'talenttrack' ) . '</option>';
        foreach ( $eligible as $u ) {
            $label = $u->display_name . ( $u->user_email ? ' (' . $u->user_email . ')' : '' );
            echo '<option value="' . (int) $u->ID . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-pa-link-btn" data-player-id="' . esc_attr( (string) $player_id ) . '">'
            . esc_html__( 'Link', 'talenttrack' ) . '</button>';
        echo '</span>';
    }

    private static function statusChip( string $status, object $r ): string {
        switch ( $status ) {
            case PlayerAccountService::STATUS_LINKED:
                $u = get_userdata( (int) $r->wp_user_id );
                if ( ! $u ) {
                    return '<span class="tt-pa-chip tt-pa-chip--linked">'
                        . esc_html( sprintf( /* translators: %s: linked WP account name */ __( 'Linked · %s', 'talenttrack' ), __( '(unknown user)', 'talenttrack' ) ) )
                        . '</span>';
                }
                $who  = $u->display_name ?: $u->user_login;
                $text = sprintf(
                    /* translators: %s: linked WP account name */
                    __( 'Linked · %s', 'talenttrack' ),
                    $who
                );
                // #1823 — click the chip to reveal WHICH account is linked
                // (display name can be ambiguous): email, username, user id.
                $detail = '';
                if ( $u->user_email ) {
                    $detail .= '<div class="tt-pa-acct-row"><span class="tt-pa-acct-k">' . esc_html__( 'Email', 'talenttrack' )
                        . '</span><span class="tt-pa-acct-v">' . esc_html( $u->user_email ) . '</span></div>';
                }
                $detail .= '<div class="tt-pa-acct-row"><span class="tt-pa-acct-k">' . esc_html__( 'Username', 'talenttrack' )
                    . '</span><span class="tt-pa-acct-v">' . esc_html( $u->user_login ) . '</span></div>';
                $detail .= '<div class="tt-pa-acct-row"><span class="tt-pa-acct-k">' . esc_html__( 'WP user', 'talenttrack' )
                    . '</span><span class="tt-pa-acct-v">#' . (int) $u->ID . '</span></div>';
                return '<details class="tt-pa-linked">'
                    . '<summary class="tt-pa-chip tt-pa-chip--linked tt-pa-linked-summary">' . esc_html( $text ) . '</summary>'
                    . '<div class="tt-pa-linked-body">' . $detail . '</div>'
                    . '</details>';
            case PlayerAccountService::STATUS_INVITED:
                return '<span class="tt-pa-chip tt-pa-chip--invited">' . esc_html__( 'Invited (pending)', 'talenttrack' ) . '</span>';
            default:
                return '<span class="tt-pa-chip tt-pa-chip--none">' . esc_html__( 'No account', 'talenttrack' ) . '</span>';
        }
    }

    private static function initials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $a = isset( $parts[0][0] ) ? mb_substr( $parts[0], 0, 1 ) : '';
        $b = ( count( $parts ) > 1 && isset( $parts[ count( $parts ) - 1 ][0] ) ) ? mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) : '';
        $out = mb_strtoupper( $a . $b );
        return $out !== '' ? $out : '?';
    }

    /** Query args (other than status/q) to preserve across the filter form. */
    private static function passthroughQueryArgs(): array {
        $keep = [];
        if ( isset( $_GET['tt_view'] ) ) $keep['tt_view'] = sanitize_key( (string) wp_unslash( $_GET['tt_view'] ) );
        if ( isset( $_GET['tt_back'] ) ) $keep['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) );
        return $keep;
    }
}
