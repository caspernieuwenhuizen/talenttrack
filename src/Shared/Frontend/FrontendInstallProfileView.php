<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\ProfileDiff;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;

/**
 * FrontendInstallProfileView (#3037) — the preview-and-confirm screen for
 * install profiles, at `?tt_view=install-profile&profile=<slug>`.
 *
 * **This is the only path in the product that applies a profile.** The
 * epic locks two decisions that pull against each other — a profile is a
 * living association, and nothing is written without a human seeing the
 * diff — and the resolution is that there is exactly one write path and
 * everything routes through it. The Modules-page strip opens this screen;
 * the release-time drift notice opens this screen; neither applies
 * anything itself.
 *
 * A pure composer. Every answer on the page comes from `ProfileService`;
 * the three-section rendering is `Components\ProfileDiff`, so the drift
 * notice can reuse it with a filtered row set rather than growing a
 * second copy of the same markup.
 */
class FrontendInstallProfileView extends FrontendViewBase {

    public const CAP  = 'tt_manage_modules';
    public const SLUG = 'install-profile';

    /** Wire the apply handler. Called from Kernel::boot alongside the Modules view. */
    public static function init(): void {
        add_action( 'admin_post_tt_install_profile_apply', [ self::class, 'handleApply' ] );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            // The chain renders on every path, permission-denied included
            // (CLAUDE.md §5) — a denied user still needs the way back.
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage modules.', 'talenttrack' ) . '</p>';
            return;
        }

        $slug    = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';
        $profile = $slug !== '' ? ProfileRegistry::get( $slug ) : null;

        if ( $profile === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Install profile', 'talenttrack' ), [ self::modulesCrumb() ] );
            self::renderHeader( __( 'Install profile', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That install profile does not exist. Pick one from the Modules page.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-install-profile',
            TT_PLUGIN_URL . 'assets/css/frontend-install-profile.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );

        FrontendBreadcrumbs::fromDashboard( $profile['label'], [ self::modulesCrumb() ] );
        self::renderHeader( $profile['label'] );

        $rows = ProfileService::diff( $slug );

        // #3039 will open this screen with `rows=` naming the pending
        // changes only. Filtering here rather than in the service keeps
        // `diff()` a single honest answer.
        $only = self::requestedRowIds();
        if ( $only !== [] ) {
            $rows = array_values( array_filter(
                $rows,
                static fn( array $r ): bool => in_array( $r['id'], $only, true )
            ) );
        }

        echo '<p class="tt-profile-preview__intro">' . esc_html( $profile['description'] ) . '</p>';

        $applicable = array_filter( $rows, static fn( array $r ): bool => $r['skipped_reason'] === null );

        if ( $rows === [] ) {
            // No enabled Confirm on a run that would change nothing. The
            // Modules crumb above is the way back; §5 allows no second one.
            echo '<p class="tt-profile-preview__empty">'
                . esc_html__( 'This install already matches this profile. There is nothing to change.', 'talenttrack' )
                . '</p>';
            return;
        }

        ?>
        <p class="tt-profile-preview__lede">
            <?php esc_html_e( 'Everything below is ticked by default. Untick anything you would rather keep as it is — nothing is changed until you apply.', 'talenttrack' ); ?>
        </p>
        <p class="tt-profile-preview__safety">
            <?php esc_html_e( 'Switching a module off never deletes anything. Its records are kept and come back if you switch it on again.', 'talenttrack' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-profile-preview__form">
            <?php wp_nonce_field( 'tt_install_profile_apply', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_install_profile_apply" />
            <input type="hidden" name="profile" value="<?php echo esc_attr( $slug ); ?>" />

            <?php ProfileDiff::render( $rows ); ?>

            <?php
            if ( $applicable === [] ) {
                // Every remaining row is above the install's plan. There is
                // nothing to confirm, so no button pretends otherwise.
                echo '<p class="tt-profile-preview__empty">'
                    . esc_html__( 'None of these changes can be applied on this install.', 'talenttrack' )
                    . '</p>';
            } else {
                echo FormSaveButton::render( [
                    'label'        => __( 'Apply', 'talenttrack' ),
                    'label_saving' => __( 'Applying...', 'talenttrack' ),
                    'label_saved'  => __( 'Applied', 'talenttrack' ),
                    'variant'      => 'primary',
                    'cancel_url'   => self::modulesUrl(),
                ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component escapes its own output.
            }
            ?>
        </form>
        <?php
    }

    /**
     * The Modules-page header strip: which profile the install is on, how
     * far it has drifted, and the way in to changing it.
     *
     * Lives here rather than on `FrontendModulesView` so the profile
     * surface owns all of its own markup and copy — the Modules view only
     * calls it.
     */
    public static function renderStrip(): void {
        if ( ! current_user_can( self::CAP ) ) return;

        $profiles = ProfileRegistry::all();
        if ( $profiles === [] ) return;

        $current    = ProfileService::current();
        $divergence = $current === null ? 0 : ProfileService::divergence( $current );
        ?>
        <section class="tt-profile-strip" aria-labelledby="tt-profile-strip-title">
            <div class="tt-profile-strip__state">
                <h2 class="tt-profile-strip__title" id="tt-profile-strip-title">
                    <?php esc_html_e( 'Install profile', 'talenttrack' ); ?>
                </h2>
                <p class="tt-profile-strip__value">
                    <?php
                    if ( $current === null ) {
                        // An install that predates profiles. Neutral, not an error.
                        esc_html_e( 'Not on a profile', 'talenttrack' );
                    } else {
                        echo esc_html( $profiles[ $current ]['label'] );
                        echo ' · ';
                        echo esc_html( sprintf(
                            /* translators: %d is the number of modules and features that no longer match the install's profile. */
                            _n( '%d change since', '%d changes since', $divergence, 'talenttrack' ),
                            $divergence
                        ) );
                    }
                    ?>
                </p>
            </div>
            <form method="get" action="<?php echo esc_url( RecordLink::dashboardUrl() ); ?>" class="tt-profile-strip__pick">
                <input type="hidden" name="tt_view" value="<?php echo esc_attr( self::SLUG ); ?>" />
                <label class="tt-profile-strip__label" for="tt-profile-strip-select">
                    <?php esc_html_e( 'Change profile', 'talenttrack' ); ?>
                </label>
                <select id="tt-profile-strip-select" name="profile" class="tt-profile-strip__select">
                    <?php foreach ( $profiles as $slug => $profile ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $current ); ?>>
                            <?php echo esc_html( $profile['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="tt-btn tt-btn-secondary tt-profile-strip__go">
                    <?php esc_html_e( 'Review changes', 'talenttrack' ); ?>
                </button>
            </form>
        </section>
        <?php
    }

    /**
     * Apply the ticked rows. The only write path — see the class docblock.
     */
    public static function handleApply(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to manage modules.', 'talenttrack' ), 403 );
        }
        check_admin_referer( 'tt_install_profile_apply', 'tt_nonce' );

        $slug = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
        if ( ! ProfileRegistry::exists( $slug ) ) {
            wp_safe_redirect( self::modulesUrl() );
            exit;
        }

        // The form carries the rows the operator KEPT ticked, so the
        // exclusions are everything in the diff that did not come back.
        // Deriving them this way rather than trusting a submitted exclude
        // list means an unticked box cannot be turned into an applied
        // change by a crafted POST.
        $ticked = isset( $_POST['tt_apply'] ) && is_array( $_POST['tt_apply'] )
            ? array_map( 'sanitize_text_field', array_map( 'strval', (array) wp_unslash( $_POST['tt_apply'] ) ) )
            : [];

        $summary = ProfileService::apply( $slug, self::exclusionsFrom( $slug, $ticked ) );

        wp_safe_redirect( add_query_arg(
            [
                'tt_view'      => 'modules',
                'tt_msg'       => 'profile-applied',
                'tt_applied'   => count( $summary['applied'] ),
                'tt_untouched' => count( $summary['skipped'] ),
            ],
            RecordLink::dashboardUrl()
        ) );
        exit;
    }

    /**
     * The rows to hold back, derived from the ones that came back ticked.
     *
     * Deriving the exclusions from the diff rather than trusting a
     * submitted exclude list is what makes an unticked box stick: a POST
     * that simply omits the exclude field would otherwise apply
     * everything. A ticked id that is not in the current diff is ignored
     * for the same reason.
     *
     * @param list<string> $ticked
     * @return list<string>
     */
    public static function exclusionsFrom( string $slug, array $ticked ): array {
        $out = [];
        foreach ( ProfileService::diff( $slug ) as $row ) {
            if ( ! in_array( $row['id'], $ticked, true ) ) $out[] = $row['id'];
        }
        return $out;
    }

    /**
     * Row ids named by the request, for the drift notice's pre-filtered
     * open. Empty means "show the whole diff".
     *
     * @return list<string>
     */
    private static function requestedRowIds(): array {
        if ( ! isset( $_GET['rows'] ) ) return [];
        $raw = wp_unslash( $_GET['rows'] );
        if ( ! is_array( $raw ) ) $raw = explode( ',', (string) $raw );
        $out = [];
        foreach ( (array) $raw as $id ) {
            $id = sanitize_text_field( (string) $id );
            if ( $id !== '' ) $out[] = $id;
        }
        return $out;
    }

    /** @return array{label:string,url:string} */
    private static function modulesCrumb(): array {
        return FrontendBreadcrumbs::viewCrumb( 'modules', __( 'Modules', 'talenttrack' ) );
    }

    /**
     * Cancel target, and where an apply lands. Not a cross-view
     * navigation affordance needing its own gate: this screen and the
     * Modules page are gated on the same capability, so anyone who can
     * see this link can already open the page it points at.
     */
    private static function modulesUrl(): string {
        return add_query_arg( [ 'tt_view' => 'modules' ], RecordLink::dashboardUrl() ); /* tt-xview-ok */
    }

}
