<?php
/**
 * Migration 0053 — Settings sub-cap backfill (#0071 child 2).
 *
 * Seeds the new `tt_view_*` / `tt_edit_*` per-area sub-caps onto every
 * user who currently holds the umbrella `tt_view_settings` /
 * `tt_edit_settings` so no user loses access on upgrade.
 *
 * Idempotent: re-running checks `has_cap()` first.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0053_settings_subcaps_seed';
    }

    public function up(): void {
        if ( ! function_exists( 'get_users' ) ) return;

        $view_subs = [
            'tt_view_lookups', 'tt_view_branding', 'tt_view_feature_toggles',
            'tt_view_audit_log', 'tt_view_translations', 'tt_view_custom_fields',
            'tt_view_evaluation_categories', 'tt_view_category_weights',
            'tt_view_rating_scale', 'tt_view_migrations', 'tt_view_seasons',
            'tt_view_setup_wizard',
        ];
        $edit_subs = [
            'tt_edit_lookups', 'tt_edit_branding', 'tt_edit_feature_toggles',
            'tt_edit_translations', 'tt_edit_custom_fields',
            'tt_edit_evaluation_categories', 'tt_edit_category_weights',
            'tt_edit_rating_scale', 'tt_edit_migrations', 'tt_edit_seasons',
            'tt_edit_setup_wizard',
        ];

        // get_users with capability filter is the cheap path; same query
        // used by the existing migration scripts.
        $view_holders = (array) get_users( [ 'capability' => 'tt_view_settings', 'fields' => [ 'ID' ] ] );
        $edit_holders = (array) get_users( [ 'capability' => 'tt_edit_settings', 'fields' => [ 'ID' ] ] );
        $manage_holders = (array) get_users( [ 'capability' => 'tt_manage_settings', 'fields' => [ 'ID' ] ] );

        foreach ( $view_holders as $row ) {
            $u = get_userdata( (int) $row->ID );
            if ( ! $u ) continue;
            foreach ( $view_subs as $cap ) {
                if ( ! $u->has_cap( $cap ) ) $u->add_cap( $cap );
            }
        }
        foreach ( $edit_holders as $row ) {
            $u = get_userdata( (int) $row->ID );
            if ( ! $u ) continue;
            foreach ( $edit_subs as $cap ) {
                if ( ! $u->has_cap( $cap ) ) $u->add_cap( $cap );
            }
        }
        foreach ( $manage_holders as $row ) {
            $u = get_userdata( (int) $row->ID );
            if ( ! $u ) continue;
            foreach ( $edit_subs as $cap ) {
                if ( ! $u->has_cap( $cap ) ) $u->add_cap( $cap );
            }
            if ( ! $u->has_cap( 'tt_manage_authorization' ) ) {
                $u->add_cap( 'tt_manage_authorization' );
            }
        }
    }
};
