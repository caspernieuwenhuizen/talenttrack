<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helper for the existing "+ New X" buttons on manage views.
 *
 *   $url = WizardEntryPoint::urlFor( 'new-player', $flat_form_url );
 *
 * Returns the wizard URL when the wizard is registered + enabled +
 * the current user has the cap; falls back to the flat-form URL
 * otherwise. Manage-view callers stay simple — one line, no branch.
 */
final class WizardEntryPoint {

    public static function urlFor( string $wizard_slug, string $fallback_url ): string {
        if ( ! WizardRegistry::isAvailable( $wizard_slug ) ) return $fallback_url;
        return add_query_arg( [ 'tt_view' => 'wizard', 'slug' => $wizard_slug ], home_url( '/' ) );
    }
}
