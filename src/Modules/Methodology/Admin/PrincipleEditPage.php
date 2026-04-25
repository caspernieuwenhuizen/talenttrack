<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FormationsRepository;
use TT\Modules\Methodology\Repositories\PrinciplesRepository;

/**
 * PrincipleEditPage — wp-admin form for creating/editing a club-
 * authored principle.
 *
 * Hidden submenu (registered with parent = null). Reached via
 * `?page=tt-methodology-principle-edit&action=new|edit&id=...`.
 *
 * Multilingual fields are rendered as side-by-side NL + EN inputs.
 * That keeps authoring fast — Casper writes NL once and (optionally)
 * paste-translates EN. Empty EN values fall back to NL at render
 * time per MultilingualField.
 *
 * Edits are blocked on shipped rows (UI sends users through Clone &
 * Edit instead). The handler also enforces `is_shipped = 0` server-
 * side as a defensive check.
 */
class PrincipleEditPage {

    public const SLUG = 'tt-methodology-principle-edit';
    public const CAP  = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_methodology_principle_save', [ self::class, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'new';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $repo   = new PrinciplesRepository();
        $row    = $action === 'edit' && $id > 0 ? $repo->find( $id ) : null;

        if ( $row && $row->is_shipped ) {
            wp_die( esc_html__( 'Shipped principles are read-only. Use Clone & Edit instead.', 'talenttrack' ) );
        }

        $formations = ( new FormationsRepository() )->listAll();
        $title_nl   = '';
        $title_en   = '';
        $expl_nl    = '';
        $expl_en    = '';
        $team_nl    = '';
        $team_en    = '';
        $line_nl    = [ 'aanvallers' => '', 'middenvelders' => '', 'verdedigers' => '', 'keeper' => '' ];
        $line_en    = [ 'aanvallers' => '', 'middenvelders' => '', 'verdedigers' => '', 'keeper' => '' ];

        if ( $row ) {
            $title_decoded = MultilingualField::decode( $row->title_json )       ?: [];
            $expl_decoded  = MultilingualField::decode( $row->explanation_json ) ?: [];
            $team_decoded  = MultilingualField::decode( $row->team_guidance_json ) ?: [];
            $line_decoded  = MultilingualField::decode( $row->line_guidance_json ) ?: [];
            $title_nl = (string) ( $title_decoded['nl'] ?? '' );
            $title_en = (string) ( $title_decoded['en'] ?? '' );
            $expl_nl  = (string) ( $expl_decoded['nl']  ?? '' );
            $expl_en  = (string) ( $expl_decoded['en']  ?? '' );
            $team_nl  = (string) ( $team_decoded['nl']  ?? '' );
            $team_en  = (string) ( $team_decoded['en']  ?? '' );
            foreach ( array_keys( $line_nl ) as $line ) {
                $entry = $line_decoded[ $line ] ?? [];
                if ( is_array( $entry ) ) {
                    $line_nl[ $line ] = (string) ( $entry['nl'] ?? '' );
                    $line_en[ $line ] = (string) ( $entry['en'] ?? '' );
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>
                <?php echo $row ? esc_html__( 'Edit principle', 'talenttrack' ) : esc_html__( 'Add principle', 'talenttrack' ); ?>
            </h1>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MethodologyPage::SLUG ) ); ?>">
                    ← <?php esc_html_e( 'Back to library', 'talenttrack' ); ?>
                </a>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_methodology_principle_save', 'tt_methodology_nonce' ); ?>
                <input type="hidden" name="action" value="tt_methodology_principle_save" />
                <?php if ( $row ) : ?><input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="tt_p_code"><?php esc_html_e( 'Code', 'talenttrack' ); ?></label></th>
                        <td><input type="text" id="tt_p_code" name="code" class="regular-text" required value="<?php echo esc_attr( (string) ( $row->code ?? '' ) ); ?>" placeholder="AO-01" /></td>
                    </tr>
                    <tr>
                        <th><label for="tt_p_function"><?php esc_html_e( 'Team-function', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_p_function" name="team_function_key" required>
                                <?php foreach ( MethodologyEnums::teamFunctions() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $row->team_function_key ?? '', $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_p_task"><?php esc_html_e( 'Team-task', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_p_task" name="team_task_key" required>
                                <?php foreach ( MethodologyEnums::teamTasks() as $k => $label ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $row->team_task_key ?? '', $k ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_p_formation"><?php esc_html_e( 'Default formation', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_p_formation" name="default_formation_id">
                                <option value=""><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                                <?php foreach ( $formations as $f ) : ?>
                                    <option value="<?php echo (int) $f->id; ?>" <?php selected( (int) ( $row->default_formation_id ?? 0 ), (int) $f->id ); ?>>
                                        <?php echo esc_html( MultilingualField::string( $f->name_json ) ?: $f->slug ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr><th colspan="2"><h3 style="margin:8px 0;"><?php esc_html_e( 'Title', 'talenttrack' ); ?></h3></th></tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Title (NL)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="title_nl" class="regular-text" value="<?php echo esc_attr( $title_nl ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Title (EN)', 'talenttrack' ); ?></label></th>
                        <td><input type="text" name="title_en" class="regular-text" value="<?php echo esc_attr( $title_en ); ?>" /></td>
                    </tr>
                    <tr><th colspan="2"><h3 style="margin:8px 0;"><?php esc_html_e( 'Explanation', 'talenttrack' ); ?></h3></th></tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Explanation (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="explanation_nl" rows="4" class="large-text"><?php echo esc_textarea( $expl_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Explanation (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="explanation_en" rows="4" class="large-text"><?php echo esc_textarea( $expl_en ); ?></textarea></td>
                    </tr>
                    <tr><th colspan="2"><h3 style="margin:8px 0;"><?php esc_html_e( 'Team-level guidance', 'talenttrack' ); ?></h3></th></tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Team (NL)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="team_nl" rows="3" class="large-text"><?php echo esc_textarea( $team_nl ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Team (EN)', 'talenttrack' ); ?></label></th>
                        <td><textarea name="team_en" rows="3" class="large-text"><?php echo esc_textarea( $team_en ); ?></textarea></td>
                    </tr>
                    <tr><th colspan="2"><h3 style="margin:8px 0;"><?php esc_html_e( 'Per-line guidance', 'talenttrack' ); ?></h3></th></tr>
                    <?php foreach ( MethodologyEnums::lines() as $key => $label ) : ?>
                        <tr>
                            <th><label><?php echo esc_html( $label ); ?> (NL)</label></th>
                            <td><textarea name="line_nl[<?php echo esc_attr( $key ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( $line_nl[ $key ] ?? '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label><?php echo esc_html( $label ); ?> (EN)</label></th>
                            <td><textarea name="line_en[<?php echo esc_attr( $key ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( $line_en[ $key ] ?? '' ); ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button( $row ? __( 'Save changes', 'talenttrack' ) : __( 'Create principle', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_methodology_principle_save', 'tt_methodology_nonce' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        $payload = [
            'code'                 => sanitize_text_field( wp_unslash( (string) ( $_POST['code'] ?? '' ) ) ),
            'team_function_key'    => sanitize_key( (string) wp_unslash( $_POST['team_function_key'] ?? '' ) ),
            'team_task_key'        => sanitize_key( (string) wp_unslash( $_POST['team_task_key'] ?? '' ) ),
            'default_formation_id' => absint( $_POST['default_formation_id'] ?? 0 ) ?: null,
            'title_json'           => MultilingualField::encode( [
                'nl' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_nl'] ?? '' ) ) ),
                'en' => sanitize_text_field( wp_unslash( (string) ( $_POST['title_en'] ?? '' ) ) ),
            ] ),
            'explanation_json'     => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['explanation_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['explanation_en'] ?? '' ) ) ),
            ] ),
            'team_guidance_json'   => MultilingualField::encode( [
                'nl' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['team_nl'] ?? '' ) ) ),
                'en' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['team_en'] ?? '' ) ) ),
            ] ),
            'line_guidance_json'   => self::encodeLines(
                isset( $_POST['line_nl'] ) && is_array( $_POST['line_nl'] ) ? wp_unslash( $_POST['line_nl'] ) : [],
                isset( $_POST['line_en'] ) && is_array( $_POST['line_en'] ) ? wp_unslash( $_POST['line_en'] ) : []
            ),
        ];

        if ( ! MethodologyEnums::isValidFunction( $payload['team_function_key'] ) ||
             ! MethodologyEnums::isValidTask( $payload['team_task_key'] ) ) {
            wp_die( esc_html__( 'Invalid team-function or team-task.', 'talenttrack' ) );
        }

        $repo = new PrinciplesRepository();
        if ( $id > 0 ) {
            $existing = $repo->find( $id );
            if ( $existing && $existing->is_shipped ) {
                wp_die( esc_html__( 'Shipped principles are read-only.', 'talenttrack' ) );
            }
            $repo->update( $id, $payload );
        } else {
            $payload['is_shipped'] = 0;
            $id = $repo->create( $payload );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => MethodologyPage::SLUG, 'tab' => 'principles', 'principle_id' => $id, 'tt_msg' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * @param array<string,string> $nl
     * @param array<string,string> $en
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
