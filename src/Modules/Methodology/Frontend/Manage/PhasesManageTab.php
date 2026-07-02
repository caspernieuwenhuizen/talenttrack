<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\PhasesRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * PhasesManageTab (#2229) — the phases authoring tab, built on the #2225
 * scaffold. Mirrors SetPiecesManageTab: register() a render + handle
 * callable into MethodologyManageRegistry, then dispatch a list ⇄ flat
 * create/edit form on the frame-supplied action.
 *
 * A phase is a child of the framework primer: it carries a side
 * (attacking / defending), a phase number (1–4), a multilingual title and
 * a multilingual goal. The four phases × two sides describe how the club
 * moves through the game. No business logic lives here beyond composition
 * — the closed side taxonomy and persistence run through MethodologyEnums
 * + PhasesRepository, the same domain layer PhasesRestController consumes
 * (§4). Mirrors the wp-admin PhaseEditPage save.
 */
final class PhasesManageTab {

    public const MTAB = 'phases';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Fasen', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 30,
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
            . esc_html__( 'Author the framework primer first — phases hang off it. Open the Raamwerk tab and save the primer.', 'talenttrack' )
            . '</p>';
        echo '<a class="tt-btn tt-btn-secondary" href="'
            . esc_url( MethodologyManageView::tabUrl( FrameworkPrimerManageTab::MTAB ) ) . '">'
            . esc_html__( 'Go to Raamwerk', 'talenttrack' ) . '</a>';
    }

    private static function renderList( int $primer_id ): void {
        echo '<div class="tt-mmg-toolbar">';
        echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
            . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
            . esc_html__( '+ New phase', 'talenttrack' ) . '</a>';
        echo '</div>';

        $phases = ( new PhasesRepository() )->listForPrimer( $primer_id );
        if ( empty( $phases ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No phases yet. Use “+ New phase” to author the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        $sides = MethodologyEnums::sides();

        echo '<ul class="tt-mmg-list">';
        foreach ( $phases as $phase ) {
            $shipped  = ! empty( $phase->is_shipped );
            $title    = MultilingualField::string( $phase->title_json );
            $edit_url = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $phase->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                . esc_html( $title !== '' ? $title : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            echo '<span class="tt-mmg-row__meta">'
                . esc_html( ( $sides[ (string) $phase->side ] ?? '' ) . ' · '
                    . sprintf( /* translators: %d: phase number 1–4 */ __( 'Phase %d', 'talenttrack' ), (int) $phase->phase_number ) )
                . '</span>';
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
                    . esc_attr( wp_json_encode( __( 'Delete this phase? This cannot be undone.', 'talenttrack' ) ) ) . ')">';
                wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                echo '<input type="hidden" name="op" value="delete" />';
                echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $phase->id ) . '" />';
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
        $repo = new PhasesRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That phase could not be found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $row && ! empty( $row->is_shipped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Shipped phases are read-only reference content and cannot be edited.', 'talenttrack' ) . '</p>';
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

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-ph-side"><?php esc_html_e( 'Side', 'talenttrack' ); ?></label>
                    <select id="tt-ph-side" class="tt-input" name="side" required>
                        <?php foreach ( MethodologyEnums::sides() as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>"<?php selected( $v['side'], $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-ph-number"><?php esc_html_e( 'Phase number (1–4)', 'talenttrack' ); ?></label>
                    <input type="number" inputmode="numeric" id="tt-ph-number" class="tt-input" name="phase_number" min="1" max="4" required value="<?php echo esc_attr( (string) $v['phase_number'] ); ?>" />
                </div>
            </div>

            <?php self::multilingualText( 'title', __( 'Title', 'talenttrack' ), $v['title_nl'], $v['title_en'] ); ?>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Goal', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ph-goal-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <textarea id="tt-ph-goal-nl" class="tt-input" name="goal_nl" rows="4"><?php echo esc_textarea( $v['goal_nl'] ); ?></textarea>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ph-goal-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <textarea id="tt-ph-goal-en" class="tt-input" name="goal_en" rows="4"><?php echo esc_textarea( $v['goal_en'] ); ?></textarea>
                    </div>
                </div>
            </div>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save phase', 'talenttrack' ) : __( 'Create phase', 'talenttrack' ),
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
                    <label class="tt-field-label" for="tt-ph-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-ph-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-ph-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-ph-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     *
     * @return array{side:string,phase_number:int,title_nl:string,title_en:string,goal_nl:string,goal_en:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'side'         => (string) ( $row->side ?? MethodologyEnums::SIDE_ATTACKING ),
            'phase_number' => (int) ( $row->phase_number ?? 1 ),
            'title_nl'     => '',
            'title_en'     => '',
            'goal_nl'      => '',
            'goal_en'      => '',
        ];
        if ( ! $row ) return $v;

        $title = MultilingualField::decode( $row->title_json ?? null ) ?: [];
        $v['title_nl'] = (string) ( $title['nl'] ?? '' );
        $v['title_en'] = (string) ( $title['en'] ?? '' );

        $goal = MultilingualField::decode( $row->goal_json ?? null ) ?: [];
        $v['goal_nl'] = (string) ( $goal['nl'] ?? '' );
        $v['goal_en'] = (string) ( $goal['en'] ?? '' );

        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (create / edit / delete).
     * Mirrors PhaseEditPage::handleSave (§4 — same domain layer).
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
        $repo = new PhasesRepository();

        if ( $op === 'delete' ) {
            if ( $id <= 0 || ! $repo->delete( $id ) ) {
                return [ 'flash' => __( 'That phase could not be deleted. Shipped reference phases cannot be removed.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Phase deleted.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $side = sanitize_key( (string) wp_unslash( $post['side'] ?? '' ) );
        if ( ! MethodologyEnums::isValidSide( $side ) ) {
            return [ 'flash' => __( 'Please choose a valid side.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'side'         => $side,
            'phase_number' => max( 1, min( 4, (int) ( $post['phase_number'] ?? 1 ) ) ),
            'title_json'   => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $post['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $post['title_en'] ?? '' ) ) ),
            ] ),
            'goal_json'    => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['goal_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['goal_en'] ?? '' ) ) ),
            ] ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That phase could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Phase saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $payload['primer_id']  = absint( $post['primer_id'] ?? 0 );
        $payload['is_shipped'] = 0;
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Phase created.', 'talenttrack' ) : __( 'Could not create the phase.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }
}
