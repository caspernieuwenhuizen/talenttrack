<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ProspectOutcome;
use TT\Modules\Prospects\Domain\ProspectRecord;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Wizards\Prospect\LogConsentRequestWizard;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendProspectDetailView (#4257) — a prospect's record page, at
 * `?tt_view=prospect&id=N`: where they stand on the onboarding pipeline
 * and what happens next, the consent requests sent on their behalf, and
 * the scouting visits they were seen at.
 *
 * Read-only. Its two actions open the surfaces that write: "Log consent
 * request" (a wizard) and "Edit contact" (`prospect-edit`).
 *
 * Every answer comes from `ProspectRecord`; a prospect the viewer may not
 * open reads exactly like one that does not exist.
 */
class FrontendProspectDetailView extends FrontendViewBase {

    public static function render( int $user_id ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-prospect-record',
            TT_PLUGIN_URL . 'assets/css/frontend-prospect-record.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        $parent_crumb = [
            FrontendBreadcrumbs::viewCrumb( 'prospects-overview', __( 'Prospects', 'talenttrack' ) ),
        ];

        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! ProspectRecord::canOpen( $user_id, $id ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Prospect not found', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Prospect not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This prospect does not exist or you do not have access.', 'talenttrack' ) . '</p>';
            return;
        }

        $prospect = (array) ( new ProspectsRepository() )->find( $id );
        $name     = trim( (string) ( $prospect['first_name'] ?? '' ) . ' ' . (string) ( $prospect['last_name'] ?? '' ) );
        if ( $name === '' ) $name = __( 'Prospect', 'talenttrack' );

        FrontendBreadcrumbs::fromDashboard( $name, $parent_crumb );
        self::renderHeader( $name );

        echo '<div class="tt-prospect-record">';
        self::renderIdentity( $prospect );
        self::renderStage( $user_id, $id );
        self::renderConsent( $user_id, $id );
        self::renderVisits( $user_id, $id, (string) ( $prospect['scouting_notes'] ?? '' ) );
        self::renderActions( $user_id, $id );
        echo '</div>';
    }

    /** @param array<string,mixed> $prospect */
    private static function renderIdentity( array $prospect ): void {
        $parts = [];
        $club  = trim( (string) ( $prospect['current_club'] ?? '' ) );
        if ( $club !== '' ) $parts[] = $club;
        $dob = (string) ( $prospect['date_of_birth'] ?? '' );
        if ( preg_match( '/^(\d{4})/', $dob, $m ) ) {
            /* translators: %s: birth year */
            $parts[] = sprintf( __( 'Born %s', 'talenttrack' ), $m[1] );
        }
        $found = (string) ( $prospect['discovered_at'] ?? '' );
        if ( $found !== '' ) {
            /* translators: %s: date the prospect was found */
            $parts[] = sprintf( __( 'Found %s', 'talenttrack' ), TTDate::date( $found ) );
        }

        echo '<p class="tt-prospect-record-identity">';
        echo '<span class="tt-chip">' . esc_html( ProspectOutcome::label( ProspectOutcome::forRow( $prospect ) ) ) . '</span>';
        foreach ( $parts as $part ) {
            echo ' <span class="tt-prospect-record-fact">' . esc_html( $part ) . '</span>';
        }
        echo '</p>';
    }

    private static function renderStage( int $user_id, int $id ): void {
        $stage = ProspectRecord::stage( $user_id, $id );
        echo '<section class="tt-prospect-record-section" aria-labelledby="tt-prospect-now">';
        echo '<h2 id="tt-prospect-now" class="tt-prospect-record-heading">' . esc_html__( 'Where they are now', 'talenttrack' ) . '</h2>';
        if ( $stage === null ) {
            echo '<p class="tt-prospect-record-empty">' . esc_html__( 'Not on the onboarding pipeline.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }
        echo '<p class="tt-prospect-record-stage"><strong>' . esc_html( $stage['label'] ) . '</strong>';
        if ( $stage['summary'] !== '' ) {
            echo ' <span class="tt-prospect-record-fact">' . esc_html( $stage['summary'] ) . '</span>';
        }
        echo '</p>';
        if ( $stage['next_url'] !== '' ) {
            echo '<p class="tt-prospect-record-next"><a class="tt-btn tt-btn-primary" href="' . esc_url( BackLink::appendTo( $stage['next_url'] ) ) . '">'
                . esc_html__( 'Open next action', 'talenttrack' ) . '</a></p>';
        }
        echo '</section>';
    }

    private static function renderConsent( int $user_id, int $id ): void {
        $entries = ProspectRecord::consentRequests( $id );
        echo '<section class="tt-prospect-record-section" aria-labelledby="tt-prospect-consent">';
        echo '<h2 id="tt-prospect-consent" class="tt-prospect-record-heading">' . esc_html__( 'Consent requests', 'talenttrack' ) . '</h2>';
        if ( $entries === [] ) {
            echo '<p class="tt-prospect-record-empty">' . esc_html__( 'No consent request logged yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-prospect-record-list">';
            foreach ( $entries as $entry ) {
                $asked_at = (string) ( $entry['asked_at'] ?? '' );
                $notes    = trim( (string) ( $entry['notes'] ?? '' ) );
                echo '<li class="tt-prospect-record-row">';
                echo '<span class="tt-prospect-record-when">' . esc_html( $asked_at !== '' ? TTDate::date( $asked_at ) : '' ) . '</span>';
                echo '<span class="tt-prospect-record-what">' . esc_html( (string) ( $entry['asked_of'] ?? '' ) ) . '</span>';
                echo '<span class="tt-chip">' . esc_html( ConsentOutcome::label( (string) ( $entry['outcome'] ?? '' ) ) ) . '</span>';
                if ( $notes !== '' ) {
                    echo '<span class="tt-prospect-record-note">' . esc_html( $notes ) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        if ( ProspectRecord::canLogConsent( $user_id, $id ) ) {
            $url = WizardEntryPoint::urlFor(
                LogConsentRequestWizard::SLUG,
                '',
                [ 'prospect_id' => $id ]
            );
            if ( $url !== '' ) {
                echo '<p class="tt-prospect-record-next"><a class="tt-btn tt-btn-secondary" href="' . esc_url( BackLink::appendTo( $url ) ) . '">'
                    . esc_html__( 'Log consent request', 'talenttrack' ) . '</a></p>';
            }
        }
        echo '</section>';
    }

    private static function renderVisits( int $user_id, int $id, string $scouting_notes ): void {
        $visits = ProspectRecord::visits( $user_id, $id );
        $notes  = trim( $scouting_notes );
        echo '<section class="tt-prospect-record-section" aria-labelledby="tt-prospect-visits">';
        echo '<h2 id="tt-prospect-visits" class="tt-prospect-record-heading">' . esc_html( _x( 'Scouting', 'prospect page section: notes and visits', 'talenttrack' ) ) . '</h2>';
        if ( $notes !== '' ) {
            echo '<p class="tt-prospect-record-notes">' . nl2br( esc_html( $notes ) ) . '</p>';
        }
        if ( $visits === [] ) {
            echo '<p class="tt-prospect-record-empty">' . esc_html__( 'No scouting visits you can open are linked to this prospect.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }
        echo '<ul class="tt-prospect-record-list">';
        foreach ( $visits as $visit ) {
            $label = trim( TTDate::date( $visit['visit_date'] ) . ' · ' . $visit['event'], ' ·' );
            echo '<li class="tt-prospect-record-row">';
            echo '<a class="tt-prospect-record-link" href="' . esc_url( RecordLink::detailUrlForWithBack( 'scouting-visit', $visit['visit_id'] ) ) . '">' . esc_html( $label ) . '</a>';
            if ( $visit['is_discovery'] ) {
                echo '<span class="tt-chip">' . esc_html( _x( 'Found here', 'the scouting visit a prospect was discovered at', 'talenttrack' ) ) . '</span>';
            }
            if ( $visit['notes'] !== '' ) {
                echo '<span class="tt-prospect-record-note">' . esc_html( $visit['notes'] ) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    private static function renderActions( int $user_id, int $id ): void {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' ) ) return;
        echo '<p class="tt-prospect-record-actions"><a class="tt-btn tt-btn-secondary" href="' . esc_url( RecordLink::detailUrlForWithBack( 'prospect-edit', $id ) ) . '">'
            . esc_html__( 'Edit contact', 'talenttrack' ) . '</a></p>';
    }
}
