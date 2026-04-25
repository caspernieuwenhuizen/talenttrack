<?php
namespace TT\Modules\Methodology\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Components\FormationDiagram;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Modules\Methodology\Repositories\MethodologyVisionRepository;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;
use TT\Modules\Methodology\Repositories\SetPiecesRepository;
use TT\Shared\Frontend\FrontendBackButton;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * MethodologyView — read-only frontend tile for the methodology
 * library. Reachable via `?tt_view=methodology`.
 *
 * Tab parameter mirrors the wp-admin browser:
 *   - principles (default)
 *   - formations
 *   - set_pieces
 *   - vision
 *
 * Authoring lives in wp-admin in v1; the frontend is for everyday
 * referencing during session planning + coaching conversations.
 */
class MethodologyView extends FrontendViewBase {

    public static function render(): void {
        if ( ! current_user_can( 'tt_view_methodology' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view the methodology library.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Methodology', 'talenttrack' ) );

        $tab = isset( $_GET['mtab'] ) ? sanitize_key( (string) $_GET['mtab'] ) : 'principles';

        $current = remove_query_arg( [ 'mtab', 'pid' ] );
        $tab_url = function ( string $key ) use ( $current ) {
            return add_query_arg( 'mtab', $key, $current );
        };

        $tabs = [
            'principles' => __( 'Principles', 'talenttrack' ),
            'formations' => __( 'Formations & positions', 'talenttrack' ),
            'set_pieces' => __( 'Set pieces', 'talenttrack' ),
            'vision'     => __( 'Vision', 'talenttrack' ),
        ];
        ?>
        <nav class="tt-mlogy-tabs" style="display:flex; gap:6px; flex-wrap:wrap; margin:8px 0 18px;">
            <?php foreach ( $tabs as $k => $label ) :
                $cls = 'tt-btn tt-btn-secondary';
                if ( $tab === $k ) $cls = 'tt-btn tt-btn-primary';
                ?>
                <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $tab_url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php

        switch ( $tab ) {
            case 'formations': self::renderFormations(); break;
            case 'set_pieces': self::renderSetPieces();  break;
            case 'vision':     self::renderVision();     break;
            case 'principles':
            default:
                $pid = isset( $_GET['pid'] ) ? absint( $_GET['pid'] ) : 0;
                if ( $pid > 0 ) self::renderPrincipleDetail( $pid );
                else            self::renderPrinciples();
        }
    }

    private static function renderPrinciples(): void {
        $repo = new PrinciplesRepository();
        $principles = $repo->listFiltered();
        if ( empty( $principles ) ) {
            echo '<p><em>' . esc_html__( 'No principles available.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $base = remove_query_arg( [ 'pid' ] );
        $by_function = [];
        foreach ( $principles as $p ) $by_function[ (string) $p->team_function_key ][] = $p;

        $functions = MethodologyEnums::teamFunctions();
        $tasks     = MethodologyEnums::teamTasks();

        foreach ( $functions as $fkey => $flabel ) :
            if ( empty( $by_function[ $fkey ] ) ) continue;
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $flabel ); ?></h3>
            <ul style="list-style:none; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px;">
                <?php foreach ( $by_function[ $fkey ] as $p ) :
                    $url = add_query_arg( 'pid', (int) $p->id, $base );
                    ?>
                    <li>
                        <a href="<?php echo esc_url( $url ); ?>" style="display:block; padding:14px; border:1px solid #e5e7ea; border-radius:8px; background:#fff; text-decoration:none; color:#1a1d21;">
                            <strong><code><?php echo esc_html( (string) $p->code ); ?></code></strong>
                            <span style="margin-left:8px; font-size:11px; padding:1px 6px; border-radius:3px; background:#f0f0f0;"><?php echo esc_html( $tasks[ (string) $p->team_task_key ] ?? $p->team_task_key ); ?></span>
                            <div style="margin-top:6px; font-weight:600;"><?php echo esc_html( MultilingualField::string( $p->title_json ) ?: '—' ); ?></div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach;
    }

    private static function renderPrincipleDetail( int $principle_id ): void {
        $repo = new PrinciplesRepository();
        $p    = $repo->find( $principle_id );
        if ( ! $p ) {
            echo '<p>' . esc_html__( 'Principle not found.', 'talenttrack' ) . '</p>';
            return;
        }
        $title       = MultilingualField::string( $p->title_json );
        $explanation = MultilingualField::string( $p->explanation_json );
        $team_g      = MultilingualField::string( $p->team_guidance_json );
        $line_map    = MultilingualField::lineMap( $p->line_guidance_json );
        $functions   = MethodologyEnums::teamFunctions();
        $tasks       = MethodologyEnums::teamTasks();
        ?>
        <p><a href="<?php echo esc_url( remove_query_arg( 'pid' ) ); ?>">← <?php esc_html_e( 'Back to principles', 'talenttrack' ); ?></a></p>
        <h2 style="display:flex; align-items:center; gap:10px;">
            <code><?php echo esc_html( (string) $p->code ); ?></code>
            <span><?php echo esc_html( $title ?: __( '(untitled)', 'talenttrack' ) ); ?></span>
        </h2>
        <p style="color:#5b6470;">
            <?php echo esc_html( $functions[ (string) $p->team_function_key ] ?? '' ); ?>
            ·
            <?php echo esc_html( $tasks[ (string) $p->team_task_key ] ?? '' ); ?>
        </p>
        <div class="tt-mlogy-detail-grid" style="display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:24px; align-items:start;">
            <div>
                <?php if ( $explanation !== '' ) : ?>
                    <h3><?php esc_html_e( 'Toelichting', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $explanation ); ?></p>
                <?php endif; ?>
                <?php if ( $team_g !== '' ) : ?>
                    <h3><?php esc_html_e( 'Team', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $team_g ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $line_map ) ) :
                    $lines = MethodologyEnums::lines();
                    ?>
                    <h3><?php esc_html_e( 'Per linie', 'talenttrack' ); ?></h3>
                    <?php foreach ( $lines as $key => $label ) :
                        if ( empty( $line_map[ $key ] ) ) continue; ?>
                        <h4 style="margin:10px 0 4px;"><?php echo esc_html( $label ); ?></h4>
                        <p style="white-space:pre-wrap;"><?php echo esc_html( (string) $line_map[ $key ] ); ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <?php if ( ! empty( $p->default_formation_id ) )
                    echo FormationDiagram::render( (int) $p->default_formation_id, [ 'overlay_data' => $p->diagram_overlay_json ] ); ?>
            </div>
        </div>
        <?php
    }

    private static function renderFormations(): void {
        $repo = new FormationsRepository();
        $list = $repo->listAll();
        if ( empty( $list ) ) {
            echo '<p><em>' . esc_html__( 'No formations available.', 'talenttrack' ) . '</em></p>';
            return;
        }
        foreach ( $list as $f ) :
            $name = MultilingualField::string( $f->name_json ) ?: $f->slug;
            $positions = $repo->positionsFor( (int) $f->id );
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $name ); ?></h3>
            <div style="display:grid; grid-template-columns:340px minmax(0,1fr); gap:24px; align-items:start;">
                <div><?php echo FormationDiagram::render( (int) $f->id ); ?></div>
                <div>
                    <table class="tt-table">
                        <thead><tr><th>#</th><th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $positions as $pos ) : ?>
                            <tr>
                                <td><strong><?php echo (int) $pos->jersey_number; ?></strong></td>
                                <td>
                                    <?php
                                    $long  = MultilingualField::string( $pos->long_name_json );
                                    $short = MultilingualField::string( $pos->short_name_json );
                                    echo esc_html( $long ?: $short ?: '—' );
                                    ?>
                                    <?php
                                    $att = MultilingualField::stringList( $pos->attacking_tasks_json );
                                    $def = MultilingualField::stringList( $pos->defending_tasks_json );
                                    if ( ! empty( $att ) || ! empty( $def ) ) : ?>
                                        <details style="margin-top:6px;">
                                            <summary style="cursor:pointer; font-size:12px; color:#5b6470;"><?php esc_html_e( 'tasks', 'talenttrack' ); ?></summary>
                                            <?php if ( ! empty( $att ) ) : ?>
                                                <strong style="font-size:12px;"><?php esc_html_e( 'Aanvallend', 'talenttrack' ); ?></strong>
                                                <ul><?php foreach ( $att as $line ) echo '<li>' . esc_html( $line ) . '</li>'; ?></ul>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $def ) ) : ?>
                                                <strong style="font-size:12px;"><?php esc_html_e( 'Verdedigend', 'talenttrack' ); ?></strong>
                                                <ul><?php foreach ( $def as $line ) echo '<li>' . esc_html( $line ) . '</li>'; ?></ul>
                                            <?php endif; ?>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach;
    }

    private static function renderSetPieces(): void {
        $repo = new SetPiecesRepository();
        $items = $repo->listFiltered();
        if ( empty( $items ) ) {
            echo '<p><em>' . esc_html__( 'No set pieces available.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $by_kind = [];
        foreach ( $items as $sp ) $by_kind[ (string) $sp->kind_key ][] = $sp;
        foreach ( $by_kind as $kind => $rows ) :
            $kind_label = MethodologyEnums::setPieceKinds()[ $kind ] ?? $kind;
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $kind_label ); ?></h3>
            <ul style="list-style:none; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px;">
                <?php foreach ( $rows as $sp ) :
                    $bullets = MultilingualField::stringList( $sp->bullets_json );
                    $title   = MultilingualField::string( $sp->title_json ) ?: $sp->slug;
                    ?>
                    <li style="padding:14px; border:1px solid #e5e7ea; border-radius:8px; background:#fff;">
                        <div style="font-weight:600; margin-bottom:4px;">
                            <?php echo esc_html( $title ); ?>
                            <span style="font-size:11px; padding:1px 6px; border-radius:3px; background:#f0f0f0; margin-left:6px;"><?php echo esc_html( MethodologyEnums::sides()[ (string) $sp->side ] ?? $sp->side ); ?></span>
                        </div>
                        <?php if ( ! empty( $bullets ) ) : ?>
                            <ul style="margin:0; padding-left:18px;">
                                <?php foreach ( $bullets as $b ) echo '<li>' . esc_html( $b ) . '</li>'; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach;
    }

    private static function renderVision(): void {
        $vision = ( new MethodologyVisionRepository() )->activeForClub();
        if ( ! $vision ) {
            echo '<p><em>' . esc_html__( 'Vision not yet recorded.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $way    = MultilingualField::string( $vision->way_of_playing_json );
        $traits = MultilingualField::stringList( $vision->important_traits_json );
        $notes  = MultilingualField::string( $vision->notes_json );
        $style  = (string) ( $vision->style_of_play_key ?? '' );
        ?>
        <div style="display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:24px; align-items:start;">
            <div>
                <h3><?php esc_html_e( 'Speelwijze', 'talenttrack' ); ?></h3>
                <p style="white-space:pre-wrap;"><?php echo esc_html( $way ?: __( '(not yet articulated)', 'talenttrack' ) ); ?></p>
                <?php if ( $style !== '' ) : ?>
                    <p><strong><?php esc_html_e( 'Style of play:', 'talenttrack' ); ?></strong> <?php echo esc_html( MethodologyEnums::stylesOfPlay()[ $style ] ?? $style ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $traits ) ) : ?>
                    <h3><?php esc_html_e( 'Belangrijke eigenschappen', 'talenttrack' ); ?></h3>
                    <ul><?php foreach ( $traits as $t ) echo '<li>' . esc_html( $t ) . '</li>'; ?></ul>
                <?php endif; ?>
                <?php if ( $notes !== '' ) : ?>
                    <h3><?php esc_html_e( 'Notes', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $notes ); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <?php if ( ! empty( $vision->formation_id ) ) echo FormationDiagram::render( (int) $vision->formation_id ); ?>
            </div>
        </div>
        <?php
    }
}
