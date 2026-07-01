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
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * MethodologyView — read-only frontend tile for the methodology
 * library. Reachable via `?tt_view=methodology`.
 *
 * Tabs (mtab query param), in display order:
 *   - vision (default)    — single vision record
 *   - framework           — the per-club methodology primer
 *   - formations          — formation positions
 *   - principles          — game principles browser + detail
 *   - actions             — football actions catalogue
 *   - set_pieces          — set pieces
 *
 * Each detail view renders an image hero (primary asset) above the
 * text, then per-line guidance, then the formation diagram (when
 * applicable). Asset rendering uses MethodologyAssetsRepository.
 */
class MethodologyView extends FrontendViewBase {

    public static function render(): void {
        // #2225 — the frontend authoring surface is co-located under the
        // same `methodology` slug. `?tt_view=methodology&mode=manage`
        // hands off to the manage view, which gates on tt_edit_methodology
        // and renders the extensible entity-tab surface.
        if ( isset( $_GET['mode'] ) && sanitize_key( (string) $_GET['mode'] ) === 'manage' ) {
            \TT\Modules\Methodology\Frontend\Manage\MethodologyManageView::render();
            return;
        }

        if ( ! current_user_can( 'tt_view_methodology' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view the methodology library.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueMethodologyAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Methodology', 'talenttrack' ) );
        // #1064 — printable methodology reference card. Opens a
        // standalone document in a new tab; defaults include all
        // three sections.
        $print_url = add_query_arg(
            [ 'tt_methodology_ref_print' => '1', 'sections' => 'principles,actions,leerdoelen' ],
            home_url( '/' )
        );
        $print_actions = [
            // #2225 — authoring entry for editors. Gated on the edit cap so
            // it renders only for roles that can author (pageActionsHtml
            // drops actions the user lacks the `cap` for).
            [
                'label'   => __( 'Manage methodology', 'talenttrack' ),
                'href'    => add_query_arg(
                    [ 'tt_view' => 'methodology', 'mode' => 'manage' ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                ),
                'variant' => 'secondary',
                'cap'     => 'tt_edit_methodology',
            ],
            [
                'label'  => __( 'Print referentiekaart', 'talenttrack' ),
                'href'   => $print_url,
                'target' => '_blank',
            ],
        ];
        self::renderHeader( __( 'Methodology', 'talenttrack' ), self::pageActionsHtml( $print_actions ) );
        self::renderInlineStyles();

        $tab = isset( $_GET['mtab'] ) ? sanitize_key( (string) $_GET['mtab'] ) : 'vision';

        $current = remove_query_arg( [ 'mtab', 'pid', 'sid', 'fid' ] );
        $tab_url = function ( string $key ) use ( $current ) {
            return add_query_arg( 'mtab', $key, $current );
        };

        $tabs = [
            'vision'     => __( 'Visie',             'talenttrack' ),
            'framework'  => __( 'Raamwerk',          'talenttrack' ),
            'formations' => __( 'Formaties',          'talenttrack' ),
            'principles' => __( 'Spelprincipes',     'talenttrack' ),
            'actions'    => __( 'Voetbalhandelingen', 'talenttrack' ),
            'set_pieces' => __( 'Spelhervattingen',  'talenttrack' ),
        ];
        ?>
        <nav class="tt-mlogy-tabs" aria-label="<?php esc_attr_e( 'Methodology sections', 'talenttrack' ); ?>">
            <?php foreach ( $tabs as $k => $label ) :
                $active   = $tab === $k;
                $is_first = $k === 'vision';
                $cls      = 'tt-mlogy-tab';
                if ( $active )   $cls .= ' is-active';
                if ( $is_first ) $cls .= ' tt-mlogy-tab--lead';
                ?>
                <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $tab_url( $k ) ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php

        switch ( $tab ) {
            case 'framework':
                self::renderFramework();
                break;
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
            case 'actions':
                self::renderFootballActions();
                break;
            case 'vision':
            default:
                self::renderVision();
        }
    }

    /**
     * Enqueue the methodology stylesheet (2026 chrome restyle, #1671)
     * and the accordion-persistence script. The stylesheet depends on
     * the shared app-chrome sheet so it inherits the brand tokens
     * (--tt-primary / --tt-secondary / --tt-muted / --tt-shadow-md).
     */
    private static function enqueueMethodologyAssets(): void {
        if ( ! function_exists( 'wp_enqueue_style' ) ) return;
        wp_enqueue_style(
            'tt-frontend-methodology',
            TT_PLUGIN_URL . 'assets/css/frontend-methodology.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-methodology-accordion',
            TT_PLUGIN_URL . 'assets/js/methodology-accordion.js',
            [],
            TT_VERSION,
            true
        );
    }

    /**
     * No-op kept for call-site stability — the methodology styling now
     * lives in `assets/css/frontend-methodology.css` (#1671). Retained
     * as a hook for any genuinely dynamic, per-render inline styling.
     */
    private static function renderInlineStyles(): void {
        // Styling moved to the enqueued stylesheet. Nothing dynamic to emit.
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
        // #1671 — each framework section is a native <details> accordion.
        // The first non-empty section opens by default; the rest are
        // collapsed. methodology-accordion.js restores the persisted
        // open/closed state per stable id (data-acc-id).
        $sections = [
            [ 'id' => 'voetbalmodel',       'label' => __( 'Voetbalmodel',          'talenttrack' ), 'body' => self::frameworkSectionBody( $primer, 'voetbalmodel_intro_json' ) ],
            [ 'id' => 'voetbalhandelingen', 'label' => __( 'Voetbalhandelingen',    'talenttrack' ), 'body' => self::frameworkSectionBody( $primer, 'voetbalhandelingen_intro_json' ) ],
            [ 'id' => 'phases',             'label' => __( 'Vier fasen',            'talenttrack' ), 'body' => self::phasesGridBody( (int) $primer->id, MultilingualField::string( $primer->phases_intro_json ) ) ],
            [ 'id' => 'learning_goals',     'label' => __( 'Leerdoelen',            'talenttrack' ), 'body' => self::learningGoalsGridBody( (int) $primer->id, MultilingualField::string( $primer->learning_goals_intro_json ) ) ],
            [ 'id' => 'influence_factors',  'label' => __( 'Factoren van invloed',  'talenttrack' ), 'body' => self::influenceFactorsBody( (int) $primer->id, MultilingualField::string( $primer->influence_factors_intro_json ) ) ],
            [ 'id' => 'reflection',         'label' => __( 'Reflectie',             'talenttrack' ), 'body' => self::frameworkSectionBody( $primer, 'reflection_json' ) ],
            [ 'id' => 'future',             'label' => __( 'De toekomst',           'talenttrack' ), 'body' => self::frameworkSectionBody( $primer, 'future_json' ) ],
        ];
        $sections = array_values( array_filter( $sections, static fn( $s ) => trim( (string) $s['body'] ) !== '' ) );

        echo '<div class="tt-mlogy-acc-list">';
        $index = 0;
        foreach ( $sections as $s ) {
            $index++;
            self::renderAccordion( (string) $s['id'], $index, (string) $s['label'], (string) $s['body'], $index === 1 );
        }
        echo '</div>';
    }

    /**
     * Render one framework section as a native <details> accordion card
     * (#1671). The summary carries a numbered index chip, the section
     * label, and a chevron that rotates on open. `data-acc-id` is a
     * stable key the persistence script reads from localStorage.
     */
    private static function renderAccordion( string $id, int $index, string $label, string $body, bool $open ): void {
        ?>
        <details class="tt-mlogy-acc" data-acc-id="<?php echo esc_attr( 'methodology-framework-' . $id ); ?>"<?php echo $open ? ' open' : ''; ?>>
            <summary class="tt-mlogy-acc__summary">
                <span class="tt-mlogy-acc__chip" aria-hidden="true"><?php echo esc_html( (string) $index ); ?></span>
                <span class="tt-mlogy-acc__title"><?php echo esc_html( $label ); ?></span>
                <span class="tt-mlogy-acc__chevron" aria-hidden="true"></span>
            </summary>
            <div class="tt-mlogy-acc__body">
                <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — body is built by escaping *Body() helpers. ?>
            </div>
        </details>
        <?php
    }

    /** Inner HTML for a plain text framework section (returns '' when empty). */
    private static function frameworkSectionBody( object $primer, string $field ): string {
        $val = MultilingualField::string( $primer->{$field} ?? null );
        if ( $val === '' ) return '';
        return '<p class="tt-mlogy-prose">' . esc_html( $val ) . '</p>';
    }

    private static function phasesGridBody( int $primer_id, string $intro ): string {
        $rows = ( new PhasesRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return '';
        $by_side = [];
        foreach ( $rows as $r ) $by_side[ (string) $r->side ][] = $r;
        ob_start();
        ?>
            <?php if ( $intro !== '' ) : ?><p class="tt-mlogy-prose tt-mlogy-intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
            <?php foreach ( MethodologyEnums::sides() as $key => $label ) :
                if ( empty( $by_side[ $key ] ) ) continue; ?>
                <h3 class="tt-mlogy-subhead"><?php echo esc_html( $label ); ?></h3>
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
        <?php
        return (string) ob_get_clean();
    }

    private static function learningGoalsGridBody( int $primer_id, string $intro ): string {
        $rows = ( new LearningGoalsRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return '';
        $by_side = [];
        foreach ( $rows as $r ) $by_side[ (string) $r->side ][] = $r;
        ob_start();
        ?>
            <?php if ( $intro !== '' ) : ?><p class="tt-mlogy-prose tt-mlogy-intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
            <?php foreach ( MethodologyEnums::sides() as $key => $label ) :
                if ( empty( $by_side[ $key ] ) ) continue; ?>
                <h3 class="tt-mlogy-subhead"><?php echo esc_html( $label ); ?></h3>
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
        <?php
        return (string) ob_get_clean();
    }

    private static function influenceFactorsBody( int $primer_id, string $intro ): string {
        $rows = ( new InfluenceFactorsRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return '';
        ob_start();
        ?>
            <?php if ( $intro !== '' ) : ?><p class="tt-mlogy-prose tt-mlogy-intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
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
        <?php
        return (string) ob_get_clean();
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
        // #919 — bare "← Back to principles" link removed per CLAUDE.md §5
        // (no third back affordance allowed). The breadcrumb chain
        // (Dashboard › Methodology) + the tt_back pill (when arriving
        // from the list) cover navigation. Click the "Principles" tab to
        // return to the list.
        ?>
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
        // #919 — bare "← Back to set pieces" link removed per CLAUDE.md §5
        // (no third back affordance allowed). The breadcrumb chain
        // (Dashboard › Methodology) + the tt_back pill (when arriving
        // from the list) cover navigation. Click the "Spelhervattingen"
        // tab to return to the list.
        ?>
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
