<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\LearningGoalsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * LearningGoalsManageTab (#2229) — the learning-goals authoring tab, built
 * on the #2225 scaffold. Mirrors SetPiecesManageTab: register() a render +
 * handle callable into MethodologyManageRegistry, then dispatch a list ⇄
 * flat create/edit form on the frame-supplied action.
 *
 * A learning goal is a child of the framework primer: a coachable focus
 * area within attacking or defending, optionally tied to a teamtaak,
 * carrying a multilingual title and a newline-separated multilingual
 * bullet checklist. No business logic lives here beyond composition — the
 * closed side / team-task taxonomies and persistence run through
 * MethodologyEnums + LearningGoalsRepository, the same domain layer
 * LearningGoalsRestController consumes (§4). Mirrors LearningGoalEditPage.
 */
final class LearningGoalsManageTab {

    public const MTAB = 'learning_goals';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Leerdoelen', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 40,
        ] );
    }

    // ── render ──────────────────────────────────────────────────────

    /** @param array{action:string,id:int,flash:string} $ctx */
    public static function render( array $ctx ): void {
        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            self::renderNoPrimer();
            return;
        }

        $action = (string) ( $ctx['action'] ?? 'list' );
        $id     = (int) ( $ctx['id'] ?? 0 );

        if ( $action === 'new' || ( $action === 'edit' && $id > 0 ) ) {
            self::renderForm( (int) $primer->id, $id );
            return;
        }
        self::renderList( (int) $primer->id );
    }

    private static function renderNoPrimer(): void {
        echo '<p class="tt-notice">'
            . esc_html__( 'Author the framework primer first — learning goals hang off it. Open the Raamwerk tab and save the primer.', 'talenttrack' )
            . '</p>';
        echo '<a class="tt-btn tt-btn-secondary" href="'
            . esc_url( MethodologyManageView::tabUrl( FrameworkPrimerManageTab::MTAB ) ) . '">'
            . esc_html__( 'Go to Raamwerk', 'talenttrack' ) . '</a>';
    }

    private static function renderList( int $primer_id ): void {
        echo '<div class="tt-mmg-toolbar">';
        echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
            . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
            . esc_html__( '+ New learning goal', 'talenttrack' ) . '</a>';
        echo '</div>';

        $goals = ( new LearningGoalsRepository() )->listForPrimer( $primer_id );
        if ( empty( $goals ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No learning goals yet. Use “+ New learning goal” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        $sides = MethodologyEnums::sides();
        $tasks = MethodologyEnums::teamTasks();

        echo '<ul class="tt-mmg-list">';
        foreach ( $goals as $goal ) {
            $shipped  = ! empty( $goal->is_shipped );
            $title    = MultilingualField::string( $goal->title_json );
            $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $goal->id ] ) );
            $task     = (string) ( $goal->team_task_key ?? '' );
            $meta     = $sides[ (string) $goal->side ] ?? '';
            if ( $task !== '' && isset( $tasks[ $task ] ) ) {
                $meta .= ' · ' . $tasks[ $task ];
            }

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>' . esc_html( (string) $goal->slug ) . '</code></span>';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                . esc_html( $title !== '' ? $title : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            echo '<span class="tt-mmg-row__meta">' . esc_html( $meta ) . '</span>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $shipped ) {
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this learning goal? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $goal->id ) . '" />';
                echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                    . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function renderForm( int $primer_id, int $id ): void {
        $repo = new LearningGoalsRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That learning goal could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped learning goals are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $v = self::formValues( $row );
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <input type="hidden" name="primer_id" value="<?php echo esc_attr( (string) $primer_id ); ?>" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-lg-slug"><?php esc_html_e( 'Slug', 'talenttrack' ); ?></label>
                <input type="text" id="tt-lg-slug" class="tt-input" name="slug" maxlength="64" required
                       value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="positiespel-verbeteren" />
            </div>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-lg-side"><?php esc_html_e( 'Side', 'talenttrack' ); ?></label>
                    <select id="tt-lg-side" class="tt-input" name="side" required>
                        <?php foreach ( MethodologyEnums::sides() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['side'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-lg-task"><?php esc_html_e( 'Linked team-task (optional)', 'talenttrack' ); ?></label>
                    <select id="tt-lg-task" class="tt-input" name="team_task_key">
                        <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                        <?php foreach ( MethodologyEnums::teamTasks() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['team_task_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php self::multilingualText( 'title', __( 'Title', 'talenttrack' ), $v['title_nl'], $v['title_en'] ); ?>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Bullets (one per line)', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-lg-bullets-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <textarea id="tt-lg-bullets-nl" class="tt-input" name="bullets_nl" rows="6"><?php echo esc_textarea( $v['bullets_nl'] ); ?></textarea>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-lg-bullets-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <textarea id="tt-lg-bullets-en" class="tt-input" name="bullets_en" rows="6"><?php echo esc_textarea( $v['bullets_en'] ); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-lg-sort"><?php esc_html_e( 'Sort order', 'talenttrack' ); ?></label>
                <input type="number" inputmode="numeric" id="tt-lg-sort" class="tt-input" name="sort_order" value="<?php echo esc_attr( (string) $v['sort_order'] ); ?>" />
            </div>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save learning goal', 'talenttrack' ) : __( 'Create learning goal', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    /** Two side-by-side NL/EN text inputs for a multilingual string field. */
    private static function multilingualText( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-lg-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-lg-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-lg-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-lg-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     * Bullets render newline-joined per language for the textareas.
     *
     * @return array{slug:string,side:string,team_task_key:string,sort_order:int,title_nl:string,title_en:string,bullets_nl:string,bullets_en:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'slug'          => (string) ( $row->slug ?? '' ),
            'side'          => (string) ( $row->side ?? MethodologyEnums::SIDE_ATTACKING ),
            'team_task_key' => (string) ( $row->team_task_key ?? '' ),
            'sort_order'    => (int) ( $row->sort_order ?? 0 ),
            'title_nl'      => '',
            'title_en'      => '',
            'bullets_nl'    => '',
            'bullets_en'    => '',
        ];
        if ( ! $row ) return $v;

        $title = MultilingualField::decode( $row->title_json ?? null ) ?: [];
        $v['title_nl'] = (string) ( $title['nl'] ?? '' );
        $v['title_en'] = (string) ( $title['en'] ?? '' );

        $bullets = MultilingualField::decode( $row->bullets_json ?? null ) ?: [];
        $v['bullets_nl'] = self::joinLines( $bullets['nl'] ?? null );
        $v['bullets_en'] = self::joinLines( $bullets['en'] ?? null );

        return $v;
    }

    /** @param mixed $list */
    private static function joinLines( $list ): string {
        if ( ! is_array( $list ) ) return '';
        $out = [];
        foreach ( $list as $item ) {
            if ( is_string( $item ) && $item !== '' ) $out[] = $item;
        }
        return implode( "\n", $out );
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
     * Mirrors LearningGoalEditPage::handleSave (§4 — same domain layer).
     *
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    public static function handle( array $post ): array {
        if ( ! current_user_can( MethodologyManageView::CAP ) ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }
        $op   = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
        $id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $repo = new LearningGoalsRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 || ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That learning goal could not be deleted. Shipped reference goals cannot be removed.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Learning goal deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $side = sanitize_key( (string) wp_unslash( $post['side'] ?? '' ) );
        if ( ! MethodologyEnums::isValidSide( $side ) ) {
            return [ 'flash' => __( 'Please choose a valid side.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $slug = sanitize_key( (string) wp_unslash( $post['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return [ 'flash' => __( 'A learning goal needs a slug.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $task = sanitize_key( (string) wp_unslash( $post['team_task_key'] ?? '' ) );
        if ( $task !== '' && ! MethodologyEnums::isValidTask( $task ) ) {
            return [ 'flash' => __( 'Please choose a valid team-task.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'slug'          => $slug,
            'side'          => $side,
            'team_task_key' => $task !== '' ? $task : null,
            'sort_order'    => (int) ( $post['sort_order'] ?? 0 ),
            'title_json'    => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['title_en'] ?? '' ) ) ),
            ] ),
            'bullets_json'  => MultilingualField::encode( [
                'nl' => self::splitLines( (string) wp_unslash( $post['bullets_nl'] ?? '' ) ),
                'en' => self::splitLines( (string) wp_unslash( $post['bullets_en'] ?? '' ) ),
            ] ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That learning goal could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Learning goal saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['primer_id']  = absint( $post['primer_id'] ?? 0 );
        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Learning goal created.', 'talenttrack' ) : __( 'Could not create the learning goal.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }

    /**
     * Split a newline-separated textarea into a clean list of bullet
     * strings. Mirrors LearningGoalEditPage::splitLines.
     *
     * @return string[]
     */
    private static function splitLines( string $raw ): array {
        $parts = preg_split( "/\r?\n/", $raw ) ?: [];
        $out = [];
        foreach ( $parts as $p ) {
            $clean = trim( sanitize_text_field( $p ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }
}
