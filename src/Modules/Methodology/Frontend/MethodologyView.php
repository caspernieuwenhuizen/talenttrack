<?php
namespace TT\Modules\Methodology\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Components\FormationDiagram;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FootballActionsRepository;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\InfluenceFactorsRepository;
use TT\Modules\Methodology\Repositories\LearningGoalsRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;
use TT\Modules\Methodology\Repositories\MethodologyVisionRepository;
use TT\Modules\Methodology\Repositories\PhasesRepository;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;
use TT\Modules\Methodology\Repositories\SetPiecesRepository;
use TT\Shared\Frontend\FrontendBackButton;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * MethodologyView — read-only frontend tile for the methodology
 * library. Reachable via `?tt_view=methodology`.
 *
 * Tabs (mtab query param):
 *   - framework (default) — the per-club methodology primer
 *   - principles          — game principles browser + detail
 *   - formations          — formation positions
 *   - set_pieces          — set pieces
 *   - vision              — single vision record
 *   - actions             — football actions catalogue
 *
 * Each detail view renders an image hero (primary asset) above the
 * text, then per-line guidance, then the formation diagram (when
 * applicable). Asset rendering uses MethodologyAssetsRepository.
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
        self::renderInlineStyles();

        $tab = isset( $_GET['mtab'] ) ? sanitize_key( (string) $_GET['mtab'] ) : 'framework';

        $current = remove_query_arg( [ 'mtab', 'pid', 'sid', 'fid' ] );
        $tab_url = function ( string $key ) use ( $current ) {
            return add_query_arg( 'mtab', $key, $current );
        };

        $tabs = [
            'framework'  => __( 'Raamwerk',          'talenttrack' ),
            'principles' => __( 'Spelprincipes',     'talenttrack' ),
            'formations' => __( 'Formaties',          'talenttrack' ),
            'set_pieces' => __( 'Spelhervattingen',  'talenttrack' ),
            'vision'     => __( 'Visie',             'talenttrack' ),
            'actions'    => __( 'Voetbalhandelingen', 'talenttrack' ),
        ];
        ?>
        <nav class="tt-mlogy-tabs">
            <?php foreach ( $tabs as $k => $label ) :
                $cls = $tab === $k ? 'tt-btn tt-btn-primary' : 'tt-btn tt-btn-secondary';
                ?>
                <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $tab_url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php

        switch ( $tab ) {
            case 'principles':
                $pid = isset( $_GET['pid'] ) ? absint( $_GET['pid'] ) : 0;
                if ( $pid > 0 ) self::renderPrincipleDetail( $pid );
                else            self::renderPrinciples();
                break;
            case 'formations':
                self::renderFormations();
                break;
            case 'set_pieces':
                $sid = isset( $_GET['sid'] ) ? absint( $_GET['sid'] ) : 0;
                if ( $sid > 0 ) self::renderSetPieceDetail( $sid );
                else            self::renderSetPieces();
                break;
            case 'vision':
                self::renderVision();
                break;
            case 'actions':
                self::renderFootballActions();
                break;
            case 'framework':
            default:
                self::renderFramework();
        }
    }

    private static function renderInlineStyles(): void {
        // Inline because methodology is the only consumer; bundling
        // into a dedicated stylesheet would carry over for routes
        // that don't render the methodology view.
        ?>
        <style>
            .tt-mlogy-tabs { display:flex; gap:6px; flex-wrap:wrap; margin:8px 0 18px; }
            .tt-mlogy-hero { display:block; max-width:100%; height:auto; border:1px solid #e0e2e7; border-radius:6px; margin-bottom:10px; background:#f6f7f9; }
            .tt-mlogy-card { padding:14px; border:1px solid #e5e7ea; border-radius:8px; background:#fff; }
            .tt-mlogy-card a { text-decoration:none; color:inherit; }
            .tt-mlogy-section { margin-top:32px; }
            .tt-mlogy-section h2 { margin-bottom:8px; }
            .tt-mlogy-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:10px; list-style:none; padding:0; }
            .tt-mlogy-detail-grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:24px; align-items:start; }
            @media (max-width: 900px) { .tt-mlogy-detail-grid { grid-template-columns: 1fr; } }
            .tt-mlogy-line { padding:10px 12px; background:#f6f7f9; border-left:3px solid #1a4a8a; margin:8px 0; border-radius:0 4px 4px 0; }
            .tt-mlogy-line h4 { margin:0 0 4px; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; color:#1a4a8a; }
            .tt-mlogy-line p { margin:0; white-space:pre-wrap; }
            .tt-mlogy-bullets { margin:6px 0 0 18px; padding:0; }
            .tt-mlogy-pill { font-size:11px; padding:1px 6px; border-radius:3px; background:#f0f0f0; }
            .tt-mlogy-phase-card { padding:12px; border:1px solid #e0e2e7; border-radius:6px; background:#fff; }
            .tt-mlogy-phase-card .num { display:inline-block; width:28px; height:28px; line-height:28px; text-align:center; border-radius:50%; color:#fff; font-weight:600; }
            .tt-mlogy-phase-card.attacking .num { background:#0a7c41; }
            .tt-mlogy-phase-card.defending .num { background:#b32d2e; }
            .tt-mlogy-factor { padding:14px; border:1px solid #e0e2e7; border-radius:6px; background:#fff; margin-bottom:10px; }
            .tt-mlogy-factor-subs { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:8px; margin-top:10px; }
            .tt-mlogy-factor-sub { padding:8px 10px; background:#f6f7f9; border-radius:4px; font-size:13px; }
            .tt-mlogy-factor-sub strong { display:block; margin-bottom:2px; color:#1a4a8a; }
        </style>
        <?php
    }

    // Framework primer

    private static function renderFramework(): void {
        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            echo '<p><em>' . esc_html__( 'No framework primer authored yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $title    = MultilingualField::string( $primer->title_json );
        $tagline  = MultilingualField::string( $primer->tagline_json );
        $intro    = MultilingualField::string( $primer->intro_json );
        $assets_repo = new MethodologyAssetsRepository();
        $primary  = $assets_repo->primaryFor( MethodologyAssetsRepository::TYPE_FRAMEWORK, (int) $primer->id );

        ?>
        <div class="tt-mlogy-detail-grid">
            <div>
                <h2 style="margin-top:0;"><?php echo esc_html( $title ?: __( '(untitled framework)', 'talenttrack' ) ); ?></h2>
                <?php if ( $tagline !== '' ) : ?><p style="font-size:15px; color:#5b6470;"><?php echo esc_html( $tagline ); ?></p><?php endif; ?>
                <?php if ( $intro !== '' ) : ?>
                    <h3><?php esc_html_e( 'Inleiding', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $intro ); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <?php if ( $primary ) self::renderAssetHero( (int) $primary->attachment_id, MultilingualField::string( $primary->caption_json ) ); ?>
            </div>
        </div>

        <?php
        self::renderFrameworkSection( $primer, 'voetbalmodel_intro_json',         __( 'Voetbalmodel',          'talenttrack' ) );
        self::renderFrameworkSection( $primer, 'voetbalhandelingen_intro_json',   __( 'Voetbalhandelingen',    'talenttrack' ) );
        self::renderPhasesGrid( (int) $primer->id, MultilingualField::string( $primer->phases_intro_json ) );
        self::renderLearningGoalsGrid( (int) $primer->id, MultilingualField::string( $primer->learning_goals_intro_json ) );
        self::renderInfluenceFactors( (int) $primer->id, MultilingualField::string( $primer->influence_factors_intro_json ) );
        self::renderFrameworkSection( $primer, 'reflection_json',                 __( 'Reflectie',             'talenttrack' ) );
        self::renderFrameworkSection( $primer, 'future_json',                     __( 'De toekomst',           'talenttrack' ) );
    }

    private static function renderFrameworkSection( object $primer, string $field, string $label ): void {
        $val = MultilingualField::string( $primer->{$field} ?? null );
        if ( $val === '' ) return;
        ?>
        <section class="tt-mlogy-section">
            <h2><?php echo esc_html( $label ); ?></h2>
            <p style="white-space:pre-wrap;"><?php echo esc_html( $val ); ?></p>
        </section>
        <?php
    }

    private static function renderPhasesGrid( int $primer_id, string $intro ): void {
        $rows = ( new PhasesRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return;
        $by_side = [];
        foreach ( $rows as $r ) $by_side[ (string) $r->side ][] = $r;
        ?>
        <section class="tt-mlogy-section">
            <h2><?php esc_html_e( 'Vier fasen', 'talenttrack' ); ?></h2>
            <?php if ( $intro !== '' ) : ?><p style="white-space:pre-wrap; color:#5b6470;"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
            <?php foreach ( MethodologyEnums::sides() as $key => $label ) :
                if ( empty( $by_side[ $key ] ) ) continue; ?>
                <h3 style="margin-top:18px;"><?php echo esc_html( $label ); ?></h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
                    <?php foreach ( $by_side[ $key ] as $r ) : ?>
                        <div class="tt-mlogy-phase-card <?php echo esc_attr( $key ); ?>">
                            <span class="num"><?php echo (int) $r->phase_number; ?></span>
                            <strong style="margin-left:6px;"><?php echo esc_html( MultilingualField::string( $r->title_json ) ?: '' ); ?></strong>
                            <p style="margin:8px 0 0; font-size:13px; white-space:pre-wrap;"><?php echo esc_html( MultilingualField::string( $r->goal_json ) ?: '' ); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    private static function renderLearningGoalsGrid( int $primer_id, string $intro ): void {
        $rows = ( new LearningGoalsRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return;
        $by_side = [];
        foreach ( $rows as $r ) $by_side[ (string) $r->side ][] = $r;
        ?>
        <section class="tt-mlogy-section">
            <h2><?php esc_html_e( 'Leerdoelen', 'talenttrack' ); ?></h2>
            <?php if ( $intro !== '' ) : ?><p style="white-space:pre-wrap; color:#5b6470;"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
            <?php foreach ( MethodologyEnums::sides() as $key => $label ) :
                if ( empty( $by_side[ $key ] ) ) continue; ?>
                <h3 style="margin-top:18px;"><?php echo esc_html( $label ); ?></h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px;">
                    <?php foreach ( $by_side[ $key ] as $r ) :
                        $bullets = MultilingualField::stringList( $r->bullets_json );
                        ?>
                        <div class="tt-mlogy-card">
                            <strong><?php echo esc_html( MultilingualField::string( $r->title_json ) ?: $r->slug ); ?></strong>
                            <?php if ( ! empty( $bullets ) ) : ?>
                                <ul class="tt-mlogy-bullets">
                                    <?php foreach ( $bullets as $b ) echo '<li>' . esc_html( $b ) . '</li>'; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    private static function renderInfluenceFactors( int $primer_id, string $intro ): void {
        $rows = ( new InfluenceFactorsRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return;
        ?>
        <section class="tt-mlogy-section">
            <h2><?php esc_html_e( 'Factoren van invloed', 'talenttrack' ); ?></h2>
            <?php if ( $intro !== '' ) : ?><p style="white-space:pre-wrap; color:#5b6470;"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
            <?php foreach ( $rows as $r ) :
                $title = MultilingualField::string( $r->title_json );
                $desc  = MultilingualField::string( $r->description_json );
                $sub   = ! empty( $r->sub_factors_json ) ? json_decode( $r->sub_factors_json, true ) : [];
                ?>
                <div class="tt-mlogy-factor">
                    <strong style="font-size:15px;"><?php echo esc_html( $title ?: $r->slug ); ?></strong>
                    <?php if ( $desc !== '' ) : ?><p style="margin:6px 0 0; white-space:pre-wrap;"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
                    <?php if ( is_array( $sub ) && ! empty( $sub ) ) : ?>
                        <div class="tt-mlogy-factor-subs">
                            <?php foreach ( $sub as $card ) :
                                $card_title = MultilingualField::string( wp_json_encode( $card['title'] ?? [] ) );
                                $card_desc  = MultilingualField::string( wp_json_encode( $card['description'] ?? [] ) );
                                if ( $card_title === '' && $card_desc === '' ) continue; ?>
                                <div class="tt-mlogy-factor-sub">
                                    <strong><?php echo esc_html( $card_title ); ?></strong>
                                    <span><?php echo esc_html( $card_desc ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    // Principles

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

        $functions    = MethodologyEnums::teamFunctions();
        $tasks        = MethodologyEnums::teamTasks();
        $assets_repo  = new MethodologyAssetsRepository();

        foreach ( $functions as $fkey => $flabel ) :
            if ( empty( $by_function[ $fkey ] ) ) continue;
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $flabel ); ?></h3>
            <ul class="tt-mlogy-grid">
                <?php foreach ( $by_function[ $fkey ] as $p ) :
                    $url     = add_query_arg( 'pid', (int) $p->id, $base );
                    $primary = $assets_repo->primaryFor( MethodologyAssetsRepository::TYPE_PRINCIPLE, (int) $p->id );
                    ?>
                    <li class="tt-mlogy-card">
                        <a href="<?php echo esc_url( $url ); ?>">
                            <?php if ( $primary ) self::renderAssetThumb( (int) $primary->attachment_id ); ?>
                            <strong><code><?php echo esc_html( (string) $p->code ); ?></code></strong>
                            <span class="tt-mlogy-pill" style="margin-left:8px;"><?php echo esc_html( $tasks[ (string) $p->team_task_key ] ?? $p->team_task_key ); ?></span>
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
        $assets_repo = new MethodologyAssetsRepository();
        $primary     = $assets_repo->primaryFor( MethodologyAssetsRepository::TYPE_PRINCIPLE, (int) $p->id );
        ?>
        <p><a href="<?php echo esc_url( remove_query_arg( 'pid' ) ); ?>">← <?php esc_html_e( 'Back to principles', 'talenttrack' ); ?></a></p>
        <h2 style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <code><?php echo esc_html( (string) $p->code ); ?></code>
            <span><?php echo esc_html( $title ?: __( '(untitled)', 'talenttrack' ) ); ?></span>
        </h2>
        <p style="color:#5b6470;">
            <?php echo esc_html( $functions[ (string) $p->team_function_key ] ?? '' ); ?>
            ·
            <?php echo esc_html( $tasks[ (string) $p->team_task_key ] ?? '' ); ?>
        </p>
        <div class="tt-mlogy-detail-grid">
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
                        <div class="tt-mlogy-line">
                            <h4><?php echo esc_html( $label ); ?></h4>
                            <p><?php echo esc_html( (string) $line_map[ $key ] ); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <?php if ( $primary ) self::renderAssetHero( (int) $primary->attachment_id, MultilingualField::string( $primary->caption_json ) ); ?>
                <?php if ( ! empty( $p->default_formation_id ) )
                    echo FormationDiagram::render( (int) $p->default_formation_id, [ 'overlay_data' => $p->diagram_overlay_json ] ); ?>
            </div>
        </div>
        <?php
    }

    // Formations / Positions

    private static function renderFormations(): void {
        $repo = new FormationsRepository();
        $list = $repo->listAll();
        if ( empty( $list ) ) {
            echo '<p><em>' . esc_html__( 'No formations available.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $assets_repo = new MethodologyAssetsRepository();
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
                        <?php foreach ( $positions as $pos ) :
                            $primary = $assets_repo->primaryFor( MethodologyAssetsRepository::TYPE_POSITION, (int) $pos->id );
                            ?>
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
                                    if ( ! empty( $att ) || ! empty( $def ) || $primary ) : ?>
                                        <details style="margin-top:6px;">
                                            <summary style="cursor:pointer; font-size:12px; color:#5b6470;"><?php esc_html_e( 'tasks', 'talenttrack' ); ?></summary>
                                            <?php if ( $primary ) self::renderAssetThumb( (int) $primary->attachment_id, 'medium' ); ?>
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

    // Set pieces

    private static function renderSetPieces(): void {
        $repo  = new SetPiecesRepository();
        $items = $repo->listFiltered();
        if ( empty( $items ) ) {
            echo '<p><em>' . esc_html__( 'No set pieces available.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $base = remove_query_arg( [ 'sid' ] );
        $assets_repo = new MethodologyAssetsRepository();
        $by_kind = [];
        foreach ( $items as $sp ) $by_kind[ (string) $sp->kind_key ][] = $sp;
        foreach ( $by_kind as $kind => $rows ) :
            $kind_label = MethodologyEnums::setPieceKinds()[ $kind ] ?? $kind;
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $kind_label ); ?></h3>
            <ul class="tt-mlogy-grid">
                <?php foreach ( $rows as $sp ) :
                    $title   = MultilingualField::string( $sp->title_json ) ?: $sp->slug;
                    $url     = add_query_arg( 'sid', (int) $sp->id, $base );
                    $primary = $assets_repo->primaryFor( MethodologyAssetsRepository::TYPE_SET_PIECE, (int) $sp->id );
                    ?>
                    <li class="tt-mlogy-card">
                        <a href="<?php echo esc_url( $url ); ?>">
                            <?php if ( $primary ) self::renderAssetThumb( (int) $primary->attachment_id ); ?>
                            <div style="font-weight:600;">
                                <?php echo esc_html( $title ); ?>
                                <span class="tt-mlogy-pill" style="margin-left:6px;"><?php echo esc_html( MethodologyEnums::sides()[ (string) $sp->side ] ?? $sp->side ); ?></span>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach;
    }

    private static function renderSetPieceDetail( int $sid ): void {
        $sp = ( new SetPiecesRepository() )->find( $sid );
        if ( ! $sp ) {
            echo '<p>' . esc_html__( 'Set piece not found.', 'talenttrack' ) . '</p>';
            return;
        }
        $title   = MultilingualField::string( $sp->title_json ) ?: $sp->slug;
        $bullets = MultilingualField::stringList( $sp->bullets_json );
        $primary = ( new MethodologyAssetsRepository() )->primaryFor( MethodologyAssetsRepository::TYPE_SET_PIECE, (int) $sp->id );
        ?>
        <p><a href="<?php echo esc_url( remove_query_arg( 'sid' ) ); ?>">← <?php esc_html_e( 'Back to set pieces', 'talenttrack' ); ?></a></p>
        <h2><?php echo esc_html( $title ); ?> <span class="tt-mlogy-pill"><?php echo esc_html( MethodologyEnums::sides()[ (string) $sp->side ] ?? $sp->side ); ?></span></h2>
        <div class="tt-mlogy-detail-grid">
            <div>
                <?php if ( ! empty( $bullets ) ) : ?>
                    <ul>
                        <?php foreach ( $bullets as $b ) echo '<li>' . esc_html( $b ) . '</li>'; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <?php if ( $primary ) self::renderAssetHero( (int) $primary->attachment_id, MultilingualField::string( $primary->caption_json ) ); ?>
                <?php if ( ! empty( $sp->default_formation_id ) )
                    echo FormationDiagram::render( (int) $sp->default_formation_id ); ?>
            </div>
        </div>
        <?php
    }

    // Vision

    private static function renderVision(): void {
        $vision = ( new MethodologyVisionRepository() )->activeForClub();
        if ( ! $vision ) {
            echo '<p><em>' . esc_html__( 'Vision not yet recorded.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $way     = MultilingualField::string( $vision->way_of_playing_json );
        $traits  = MultilingualField::stringList( $vision->important_traits_json );
        $notes   = MultilingualField::string( $vision->notes_json );
        $style   = (string) ( $vision->style_of_play_key ?? '' );
        $primary = ( new MethodologyAssetsRepository() )->primaryFor( MethodologyAssetsRepository::TYPE_VISION, (int) $vision->id );
        ?>
        <div class="tt-mlogy-detail-grid">
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
                <?php if ( $primary ) self::renderAssetHero( (int) $primary->attachment_id, MultilingualField::string( $primary->caption_json ) ); ?>
                <?php if ( ! empty( $vision->formation_id ) ) echo FormationDiagram::render( (int) $vision->formation_id ); ?>
            </div>
        </div>
        <?php
    }

    // Football actions

    private static function renderFootballActions(): void {
        $rows = ( new FootballActionsRepository() )->listAll();
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No football actions available.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $by_cat = [];
        foreach ( $rows as $r ) $by_cat[ (string) $r->category_key ][] = $r;
        foreach ( FootballActionsRepository::categories() as $cat_key => $cat_label ) :
            if ( empty( $by_cat[ $cat_key ] ) ) continue; ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $cat_label ); ?></h3>
            <ul class="tt-mlogy-grid">
                <?php foreach ( $by_cat[ $cat_key ] as $r ) : ?>
                    <li class="tt-mlogy-card">
                        <strong><?php echo esc_html( MultilingualField::string( $r->name_json ) ?: $r->slug ); ?></strong>
                        <?php $desc = MultilingualField::string( $r->description_json ); if ( $desc !== '' ) : ?>
                            <p style="margin:6px 0 0; font-size:13px; white-space:pre-wrap;"><?php echo esc_html( $desc ); ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach;
    }

    // Asset rendering helpers

    private static function renderAssetHero( int $attachment_id, string $caption = '' ): void {
        if ( $attachment_id <= 0 ) return;
        $img = wp_get_attachment_image( $attachment_id, 'large', false, [ 'class' => 'tt-mlogy-hero', 'alt' => $caption ] );
        if ( ! $img ) return;
        echo $img;
        if ( $caption !== '' ) echo '<p style="font-size:12px; color:#5b6470; margin:0 0 12px;">' . esc_html( $caption ) . '</p>';
    }

    private static function renderAssetThumb( int $attachment_id, string $size = 'medium' ): void {
        if ( $attachment_id <= 0 ) return;
        $img = wp_get_attachment_image( $attachment_id, $size, false, [
            'style' => 'display:block; max-width:100%; height:auto; border-radius:4px; margin-bottom:8px;',
            'alt'   => '',
        ] );
        if ( $img ) echo $img;
    }
}
