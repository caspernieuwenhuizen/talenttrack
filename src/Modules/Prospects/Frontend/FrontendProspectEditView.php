<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendProspectEditView (#2838) — correct the parent contact block
 * and the consent state on an existing prospect, at
 * `?tt_view=prospect-edit&id=N`.
 *
 * A prospect could be created and never corrected. Phone numbers change,
 * emails get mistyped, and consent frequently arrives a day later by
 * text — and the scout's only recourse was a message to the head of
 * development, which is the "data lives in WhatsApp" failure the
 * onboarding pipeline exists to end.
 *
 * Consent is the sharp half. These are minors: a consent state that
 * cannot be corrected after creation asserts something about a family
 * that may no longer be true, and nobody could fix it without a database
 * edit. Withdrawing consent here is exactly as easy as recording it —
 * clear the date and save.
 *
 * Deliberately narrow. Name, date of birth and the discovery context are
 * not editable here; correcting *who this player is* is a different
 * question from correcting *how we reach their family*, and mixing them
 * into one form invites the first to be changed by accident while doing
 * the second. The per-prospect detail view and the append-only scouting
 * note are a separate piece of work.
 *
 * All decisions live in `ProspectsRepository` and the REST controller —
 * this view composes and nothing more.
 */
class FrontendProspectEditView extends FrontendViewBase {

    public static function render( int $user_id ): void {
        $parent_crumb = [
            FrontendBreadcrumbs::viewCrumb( 'prospects-overview', __( 'Prospects', 'talenttrack' ) ),
        ];

        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Edit prospect', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit prospects.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $prospect = $id > 0 ? ( new ProspectsRepository() )->find( $id ) : null;

        if ( ! $prospect ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Prospect not found', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Prospect not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This prospect does not exist or you do not have access.', 'talenttrack' ) . '</p>';
            return;
        }

        $name = trim( (string) ( $prospect->first_name ?? '' ) . ' ' . (string) ( $prospect->last_name ?? '' ) );
        if ( $name === '' ) {
            $name = __( 'Prospect', 'talenttrack' );
        }

        FrontendBreadcrumbs::fromDashboard( $name, $parent_crumb );
        self::renderHeader( $name );

        self::renderForm( $prospect );
    }

    private static function renderForm( object $prospect ): void {
        $id = (int) ( $prospect->id ?? 0 );

        // The column is a datetime; the form works in whole days, which
        // is the granularity a consent conversation actually has.
        $consent_raw  = (string) ( $prospect->consent_given_at ?? '' );
        $consent_date = $consent_raw !== '' ? substr( $consent_raw, 0, 10 ) : '';

        // Cancel target: where the user came from when tt_back carries it,
        // the list otherwise (CLAUDE.md § 6). Not routed through
        // CrossViewLink deliberately — that helper HIDES a link whose target
        // the user cannot reach, and a Cancel button that can disappear
        // strands someone on a half-filled form, which is the exact failure
        // § 6 exists to prevent. The user reached this form from the list or
        // the pipeline, so the target is one they just came from.
        $back       = BackLink::resolve();
        $cancel_url = $back !== null
            ? $back['url']
            : remove_query_arg( [ 'action', 'id' ], add_query_arg( [ 'tt_view' => 'prospects-overview' ] ) ); /* tt-xview-ok */
        ?>
        <form class="tt-ajax-form"
              data-rest-path="prospects/<?php echo esc_attr( (string) $id ); ?>"
              data-rest-method="PATCH"
              data-redirect-after-save="list:prospects-overview"
              data-redirect-after-save-url="<?php echo esc_url( $cancel_url ); ?>">

            <fieldset class="tt-fieldset">
                <legend class="tt-field-label"><?php esc_html_e( 'Parent or guardian', 'talenttrack' ); ?></legend>

                <div class="tt-field">
                    <label class="tt-field-label" for="tt-prospect-parent-name"><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-prospect-parent-name" class="tt-input"
                           name="parent_name" autocomplete="name"
                           value="<?php echo esc_attr( (string) ( $prospect->parent_name ?? '' ) ); ?>" />
                </div>

                <div class="tt-field">
                    <label class="tt-field-label" for="tt-prospect-parent-email"><?php esc_html_e( 'Email', 'talenttrack' ); ?></label>
                    <input type="email" id="tt-prospect-parent-email" class="tt-input"
                           name="parent_email" inputmode="email" autocomplete="email"
                           value="<?php echo esc_attr( (string) ( $prospect->parent_email ?? '' ) ); ?>" />
                </div>

                <div class="tt-field">
                    <label class="tt-field-label" for="tt-prospect-parent-phone"><?php esc_html_e( 'Phone', 'talenttrack' ); ?></label>
                    <input type="tel" id="tt-prospect-parent-phone" class="tt-input"
                           name="parent_phone" inputmode="tel" autocomplete="tel"
                           value="<?php echo esc_attr( (string) ( $prospect->parent_phone ?? '' ) ); ?>" />
                </div>
            </fieldset>

            <fieldset class="tt-fieldset">
                <legend class="tt-field-label"><?php esc_html_e( 'Consent', 'talenttrack' ); ?></legend>

                <div class="tt-field">
                    <label class="tt-field-label" for="consent_given_at"><?php esc_html_e( 'Consent given on', 'talenttrack' ); ?></label>
                    <input type="date" id="consent_given_at" class="tt-input"
                           name="consent_given_at"
                           value="<?php echo esc_attr( $consent_date ); ?>" />
                    <span class="tt-field-hint">
                        <?php esc_html_e( 'The date the parent or guardian agreed. Leave empty if consent has not been given, or clear it to record that consent was withdrawn.', 'talenttrack' ); ?>
                    </span>
                </div>
            </fieldset>

            <?php
            echo FormSaveButton::render( [
                'label'      => __( 'Save changes', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }
}
