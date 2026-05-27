<?php
namespace TT\Modules\Vct\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Vct\Repositories\VctCoachingPointsRepository;
use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Modules\Vct\Repositories\VctSessionBlocksRepository;
use TT\Modules\Vct\Repositories\VctSessionsRepository;

/**
 * FrontendVctSessionPrintView (#0095 VCT-10 / #948).
 *
 * A4 print sub-render of FrontendVctSessionView. No breadcrumbs, no
 * dashboard chrome — just the coach-clipboard sheet. Spec § UI
 * surfaces: "sub-renders of the session view emit no breadcrumbs
 * of their own per docs/back-navigation.md".
 *
 * Same cap + scope check as the main view.
 */
class FrontendVctSessionPrintView {

    public static function render( int $id, int $user_id, bool $is_admin ): void {
        $session = ( new VctSessionsRepository() )->find( $id );
        if ( $session === null ) {
            echo '<p>' . esc_html__( 'VCT training not found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_plan' )
            || ! AuthorizationService::canPlanForTeam( $user_id, (int) $session['team_id'], 'read' ) ) {
            echo '<p>' . esc_html__( 'Not authorised to view this VCT training.', 'talenttrack' ) . '</p>';
            return;
        }

        $blocks         = ( new VctSessionBlocksRepository() )->listForSession( $id );
        $exercises_repo = new VctExercisesRepository();
        $coaching_repo  = new VctCoachingPointsRepository();
        $locale         = get_user_locale();

        ?>
        <div class="tt-vct-print" style="font-family: system-ui, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; color: #111;">
            <header style="border-bottom: 2px solid #0b3d2e; padding-bottom: 12px; margin-bottom: 16px;">
                <h1 style="margin: 0; font-size: 22px;"><?php
                    echo esc_html( sprintf(
                        /* translators: 1: age group, 2: md context label, 3: localised date */
                        __( 'VCT training — %1$s · %2$s · %3$s', 'talenttrack' ),
                        (string) $session['age_group'],
                        LookupTranslator::byTypeAndName( 'vct_md_context', (string) $session['md_context'] ),
                        mysql2date( get_option( 'date_format' ), (string) $session['session_date'], true )
                    ) );
                ?></h1>
                <p style="margin: 4px 0 0; font-size: 13px; color: #555;">
                    <?php echo esc_html( sprintf(
                        /* translators: 1: total minutes, 2: total load */
                        __( '%1$d minutes · load %2$d', 'talenttrack' ),
                        (int) $session['total_duration_minutes'], (int) $session['total_load']
                    ) ); ?>
                    <?php if ( ! empty( $session['tactical_theme'] ) ) : ?>
                        · <?php echo esc_html( LookupTranslator::byTypeAndName( 'vct_tactical_theme', (string) $session['tactical_theme'] ) ); ?>
                    <?php endif; ?>
                </p>
            </header>

            <ol style="list-style: none; padding: 0; margin: 0;">
                <?php foreach ( $blocks as $b ) :
                    $slot_label = LookupTranslator::byTypeAndName( 'vct_exercise_category', (string) $b['slot_category'] );
                    $ex_name = '—';
                    $cues    = [];
                    $ex_id   = isset( $b['exercise_id'] ) ? (int) $b['exercise_id'] : 0;
                    if ( $ex_id > 0 ) {
                        $ex = $exercises_repo->find( $ex_id );
                        if ( $ex !== null ) {
                            $ex_name = (string) $ex['name_canonical'];
                            $cues    = $coaching_repo->listForExercise( $ex_id, $locale );
                        }
                    } elseif ( ! empty( $b['custom_label'] ) ) {
                        $ex_name = (string) $b['custom_label'];
                    }
                    ?>
                    <li style="page-break-inside: avoid; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px;">
                            <h2 style="margin: 0; font-size: 16px;">
                                <?php echo esc_html( sprintf( '%d. %s', (int) $b['sequence'], $slot_label ) ); ?>
                            </h2>
                            <span style="font-size: 12px; color: #555;">
                                <?php echo esc_html( sprintf(
                                    /* translators: 1: minutes, 2: intensity band */
                                    __( '%1$d min · band %2$d', 'talenttrack' ),
                                    (int) $b['duration_minutes'], (int) $b['intensity_band']
                                ) ); ?>
                            </span>
                        </div>
                        <p style="margin: 6px 0 4px; font-weight: 600;"><?php echo esc_html( $ex_name ); ?></p>
                        <?php if ( $cues ) : ?>
                            <ul style="margin: 0; padding-left: 18px; font-size: 13px;">
                                <?php foreach ( $cues as $cue ) : ?>
                                    <li><?php echo esc_html( (string) $cue['text'] ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>

            <footer style="margin-top: 16px; padding-top: 8px; border-top: 1px solid #ddd; font-size: 11px; color: #777;">
                <?php echo esc_html__( 'TalentTrack — VCT planner', 'talenttrack' ); ?>
            </footer>
        </div>
        <style media="print">
            body { background: #fff !important; }
            .tt-dashboard, .tt-back-pill, .tt-breadcrumb, header.tt-header, nav { display: none !important; }
            @page { size: A4 portrait; margin: 15mm; }
        </style>
        <?php
    }
}
