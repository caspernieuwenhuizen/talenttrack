<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Players\ParentAccountService;

/**
 * FrontendParentAccountsView (#1815) — `?tt_view=parent-accounts`.
 *
 * The academy admin's parent / guardian account-mapping surface. One row
 * per parent (a `tt_parent` WP user) showing the players they guard, with
 * per-link unlink and a top panel to link an existing WP account to a
 * player as a parent. Sibling of FrontendPlayerAccountsView; the player↔
 * parent relationship is the `tt_player_parents` pivot.
 *
 * Player-centric (CLAUDE.md §1): a parent exists in service of a player —
 * each row surfaces the players the account is attached to. Mutations go
 * through ParentAccountService + its REST endpoints, so no business logic
 * lives here (CLAUDE.md §4).
 *
 * Cap: the dedicated `tt_manage_parent_accounts` (matrix entity
 * `parent_accounts`).
 */
final class FrontendParentAccountsView extends FrontendViewBase {

    /** Safety cap on the player picker (pilot clubs are well under). */
    private const MAX_PLAYERS = 500;

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Parent accounts', 'talenttrack' );

        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_parent_accounts' ) && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage parent accounts.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueuePageAssets();

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        [ $msg_kind, $msg_text ] = self::handleCreatePost();
        if ( $msg_text !== '' ) {
            echo '<div class="tt-notice tt-notice-' . esc_attr( $msg_kind ) . '">' . esc_html( $msg_text ) . '</div>';
        }

        $svc = new ParentAccountService();
        self::renderAddPanel( $svc );
        self::renderCreatePanel();
        self::renderList( $svc );
    }

    /**
     * #1847 — "Create a new parent account". Provisions a fresh WP account
     * (set-password email by default; a temp-password path behind an explicit
     * confirmation for the no-usable-email case) and links it to a player.
     * Posts to this page; the service owns the logic + audit-log (§4).
     */
    private static function renderCreatePanel(): void {
        $players = self::activePlayers();
        ?>
        <form method="post" class="tt-pp-add tt-pp-create">
            <?php wp_nonce_field( 'tt_pa_create', 'tt_pa_create_nonce' ); ?>
            <input type="hidden" name="tt_pa_action" value="create_parent" />
            <h3 class="tt-pp-add-title"><?php esc_html_e( 'Create a new parent account', 'talenttrack' ); ?></h3>
            <div class="tt-pp-add-row">
                <label class="tt-pp-field">
                    <span class="tt-pp-field-label"><?php esc_html_e( 'Player', 'talenttrack' ); ?></span>
                    <select name="player_id" class="tt-input" required>
                        <option value="0"><?php esc_html_e( '— Choose player —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $p ) : ?>
                            <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( trim( (string) $p->first_name . ' ' . (string) $p->last_name ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tt-pp-field">
                    <span class="tt-pp-field-label"><?php esc_html_e( 'First name', 'talenttrack' ); ?></span>
                    <input type="text" name="first_name" class="tt-input" autocomplete="given-name" />
                </label>
                <label class="tt-pp-field">
                    <span class="tt-pp-field-label"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></span>
                    <input type="text" name="last_name" class="tt-input" autocomplete="family-name" />
                </label>
                <label class="tt-pp-field">
                    <span class="tt-pp-field-label"><?php esc_html_e( 'Email', 'talenttrack' ); ?></span>
                    <input type="email" inputmode="email" name="email" class="tt-input" autocomplete="email" required />
                </label>
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Create account', 'talenttrack' ); ?></button>
            </div>
            <p class="tt-pp-add-hint"><?php esc_html_e( 'The parent receives a "set your password" email; you never see a password.', 'talenttrack' ); ?></p>
            <label class="tt-pp-field tt-pp-temp">
                <input type="checkbox" name="use_temp" value="1" />
                <span><?php esc_html_e( 'No usable email - set a temporary password instead (you must share it securely).', 'talenttrack' ); ?></span>
            </label>
            <input type="password" name="temp_password" class="tt-input" autocomplete="new-password" minlength="8" placeholder="<?php esc_attr_e( 'Temporary password', 'talenttrack' ); ?>" />
        </form>
        <?php
    }

    /**
     * Handle the create-parent POST. Returns [kind, message] for the next
     * render; empty message means nothing to show.
     *
     * @return array{0:string,1:string}
     */
    private static function handleCreatePost(): array {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return [ '', '' ];
        if ( ( $_POST['tt_pa_action'] ?? '' ) !== 'create_parent' ) return [ '', '' ];
        if ( ! isset( $_POST['tt_pa_create_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_pa_create_nonce'] ) ), 'tt_pa_create' ) ) {
            return [ 'error', __( 'Security check failed. Reload and try again.', 'talenttrack' ) ];
        }
        if ( ! AuthorizationService::userCanOrMatrix( get_current_user_id(), 'tt_manage_parent_accounts' ) ) {
            return [ 'error', __( 'You do not have permission to create parent accounts.', 'talenttrack' ) ];
        }
        $use_temp = ! empty( $_POST['use_temp'] );
        $result = ( new ParentAccountService() )->directCreate(
            absint( $_POST['player_id'] ?? 0 ),
            sanitize_text_field( wp_unslash( (string) ( $_POST['first_name'] ?? '' ) ) ),
            sanitize_text_field( wp_unslash( (string) ( $_POST['last_name'] ?? '' ) ) ),
            sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ),
            $use_temp ? (string) ( $_POST['temp_password'] ?? '' ) : null
        );
        return $result['ok'] ? [ 'success', (string) $result['message'] ] : [ 'error', (string) $result['message'] ];
    }

    private static function enqueuePageAssets(): void {
        // Reuse the player-accounts card/list vocabulary; add the small
        // parent-specific bits (player chips with an unlink control).
        wp_enqueue_style(
            'tt-player-accounts',
            TT_PLUGIN_URL . 'assets/css/components/player-accounts.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-parent-accounts',
            TT_PLUGIN_URL . 'assets/css/components/parent-accounts.css',
            [ 'tt-player-accounts' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-parent-accounts',
            TT_PLUGIN_URL . 'assets/js/components/parent-accounts.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-parent-accounts', 'TT_PARENT_ACCOUNTS', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/players/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'       => [
                'pick_player'    => __( 'Choose a player first.', 'talenttrack' ),
                'pick_user'      => __( 'Choose an account to link first.', 'talenttrack' ),
                'confirm_unlink' => __( 'Unlink this parent from the player?', 'talenttrack' ),
                'error_generic'  => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'network_error'  => __( 'Network error. Please try again.', 'talenttrack' ),
            ],
        ] );
    }

    private static function renderAddPanel( ParentAccountService $svc ): void {
        $players  = self::activePlayers();
        $eligible = $svc->eligibleUsers();
        ?>
        <div class="tt-pp-add" data-tt-parent-add>
            <h3 class="tt-pp-add-title"><?php esc_html_e( 'Link a parent to a player', 'talenttrack' ); ?></h3>
            <div class="tt-pp-add-row">
                <label class="tt-pp-field">
                    <span class="tt-pp-field-label"><?php esc_html_e( 'Player', 'talenttrack' ); ?></span>
                    <select id="tt-pp-player" class="tt-input">
                        <option value="0"><?php esc_html_e( '— Choose player —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $p ) : ?>
                            <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( trim( (string) $p->first_name . ' ' . (string) $p->last_name ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tt-pp-field">
                    <span class="tt-pp-field-label"><?php esc_html_e( 'Parent account', 'talenttrack' ); ?></span>
                    <select id="tt-pp-user" class="tt-input">
                        <option value="0"><?php esc_html_e( '— Choose account —', 'talenttrack' ); ?></option>
                        <?php foreach ( $eligible as $u ) : ?>
                            <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ( $u->user_email ? ' (' . $u->user_email . ')' : '' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="tt-btn tt-btn-primary tt-pp-link-btn"><?php esc_html_e( 'Link parent', 'talenttrack' ); ?></button>
            </div>
            <p class="tt-pp-add-hint"><?php esc_html_e( 'The account becomes a guardian of the chosen player. A parent can guard several players.', 'talenttrack' ); ?></p>
            <p class="tt-pa-msg" data-tt-parent-add-msg role="status" aria-live="polite"></p>
        </div>
        <?php
    }

    private static function renderList( ParentAccountService $svc ): void {
        $parents = $svc->listParents();

        if ( empty( $parents ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No parent accounts yet. Link one above, or invite a parent from a player\'s Family tab.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-pa-list tt-pp-list">';
        foreach ( $parents as $parent ) {
            self::renderParentRow( $parent );
        }
        echo '</ul>';
    }

    /** @param object $parent row from ParentAccountService::listParents() */
    private static function renderParentRow( object $parent ): void {
        $uid   = (int) $parent->wp_user_id;
        $name  = (string) $parent->display_name;
        $email = (string) $parent->user_email;
        if ( $name === '' ) {
            $name = $parent->exists ? __( '(no name)', 'talenttrack' ) : __( '(deleted account)', 'talenttrack' );
        }

        echo '<li class="tt-pa-row tt-pp-row" data-parent-id="' . esc_attr( (string) $uid ) . '">';

        echo '<div class="tt-pa-id">';
        echo '<span class="tt-pa-avatar tt-pa-avatar--initials" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
        echo '<span class="tt-pa-id-text">';
        echo '<span class="tt-pa-name">' . esc_html( $name ) . '</span>';
        if ( $email !== '' ) {
            echo '<span class="tt-pa-meta">' . esc_html( $email ) . '</span>';
        }
        echo '</span></div>';

        // Players this parent guards, each with an unlink control.
        echo '<div class="tt-pp-players">';
        $player_ids   = is_array( $parent->player_ids ) ? $parent->player_ids : [];
        $player_names = is_array( $parent->player_names ) ? $parent->player_names : [];
        foreach ( $player_ids as $i => $pid ) {
            $pid       = (int) $pid;
            $pname     = (string) ( $player_names[ $i ] ?? ( '#' . $pid ) );
            $player_url = RecordLink::detailUrlForWithBack( 'players', $pid );
            echo '<span class="tt-pp-chip">';
            echo '<a class="tt-pp-chip-link" href="' . esc_url( $player_url ) . '">' . esc_html( $pname ) . '</a>';
            echo '<button type="button" class="tt-pp-chip-unlink" data-player-id="' . esc_attr( (string) $pid ) . '" data-parent-id="' . esc_attr( (string) $uid ) . '" aria-label="'
                . esc_attr( sprintf( /* translators: %s: player name */ __( 'Unlink from %s', 'talenttrack' ), $pname ) ) . '">&times;</button>';
            echo '</span>';
        }
        echo '</div>';

        echo '<p class="tt-pa-msg" data-parent-id="' . esc_attr( (string) $uid ) . '" role="status" aria-live="polite"></p>';
        echo '</li>';
    }

    /**
     * Active players for the link picker (id + name), club-scoped.
     *
     * @return list<object>
     */
    private static function activePlayers(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name
               FROM {$wpdb->prefix}tt_players
              WHERE club_id = %d AND archived_at IS NULL
           ORDER BY last_name ASC, first_name ASC
              LIMIT %d",
            CurrentClub::id(),
            self::MAX_PLAYERS
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function initials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $a = isset( $parts[0][0] ) ? mb_substr( $parts[0], 0, 1 ) : '';
        $b = ( count( $parts ) > 1 && isset( $parts[ count( $parts ) - 1 ][0] ) ) ? mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) : '';
        $out = mb_strtoupper( $a . $b );
        return $out !== '' ? $out : '?';
    }
}
