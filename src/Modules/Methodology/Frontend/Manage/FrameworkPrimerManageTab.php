<?php
namespace TT\Modules\Methodology\Frontend\Manage;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrameworkPrimerManageTab (#2226) — the club framework-primer authoring
 * tab.
 *
 * Like VisionManageTab, the primer is a SINGLETON: one editable record per
 * club that introduces the framework and each of its themes (voetbalmodel,
 * voetbalhandelingen, phases, learning goals, influence factors) plus a
 * reflection and future outlook. The tab renders a single edit form — no
 * list, no "+ New", no delete. It resolves the active club-authored
 * primer, or a blank form that creates one on first save. The shipped
 * primer is read-only reference content.
 *
 * The primer is the parent of phases / learning goals / influence factors
 * (authored by child #2229 as their own tabs).
 *
 * Persistence runs through FrameworkPrimerRepository + MultilingualField —
 * the same domain layer FrameworkPrimerRestController consumes (§4).
 * Mirrors the wp-admin FrameworkPrimerEditPage save.
 */
final class FrameworkPrimerManageTab {

    public const MTAB = 'framework';

    /**
     * The primer's authorable fields: field slug → [ label, is_textarea ].
     * `title` + `tagline` are single-line; every intro / reflection /
     * future field is a long-text block. The order here is the render
     * order (identity first, then sections).
     *
     * @return array<string, array{0:string,1:bool}>
     */
    private static function fields(): array {
        return [
            'title'                    => [ __( 'Title', 'talenttrack' ),                          false ],
            'tagline'                  => [ __( 'Tagline', 'talenttrack' ),                        false ],
            'intro'                    => [ __( 'Inleiding', 'talenttrack' ),                      true ],
            'voetbalmodel_intro'       => [ __( 'Voetbalmodel — toelichting', 'talenttrack' ),     true ],
            'voetbalhandelingen_intro' => [ __( 'Voetbalhandelingen — toelichting', 'talenttrack' ), true ],
            'phases_intro'             => [ __( 'Vier fasen — toelichting', 'talenttrack' ),        true ],
            'learning_goals_intro'     => [ __( 'Leerdoelen — toelichting', 'talenttrack' ),        true ],
            'influence_factors_intro'  => [ __( 'Factoren van invloed — toelichting', 'talenttrack' ), true ],
            'reflection'               => [ __( 'Reflectie', 'talenttrack' ),                      true ],
            'future'                   => [ __( 'De toekomst', 'talenttrack' ),                    true ],
        ];
    }

    /** Wire the tab into the shared registry. Called from MethodologyModule::boot(). */
    public static function register(): void {
        MethodologyManageRegistry::register( [
            'key'    => self::MTAB,
            'label'  => __( 'Raamwerk', 'talenttrack' ),
            'render' => [ self::class, 'render' ],
            'handle' => [ self::class, 'handle' ],
            'order'  => 20,
        ] );
    }

    // ── render ──────────────────────────────────────────────────────

    /** @param array{action:string,id:int,flash:string} $ctx */
    public static function render( array $ctx ): void {
        $repo = new FrameworkPrimerRepository();
        $row  = self::editableRow( $repo );

        $values     = self::extract( $row );
        $cancel_url = MethodologyManageView::cancelUrl( self::MTAB );
        ?>
        <p class="tt-mmg-intro"><?php esc_html_e( 'The framework primer is a single record. Edit its intro sections here; they appear on the read view’s Raamwerk tab.', 'talenttrack' ); ?></p>
        <form method="post" class="tt-mmg-form">
            <?php wp_nonce_field( MethodologyManageView::NONCE_ACTION, MethodologyManageView::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="save" />
            <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $row->id ); ?>" /><?php endif; ?>

            <?php foreach ( self::fields() as $field => $meta ) :
                [ $label, $is_textarea ] = $meta;
                self::multilingual( $field, $label, $is_textarea, $values[ $field ]['nl'], $values[ $field ]['en'] );
            endforeach; ?>

            <?php
            echo FormSaveButton::render( [
                'label'      => $row ? __( 'Save framework primer', 'talenttrack' ) : __( 'Create framework primer', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    /**
     * The club-authored primer row that this tab edits (never the shipped
     * primer). Returns null when the club has not yet authored one — the
     * form then renders blank and creates on first save.
     */
    private static function editableRow( FrameworkPrimerRepository $repo ): ?object {
        $active = $repo->activeForClub();
        if ( $active && empty( $active->is_shipped ) ) {
            return $active;
        }
        return null;
    }

    /** Two side-by-side NL/EN inputs (or textareas) for a multilingual field. */
    private static function multilingual( string $name, string $label, bool $textarea, string $nl, string $en ): void {
        ?>
        <div class="tt-mmg-ml">
            <h3 class="tt-mmg-ml__label"><?php echo esc_html( $label ); ?></h3>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mfp-<?php echo esc_attr( $name ); ?>-nl"><?php esc_html_e( 'Dutch (NL)', 'talenttrack' ); ?></label>
                    <?php if ( $textarea ) : ?>
                        <textarea id="tt-mfp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" rows="4"><?php echo esc_textarea( $nl ); ?></textarea>
                    <?php else : ?>
                        <input type="text" id="tt-mfp-<?php echo esc_attr( $name ); ?>-nl" class="tt-input" name="<?php echo esc_attr( $name ); ?>_nl" value="<?php echo esc_attr( $nl ); ?>" />
                    <?php endif; ?>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mfp-<?php echo esc_attr( $name ); ?>-en"><?php esc_html_e( 'English (EN)', 'talenttrack' ); ?></label>
                    <?php if ( $textarea ) : ?>
                        <textarea id="tt-mfp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" rows="4"><?php echo esc_textarea( $en ); ?></textarea>
                    <?php else : ?>
                        <input type="text" id="tt-mfp-<?php echo esc_attr( $name ); ?>-en" class="tt-input" name="<?php echo esc_attr( $name ); ?>_en" value="<?php echo esc_attr( $en ); ?>" />
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Decode a row (or blank template) into per-field NL/EN values.
     *
     * @return array<string, array{nl:string,en:string}>
     */
    private static function extract( ?object $row ): array {
        $out = [];
        foreach ( array_keys( self::fields() ) as $field ) {
            $col     = $field . '_json';
            $decoded = $row ? ( MultilingualField::decode( $row->{$col} ?? null ) ?: [] ) : [];
            $out[ $field ] = [
                'nl' => (string) ( $decoded['nl'] ?? '' ),
                'en' => (string) ( $decoded['en'] ?? '' ),
            ];
        }
        return $out;
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * Server-side handler for the primer form. Mirrors
     * FrameworkPrimerEditPage::handleSave (§4 — same domain layer). Create
     * when no club-authored row exists yet, update otherwise. No delete:
     * the primer is a singleton.
     *
     * @param array<string,mixed> $post
     * @return array{flash:string,back_to_list:bool}
     */
    public static function handle( array $post ): array {
        if ( ! current_user_can( MethodologyManageView::CAP ) ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }
        $op = isset( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
        if ( $op !== 'save' ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $repo = new FrameworkPrimerRepository();

        $payload = [];
        foreach ( self::fields() as $field => $meta ) {
            $is_textarea = $meta[1];
            $payload[ $field . '_json' ] = self::encodeField( $post, $field, $is_textarea );
        }

        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( ! $existing || ! empty( $existing->is_shipped ) ) {
                return [ 'flash' => __( 'That framework primer could not be saved.', 'talenttrack' ), 'back_to_list' => false ];
            }
            $repo->update( $id, $payload );
            return [ 'flash' => __( 'Framework primer saved.', 'talenttrack' ), 'back_to_list' => false ];
        }

        $payload['is_shipped'] = 0;
        $payload['club_scope'] = 'site';
        $new_id = $repo->create( $payload );
        return [
            'flash'        => $new_id > 0 ? __( 'Framework primer created.', 'talenttrack' ) : __( 'Could not create the framework primer.', 'talenttrack' ),
            'back_to_list' => false,
        ];
    }

    /**
     * Encode one NL/EN field from the POST into multilingual JSON.
     *
     * @param array<string,mixed> $post
     */
    private static function encodeField( array $post, string $field, bool $textarea ): string {
        $sanitize = static fn ( string $raw ): string => $textarea
            ? sanitize_textarea_field( wp_unslash( $raw ) )
            : sanitize_text_field( wp_unslash( $raw ) );
        return MultilingualField::encode( [
            'nl' => $sanitize( (string) ( $post[ $field . '_nl' ] ?? '' ) ),
            'en' => $sanitize( (string) ( $post[ $field . '_en' ] ?? '' ) ),
        ] );
    }
}
