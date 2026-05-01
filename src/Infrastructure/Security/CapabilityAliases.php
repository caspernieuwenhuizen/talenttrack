<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CapabilityAliases — v3.0.0 soft-compatibility layer.
 *
 * The capability refactor (see RolesService) split each legacy cap
 * into view/edit pairs. Rather than rewriting every existing
 * `current_user_can('tt_manage_players')` call site at once — which
 * would be ~60-80 files of churn and a high regression risk — we
 * install a tiny shim: checks against the OLD cap names get resolved
 * to the NEW granular caps under the hood.
 *
 * Mapping:
 *   tt_manage_players    →  user must have BOTH tt_view_players AND tt_edit_players
 *   tt_evaluate_players  →  tt_view_evaluations AND tt_edit_evaluations
 *   tt_manage_settings   →  tt_view_settings AND tt_edit_settings
 *
 * This is a conservative mapping: legacy checks that used to mean
 * "can manage" (implying write) now require the write cap explicitly.
 * Pure-view users (observer role) correctly fail legacy `manage`
 * checks because they lack the edit cap.
 *
 * tt_view_reports is unchanged — it had no write companion.
 *
 * Slice 2 of v3 gradually rewrites call sites to use the granular
 * caps directly, at which point this alias layer can be simplified
 * or removed in v3.1+.
 */
class CapabilityAliases {

    /**
     * Legacy cap → [required new caps].
     * Both new caps must be present for the legacy check to pass.
     */
    private const MAP = [
        'tt_manage_players'   => [ 'tt_view_players', 'tt_edit_players' ],
        'tt_evaluate_players' => [ 'tt_view_evaluations', 'tt_edit_evaluations' ],
        'tt_manage_settings'  => [ 'tt_view_settings', 'tt_edit_settings' ],

        // #0071 — Settings sub-cap split. The umbrella `tt_view_settings` /
        // `tt_edit_settings` become roll-ups: a user "has" them iff they
        // hold ALL the per-area sub-caps. This keeps existing umbrella-cap
        // call sites working while new code uses the specific sub-cap.
        'tt_view_settings' => [
            'tt_view_lookups', 'tt_view_branding', 'tt_view_feature_toggles',
            'tt_view_audit_log', 'tt_view_translations', 'tt_view_custom_fields',
            'tt_view_evaluation_categories', 'tt_view_category_weights',
            'tt_view_rating_scale', 'tt_view_migrations', 'tt_view_seasons',
            'tt_view_setup_wizard',
        ],
        'tt_edit_settings' => [
            'tt_edit_lookups', 'tt_edit_branding', 'tt_edit_feature_toggles',
            'tt_edit_translations', 'tt_edit_custom_fields',
            'tt_edit_evaluation_categories', 'tt_edit_category_weights',
            'tt_edit_rating_scale', 'tt_edit_migrations', 'tt_edit_seasons',
            'tt_edit_setup_wizard',
        ],
    ];

    public static function init(): void {
        add_filter( 'user_has_cap', [ __CLASS__, 'filter' ], 10, 4 );
    }

    /**
     * WordPress fires `user_has_cap` when checking capabilities. The
     * filter receives the user's resolved cap map — we add legacy
     * caps as true when their new-cap counterparts are all granted.
     *
     * The filter signature:
     *   @param array $allcaps   Caps map the user has resolved to
     *   @param array $caps      Required caps for the meta cap check
     *   @param array $args      Meta cap args ([0]=requested cap, [1]=user_id, [2]=object_id)
     *   @param \WP_User $user
     *
     * @param array<string,bool> $allcaps
     * @param array<string>      $caps
     * @param array<mixed>       $args
     * @return array<string,bool>
     */
    public static function filter( $allcaps, $caps, $args, $user ) {
        foreach ( self::MAP as $legacy_cap => $required_new_caps ) {
            // If the user already has this legacy cap granted directly
            // (via role assignment or add_cap), leave it alone — the
            // cap is set by legitimate means.
            if ( ! empty( $allcaps[ $legacy_cap ] ) ) continue;

            // Otherwise, grant the legacy cap iff the user holds ALL
            // the required new granular caps.
            $all_present = true;
            foreach ( $required_new_caps as $req ) {
                if ( empty( $allcaps[ $req ] ) ) {
                    $all_present = false;
                    break;
                }
            }
            if ( $all_present ) {
                $allcaps[ $legacy_cap ] = true;
            }
        }
        return $allcaps;
    }
}
