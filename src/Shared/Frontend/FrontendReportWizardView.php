<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Reports\AudienceDefaults;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\PlayerReportRenderer;
use TT\Modules\Reports\PrivacySettings;
use TT\Modules\Reports\ReportConfig;
use TT\Modules\Reports\ScoutDelivery;
use TT\Modules\Reports\ScoutReportsRepository;

/**
 * FrontendReportWizardView — the four-step wizard that builds a
 * {@see ReportConfig} and feeds it to {@see PlayerReportRenderer}.
 *
 * #0014 Sprint 4 + 5. Single-page form with progressive sections;
 * "Preview" submits and the rendered report appears inline below.
 * Scout audience gets an additional delivery block (Sprint 5).
 *
 * Capability gate: `tt_generate_report` (granted to head_dev + coach +
 * implicitly to the player on their own record). Scout-flow delivery
 * additionally requires `tt_generate_scout_report`.
 */
class FrontendReportWizardView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $player    = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        if ( ! $player ) {
            self::renderHeader( __( 'Generate report', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Pick a player from their profile or rate-card page first.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! self::canGenerateForPlayer( $user_id, $player, $is_admin ) ) {
            self::renderHeader( __( 'Generate report', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( "You don't have permission to generate a report for this player.", 'talenttrack' ) . '</p>';
            return;
        }

        $is_preview = isset( $_GET['preview'] ) && $_GET['preview'] === '1';
        $submitted  = $_POST ?: $_GET;

        $audience_defaults_for = static function ( string $a ): array {
            return AudienceDefaults::defaultsFor( $a );
        };

        // Resolve current state. POST values win; otherwise audience
        // defaults; otherwise spec defaults.
        $audience = (string) ( $submitted['audience'] ?? AudienceType::STANDARD );
        if ( ! AudienceType::isValid( $audience ) ) $audience = AudienceType::STANDARD;
        $defaults  = $audience_defaults_for( $audience );

        $scope        = (string) ( $submitted['scope'] ?? $defaults['scope'] );
        $date_from    = (string) ( $submitted['date_from'] ?? '' );
        $date_to      = (string) ( $submitted['date_to'] ?? '' );
        $sections     = isset( $submitted['sections'] ) && is_array( $submitted['sections'] )
            ? array_map( 'sanitize_key', $submitted['sections'] )
            : $defaults['sections'];

        $privacy_in   = isset( $submitted['privacy'] ) && is_array( $submitted['privacy'] )
            ? $submitted['privacy']
            : $defaults['privacy']->toArray();
        $privacy      = PrivacySettings::fromArray( $privacy_in );

        // Resolve filters from scope (or custom range).
        if ( $scope === 'custom' ) {
            $filters = [
                'date_from'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '',
                'date_to'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ? $date_to : '',
                'eval_type_id' => 0,
            ];
        } else {
            $f = AudienceDefaults::resolveScope( $scope );
            $filters = [
                'date_from'    => $f['date_from'],
                'date_to'      => $f['date_to'],
                'eval_type_id' => 0,
            ];
        }

        $config = new ReportConfig(
            $audience,
            $filters,
            $sections,
            $privacy,
            $player_id,
            $user_id,
            null,
            (string) ( $defaults['tone_variant'] ?? 'default' )
        );

        self::renderHeader( sprintf(
            /* translators: %s: player name */
            __( 'Generate report — %s', 'talenttrack' ),
            QueryHelpers::player_display_name( $player )
        ) );

        self::enqueueWizardStyles();
        self::renderForm( $player, $config, $scope, $date_from, $date_to );

        if ( $is_preview ) {
            // Scout deliver: when scout audience and a recipient email
            // is provided, persist the report and either email the link
            // or surface the assignment confirmation.
            if ( $audience === AudienceType::SCOUT && ! empty( $submitted['scout_send'] ) && current_user_can( 'tt_generate_scout_report' ) ) {
                self::handleScoutSend( $player, $config, $submitted );
            }

            self::renderPreview( $config );
        }
    }

    /**
     * Cap gate. Coaches can generate for players on their teams; HoD
     * for any; players for themselves; admins for any.
     */
    private static function canGenerateForPlayer( int $user_id, object $player, bool $is_admin ): bool {
        if ( ! current_user_can( 'tt_generate_report' ) && ! $is_admin ) return false;
        if ( $is_admin || current_user_can( 'tt_view_settings' ) ) return true;

        // Coach: must coach the player's team.
        if ( current_user_can( 'tt_edit_evaluations' ) && ! empty( $player->team_id ) ) {
            $coached = QueryHelpers::get_teams_for_coach( $user_id );
            foreach ( $coached as $t ) {
                if ( (int) $t->id === (int) $player->team_id ) return true;
            }
        }

        // Player viewing own record.
        $own = QueryHelpers::get_player_for_user( $user_id );
        if ( $own && (int) $own->id === (int) $player->id ) return true;

        return false;
    }

    /**
     * @param object       $player
     * @param ReportConfig $config
     */
    private static function renderForm( object $player, ReportConfig $config, string $scope, string $date_from, string $date_to ): void {
        $audiences = AudienceType::all();
        $current_audience = $config->audience;
        $is_scout = $current_audience === AudienceType::SCOUT;
        $can_scout = current_user_can( 'tt_generate_scout_report' );
        $current_url = remove_query_arg( [ 'preview' ] );
        ?>
        <form method="post" class="tt-report-wizard" action="<?php echo esc_url( add_query_arg( 'preview', '1', $current_url ) ); ?>">
            <input type="hidden" name="player_id" value="<?php echo (int) $player->id; ?>" />

            <fieldset class="tt-rwz-step">
                <legend><?php esc_html_e( '1. Audience', 'talenttrack' ); ?></legend>
                <p class="tt-rwz-help"><?php esc_html_e( 'Pick who the report is for. Sensible defaults for sections and privacy are pre-selected.', 'talenttrack' ); ?></p>
                <div class="tt-rwz-radios">
                    <?php foreach ( $audiences as $a ) :
                        if ( $a === AudienceType::SCOUT && ! $can_scout ) continue;
                        $checked = $a === $current_audience;
                        ?>
                        <label class="tt-rwz-radio">
                            <input type="radio" name="audience" value="<?php echo esc_attr( $a ); ?>" <?php checked( $checked ); ?> />
                            <span class="tt-rwz-radio-label"><?php echo esc_html( AudienceType::label( $a ) ); ?></span>
                            <span class="tt-rwz-radio-desc"><?php echo esc_html( AudienceType::describe( $a ) ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="tt-rwz-step">
                <legend><?php esc_html_e( '2. Scope', 'talenttrack' ); ?></legend>
                <p class="tt-rwz-help"><?php esc_html_e( 'Time window for the data on the report.', 'talenttrack' ); ?></p>
                <div class="tt-rwz-scope">
                    <?php foreach ( AudienceDefaults::scopeOptions() as $key => $label ) :
                        $checked = $key === $scope;
                        ?>
                        <label class="tt-rwz-radio">
                            <input type="radio" name="scope" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> />
                            <span class="tt-rwz-radio-label"><?php echo esc_html( $label ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="tt-rwz-custom-range" style="<?php echo $scope === 'custom' ? '' : 'display:none;'; ?>">
                    <label>
                        <span><?php esc_html_e( 'From', 'talenttrack' ); ?></span>
                        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Until', 'talenttrack' ); ?></span>
                        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
                    </label>
                </div>
            </fieldset>

            <fieldset class="tt-rwz-step">
                <legend><?php esc_html_e( '3. Sections', 'talenttrack' ); ?></legend>
                <p class="tt-rwz-help"><?php esc_html_e( 'Pick which parts to include. Defaults match the audience.', 'talenttrack' ); ?></p>
                <div class="tt-rwz-checks">
                    <?php foreach ( AudienceDefaults::sectionLabels() as $key => $label ) :
                        $checked = $config->includesSection( $key );
                        ?>
                        <label class="tt-rwz-check">
                            <input type="checkbox" name="sections[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="tt-rwz-step">
                <legend><?php esc_html_e( '4. Privacy', 'talenttrack' ); ?></legend>
                <p class="tt-rwz-help"><?php esc_html_e( 'Defaults are conservative. Tick a box to include extra data on this report only.', 'talenttrack' ); ?></p>
                <div class="tt-rwz-checks">
                    <label class="tt-rwz-check">
                        <input type="checkbox" name="privacy[include_contact_details]" value="1" <?php checked( $config->privacy->include_contact_details ); ?> />
                        <?php esc_html_e( 'Include contact details (guardian email/phone)', 'talenttrack' ); ?>
                    </label>
                    <label class="tt-rwz-check">
                        <input type="checkbox" name="privacy[include_full_dob]" value="1" <?php checked( $config->privacy->include_full_dob ); ?> />
                        <?php esc_html_e( 'Include full date of birth', 'talenttrack' ); ?>
                    </label>
                    <label class="tt-rwz-check">
                        <input type="checkbox" name="privacy[include_photo]" value="1" <?php checked( $config->privacy->include_photo ); ?> />
                        <?php esc_html_e( "Include the player's photo", 'talenttrack' ); ?>
                    </label>
                    <label class="tt-rwz-check">
                        <input type="checkbox" name="privacy[include_coach_notes]" value="1" <?php checked( $config->privacy->include_coach_notes ); ?> />
                        <?php esc_html_e( 'Include coach free-text notes', 'talenttrack' ); ?>
                    </label>
                    <label class="tt-rwz-check tt-rwz-check--inline">
                        <span><?php esc_html_e( 'Hide ratings below', 'talenttrack' ); ?></span>
                        <input type="number" step="0.1" min="0" max="5" name="privacy[min_rating_threshold]" value="<?php echo esc_attr( (string) $config->privacy->min_rating_threshold ); ?>" />
                    </label>
                </div>
            </fieldset>

            <?php if ( $is_scout && $can_scout ) : ?>
                <fieldset class="tt-rwz-step tt-rwz-step--scout">
                    <legend><?php esc_html_e( 'Scout delivery', 'talenttrack' ); ?></legend>
                    <p class="tt-rwz-help"><?php esc_html_e( 'After previewing, send the report as a one-time emailed link.', 'talenttrack' ); ?></p>
                    <label class="tt-rwz-field">
                        <span><?php esc_html_e( 'Recipient email', 'talenttrack' ); ?></span>
                        <input type="email" name="scout_email" value="<?php echo esc_attr( (string) ( $_POST['scout_email'] ?? '' ) ); ?>" />
                    </label>
                    <label class="tt-rwz-field">
                        <span><?php esc_html_e( 'Link expires after', 'talenttrack' ); ?></span>
                        <select name="scout_expiry_days">
                            <option value="7">7 <?php esc_html_e( 'days', 'talenttrack' ); ?></option>
                            <option value="14" selected>14 <?php esc_html_e( 'days', 'talenttrack' ); ?></option>
                            <option value="30">30 <?php esc_html_e( 'days', 'talenttrack' ); ?></option>
                        </select>
                    </label>
                    <label class="tt-rwz-field">
                        <span><?php esc_html_e( 'Optional message to scout', 'talenttrack' ); ?></span>
                        <textarea name="scout_message" rows="3"><?php echo esc_textarea( (string) ( $_POST['scout_message'] ?? '' ) ); ?></textarea>
                    </label>
                    <p class="tt-rwz-help">
                        <?php esc_html_e( 'Tick the box below to send when you click Preview.', 'talenttrack' ); ?>
                    </p>
                    <label class="tt-rwz-check">
                        <input type="checkbox" name="scout_send" value="1" />
                        <?php esc_html_e( 'Send link to the recipient on Preview', 'talenttrack' ); ?>
                    </label>
                </fieldset>
            <?php endif; ?>

            <div class="tt-rwz-actions">
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Preview report', 'talenttrack' ); ?>
                </button>
            </div>
        </form>

        <script>
        (function(){
            var scopeRadios = document.querySelectorAll('.tt-rwz-scope input[type="radio"][name="scope"]');
            var customBlock = document.querySelector('.tt-rwz-custom-range');
            scopeRadios.forEach(function(r){
                r.addEventListener('change', function(){
                    if ( customBlock ) customBlock.style.display = (r.value === 'custom' && r.checked) ? '' : 'none';
                });
            });
            var audienceRadios = document.querySelectorAll('.tt-rwz-radios input[type="radio"][name="audience"]');
            audienceRadios.forEach(function(r){
                r.addEventListener('change', function(){
                    // Re-submit on audience change so server applies defaults.
                    if ( r.checked ) r.form.submit();
                });
            });
        })();
        </script>
        <?php
    }

    private static function renderPreview( ReportConfig $config ): void {
        ?>
        <div class="tt-rwz-preview">
            <h3 class="tt-rwz-preview-title"><?php esc_html_e( 'Preview', 'talenttrack' ); ?></h3>
            <p class="tt-rwz-help">
                <?php esc_html_e( "Use your browser's print dialog (Save as PDF) to keep a copy.", 'talenttrack' ); ?>
                <button type="button" class="tt-btn tt-btn-secondary" onclick="window.print();" style="margin-left:8px;">
                    <?php esc_html_e( 'Print this report', 'talenttrack' ); ?>
                </button>
            </p>
            <div class="tt-rwz-report-host">
                <?php
                $renderer = new PlayerReportRenderer();
                echo $renderer->render( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped HTML.
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Sprint 5 — when the scout audience is selected and the user
     * confirms, persist the report + send the email. Surfaces a
     * confirmation row above the preview.
     */
    private static function handleScoutSend( object $player, ReportConfig $config, array $submitted ): void {
        if ( ! class_exists( ScoutDelivery::class ) || ! class_exists( ScoutReportsRepository::class ) ) {
            return;
        }
        $email   = sanitize_email( (string) ( $submitted['scout_email'] ?? '' ) );
        $expiry  = (int) ( $submitted['scout_expiry_days'] ?? 14 );
        $message = sanitize_textarea_field( (string) ( $submitted['scout_message'] ?? '' ) );
        if ( $email === '' || ! is_email( $email ) ) {
            echo '<p class="tt-notice notice-error">' . esc_html__( 'Recipient email is missing or invalid.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! in_array( $expiry, [ 7, 14, 30 ], true ) ) $expiry = 14;

        $delivery = new ScoutDelivery();
        $result   = $delivery->emailLink( $player, $config, $email, $expiry, $message );
        if ( $result['ok'] ) {
            echo '<p class="tt-notice notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">'
                . esc_html( sprintf(
                    /* translators: 1: recipient email, 2: expiry days */
                    __( 'Link emailed to %1$s. Expires in %2$d days.', 'talenttrack' ),
                    $email,
                    $expiry
                ) )
                . '</p>';
        } else {
            echo '<p class="tt-notice notice-error">'
                . esc_html__( 'Could not send the link. Check the email address and your site mail settings, then try again.', 'talenttrack' )
                . '</p>';
        }
    }

    private static function enqueueWizardStyles(): void {
        ?>
        <style>
        .tt-report-wizard {
            max-width: 760px;
            margin: 0 0 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .tt-rwz-step {
            border: 1px solid #e5e7ea;
            border-radius: 10px;
            padding: 16px 20px;
            background: #fff;
            margin: 0;
        }
        .tt-rwz-step legend {
            font-weight: 600;
            font-size: 14px;
            padding: 0 6px;
            color: #1a1d21;
        }
        .tt-rwz-step--scout { border-color: #2271b1; background: #f6fafe; }
        .tt-rwz-help {
            margin: 4px 0 12px;
            font-size: 12px;
            color: #5b6470;
        }
        .tt-rwz-radios, .tt-rwz-checks, .tt-rwz-scope {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .tt-rwz-radio {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid transparent;
            border-radius: 6px;
            cursor: pointer;
        }
        .tt-rwz-radio:hover { background: #f6f7f7; }
        .tt-rwz-radio input { margin-top: 2px; }
        .tt-rwz-radio-label { font-weight: 600; }
        .tt-rwz-radio-desc {
            display: block;
            width: 100%;
            margin-left: 24px;
            font-size: 12px;
            color: #5b6470;
        }
        .tt-rwz-scope .tt-rwz-radio { padding: 4px 8px; }
        .tt-rwz-custom-range { display: flex; gap: 12px; margin-top: 8px; }
        .tt-rwz-custom-range label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
        .tt-rwz-check { display: inline-flex; align-items: center; gap: 8px; }
        .tt-rwz-check--inline { gap: 12px; }
        .tt-rwz-check--inline input[type="number"] { width: 70px; }
        .tt-rwz-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .tt-rwz-field input, .tt-rwz-field select, .tt-rwz-field textarea {
            padding: 6px 8px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px;
        }
        .tt-rwz-actions { padding-top: 4px; }
        .tt-rwz-preview { margin-top: 28px; }
        .tt-rwz-preview-title {
            font-size: 18px; margin: 0 0 6px; padding-bottom: 6px;
            border-bottom: 2px solid #1a1d21;
        }
        @media print {
            .tt-report-wizard, .tt-rwz-preview-title, .tt-rwz-help { display: none !important; }
            .tt-fview-title, .tt-back-link { display: none !important; }
        }
        </style>
        <?php
    }
}
