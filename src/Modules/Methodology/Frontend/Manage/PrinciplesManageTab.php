<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * PrinciplesManageTab (#2225) — the reference implementation of a
 * methodology manage tab. Every sibling entity tab copies this shape:
 *
 *   1. register() → MethodologyManageRegistry::register([...]) with a
 *      render callable + a POST handle callable.
 *   2. render()   → list ⇄ flat create/edit form, dispatched on the
 *      `action` in the frame-supplied context.
 *   3. handle()   → sanitize → MultilingualField::encode → repository
 *      create/update/delete (mirrors the wp-admin PrincipleEditPage save).
 *
 * No business logic lives here beyond composition — validation of the
 * closed taxonomies (team-function / team-task) and the persistence run
 * through MethodologyEnums + PrinciplesRepository, the same domain layer
 * PrinciplesRestController consumes (§4).
 */
final class PrinciplesManageTab {

    public const MTAB = 'principles';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Spelprincipes', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 40,
        ] );
    }

    // ── render ──────────────────────────────────────────────────────

    /** @param array{action:string,id:int,flash:string} $ctx */
    public static function render( array $ctx ): void {
        $action = (string) ( $ctx['action'] ?? 'list' );
        $id     = (int) ( $ctx['id'] ?? 0 );

        if ( $action === 'new' || ( $action === 'edit' && $id > 0 ) ) {
            self::renderForm( $id );
            return;
        }
        self::renderList();
    }

    private static function renderList(): void {
        echo '<div class="tt-mmg-toolbar">';
        echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
            . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
            . esc_html__( '+ New principle', 'talenttrack' ) . '</a>';
        echo '</div>';

        $principles = ( new PrinciplesRepository() )->listFiltered();
        if ( empty( $principles ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No principles yet. Use “+ New principle” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        $functions = MethodologyEnums::teamFunctions();
        $tasks     = MethodologyEnums::teamTasks();

        echo '<ul class="tt-mmg-list">';
        foreach ( $principles as $p ) {
            $shipped   = ! empty( $p->is_shipped );
            $title     = MultilingualField::string( $p->title_json );
            $edit_url  = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $p->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<span class="tt-mmg-row__code"><code>' . esc_html( (string) $p->code ) . '</code></span>';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                . esc_html( $title !== '' ? $title : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            echo '<span class="tt-mmg-row__meta">'
                . esc_html( ( $functions[ (string) $p->team_function_key ] ?? '' ) . ' · ' . ( $tasks[ (string) $p->team_task_key ] ?? '' ) )
                . '</span>';
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $shipped ) {
                // Shipped rows are read-only reference content — no frontend
                // edit / delete. The read view surfaces them; club authoring
                // acts on club-authored rows only.
                echo '<span class="tt-mmg-readonly">' . esc_html__( 'Read-only', 'talenttrack' ) . '</span>';
            } else {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
                echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                    . esc_attr( wp_json_encode( __( 'Delete this principle? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $p->id ) . '" />';
                echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                    . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function renderForm( int $id ): void {
        $repo = new PrinciplesRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That principle could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped principles are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $v = self::formValues( $row );

        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mp-code"><?php esc_html_e( 'Code', 'talenttrack' ); ?></label>
                <input type="text" id="tt-mp-code" class="tt-input" name="code" maxlength="50" required
                       value="<?php echo esc_attr( $v['code'] ); ?>" placeholder="AO-01" />
            </div>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mp-function"><?php esc_html_e( 'Team-function', 'talenttrack' ); ?></label>
                    <select id="tt-mp-function" class="tt-input" name="team_function_key" required>
                        <?php foreach ( MethodologyEnums::teamFunctions() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['team_function_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mp-task"><?php esc_html_e( 'Team-task', 'talenttrack' ); ?></label>
                    <select id="tt-mp-task" class="tt-input" name="team_task_key" required>
                        <?php foreach ( MethodologyEnums::teamTasks() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['team_task_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            self::multilingualText( 'title', __( 'Title', 'talenttrack' ), $v['title_nl'], $v['title_en'] );
            self::multilingualTextarea( 'explanation', __( 'Explanation', 'talenttrack' ), $v['explanation_nl'], $v['explanation_en'] );
            self::multilingualTextarea( 'team', __( 'Team-level guidance', 'talenttrack' ), $v['team_nl'], $v['team_en'] );
            ?>

            <fieldset class="tt-mmg-lines">
                <legend><?php esc_html_e( 'Per-line guidance', 'talenttrack' ); ?></legend>
                <?php foreach ( MethodologyEnums::lines() as $key => $label ) : ?>
                    <div class="tt-mmg-line-set">
                        <h3 class="tt-mmg-line-set__label"><?php echo esc_html( $label ); ?></h3>
                        <div class="tt-grid tt-grid-2">
                            <div class="tt-field">
                                <label class="tt-field-label" for="tt-mp-line-nl-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                                <textarea id="tt-mp-line-nl-<?php echo esc_attr( $key ); ?>" class="tt-input" name="line_nl[<?php echo esc_attr( $key ); ?>]" rows="2"><?php echo esc_textarea( $v['line_nl'][ $key ] ?? '' ); ?></textarea>
                            </div>
                            <div class="tt-field">
                                <label class="tt-field-label" for="tt-mp-line-en-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                                <textarea id="tt-mp-line-en-<?php echo esc_attr( $key ); ?>" class="tt-input" name="line_en[<?php echo esc_attr( $key ); ?>]" rows="2"><?php echo esc_textarea( $v['line_en'][ $key ] ?? '' ); ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </fieldset>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save principle', 'talenttrack' ) : __( 'Create principle', 'talenttrack' ),
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
                    <label class="tt-field-label" for="tt-mp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-mp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-mp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    /** Two side-by-side NL/EN textareas for a multilingual long-text field. */
    private static function multilingualTextarea( string $name, string $label, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="4"><?php echo esc_textarea( $nl ); ?></textarea>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <textarea id="tt-mp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="4"><?php echo esc_textarea( $en ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     *
     * @return array{code:string,team_function_key:string,team_task_key:string,title_nl:string,title_en:string,explanation_nl:string,explanation_en:string,team_nl:string,team_en:string,line_nl:array<string,string>,line_en:array<string,string>}
     */
    private static function formValues( ?object $row ): array {
        $blank_lines = array_fill_keys( array_keys( MethodologyEnums::lines() ), '' );
        $v = [
            'code'              => (string) ( $row->code ?? '' ),
            'team_function_key' => (string) ( $row->team_function_key ?? '' ),
            'team_task_key'     => (string) ( $row->team_task_key ?? '' ),
            'title_nl'          => '',
            'title_en'          => '',
            'explanation_nl'    => '',
            'explanation_en'    => '',
            'team_nl'           => '',
            'team_en'           => '',
            'line_nl'           => $blank_lines,
            'line_en'           => $blank_lines,
        ];
        if ( ! $row ) return $v;

        foreach ( [ 'title' => 'title_json', 'explanation' => 'explanation_json', 'team' => 'team_guidance_json' ] as $field => $col ) {
            $decoded = MultilingualField::decode( $row->{$col} ?? null ) ?: [];
            $v[ $field . '_nl' ] = (string) ( $decoded['nl'] ?? '' );
            $v[ $field . '_en' ] = (string) ( $decoded['en'] ?? '' );
        }

        $lines = MultilingualField::decode( $row->line_guidance_json ?? null ) ?: [];
        foreach ( array_keys( MethodologyEnums::lines() ) as $line ) {
            $entry = $lines[ $line ] ?? [];
            if ( is_array( $entry ) ) {
                $v['line_nl'][ $line ] = (string) ( $entry['nl'] ?? '' );
                $v['line_en'][ $line ] = (string) ( $entry['en'] ?? '' );
            }
        }
        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
     * Mirrors PrincipleEditPage::handleSave (§4 — same domain layer).
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
        $repo = new PrinciplesRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 || ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That principle could not be deleted.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Principle deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $function = sanitize_key( (string) wp_unslash( $post['team_function_key'] ?? '' ) );
        $task     = sanitize_key( (string) wp_unslash( $post['team_task_key'] ?? '' ) );
        if ( ! MethodologyEnums::isValidFunction( $function ) || ! MethodologyEnums::isValidTask( $task ) ) {
            return [ 'flash' => __( 'Please choose a valid team-function and team-task.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $code = sanitize_text_field( wp_unslash( (string) ( $post['code'] ?? '' ) ) );
        if ( $code === '' ) {
            return [ 'flash' => __( 'A principle needs a code.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'code'                => $code,
            'team_function_key'   => $function,
            'team_task_key'       => $task,
            'title_json'          => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['title_en'] ?? '' ) ) ),
            ] ),
            'explanation_json'    => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['explanation_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['explanation_en'] ?? '' ) ) ),
            ] ),
            'team_guidance_json'  => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['team_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['team_en'] ?? '' ) ) ),
            ] ),
            'line_guidance_json'  => self::encodeLines(
                is_array( $post['line_nl'] ?? null ) ? wp_unslash( $post['line_nl'] ) : [],
                is_array( $post['line_en'] ?? null ) ? wp_unslash( $post['line_en'] ) : []
            ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That principle could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Principle saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Principle created.', 'talenttrack' ) : __( 'Could not create the principle.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }

    /**
     * Encode the per-line NL/EN inputs into the nested line-guidance JSON
     * shape ({ line: { nl, en } }). Mirrors PrincipleEditPage::encodeLines.
     *
     * @param array<string,mixed> $nl
     * @param array<string,mixed> $en
     */
    private static function encodeLines( array $nl, array $en ): string {
        $lines = [];
        foreach ( array_keys( MethodologyEnums::lines() ) as $line ) {
            $lines[ $line ] = MultilingualField::decode( MultilingualField::encode( [
                'nl' => sanitize_textarea_field( (string) ( $nl[ $line ] ?? '' ) ),
                'en' => sanitize_textarea_field( (string) ( $en[ $line ] ?? '' ) ),
            ] ) ) ?? [];
        }
        return (string) wp_json_encode( $lines );
    }
}
