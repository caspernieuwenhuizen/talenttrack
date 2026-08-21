<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\SubPrinciplesRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * SubPrinciplesManageTab (#2369) — the frontend manage tab for
 * sub-principles (per-line coaching points under a phase). Mirrors the
 * PrinciplesManageTab / FootballActionsManageTab shape:
 *
 *   1. register() → MethodologyManageRegistry::register([...]).
 *   2. render()   → list (grouped by phase + line) ⇄ flat create/edit form.
 *   3. handle()   → sanitize → MultilingualField::encode → repository
 *      create/update/delete.
 *
 * Fields: phase_side, phase_number, line_key, and the NL/EN multilingual
 * `title` + `description`. No business logic lives here beyond composition
 * — persistence runs through SubPrinciplesRepository, the same domain
 * layer SubPrinciplesRestController consumes (§4).
 */
final class SubPrinciplesManageTab {

    public const MTAB = 'sub-principles';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Sub-principes', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            // Just after Spelprincipes (Principles register at order 40) so
            // the container narrative reads principle → sub-principle.
            'order'  => 42,
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
            . esc_html__( 'Add sub-principle', 'talenttrack' ) . '</a>';
        echo '</div>';

        $rows = ( new SubPrinciplesRepository() )->listFiltered();
        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No sub-principles yet. Use “+ New sub-principle” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        $sides = MethodologyEnums::sides();
        $lines = MethodologyEnums::lines();

        // Group by phase (side + number) so the list reads per phase.
        $grouped = [];
        foreach ( $rows as $r ) {
            $key = (string) $r->phase_side . ':' . (int) $r->phase_number;
            $grouped[ $key ][] = $r;
        }

        foreach ( $grouped as $key => $group ) {
            [ $side, $number ] = explode( ':', $key );
            $side_label = $sides[ $side ] ?? $side;
            $heading = $number > 0
                ? sprintf( '%s %d', $side_label, (int) $number )
                : $side_label;
            echo '<h3 class="tt-mmg-group-head">' . esc_html( $heading ) . '</h3>';

            echo '<ul class="tt-mmg-list">';
            foreach ( $group as $r ) {
                $shipped  = ! empty( $r->is_shipped );
                $title    = MultilingualField::string( $r->title_json );
                $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $r->id ] ) );

                echo '<li class="tt-mmg-row">';
                echo '<div class="tt-mmg-row__main">';
                echo '<span class="tt-mmg-row__meta">' . esc_html( $lines[ (string) $r->line_key ] ?? (string) $r->line_key ) . '</span>';
                echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                    . esc_html( $title !== '' ? $title : __( '(untitled)', 'talenttrack' ) ) . '</a>';
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
                        . esc_attr( wp_json_encode( __( 'Delete this sub-principle? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                    wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                    echo '<input type="hidden" name="op" value="delete" />';
                    echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $r->id ) . '" />';
                    echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                        . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
                    echo '</form>';
                }
                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    private static function renderForm( int $id ): void {
        $repo = new SubPrinciplesRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That sub-principle could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped sub-principles are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
            return;
        }

        $v          = self::formValues( $row );
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-side"><?php esc_html_e( 'Phase side', 'talenttrack' ); ?></label>
                    <select id="tt-sp-side" class="tt-input" name="phase_side" required>
                        <?php foreach ( MethodologyEnums::sides() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['phase_side'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-number"><?php esc_html_e( 'Phase number', 'talenttrack' ); ?></label>
                    <input type="number" id="tt-sp-number" class="tt-input" name="phase_number" min="0" max="9" inputmode="numeric"
                           value="<?php echo esc_attr( (string) $v['phase_number'] ); ?>" />
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-sp-line"><?php esc_html_e( 'Line', 'talenttrack' ); ?></label>
                <select id="tt-sp-line" class="tt-input" name="line_key" required>
                    <?php foreach ( MethodologyEnums::lines() as $k => $label ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['line_key'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php
            self::multilingualText( 'title', __( 'Title', 'talenttrack' ), $v['title_nl'], $v['title_en'] );
            self::multilingualTextarea( 'description', __( 'Description', 'talenttrack' ), $v['description_nl'], $v['description_en'] );
            ?>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save sub-principle', 'talenttrack' ) : __( 'Create sub-principle', 'talenttrack' ),
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
                    <label class="tt-field-label" for="tt-sp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-sp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-sp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
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
                    <label class="tt-field-label" for="tt-sp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <textarea id="tt-sp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="3"><?php echo esc_textarea( $nl ); ?></textarea>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <textarea id="tt-sp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="3"><?php echo esc_textarea( $en ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     *
     * @return array{phase_side:string,phase_number:int,line_key:string,title_nl:string,title_en:string,description_nl:string,description_en:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'phase_side'     => (string) ( $row->phase_side ?? MethodologyEnums::SIDE_DEFENDING ),
            'phase_number'   => (int) ( $row->phase_number ?? 1 ),
            'line_key'       => (string) ( $row->line_key ?? MethodologyEnums::LINE_AANVALLERS ),
            'title_nl'       => '',
            'title_en'       => '',
            'description_nl' => '',
            'description_en' => '',
        ];
        if ( ! $row ) return $v;

        foreach ( [ 'title' => 'title_json', 'description' => 'description_json' ] as $field => $col ) {
            $decoded = MultilingualField::decode( $row->{$col} ?? null ) ?: [];
            $v[ $field . '_nl' ] = (string) ( $decoded['nl'] ?? '' );
            $v[ $field . '_en' ] = (string) ( $decoded['en'] ?? '' );
        }
        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
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
        $repo = new SubPrinciplesRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 || ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That sub-principle could not be deleted.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Sub-principle deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $side = sanitize_key( (string) wp_unslash( $post['phase_side'] ?? '' ) );
        if ( ! MethodologyEnums::isValidSide( $side ) ) {
            return [ 'flash' => __( 'Please choose a valid phase side.', 'talenttrack' ), 'back_to_list' => false ];
        }
        $line = sanitize_key( (string) wp_unslash( $post['line_key'] ?? '' ) );
        if ( ! MethodologyEnums::isValidLine( $line ) ) {
            return [ 'flash' => __( 'Please choose a valid line.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'phase_side'       => $side,
            'phase_number'     => absint( $post['phase_number'] ?? 0 ),
            'line_key'         => $line,
            'title_json'       => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['title_en'] ?? '' ) ) ),
            ] ),
            'description_json' => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_en'] ?? '' ) ) ),
            ] ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That sub-principle could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Sub-principle saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Sub-principle created.', 'talenttrack' ) : __( 'Could not create the sub-principle.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }
}
