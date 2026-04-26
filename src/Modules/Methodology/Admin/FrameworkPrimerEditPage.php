<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\MethodologyAssetsRepository;

/**
 * FrameworkPrimerEditPage — edit the per-club methodology framework
 * primer (intro, voetbalmodel intro, phases intro, learning goals
 * intro, influence factors intro, reflection, future).
 *
 * The primer is a single record per club_scope. Editing the shipped
 * primer is blocked; clicking "Author my own" on the Framework tab
 * clones the shipped primer with `cloned_from_id` set and lands on
 * this page.
 *
 * Sub-rows (phases, learning goals, influence factors) have their
 * own edit pages reached via the Framework tab on MethodologyPage.
 */
final class FrameworkPrimerEditPage {

    public const SLUG = 'tt-methodology-primer-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_primer_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'edit';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo = new FrameworkPrimerRepository();
        $row  = $action === 'edit' && $id > 0 ? $repo->find( $id ) : $repo->activeForClub();

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'The shipped framework primer is read-only. Use "Author my own" to clone it for editing.', 'talenttrack' ) );
        }

        MediaPicker::enqueueAssets();

        $values = self::extract( $row );
        $is_new = ! $row;
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? esc_html__( 'Define methodology framework', 'talenttrack' ) : esc_html__( 'Edit methodology framework', 'talenttrack' ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG . '&tab=framework' ) ); ?>">← <?php esc_html_e( 'Back to framework', 'talenttrack' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_primer_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_primer_save" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>

                <h2 style="margin-top:18px;"><?php esc_html_e( 'Identity', 'talenttrack' ); ?></h2>
                <table class="form-table">
                    <?php
                    self::fieldRow( 'title',   __( 'Title',   'talenttrack' ), $values['title'],   'text' );
                    self::fieldRow( 'tagline', __( 'Tagline', 'talenttrack' ), $values['tagline'], 'text' );
                    ?>
                </table>

                <h2 style="margin-top:18px;"><?php esc_html_e( 'Sections', 'talenttrack' ); ?></h2>
                <table class="form-table">
                    <?php
                    self::fieldRow( 'intro',                  __( 'Inleiding',                'talenttrack' ), $values['intro'],                  'textarea' );
                    self::fieldRow( 'voetbalmodel_intro',     __( 'Voetbalmodel — toelichting',     'talenttrack' ), $values['voetbalmodel_intro'],     'textarea' );
                    self::fieldRow( 'voetbalhandelingen_intro', __( 'Voetbalhandelingen — toelichting', 'talenttrack' ), $values['voetbalhandelingen_intro'], 'textarea' );
                    self::fieldRow( 'phases_intro',           __( 'Vier fasen — toelichting',         'talenttrack' ), $values['phases_intro'],           'textarea' );
                    self::fieldRow( 'learning_goals_intro',   __( 'Leerdoelen — toelichting',         'talenttrack' ), $values['learning_goals_intro'],   'textarea' );
                    self::fieldRow( 'influence_factors_intro', __( 'Factoren van invloed — toelichting', 'talenttrack' ), $values['influence_factors_intro'], 'textarea' );
                    self::fieldRow( 'reflection',             __( 'Reflectie',                'talenttrack' ), $values['reflection'],             'textarea' );
                    self::fieldRow( 'future',                 __( 'De toekomst',              'talenttrack' ), $values['future'],                 'textarea' );
                    ?>
                </table>

                <?php if ( $row ) MediaPicker::render( MethodologyAssetsRepository::TYPE_FRAMEWORK, (int) $row->id ); ?>
                <?php submit_button( $is_new ? __( 'Create framework primer', 'talenttrack' ) : __( 'Save changes', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_primer_save', 'tt_methodology_nonce' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $payload = [
            'title_json'                  => self::encodeField( 'title' ),
            'tagline_json'                => self::encodeField( 'tagline' ),
            'intro_json'                  => self::encodeField( 'intro', true ),
            'voetbalmodel_intro_json'     => self::encodeField( 'voetbalmodel_intro', true ),
            'voetbalhandelingen_intro_json' => self::encodeField( 'voetbalhandelingen_intro', true ),
            'phases_intro_json'           => self::encodeField( 'phases_intro', true ),
            'learning_goals_intro_json'   => self::encodeField( 'learning_goals_intro', true ),
            'influence_factors_intro_json' => self::encodeField( 'influence_factors_intro', true ),
            'reflection_json'             => self::encodeField( 'reflection', true ),
            'future_json'                 => self::encodeField( 'future', true ),
        ];

        $repo = new FrameworkPrimerRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) {
                wp_die( esc_html__( 'The shipped framework primer is read-only.', 'talenttrack' ) );
            }
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $payload['club_scope'] = 'site';
            $id = $repo->create( $payload );
        }

        MediaPicker::handleSave( MethodologyAssetsRepository::TYPE_FRAMEWORK, (int) $id );

        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'framework', 'tt_msg' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * @return array<string, array{nl:string,en:string}>
     */
    private static function extract( ?object $row ): array {
        $cols = [
            'title'                      => 'title_json',
            'tagline'                    => 'tagline_json',
            'intro'                      => 'intro_json',
            'voetbalmodel_intro'         => 'voetbalmodel_intro_json',
            'voetbalhandelingen_intro'   => 'voetbalhandelingen_intro_json',
            'phases_intro'               => 'phases_intro_json',
            'learning_goals_intro'       => 'learning_goals_intro_json',
            'influence_factors_intro'    => 'influence_factors_intro_json',
            'reflection'                 => 'reflection_json',
            'future'                     => 'future_json',
        ];
        $out = [];
        foreach ( $cols as $field => $col ) {
            $decoded = $row ? ( MultilingualField::decode( $row->{$col} ?? null ) ?: [] ) : [];
            $out[ $field ] = [
                'nl' => (string) ( $decoded['nl'] ?? '' ),
                'en' => (string) ( $decoded['en'] ?? '' ),
            ];
        }
        return $out;
    }

    /** @param array{nl:string,en:string} $values */
    private static function fieldRow( string $field, string $label, array $values, string $type ): void {
        ?>
        <tr>
            <th><label><?php echo esc_html( $label ); ?> (NL)</label></th>
            <td>
                <?php if ( $type === 'textarea' ) : ?>
                    <textarea name="<?php echo esc_attr( $field . '_nl' ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $values['nl'] ); ?></textarea>
                <?php else : ?>
                    <input type="text" name="<?php echo esc_attr( $field . '_nl' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['nl'] ); ?>" />
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label><?php echo esc_html( $label ); ?> (EN)</label></th>
            <td>
                <?php if ( $type === 'textarea' ) : ?>
                    <textarea name="<?php echo esc_attr( $field . '_en' ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $values['en'] ); ?></textarea>
                <?php else : ?>
                    <input type="text" name="<?php echo esc_attr( $field . '_en' ); ?>" class="regular-text" value="<?php echo esc_attr( $values['en'] ); ?>" />
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function encodeField( string $field, bool $textarea = false ): string {
        $clean_nl = $textarea
            ? sanitize_textarea_field( wp_unslash( (string) ( $_POST[ $field . '_nl' ] ?? '' ) ) )
            : sanitize_text_field( wp_unslash( (string) ( $_POST[ $field . '_nl' ] ?? '' ) ) );
        $clean_en = $textarea
            ? sanitize_textarea_field( wp_unslash( (string) ( $_POST[ $field . '_en' ] ?? '' ) ) )
            : sanitize_text_field( wp_unslash( (string) ( $_POST[ $field . '_en' ] ?? '' ) ) );
        return MultilingualField::encode( [ 'nl' => $clean_nl, 'en' => $clean_en ] );
    }
}
