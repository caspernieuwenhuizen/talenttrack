<?php
namespace TT\Modules\Vct\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Vct\Repositories\VctCoachingPointsRepository;
use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Modules\Vct\Repositories\VctSessionBlocksRepository;
use TT\Modules\Vct\Repositories\VctSessionsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendVctSessionView (#0095 VCT-10 / #948).
 *
 * Coach mobile session view at ?tt_view=vct-session&id=N.
 *
 * Renders a single VCT training the wizard produced: header chips
 * (age, MD context, total min, total load, status) + one card per
 * block (slot, picked exercise, coaching points, duration, intensity)
 * + engine warnings + a Publish CTA (form POST to /vct/sessions/{id}/
 * publish via the REST controller).
 *
 * When `?print=a4` is present, delegates to FrontendVctSessionPrintView
 * — a sub-render that emits no breadcrumbs and minimal chrome (a coach
 * clipboard A4 sheet).
 *
 * Two-layer permission: cap `tt_vct_plan` (matrix-aware) + scope check
 * against the session's team_id via canPlanForTeam().
 */
class FrontendVctSessionView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        // Print sub-render path.
        $print = isset( $_GET['print'] ) ? sanitize_key( (string) $_GET['print'] ) : '';
        if ( $print === 'a4' && $id > 0 ) {
            FrontendVctSessionPrintView::render( (int) $id, $user_id, $is_admin );
            return;
        }

        $sessions_repo = new VctSessionsRepository();
        $session = $id > 0 ? $sessions_repo->find( $id ) : null;

        $parent_crumb = [ FrontendBreadcrumbs::viewCrumb( 'wizard', __( 'VCT planner', 'talenttrack' ) ) ];

        if ( $session === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'VCT training not found', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'VCT training not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This VCT training does not exist or you do not have access.', 'talenttrack' ) . '</p>';
            return;
        }

        // Cap + scope check.
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_plan' )
            || ! AuthorizationService::canPlanForTeam( $user_id, (int) $session['team_id'], 'read' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'VCT training', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this VCT training.', 'talenttrack' ) . '</p>';
            return;
        }

        // Handle POST publish action (form POST from the Publish button).
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['_tt_vct_publish_nonce'] ) ) {
            self::handlePublishPost( $session, (string) $_POST['_tt_vct_publish_nonce'] );
            // Re-read after publish to get fresh status.
            $session = $sessions_repo->find( $id );
            if ( $session === null ) return;
        }

        $title = sprintf(
            /* translators: 1: age group, 2: localised MD context label, 3: localised date */
            __( 'VCT training — %1$s · %2$s · %3$s', 'talenttrack' ),
            (string) $session['age_group'],
            LookupTranslator::byTypeAndName( 'vct_md_context', (string) $session['md_context'] ),
            mysql2date( get_option( 'date_format' ), (string) $session['session_date'], true )
        );
        FrontendBreadcrumbs::fromDashboard( $title, $parent_crumb );

        $page_actions = [];
        if ( $session['status'] === 'draft'
            && AuthorizationService::canPlanForTeam( $user_id, (int) $session['team_id'], 'change' ) ) {
            // Print link.
            $page_actions[] = [
                'label' => __( 'Print A4', 'talenttrack' ),
                'href'  => add_query_arg( [ 'print' => 'a4' ] ),
                'icon'  => '🖨',
            ];
        }
        self::renderHeader( $title, self::pageActionsHtml( $page_actions ) );

        self::renderFactsHeader( $session );

        $blocks = ( new VctSessionBlocksRepository() )->listForSession( (int) $session['id'] );
        self::renderBlocks( $blocks );

        if ( $session['status'] === 'draft'
            && AuthorizationService::canPlanForTeam( $user_id, (int) $session['team_id'], 'change' ) ) {
            self::renderPublishForm( $session );
        } else {
            self::renderStatusNotice( $session );
        }
    }

    private static function renderFactsHeader( array $session ): void {
        $chips = [
            [ __( 'Age',      'talenttrack' ), (string) $session['age_group'] ],
            [ __( 'MD',       'talenttrack' ), LookupTranslator::byTypeAndName( 'vct_md_context', (string) $session['md_context'] ) ],
            [ __( 'Duration', 'talenttrack' ), sprintf( '%d %s', (int) $session['total_duration_minutes'], __( 'min', 'talenttrack' ) ) ],
            [ __( 'Load',     'talenttrack' ), (string) (int) $session['total_load'] ],
            [ __( 'Status',   'talenttrack' ), LookupTranslator::byTypeAndName( 'vct_session_status', (string) $session['status'] ) ],
        ];
        if ( ! empty( $session['tactical_theme'] ) ) {
            $chips[] = [ __( 'Theme', 'talenttrack' ), LookupTranslator::byTypeAndName( 'vct_tactical_theme', (string) $session['tactical_theme'] ) ];
        }

        echo '<div class="tt-vct-chips" style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 16px;">';
        foreach ( $chips as [ $label, $value ] ) {
            echo '<span class="tt-pill" style="display:inline-block;padding:6px 12px;border-radius:999px;background:#0b3d2e;color:#fff;font-size:13px;">'
                . '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value )
                . '</span>';
        }
        echo '</div>';
    }

    /** @param list<array<string,mixed>> $blocks */
    private static function renderBlocks( array $blocks ): void {
        if ( ! $blocks ) {
            echo '<p class="tt-notice">' . esc_html__( 'No blocks composed for this VCT training.', 'talenttrack' ) . '</p>';
            return;
        }

        $exercises_repo = new VctExercisesRepository();
        $coaching_repo  = new VctCoachingPointsRepository();
        $locale         = get_user_locale();

        foreach ( $blocks as $b ) {
            $slot_label = LookupTranslator::byTypeAndName( 'vct_exercise_category', (string) $b['slot_category'] );
            $ex_name    = '—';
            $ex_id      = isset( $b['exercise_id'] ) ? (int) $b['exercise_id'] : 0;
            $cues       = [];
            if ( $ex_id > 0 ) {
                $ex = $exercises_repo->find( $ex_id );
                if ( $ex !== null ) {
                    $ex_name = (string) $ex['name_canonical'];
                    $cues    = $coaching_repo->listForExercise( $ex_id, $locale );
                }
            } elseif ( ! empty( $b['custom_label'] ) ) {
                $ex_name = (string) $b['custom_label'];
            }

            echo '<div class="tt-card tt-vct-block" style="margin:12px 0;padding:16px;border:1px solid #ddd;border-radius:8px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">';
            echo '<h3 style="margin:0;font-size:16px;">' . esc_html( sprintf( '%d. %s', (int) $b['sequence'], $slot_label ) ) . '</h3>';
            echo '<span class="tt-vct-meta" style="font-size:13px;color:#555;">'
                . esc_html( sprintf(
                    /* translators: 1: minutes, 2: intensity band */
                    __( '%1$d min · band %2$d', 'talenttrack' ),
                    (int) $b['duration_minutes'], (int) $b['intensity_band']
                ) )
                . '</span>';
            echo '</div>';
            echo '<p style="margin:0 0 8px;font-weight:600;">' . esc_html( $ex_name ) . '</p>';

            if ( $cues ) {
                echo '<ul style="margin:0;padding-left:20px;font-size:14px;color:#333;">';
                foreach ( $cues as $cue ) {
                    echo '<li>' . esc_html( (string) $cue['text'] ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }
    }

    private static function renderPublishForm( array $session ): void {
        echo '<form method="POST" action="" style="margin-top:24px;padding:16px;background:#f5f5f5;border-radius:8px;">';
        wp_nonce_field( 'tt_vct_publish_' . (int) $session['id'], '_tt_vct_publish_nonce' );
        echo '<p style="margin:0 0 12px;">'
            . esc_html__( 'Publish this draft to bind it to a team Activity (creates a new Activity, or binds to an existing one at the same slot if you confirm).', 'talenttrack' )
            . '</p>';
        echo '<input type="hidden" name="bind_existing" value="0">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Publish VCT training', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    private static function renderStatusNotice( array $session ): void {
        $status = (string) $session['status'];
        if ( $status === 'published' ) {
            echo '<p class="tt-notice tt-notice--info" style="margin-top:16px;">'
                . esc_html__( 'This VCT training is published and bound to a team Activity.', 'talenttrack' )
                . '</p>';
        }
    }

    /**
     * Handle the inline Publish form POST. Routes through the REST
     * controller's publish path by calling the underlying repo +
     * activity-creation logic directly (the REST handler is the
     * canonical client; this is the same workflow without the HTTP
     * round-trip).
     */
    private static function handlePublishPost( array $session, string $nonce ): void {
        if ( ! wp_verify_nonce( $nonce, 'tt_vct_publish_' . (int) $session['id'] ) ) {
            echo '<p class="tt-notice tt-notice--error">' . esc_html__( 'Publish action failed: session expired. Please reload the page and try again.', 'talenttrack' ) . '</p>';
            return;
        }

        $bind_existing = ! empty( $_POST['bind_existing'] ) && (string) $_POST['bind_existing'] === '1';
        $existing      = self::findActivityForSlot( $session );

        if ( $existing !== null && ! $bind_existing ) {
            echo '<form method="POST" action="" class="tt-notice tt-notice--info" style="padding:16px;background:#fff8e1;border-left:4px solid #dba617;margin:16px 0;">';
            wp_nonce_field( 'tt_vct_publish_' . (int) $session['id'], '_tt_vct_publish_nonce' );
            echo '<p>' . esc_html(
                sprintf(
                    /* translators: 1: existing activity id, 2: existing activity title */
                    __( 'An Activity already exists at this slot (#%1$d: %2$s). Bind this VCT training to it?', 'talenttrack' ),
                    (int) ( $existing['id'] ?? 0 ),
                    (string) ( $existing['title'] ?? '' )
                )
            ) . '</p>';
            echo '<input type="hidden" name="bind_existing" value="1">';
            echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Bind to existing Activity', 'talenttrack' ) . '</button>';
            echo '</form>';
            return;
        }

        $activity_id = $existing !== null ? (int) $existing['id'] : self::createActivityForSession( $session );
        if ( $activity_id <= 0 ) {
            echo '<p class="tt-notice tt-notice--error">' . esc_html__( 'Could not create or bind the Activity. Try again.', 'talenttrack' ) . '</p>';
            return;
        }

        ( new VctSessionsRepository() )->updateStatus( (int) $session['id'], 'published', $activity_id );
        echo '<p class="tt-notice tt-notice--success" style="padding:12px;background:#e9f5e9;border-left:4px solid #2c8a2c;margin:16px 0;">'
            . esc_html__( 'Published. Bound Activity created.', 'talenttrack' )
            . '</p>';
    }

    /**
     * Mirror of VctTrainingsRestController::findActivityForSlot — looks
     * for a same-slot Activity to bind to or conflict with.
     *
     * @return array<string,mixed>|null
     */
    private static function findActivityForSlot( array $session ): ?array {
        global $wpdb;
        $activities = $wpdb->prefix . 'tt_activities';
        $sql = "SELECT id, title, session_date, start_time, activity_type FROM {$activities}
                 WHERE club_id = %d AND team_id = %d AND session_date = %s";
        $params = [ CurrentClub::id(), (int) $session['team_id'], (string) $session['session_date'] ];
        if ( ! empty( $session['start_time'] ) ) {
            $sql .= ' AND start_time = %s';
            $params[] = (string) $session['start_time'];
        } else {
            $sql .= ' AND start_time IS NULL';
        }
        $sql .= " AND activity_type LIKE %s LIMIT 1";
        $params[] = '%training%';

        $row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return $row !== null ? (array) $row : null;
    }

    private static function createActivityForSession( array $session ): int {
        global $wpdb;
        $activities = $wpdb->prefix . 'tt_activities';
        $ok = $wpdb->insert( $activities, [
            'club_id'       => CurrentClub::id(),
            'team_id'       => (int) $session['team_id'],
            'session_date'  => (string) $session['session_date'],
            'start_time'    => $session['start_time'] ?? null,
            'activity_type' => 'training',
            'title'         => sprintf(
                /* translators: 1: age group, 2: md context label */
                __( 'VCT training — %1$s (%2$s)', 'talenttrack' ),
                (string) $session['age_group'],
                (string) $session['md_context']
            ),
        ] );
        return $ok !== false ? (int) $wpdb->insert_id : 0;
    }
}
