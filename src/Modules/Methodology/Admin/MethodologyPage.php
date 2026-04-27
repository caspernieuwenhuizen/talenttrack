<?php
namespace TT\Modules\Methodology\Admin;

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
use TT\Modules\Methodology\Repositories\PrincipleLinksRepository;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;
use TT\Modules\Methodology\Repositories\SetPiecesRepository;

/**
 * MethodologyPage — `TalentTrack → Methodology` admin browser.
 *
 * Four tabs:
 *
 *   - Spelprincipes (default): filterable list of game principles.
 *     Filters: team-function, team-task, source, formation, search.
 *     Each row → detail view with code, title, explanation, team
 *     guidance, per-line guidance, formation diagram, usage count.
 *
 *   - Formaties & Posities: list of formations, click-into reveals
 *     all 11 position cards laid out on the diagram. Click a
 *     position → detail panel with attacking + defending tasks.
 *
 *   - Spelhervattingen: grouped by kind. Each entry shows the bullet
 *     list and the diagrammed positions.
 *
 *   - Visie: single record per club. Edit form for the active club's
 *     style + formation + traits.
 *
 * The page is read+browse only — write actions go through the
 * dedicated edit pages (PrincipleEditPage, PositionEditPage, etc.)
 * that this page links to.
 */
class MethodologyPage {

    public const SLUG = 'tt-methodology';
    public const CAP_VIEW = 'tt_view_methodology';
    public const CAP_EDIT = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_clone',   [ self::class, 'handleClone' ] );
        add_action( 'admin_post_tt_methodology_archive', [ self::class, 'handleArchive' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP_VIEW ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'framework';
        $tabs = [
            'framework'  => __( 'Raamwerk',           'talenttrack' ),
            'principles' => __( 'Spelprincipes',      'talenttrack' ),
            'formations' => __( 'Formaties & Posities', 'talenttrack' ),
            'set_pieces' => __( 'Spelhervattingen',   'talenttrack' ),
            'vision'     => __( 'Visie',              'talenttrack' ),
        ];

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Methodology', 'talenttrack' ); ?>
                <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PrincipleEditPage::SLUG . '&action=new' ) ); ?>" class="page-title-action">
                        <?php esc_html_e( 'Add principle', 'talenttrack' ); ?>
                    </a>
                <?php endif; ?>
            </h1>

            <?php self::renderNotices(); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div style="margin-top:20px;">
                <?php
                switch ( $tab ) {
                    case 'principles':
                        $principle_id = isset( $_GET['principle_id'] ) ? absint( $_GET['principle_id'] ) : 0;
                        if ( $principle_id > 0 ) {
                            self::renderPrincipleDetail( $principle_id );
                        } else {
                            self::renderPrinciplesTab();
                        }
                        break;
                    case 'formations': self::renderFormationsTab(); break;
                    case 'set_pieces': self::renderSetPiecesTab();  break;
                    case 'vision':     self::renderVisionTab();     break;
                    case 'framework':
                    default:
                        self::renderFrameworkTab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ═══════════════ Tabs ═══════════════ */

    private static function renderPrinciplesTab(): void {
        $repo = new PrinciplesRepository();
        $filters = [
            'team_function' => isset( $_GET['team_function'] ) ? sanitize_key( (string) $_GET['team_function'] ) : '',
            'team_task'     => isset( $_GET['team_task'] )     ? sanitize_key( (string) $_GET['team_task'] )     : '',
            'source'        => isset( $_GET['source'] )        ? sanitize_key( (string) $_GET['source'] )        : 'both',
            'formation_id'  => isset( $_GET['formation_id'] )  ? absint( $_GET['formation_id'] )                  : 0,
            'search'        => isset( $_GET['s'] )             ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '',
        ];
        $principles = $repo->listFiltered( $filters );
        ?>
        <form method="get" style="margin:12px 0; display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
            <input type="hidden" name="tab"  value="principles" />
            <label>
                <span style="display:block; font-size:11px; text-transform:uppercase; color:#5b6470;"><?php esc_html_e( 'Team-function', 'talenttrack' ); ?></span>
                <select name="team_function">
                    <option value=""><?php esc_html_e( 'All', 'talenttrack' ); ?></option>
                    <?php foreach ( MethodologyEnums::teamFunctions() as $k => $label ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['team_function'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span style="display:block; font-size:11px; text-transform:uppercase; color:#5b6470;"><?php esc_html_e( 'Team-task', 'talenttrack' ); ?></span>
                <select name="team_task">
                    <option value=""><?php esc_html_e( 'All', 'talenttrack' ); ?></option>
                    <?php foreach ( MethodologyEnums::teamTasks() as $k => $label ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['team_task'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span style="display:block; font-size:11px; text-transform:uppercase; color:#5b6470;"><?php esc_html_e( 'Source', 'talenttrack' ); ?></span>
                <select name="source">
                    <option value="both"    <?php selected( $filters['source'], 'both' ); ?>><?php esc_html_e( 'Shipped + club', 'talenttrack' ); ?></option>
                    <option value="shipped" <?php selected( $filters['source'], 'shipped' ); ?>><?php esc_html_e( 'Shipped only',  'talenttrack' ); ?></option>
                    <option value="club"    <?php selected( $filters['source'], 'club' ); ?>><?php esc_html_e( 'Club-authored only', 'talenttrack' ); ?></option>
                </select>
            </label>
            <label>
                <span style="display:block; font-size:11px; text-transform:uppercase; color:#5b6470;"><?php esc_html_e( 'Search', 'talenttrack' ); ?></span>
                <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Code or title', 'talenttrack' ); ?>" />
            </label>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
        </form>

        <?php if ( empty( $principles ) ) : ?>
            <p><em><?php esc_html_e( 'No principles match these filters.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Code', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Function', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Task', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $principles as $p ) :
                    $detail_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=principles&principle_id=' . (int) $p->id );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $p->code ); ?></code></td>
                        <td><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( MultilingualField::string( $p->title_json ) ?: '—' ); ?></a></td>
                        <td><?php echo esc_html( MethodologyEnums::teamFunctions()[ (string) $p->team_function_key ] ?? $p->team_function_key ); ?></td>
                        <td><?php echo esc_html( MethodologyEnums::teamTasks()[ (string) $p->team_task_key ] ?? $p->team_task_key ); ?></td>
                        <td><?php echo $p->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'View', 'talenttrack' ); ?></a>
                            <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                                <?php if ( $p->is_shipped ) : ?>
                                    | <a href="<?php echo esc_url( self::cloneActionUrl( 'principle', (int) $p->id ) ); ?>"><?php esc_html_e( 'Clone & edit', 'talenttrack' ); ?></a>
                                <?php else : ?>
                                    | <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PrincipleEditPage::SLUG . '&action=edit&id=' . (int) $p->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                    | <a href="<?php echo esc_url( self::archiveActionUrl( 'principle', (int) $p->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Archive this principle?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Archive', 'talenttrack' ); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    private static function renderPrincipleDetail( int $principle_id ): void {
        $repo  = new PrinciplesRepository();
        $links = new PrincipleLinksRepository();
        $p = $repo->find( $principle_id );
        if ( ! $p ) {
            echo '<p>' . esc_html__( 'Principle not found.', 'talenttrack' ) . '</p>';
            return;
        }
        $title       = MultilingualField::string( $p->title_json );
        $explanation = MultilingualField::string( $p->explanation_json );
        $team_g      = MultilingualField::string( $p->team_guidance_json );
        $line_map    = MultilingualField::lineMap( $p->line_guidance_json );
        $usage       = $links->usageCountsForPrinciple( $principle_id );
        ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=principles' ) ); ?>">
                ← <?php esc_html_e( 'Back to principles', 'talenttrack' ); ?>
            </a>
        </p>
        <h2 style="display:flex; align-items:center; gap:10px;">
            <code><?php echo esc_html( (string) $p->code ); ?></code>
            <span><?php echo esc_html( $title ?: __( '(untitled)', 'talenttrack' ) ); ?></span>
            <span style="font-size:12px; padding:2px 8px; border-radius:3px; background:<?php echo $p->is_shipped ? '#e0f2f1' : '#fff3e0'; ?>; color:#333;">
                <?php echo $p->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?>
            </span>
        </h2>
        <p style="color:#5b6470;">
            <?php echo esc_html( MethodologyEnums::teamFunctions()[ (string) $p->team_function_key ] ?? '' ); ?>
            ·
            <?php echo esc_html( MethodologyEnums::teamTasks()[ (string) $p->team_task_key ] ?? '' ); ?>
        </p>

        <div style="display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:24px; align-items:start; margin-top:18px;">
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
                        if ( empty( $line_map[ $key ] ) ) continue;
                        ?>
                        <h4 style="margin:10px 0 4px;"><?php echo esc_html( $label ); ?></h4>
                        <p style="white-space:pre-wrap;"><?php echo esc_html( (string) $line_map[ $key ] ); ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>

                <h3 style="margin-top:24px;"><?php esc_html_e( 'Usage', 'talenttrack' ); ?></h3>
                <ul style="margin:0;">
                    <li><?php
                        printf(
                            /* translators: %d is a count */
                            esc_html__( 'Sessions referencing: %d', 'talenttrack' ),
                            (int) ( $usage['activity'] ?? 0 )
                        );
                    ?></li>
                    <li><?php
                        printf(
                            /* translators: %d is a count */
                            esc_html__( 'Goals linked: %d', 'talenttrack' ),
                            (int) ( $usage['goal'] ?? 0 )
                        );
                    ?></li>
                    <li style="color:#5b6470;"><?php esc_html_e( 'Team plans (when #0006 ships): 0', 'talenttrack' ); ?></li>
                </ul>

                <p style="margin-top:24px;">
                    <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                        <?php if ( $p->is_shipped ) : ?>
                            <a class="button" href="<?php echo esc_url( self::cloneActionUrl( 'principle', $principle_id ) ); ?>">
                                <?php esc_html_e( 'Clone & edit', 'talenttrack' ); ?>
                            </a>
                        <?php else : ?>
                            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PrincipleEditPage::SLUG . '&action=edit&id=' . $principle_id ) ); ?>">
                                <?php esc_html_e( 'Edit', 'talenttrack' ); ?>
                            </a>
                            <a class="button" href="<?php echo esc_url( self::archiveActionUrl( 'principle', $principle_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Archive this principle?', 'talenttrack' ) ); ?>')">
                                <?php esc_html_e( 'Archive', 'talenttrack' ); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>

            <div>
                <?php if ( ! empty( $p->default_formation_id ) ) :
                    echo FormationDiagram::render( (int) $p->default_formation_id, [
                        'overlay_data' => $p->diagram_overlay_json,
                    ] );
                else : ?>
                    <p style="color:#5b6470; font-style:italic;"><?php esc_html_e( 'No formation diagram authored for this principle.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderFormationsTab(): void {
        $repo = new FormationsRepository();
        $formations = $repo->listAll();

        $current_id = isset( $_GET['formation_id'] ) ? absint( $_GET['formation_id'] ) : 0;
        if ( $current_id === 0 && ! empty( $formations ) ) $current_id = (int) $formations[0]->id;

        $current = $current_id > 0 ? $repo->findWithPositions( $current_id ) : null;
        ?>
        <p style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php foreach ( $formations as $f ) :
                $name = MultilingualField::string( $f->name_json ) ?: (string) $f->slug;
                $url  = admin_url( 'admin.php?page=' . self::SLUG . '&tab=formations&formation_id=' . (int) $f->id );
                $cur  = ( (int) $f->id === $current_id );
                ?>
                <a class="button <?php echo $cur ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
                    <?php echo esc_html( $name ); ?>
                </a>
            <?php endforeach; ?>
        </p>

        <?php if ( ! $current ) : ?>
            <p><em><?php esc_html_e( 'No formations available yet.', 'talenttrack' ); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <?php
        $formation = $current['formation'];
        $positions = $current['positions'];
        $highlight = isset( $_GET['position_id'] ) ? absint( $_GET['position_id'] ) : 0;
        $highlight_jersey = 0;
        if ( $highlight > 0 ) {
            foreach ( $positions as $pos ) {
                if ( (int) $pos->id === $highlight ) { $highlight_jersey = (int) $pos->jersey_number; break; }
            }
        }
        ?>
        <div style="display:grid; grid-template-columns:340px minmax(0,1fr); gap:24px; align-items:start; margin-top:18px;">
            <div>
                <?php echo FormationDiagram::render( (int) $formation->id, [ 'highlight_position' => $highlight_jersey ] ); ?>
            </div>
            <div>
                <h3><?php echo esc_html( MultilingualField::string( $formation->name_json ) ?: (string) $formation->slug ); ?></h3>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( '#', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $positions as $pos ) :
                        $detail_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=formations&formation_id=' . (int) $formation->id . '&position_id=' . (int) $pos->id );
                        ?>
                        <tr<?php echo $highlight === (int) $pos->id ? ' style="background:#f6f7f9;"' : ''; ?>>
                            <td><strong><?php echo (int) $pos->jersey_number; ?></strong></td>
                            <td><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( MultilingualField::string( $pos->long_name_json ) ?: MultilingualField::string( $pos->short_name_json ) ?: '—' ); ?></a></td>
                            <td><?php echo $pos->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                            <td>
                                <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                                    <?php if ( $pos->is_shipped ) : ?>
                                        <a href="<?php echo esc_url( self::cloneActionUrl( 'position', (int) $pos->id ) ); ?>"><?php esc_html_e( 'Clone & edit', 'talenttrack' ); ?></a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PositionEditPage::SLUG . '&action=edit&id=' . (int) $pos->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ( $highlight === (int) $pos->id ) :
                            $att = MultilingualField::stringList( $pos->attacking_tasks_json );
                            $def = MultilingualField::stringList( $pos->defending_tasks_json );
                            ?>
                            <tr><td colspan="4">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; padding:12px; background:#fafafa; border:1px solid #e0e0e0;">
                                    <div>
                                        <strong><?php esc_html_e( 'Aanvallend', 'talenttrack' ); ?></strong>
                                        <ul><?php foreach ( $att as $line ) echo '<li>' . esc_html( $line ) . '</li>'; ?></ul>
                                    </div>
                                    <div>
                                        <strong><?php esc_html_e( 'Verdedigend', 'talenttrack' ); ?></strong>
                                        <ul><?php foreach ( $def as $line ) echo '<li>' . esc_html( $line ) . '</li>'; ?></ul>
                                    </div>
                                </div>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private static function renderSetPiecesTab(): void {
        $repo = new SetPiecesRepository();
        $items = $repo->listFiltered();

        if ( empty( $items ) ) {
            echo '<p><em>' . esc_html__( 'No set pieces authored yet.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $by_kind = [];
        foreach ( $items as $sp ) {
            $by_kind[ (string) $sp->kind_key ][] = $sp;
        }
        ?>
        <?php foreach ( $by_kind as $kind => $rows ) :
            $kind_label = MethodologyEnums::setPieceKinds()[ $kind ] ?? $kind;
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( $kind_label ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Side', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $sp ) :
                    $bullets = MultilingualField::stringList( $sp->bullets_json );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( MultilingualField::string( $sp->title_json ) ?: $sp->slug ); ?></strong>
                            <?php if ( ! empty( $bullets ) ) : ?>
                                <ul style="margin:6px 0 0 18px;">
                                    <?php foreach ( $bullets as $b ) echo '<li>' . esc_html( $b ) . '</li>'; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( MethodologyEnums::sides()[ (string) $sp->side ] ?? $sp->side ); ?></td>
                        <td><?php echo $sp->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                        <td>
                            <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                                <?php if ( $sp->is_shipped ) : ?>
                                    <a href="<?php echo esc_url( self::cloneActionUrl( 'set_piece', (int) $sp->id ) ); ?>"><?php esc_html_e( 'Clone & edit', 'talenttrack' ); ?></a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SetPieceEditPage::SLUG . '&action=edit&id=' . (int) $sp->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                    | <a href="<?php echo esc_url( self::archiveActionUrl( 'set_piece', (int) $sp->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Archive this set piece?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Archive', 'talenttrack' ); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach;
    }

    private static function renderVisionTab(): void {
        $repo = new MethodologyVisionRepository();
        $vision = $repo->activeForClub();

        if ( ! $vision ) {
            echo '<p>' . esc_html__( 'No vision recorded yet.', 'talenttrack' ) . '</p>';
            if ( current_user_can( self::CAP_EDIT ) ) {
                echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . VisionEditPage::SLUG . '&action=new' ) ) . '">' . esc_html__( 'Define vision', 'talenttrack' ) . '</a></p>';
            }
            return;
        }

        $way     = MultilingualField::string( $vision->way_of_playing_json );
        $traits  = MultilingualField::stringList( $vision->important_traits_json );
        $notes   = MultilingualField::string( $vision->notes_json );
        $style   = (string) ( $vision->style_of_play_key ?? '' );
        ?>
        <div style="display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:24px; align-items:start;">
            <div>
                <h3><?php esc_html_e( 'Speelwijze', 'talenttrack' ); ?></h3>
                <p style="white-space:pre-wrap;"><?php echo esc_html( $way ?: __( '(not yet articulated)', 'talenttrack' ) ); ?></p>

                <?php if ( $style !== '' ) : ?>
                    <p>
                        <strong><?php esc_html_e( 'Style of play:', 'talenttrack' ); ?></strong>
                        <?php echo esc_html( MethodologyEnums::stylesOfPlay()[ $style ] ?? $style ); ?>
                    </p>
                <?php endif; ?>

                <?php if ( ! empty( $traits ) ) : ?>
                    <h3><?php esc_html_e( 'Belangrijke eigenschappen', 'talenttrack' ); ?></h3>
                    <ul>
                        <?php foreach ( $traits as $t ) echo '<li>' . esc_html( $t ) . '</li>'; ?>
                    </ul>
                <?php endif; ?>

                <?php if ( $notes !== '' ) : ?>
                    <h3><?php esc_html_e( 'Notes', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $notes ); ?></p>
                <?php endif; ?>

                <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                    <p style="margin-top:24px;">
                        <?php if ( $vision->is_shipped ) : ?>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . VisionEditPage::SLUG . '&action=new' ) ); ?>">
                                <?php esc_html_e( 'Author your own vision', 'talenttrack' ); ?>
                            </a>
                        <?php else : ?>
                            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . VisionEditPage::SLUG . '&action=edit&id=' . (int) $vision->id ) ); ?>">
                                <?php esc_html_e( 'Edit vision', 'talenttrack' ); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <div>
                <?php if ( ! empty( $vision->formation_id ) ) :
                    echo FormationDiagram::render( (int) $vision->formation_id );
                endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderFrameworkTab(): void {
        $repo   = new FrameworkPrimerRepository();
        $primer = $repo->activeForClub();

        if ( ! $primer ) {
            echo '<p>' . esc_html__( 'No framework primer recorded yet.', 'talenttrack' ) . '</p>';
            if ( current_user_can( self::CAP_EDIT ) ) {
                echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . FrameworkPrimerEditPage::SLUG . '&action=new' ) ) . '">' . esc_html__( 'Define framework', 'talenttrack' ) . '</a></p>';
            }
            return;
        }

        $assets   = ( new MethodologyAssetsRepository() )->listFor( MethodologyAssetsRepository::TYPE_FRAMEWORK, (int) $primer->id );
        $title    = MultilingualField::string( $primer->title_json );
        $tagline  = MultilingualField::string( $primer->tagline_json );
        $intro    = MultilingualField::string( $primer->intro_json );
        $sections = [
            'voetbalmodel_intro'        => __( 'Voetbalmodel',         'talenttrack' ),
            'voetbalhandelingen_intro'  => __( 'Voetbalhandelingen',   'talenttrack' ),
            'phases_intro'              => __( 'Vier fasen',           'talenttrack' ),
            'learning_goals_intro'      => __( 'Leerdoelen',           'talenttrack' ),
            'influence_factors_intro'   => __( 'Factoren van invloed', 'talenttrack' ),
            'reflection'                => __( 'Reflectie',            'talenttrack' ),
            'future'                    => __( 'De toekomst',          'talenttrack' ),
        ];
        ?>
        <div style="display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:24px; align-items:start;">
            <div>
                <h2 style="margin-top:0;"><?php echo esc_html( $title ?: __( '(untitled framework)', 'talenttrack' ) ); ?></h2>
                <?php if ( $tagline !== '' ) : ?><p style="font-size:14px; color:#5b6470;"><?php echo esc_html( $tagline ); ?></p><?php endif; ?>
                <?php if ( $intro !== '' ) : ?>
                    <h3><?php esc_html_e( 'Inleiding', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $intro ); ?></p>
                <?php endif; ?>
                <?php foreach ( $sections as $field => $label ) :
                    $val = MultilingualField::string( $primer->{$field . '_json'} ?? null );
                    if ( $val === '' ) continue; ?>
                    <h3><?php echo esc_html( $label ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( $val ); ?></p>
                <?php endforeach; ?>
                <p style="margin-top:24px;">
                    <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                        <?php if ( $primer->is_shipped ) : ?>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FrameworkPrimerEditPage::SLUG . '&action=new' ) ); ?>">
                                <?php esc_html_e( 'Author my own framework', 'talenttrack' ); ?>
                            </a>
                        <?php else : ?>
                            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FrameworkPrimerEditPage::SLUG . '&action=edit&id=' . (int) $primer->id ) ); ?>">
                                <?php esc_html_e( 'Edit framework', 'talenttrack' ); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <?php if ( ! empty( $assets ) ) :
                    foreach ( $assets as $asset ) :
                        $img = wp_get_attachment_image( (int) $asset->attachment_id, 'medium', false, [ 'style' => 'max-width:100%; height:auto; border:1px solid #e0e2e7; border-radius:6px; margin-bottom:8px;' ] );
                        $caption = MultilingualField::string( $asset->caption_json );
                        if ( $img ) echo $img;
                        if ( $caption !== '' ) echo '<p style="font-size:12px; color:#5b6470; margin:0 0 12px;">' . esc_html( $caption ) . '</p>';
                    endforeach;
                else : ?>
                    <p style="color:#5b6470; font-style:italic;"><?php esc_html_e( 'No framework illustrations attached yet.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php
        self::renderFrameworkPhases( (int) $primer->id );
        self::renderFrameworkLearningGoals( (int) $primer->id );
        self::renderFrameworkInfluenceFactors( (int) $primer->id );
    }

    private static function renderFrameworkPhases( int $primer_id ): void {
        $rows = ( new PhasesRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return;
        $by_side = [];
        foreach ( $rows as $r ) $by_side[ (string) $r->side ][] = $r;
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Vier fasen', 'talenttrack' ); ?></h2>
        <?php foreach ( MethodologyEnums::sides() as $key => $label ) :
            if ( empty( $by_side[ $key ] ) ) continue; ?>
            <h3 style="margin-top:14px;"><?php echo esc_html( $label ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th style="width:60px;">#</th>
                    <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Goal', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $by_side[ $key ] as $r ) : ?>
                    <tr>
                        <td><strong><?php echo (int) $r->phase_number; ?></strong></td>
                        <td><?php echo esc_html( MultilingualField::string( $r->title_json ) ?: '—' ); ?></td>
                        <td><?php echo esc_html( MultilingualField::string( $r->goal_json )  ?: '—' ); ?></td>
                        <td><?php echo $r->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                        <td>
                            <?php if ( current_user_can( self::CAP_EDIT ) && empty( $r->is_shipped ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PhaseEditPage::SLUG . '&action=edit&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach;
    }

    private static function renderFrameworkLearningGoals( int $primer_id ): void {
        $rows = ( new LearningGoalsRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return;
        $by_side = [];
        foreach ( $rows as $r ) $by_side[ (string) $r->side ][] = $r;
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Leerdoelen', 'talenttrack' ); ?></h2>
        <?php foreach ( MethodologyEnums::sides() as $key => $label ) :
            if ( empty( $by_side[ $key ] ) ) continue; ?>
            <h3 style="margin-top:14px;"><?php echo esc_html( $label ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Bullets', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $by_side[ $key ] as $r ) :
                    $bullets = MultilingualField::stringList( $r->bullets_json );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( MultilingualField::string( $r->title_json ) ?: $r->slug ); ?></strong></td>
                        <td>
                            <?php if ( ! empty( $bullets ) ) : ?>
                                <ul style="margin:0 0 0 18px;">
                                    <?php foreach ( $bullets as $b ) echo '<li>' . esc_html( $b ) . '</li>'; ?>
                                </ul>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td><?php echo $r->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                        <td>
                            <?php if ( current_user_can( self::CAP_EDIT ) && empty( $r->is_shipped ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LearningGoalEditPage::SLUG . '&action=edit&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach;
    }

    private static function renderFrameworkInfluenceFactors( int $primer_id ): void {
        $rows = ( new InfluenceFactorsRepository() )->listForPrimer( $primer_id );
        if ( empty( $rows ) ) return;
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Factoren van invloed', 'talenttrack' ); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Title', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Sub-cards', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $sub = ! empty( $r->sub_factors_json ) ? json_decode( $r->sub_factors_json, true ) : [];
                $count = is_array( $sub ) ? count( $sub ) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( MultilingualField::string( $r->title_json ) ?: $r->slug ); ?></strong></td>
                    <td><?php echo esc_html( MultilingualField::string( $r->description_json ) ?: '—' ); ?></td>
                    <td><?php echo (int) $count; ?></td>
                    <td><?php echo $r->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                    <td>
                        <?php if ( current_user_can( self::CAP_EDIT ) && empty( $r->is_shipped ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . InfluenceFactorEditPage::SLUG . '&action=edit&id=' . (int) $r->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ═══════════════ Action handlers ═══════════════ */

    public static function handleClone(): void {
        if ( ! current_user_can( self::CAP_EDIT ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_clone', 'tt_methodology_nonce' );
        $type = isset( $_GET['type'] ) ? sanitize_key( (string) $_GET['type'] ) : '';
        $id   = isset( $_GET['id'] )   ? absint( $_GET['id'] )                  : 0;

        $new_id = 0;
        $back   = admin_url( 'admin.php?page=' . self::SLUG );
        switch ( $type ) {
            case 'principle':
                $new_id = ( new PrinciplesRepository() )->cloneShipped( $id );
                $back   = admin_url( 'admin.php?page=' . PrincipleEditPage::SLUG . '&action=edit&id=' . $new_id );
                break;
            case 'position':
                $new_id = ( new FormationsRepository() )->clonePosition( $id );
                $back   = admin_url( 'admin.php?page=' . PositionEditPage::SLUG . '&action=edit&id=' . $new_id );
                break;
            case 'set_piece':
                $new_id = ( new SetPiecesRepository() )->cloneShipped( $id );
                $back   = admin_url( 'admin.php?page=' . SetPieceEditPage::SLUG . '&action=edit&id=' . $new_id );
                break;
        }

        if ( $new_id <= 0 ) {
            $back = add_query_arg( [ 'page' => self::SLUG, 'tt_msg' => 'clone_failed' ], admin_url( 'admin.php' ) );
        }
        wp_safe_redirect( $back );
        exit;
    }

    public static function handleArchive(): void {
        if ( ! current_user_can( self::CAP_EDIT ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_archive', 'tt_methodology_nonce' );
        $type = isset( $_GET['type'] ) ? sanitize_key( (string) $_GET['type'] ) : '';
        $id   = isset( $_GET['id'] )   ? absint( $_GET['id'] )                  : 0;

        switch ( $type ) {
            case 'principle': ( new PrinciplesRepository() )->archive( $id );    break;
            case 'set_piece': ( new SetPiecesRepository() )->archive( $id );     break;
        }
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'tt_msg' => 'archived' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function renderNotices(): void {
        if ( ! isset( $_GET['tt_msg'] ) ) return;
        $msg = sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) );
        $map = [
            'saved'         => __( 'Saved.',                     'talenttrack' ),
            'archived'      => __( 'Archived.',                  'talenttrack' ),
            'clone_failed'  => __( 'Clone failed.',              'talenttrack' ),
            'cloned'        => __( 'Cloned. Edit the new copy.', 'talenttrack' ),
        ];
        if ( ! isset( $map[ $msg ] ) ) return;
        $class = $msg === 'clone_failed' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
    }

    public static function cloneActionUrl( string $type, int $id ): string {
        return wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_methodology_clone', 'type' => $type, 'id' => $id ],
                admin_url( 'admin-post.php' )
            ),
            'tt_methodology_clone',
            'tt_methodology_nonce'
        );
    }

    public static function archiveActionUrl( string $type, int $id ): string {
        return wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_methodology_archive', 'type' => $type, 'id' => $id ],
                admin_url( 'admin-post.php' )
            ),
            'tt_methodology_archive',
            'tt_methodology_nonce'
        );
    }
}
