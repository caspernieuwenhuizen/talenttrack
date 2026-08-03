<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\ActiveMethodologyResolver;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\MethodologiesRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * MethodologySetsManageTab (#2320) — the frontend manage tab for the
 * methodology *sets* themselves (epic #2316). Mirrors the
 * FootballActionsManageTab shape:
 *
 *   1. register() → MethodologyManageRegistry::register([...]) with a low
 *      order so "Speelwijzen" leads the tab bar.
 *   2. render()   → list ⇄ flat create/edit form.
 *   3. handle()   → sanitize → MethodologiesRepository create/update/
 *      set-default/archive.
 *
 * The list surfaces which set is install-active (badge) and shipped sets
 * (badge), and offers "Make active" + Archive per row (both hidden on
 * shipped sets; Archive also hidden on the active set). All mutating
 * affordances are cap-gated on `tt_edit_methodology`. No business logic
 * lives here beyond composition — persistence + the shipped / last-set
 * guards run through MethodologiesRepository, the same domain layer
 * MethodologySetsRestController consumes (§4).
 */
final class MethodologySetsManageTab {

    public const MTAB = 'sets';

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Speelwijzen', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 5,
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
        $can_edit = current_user_can( MethodologyManageView::CAP );

        if ( $can_edit ) {
            echo '<div class="tt-mmg-toolbar">';
            echo '<a class="tt-btn tt-btn-primary tt-mmg-new" href="'
                . esc_url( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'new' ] ) ) . '">'
                . esc_html__( '+ Nieuwe speelwijze', 'talenttrack' ) . '</a>';
            echo '</div>';
        }

        $sets = ( new MethodologiesRepository() )->allForClub();
        if ( empty( $sets ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No methodology sets yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $active_id = ActiveMethodologyResolver::forInstall();

        echo '<ul class="tt-mmg-list">';
        foreach ( $sets as $s ) {
            $shipped   = ! empty( $s->is_shipped );
            $is_active = (int) $s->id === $active_id;
            $name      = MultilingualField::string( $s->name_json );
            $edit_url  = BackLink::appendTo( MethodologyManageView::tabUrl( self::MTAB, [ 'action' => 'edit', 'id' => (int) $s->id ] ) );

            echo '<li class="tt-mmg-row">';
            echo '<div class="tt-mmg-row__main">';
            if ( $can_edit ) {
                echo '<a class="tt-mmg-row__name" href="' . esc_url( $edit_url ) . '">'
                    . esc_html( $name !== '' ? $name : __( '(untitled)', 'talenttrack' ) ) . '</a>';
            } else {
                echo '<span class="tt-mmg-row__name">'
                    . esc_html( $name !== '' ? $name : __( '(untitled)', 'talenttrack' ) ) . '</span>';
            }
            if ( $is_active ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--active">' . esc_html__( 'Actief', 'talenttrack' ) . '</span>';
            }
            if ( $shipped ) {
                echo '<span class="tt-mmg-chip tt-mmg-chip--shipped">' . esc_html__( 'Shipped', 'talenttrack' ) . '</span>';
            }
            echo '</div>';

            echo '<div class="tt-mmg-row__actions">';
            if ( $can_edit ) {
                echo '<a class="tt-btn tt-btn-secondary tt-mmg-action" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Edit', 'talenttrack' ) . '</a>';

                if ( ! $is_active ) {
                    echo '<form method="post" class="tt-mmg-inline-form">';
                    wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                    echo '<input type="hidden" name="op" value="make_default" />';
                    echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $s->id ) . '" />';
                    echo '<button type="submit" class="tt-btn tt-btn-secondary tt-mmg-action">'
                        . esc_html__( 'Maak actief', 'talenttrack' ) . '</button>';
                    echo '</form>';
                }

                // Archive: never on shipped sets, never on the active set.
                if ( ! $shipped && ! $is_active ) {
                    echo '<form method="post" class="tt-mmg-inline-form" onsubmit="return confirm('
                        . esc_attr( wp_json_encode( __( 'Archive this methodology set?', 'talenttrack' ) ) ) . ')">';
                    wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD );
                    echo '<input type="hidden" name="op" value="archive" />';
                    echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $s->id ) . '" />';
                    echo '<button type="submit" class="tt-btn tt-btn-danger tt-mmg-action">'
                        . esc_html__( 'Archive', 'talenttrack' ) . '</button>';
                    echo '</form>';
                }
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function renderForm( int $id ): void {
        $repo = new MethodologiesRepository();
        $row  = $id > 0 ? $repo->find( $id ) : null;

        if ( $id > 0 && ! $row ) {
            echo '<p class="tt-notice">' . esc_html__( 'That methodology set could not be found.', 'talenttrack' ) . '</p>';
            return;
        }

        $v          = self::formValues( $row );
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Name', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-name-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-ms-name-nl" class="tt-input" name="name_nl" value="<?php echo esc_attr( $v['name_nl'] ); ?>" required />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-name-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-ms-name-en" class="tt-input" name="name_en" value="<?php echo esc_attr( $v['name_en'] ); ?>" />
                    </div>
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-ms-slug"><?php esc_html_e( 'Slug (optional)', 'talenttrack' ); ?></label>
                <input type="text" id="tt-ms-slug" class="tt-input" name="slug" maxlength="64"
                       value="<?php echo esc_attr( $v['slug'] ); ?>" placeholder="jo14-1-hedel" />
            </div>

            <div class="tt-mmg-ml">
                <h3 class="tt-mmg-ml__label"><?php esc_html_e( 'Description', 'talenttrack' ); ?></h3>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-desc-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                        <textarea id="tt-ms-desc-nl" class="tt-input" name="description_nl" rows="4"><?php echo esc_textarea( $v['description_nl'] ); ?></textarea>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-desc-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                        <textarea id="tt-ms-desc-en" class="tt-input" name="description_en" rows="4"><?php echo esc_textarea( $v['description_en'] ); ?></textarea>
                    </div>
                </div>
            </div>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save methodology set', 'talenttrack' ) : __( 'Create methodology set', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    /**
     * Decode a row (or blank template) into the form's field values.
     *
     * @return array{slug:string,name_nl:string,name_en:string,description_nl:string,description_en:string}
     */
    private static function formValues( ?object $row ): array {
        $v = [
            'slug'           => (string) ( $row->slug ?? '' ),
            'name_nl'        => '',
            'name_en'        => '',
            'description_nl' => '',
            'description_en' => '',
        ];
        if ( ! $row ) return $v;

        foreach ( [ 'name' => 'name_json', 'description' => 'description_json' ] as $field => $col ) {
            $decoded = MultilingualField::decode( $row->{$col} ?? null ) ?: [];
            $v[ $field . '_nl' ] = (string) ( $decoded['nl'] ?? '' );
            $v[ $field . '_en' ] = (string) ( $decoded['en'] ?? '' );
        }
        return $v;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the tab's forms (save / make-default /
     * archive). Delegates the shipped + last-set guards to the repository
     * (§4 — same domain layer as MethodologySetsRestController).
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
        $repo = new MethodologiesRepository();

        if ( $op === 'make_default' ) {
            if ( $id <= 0 || ! $repo->setDefault( $id ) ) {
                return [ 'flash' => __( 'Could not change the active methodology.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Active methodology updated.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op === 'archive' ) {
            if ( $id <= 0 || ! $repo->archive( $id ) ) {
                return [ 'flash' => __( 'That methodology set could not be archived.', 'talenttrack' ), 'back_to_list' => true ];
            }
            return [ 'flash' => __( 'Methodology set archived.', 'talenttrack' ), 'back_to_list' => true ];
        }

        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $name_nl = sanitize_text_field( wp_unslash( (string) ( $post['name_nl'] ?? '' ) ) );
        $name_en = sanitize_text_field( wp_unslash( (string) ( $post['name_en'] ?? '' ) ) );
        if ( $name_nl === '' && $name_en === '' ) {
            return [ 'flash' => __( 'A methodology set needs a name.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload = [
            'name'        => [ 'nl' => $name_nl, 'en' => $name_en ],
            'description' => [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $post['description_en'] ?? '' ) ) ),
            ],
            'slug'        => sanitize_title( (string) wp_unslash( $post['slug'] ?? '' ) ),
        ];

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That methodology set could not be saved.', 'talenttrack' ), 'back_to_list' => true ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Methodology set saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Methodology set created.', 'talenttrack' ) : __( 'Could not create the methodology set.', 'talenttrack' ),
            'back_to_list' => $new_id > 0,
        ];
    }
}
