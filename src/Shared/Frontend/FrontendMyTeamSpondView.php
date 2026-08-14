<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Spond\TeamSpondAccess;
use TT\Modules\Spond\TeamSpondAccount;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendMyTeamSpondView (#2388) — head-coach-facing panel to connect a
 * team's own Spond account, reachable from the team detail page ("My
 * Team"). Previously only an academy admin could connect Spond, via the
 * club-level `?tt_view=spond` page (gated `tt_edit_teams`); a head coach
 * had no way to link their own account for the team they run.
 *
 * Access is `TeamSpondAccess::canManage()` — `spond_integration → change`
 * for this exact team (admin global, or head coach of the team). The same
 * authority gates the affordance on the team detail page and the per-team
 * REST endpoints this view POSTs to, so the button never outlives the
 * endpoint's gate (CLAUDE.md §7). It reuses the per-team credential form +
 * `frontend-spond.js` behaviour the club page already ships (#2286).
 */
class FrontendMyTeamSpondView extends FrontendViewBase {

    public static function render( int $user_id, int $team_id ): void {
        if ( $team_id <= 0 || ! TeamSpondAccess::currentUserCanManage( $team_id ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'Spond connection', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( "You do not have access to this team's Spond connection.", 'talenttrack' )
                . '</p>';
            return;
        }

        $team = QueryHelpers::get_team( $team_id );
        if ( ! $team ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Team not found', 'talenttrack' ) );
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Team not found.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_name = (string) $team->name;
        FrontendBreadcrumbs::fromDashboard( __( 'Spond connection', 'talenttrack' ), [
            FrontendBreadcrumbs::viewCrumb( 'teams', __( 'Teams', 'talenttrack' ) ),
            FrontendBreadcrumbs::viewCrumb( 'teams', $team_name, [ 'id' => $team_id ] ),
        ] );

        self::enqueueViewAssets();
        self::renderHeader( sprintf(
            /* translators: %s: team name */
            __( 'Spond connection — %s', 'talenttrack' ),
            $team_name
        ) );

        $account     = new TeamSpondAccount( $team_id );
        $is_override = $account->hasCredentials();
        $own_email   = $is_override ? $account->getEmail() : '';
        $group_id    = (string) ( $team->spond_group_id ?? '' );
        $has_group   = $group_id !== '';
        ?>
        <div class="tt-spond" data-tt-spond>
            <p class="tt-spond__intro">
                <?php esc_html_e( "Connect this team's own Spond account so its calendar syncs into the players' timelines. Use a Spond login that's a member of this team's group. Two-factor authentication is not supported — use a non-2FA account. Leaving the email blank falls back to the club-wide account.", 'talenttrack' ); ?>
            </p>

            <div class="tt-spond__team-account tt-spond__team-account--standalone" data-team-id="<?php echo (int) $team_id; ?>">
                <p class="tt-spond__status">
                    <?php if ( $is_override ) : ?>
                        <span class="tt-spond__badge tt-spond__badge--override"><?php esc_html_e( 'Own account', 'talenttrack' ); ?></span>
                        <span class="tt-spond__muted"><?php echo esc_html( $own_email ); ?></span>
                    <?php else : ?>
                        <span class="tt-spond__badge tt-spond__badge--muted"><?php esc_html_e( 'Uses club account', 'talenttrack' ); ?></span>
                    <?php endif; ?>
                </p>

                <form class="tt-spond__team-account-form" data-tt-spond-team-creds-form data-team-id="<?php echo (int) $team_id; ?>">
                    <div class="tt-spond__field">
                        <label class="tt-spond__legend" for="tt-spond-team-email-<?php echo (int) $team_id; ?>"><?php esc_html_e( 'Spond email', 'talenttrack' ); ?></label>
                        <input type="email" inputmode="email" id="tt-spond-team-email-<?php echo (int) $team_id; ?>" class="tt-spond__input" name="email"
                            value="<?php echo esc_attr( $own_email ); ?>" autocomplete="off" />
                    </div>
                    <div class="tt-spond__field">
                        <label class="tt-spond__legend" for="tt-spond-team-password-<?php echo (int) $team_id; ?>"><?php esc_html_e( 'Spond password', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-spond-team-password-<?php echo (int) $team_id; ?>" class="tt-spond__input" name="password"
                            value="" autocomplete="new-password"
                            placeholder="<?php echo $is_override ? esc_attr__( 'Leave blank to keep current password', 'talenttrack' ) : ''; ?>" />
                    </div>
                    <p class="tt-spond__hint"><?php esc_html_e( 'Leave email blank to use the club account.', 'talenttrack' ); ?></p>
                    <div class="tt-spond__team-account-actions">
                        <button type="submit" class="tt-btn tt-btn-primary" data-tt-spond-team-save><?php esc_html_e( 'Save', 'talenttrack' ); ?></button>
                        <button type="button" class="tt-btn tt-btn-secondary" data-tt-spond-team-test><?php esc_html_e( 'Test', 'talenttrack' ); ?></button>
                        <?php if ( $is_override ) : ?>
                            <button type="button" class="tt-btn tt-btn-secondary" data-tt-spond-team-use-club><?php esc_html_e( 'Use club account', 'talenttrack' ); ?></button>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="tt-spond__team-group">
                    <?php if ( $has_group ) : ?>
                        <p class="tt-spond__muted"><?php esc_html_e( 'A Spond group is linked to this team. Sync pulls its calendar into the team\'s activities.', 'talenttrack' ); ?></p>
                        <button type="button" class="tt-btn tt-btn-secondary" data-tt-spond-refresh data-team-id="<?php echo (int) $team_id; ?>">
                            <?php esc_html_e( 'Refresh now', 'talenttrack' ); ?>
                        </button>
                    <?php else : ?>
                        <p class="tt-spond__muted"><?php esc_html_e( 'No Spond group is linked to this team yet. Once a group is linked on the team edit form, syncing pulls its calendar in.', 'talenttrack' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /** Mirror FrontendSpondView's asset enqueue so the shared JS behaves identically. */
    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-spond',
            TT_PLUGIN_URL . 'assets/css/frontend-spond.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-spond',
            TT_PLUGIN_URL . 'assets/js/frontend-spond.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-spond',
            'TT_Spond',
            [
                'i18n' => [
                    'saved'                 => __( 'Credentials saved.', 'talenttrack' ),
                    'test_ok'               => __( 'Spond login successful.', 'talenttrack' ),
                    'test_failed'           => __( 'Spond login failed.', 'talenttrack' ),
                    'disconnected'          => __( 'Spond disconnected.', 'talenttrack' ),
                    'base_url_saved'        => __( 'API endpoint saved.', 'talenttrack' ),
                    'refreshing'            => __( 'Refreshing…', 'talenttrack' ),
                    'refreshed'             => __( 'Sync triggered. Reload to see the updated status.', 'talenttrack' ),
                    'error'                 => __( 'Could not save. Please try again.', 'talenttrack' ),
                    'network_error'         => __( 'Network error. Please try again.', 'talenttrack' ),
                    'disconnect_confirm'    => __( 'Disconnect Spond? Existing imported activities are kept; per-team group selections stay on file.', 'talenttrack' ),
                    'team_saved'            => __( 'Team account saved.', 'talenttrack' ),
                    'team_cleared'          => __( 'Team now uses the club account.', 'talenttrack' ),
                    'team_use_club_confirm' => __( 'Use the club account for this team? The team\'s own Spond login will be removed.', 'talenttrack' ),
                ],
            ]
        );
    }
}
