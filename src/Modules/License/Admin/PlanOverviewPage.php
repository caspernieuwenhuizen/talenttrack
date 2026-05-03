<?php
namespace TT\Modules\License\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\FeatureMap;
use TT\Modules\License\FreeTierCaps;
use TT\Modules\License\LicenseGate;

/**
 * PlanOverviewPage (v3.85.5) — single page that shows every license
 * restriction in one place. Visible to any logged-in user with the
 * `read` capability so coaches and players can see what's gated and
 * which plan they'd need to unlock more.
 *
 * Distinct from AccountPage:
 *   - AccountPage is operator-only (tt_edit_settings) and handles the
 *     trial / Freemius checkout flow.
 *   - PlanOverviewPage is read-only and answers "what can I do today
 *     and what's locked?" — a one-glance reference.
 *
 * Sections:
 *   1. Current tier + trial / grace banner.
 *   2. Caps table — current vs limit, with at-cap warning.
 *   3. Features matrix — 3-column Free/Standard/Pro × every feature.
 *   4. CTA to AccountPage to upgrade.
 */
final class PlanOverviewPage {

    public const SLUG = 'tt-license-plan';
    public const CAP  = 'read';

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register' ], 35 );
    }

    public static function register(): void {
        add_submenu_page(
            'talenttrack',
            __( 'Plan & restrictions', 'talenttrack' ),
            __( 'Plan & restrictions', 'talenttrack' ),
            self::CAP,
            self::SLUG,
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        $tier        = LicenseGate::tier();
        $tier_label  = FeatureMap::tierLabel( $tier );
        $effective   = LicenseGate::effectiveTier();
        $in_trial    = LicenseGate::isInTrial();
        $in_grace    = LicenseGate::isInGrace();
        $trial_days  = LicenseGate::trialDaysRemaining();
        $grace_days  = LicenseGate::graceDaysRemaining();

        echo '<div class="wrap"><h1>' . esc_html__( 'Plan & restrictions', 'talenttrack' ) . '</h1>';
        echo '<p>' . esc_html__( 'Everything that\'s locked or limited on your install, in one place. Caps come from the Free-tier policy; features come from the plan you\'re on.', 'talenttrack' ) . '</p>';

        // 1. Current tier
        echo '<div class="notice" style="padding:16px; max-width:760px; margin-top:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__( 'Current plan', 'talenttrack' ) . '</h2>';
        echo '<p style="font-size:18px; margin:0;"><strong>' . esc_html( $tier_label ) . '</strong>';
        if ( $in_trial ) {
            echo ' · <span style="color:#0b3d2e;">' . esc_html(
                sprintf(
                    /* translators: %d days remaining */
                    _n( '%d day left in trial', '%d days left in trial', $trial_days, 'talenttrack' ),
                    $trial_days
                )
            ) . '</span>';
        } elseif ( $in_grace ) {
            echo ' · <span style="color:#a86322;">' . esc_html(
                sprintf(
                    /* translators: %d grace days */
                    _n( 'Grace period — %d day until features lock', 'Grace period — %d days until features lock', $grace_days, 'talenttrack' ),
                    $grace_days
                )
            ) . '</span>';
        }
        echo '</p>';
        if ( $tier !== FeatureMap::TIER_PRO ) {
            $url = admin_url( 'admin.php?page=' . AccountPage::SLUG );
            echo '<p style="margin-top:12px;"><a class="button button-primary" href="' . esc_url( $url ) . '">'
                . esc_html__( 'Upgrade or start a trial', 'talenttrack' )
                . '</a></p>';
        }
        echo '</div>';

        // 2. Caps table
        echo '<h2 style="margin-top:32px;">' . esc_html__( 'Free-tier caps', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Caps apply only on the Free plan. Trial and Standard / Pro have no cap.', 'talenttrack' ) . '</p>';
        $caps_apply = ( $effective === FeatureMap::TIER_FREE );
        echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>';
        echo '<th>' . esc_html__( 'Resource', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Current', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Limit', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( [ 'teams', 'players' ] as $cap_type ) {
            $current = FreeTierCaps::currentCount( $cap_type );
            $limit   = FreeTierCaps::capFor( $cap_type );
            $at_cap  = $caps_apply && $current >= $limit;
            $colour  = $at_cap ? '#b32d2e' : ( $caps_apply && $current >= ( $limit * 0.8 ) ? '#a86322' : '#137333' );
            $status  = ! $caps_apply
                ? __( 'No cap (paid / trial)', 'talenttrack' )
                : ( $at_cap ? __( 'At cap — upgrade to add more', 'talenttrack' ) : __( 'Within cap', 'talenttrack' ) );
            $resource_label = $cap_type === 'teams'
                ? __( 'Teams', 'talenttrack' )
                : __( 'Players', 'talenttrack' );
            echo '<tr>';
            echo '<td>' . esc_html( $resource_label ) . '</td>';
            echo '<td style="font-variant-numeric:tabular-nums;">' . (int) $current . '</td>';
            echo '<td style="font-variant-numeric:tabular-nums;">' . esc_html( $caps_apply ? (string) $limit : '—' ) . '</td>';
            echo '<td style="color:' . esc_attr( $colour ) . ';">' . esc_html( $status ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // 3. Feature matrix
        echo '<h2 style="margin-top:32px;">' . esc_html__( 'Features by plan', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Tick = available on that plan. Your current effective plan is highlighted.', 'talenttrack' ) . '</p>';
        echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
        echo '<th>' . esc_html__( 'Feature', 'talenttrack' ) . '</th>';
        $tiers = [ FeatureMap::TIER_FREE, FeatureMap::TIER_STANDARD, FeatureMap::TIER_PRO ];
        foreach ( $tiers as $col_tier ) {
            $is_current = ( $col_tier === $effective );
            $style = $is_current ? ' style="background:#fffbe6;"' : '';
            echo '<th' . $style . '>' . esc_html( FeatureMap::tierLabel( $col_tier ) ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( self::featureCatalogue() as $feature_key => $feature_label ) {
            echo '<tr>';
            echo '<td>' . esc_html( $feature_label ) . '</td>';
            foreach ( $tiers as $col_tier ) {
                $has = FeatureMap::tierHas( $col_tier, $feature_key );
                $is_current = ( $col_tier === $effective );
                $cell_style = $is_current ? ' style="background:#fffbe6; text-align:center;"' : ' style="text-align:center;"';
                echo '<td' . $cell_style . '>' . ( $has ? '<span style="color:#137333; font-weight:600;">✓</span>' : '<span style="color:#999;">—</span>' ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:24px; color:#5b6e75; font-size:13px;">'
            . esc_html__( 'Caps and features update immediately when you start a trial or upgrade. The trial gives Standard for 30 days, then a 7-day grace period at Free with the full data still readable.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    /**
     * Catalogue of features to display, with translated labels. Order
     * matches the ranges declared in FeatureMap::DEFAULT_MAP for
     * stable rendering. Internal-only features (radar_charts, undo_bulk)
     * are included so operators understand the full restriction set.
     *
     * @return array<string,string>
     */
    private static function featureCatalogue(): array {
        return [
            'core_evaluations'  => __( 'Evaluations', 'talenttrack' ),
            'core_sessions'     => __( 'Activities', 'talenttrack' ),
            'core_goals'        => __( 'Goals', 'talenttrack' ),
            'core_attendance'   => __( 'Attendance', 'talenttrack' ),
            'core_player_card'  => __( 'Player cards', 'talenttrack' ),
            'core_dashboard'    => __( 'Dashboard', 'talenttrack' ),
            'backup_local'      => __( 'Local backup', 'talenttrack' ),
            'backup_email'      => __( 'Email backup', 'talenttrack' ),
            'onboarding'        => __( 'Onboarding wizard', 'talenttrack' ),
            'demo_data'         => __( 'Demo data generator', 'talenttrack' ),
            'radar_charts'      => __( 'Radar charts', 'talenttrack' ),
            'player_comparison' => __( 'Player comparison', 'talenttrack' ),
            'rate_cards_full'   => __( 'Rate cards (full)', 'talenttrack' ),
            'csv_import'        => __( 'CSV import', 'talenttrack' ),
            'functional_roles'  => __( 'Functional roles', 'talenttrack' ),
            'partial_restore'   => __( 'Partial restore', 'talenttrack' ),
            'undo_bulk'         => __( 'Undo bulk actions', 'talenttrack' ),
            'multi_academy'     => __( 'Multi-academy', 'talenttrack' ),
            'photo_session'     => __( 'Photo-to-activity capture', 'talenttrack' ),
            'trial_module'      => __( 'Trial cases', 'talenttrack' ),
            'scout_access'      => __( 'Scout access', 'talenttrack' ),
            'team_chemistry'    => __( 'Team chemistry', 'talenttrack' ),
            's3_backup'         => __( 'S3 backup', 'talenttrack' ),
        ];
    }
}
