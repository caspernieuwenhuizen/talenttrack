<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;

/**
 * ParentSearchPickerComponent — autocomplete-driven parent picker for
 * the player edit form (#0063).
 *
 * Mirrors PlayerSearchPickerComponent's `.tt-psp` DOM contract +
 * embedded JSON payload so the existing
 * `assets/js/components/player-search-picker.js` hydrator drives both
 * pickers without modification.
 *
 * Replaces the legacy three text fields (guardian_name / guardian_email
 * / guardian_phone) on the player edit form with a single linked-record
 * picker that scopes `tt_people` to `role_type IN ('parent','guardian')`.
 *
 * The "Parent doesn't exist?" branch returns a CTA that launches the
 * new-person creation wizard (slug `new-person`) with the role
 * pre-selected to `parent` and a `return_to` query arg pointing back
 * at the player edit form. The wizard's final step redirects back and
 * pre-selects the new person id.
 *
 * Usage:
 *
 *   echo ParentSearchPickerComponent::render([
 *       'name'     => 'parent_person_id',
 *       'label'    => __( 'Connect a parent account', 'talenttrack' ),
 *       'selected' => (int) ( $player->parent_person_id ?? 0 ),
 *       'return_to'=> $current_admin_url,
 *   ]);
 */
class ParentSearchPickerComponent {

    /**
     * @param array{
     *   name?:string,
     *   label?:string,
     *   required?:bool,
     *   selected?:int,
     *   placeholder?:string,
     *   return_to?:string,
     *   show_create_link?:bool
     * } $args
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name']        ?? 'parent_person_id' );
        $label       = (string) ( $args['label']       ?? __( 'Connect a parent account', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( 'Type a parent name to search…', 'talenttrack' ) );
        $selected    = (int)    ( $args['selected']    ?? 0 );
        $return_to   = (string) ( $args['return_to']   ?? '' );
        $show_create = ! isset( $args['show_create_link'] ) || $args['show_create_link'];

        $people = ( new PeopleRepository() )->list( [
            'role_type' => [ 'parent' ],
            'status'    => 'active',
        ] );

        $rows           = self::buildRows( $people );
        $selected_label = '';
        foreach ( $rows as $r ) {
            if ( (int) $r['id'] === $selected ) {
                $selected_label = (string) $r['label'];
                break;
            }
        }

        $instance = 'tt-psp-parent-' . wp_generate_uuid4();

        $create_url = '';
        if ( $show_create ) {
            $create_args = [ 'tt_view' => 'wizard', 'slug' => 'new-person', 'role_hint' => 'parent' ];
            if ( $return_to !== '' ) $create_args['return_to'] = $return_to;
            $create_url = (string) add_query_arg( $create_args, RecordLink::dashboardUrl() );
        }

        ob_start();
        ?>
        <div class="tt-field tt-psp" data-tt-psp data-instance="<?php echo esc_attr( $instance ); ?>">
            <label class="tt-field-label<?php echo $required ? ' tt-field-required' : ''; ?>" for="<?php echo esc_attr( $instance . '-search' ); ?>">
                <?php echo esc_html( $label ); ?>
            </label>

            <input
                type="hidden"
                name="<?php echo esc_attr( $name ); ?>"
                value="<?php echo esc_attr( (string) $selected ); ?>"
                <?php echo $required ? 'required' : ''; ?>
                data-tt-psp-value
            />

            <div class="tt-psp-selected" style="<?php echo $selected ? '' : 'display:none;'; ?>" data-tt-psp-selected>
                <span class="tt-psp-selected-label" data-tt-psp-selected-label>
                    <?php echo esc_html( $selected_label ); ?>
                </span>
                <button type="button" class="tt-psp-clear" data-tt-psp-clear aria-label="<?php esc_attr_e( 'Clear selection', 'talenttrack' ); ?>">×</button>
            </div>

            <input
                type="text"
                id="<?php echo esc_attr( $instance . '-search' ); ?>"
                class="tt-input tt-psp-search"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                autocomplete="off"
                data-tt-psp-search
                style="<?php echo $selected ? 'display:none;' : ''; ?>"
            />

            <ul class="tt-psp-results" data-tt-psp-results role="listbox" hidden></ul>

            <?php if ( $create_url !== '' ) : ?>
                <p class="tt-psp-create-hint" style="margin: 6px 0 0; font-size: 12px;">
                    <?php esc_html_e( "Parent doesn't exist yet?", 'talenttrack' ); ?>
                    <a class="tt-link" href="<?php echo esc_url( $create_url ); ?>">
                        <?php esc_html_e( 'Create a parent account', 'talenttrack' ); ?> &rarr;
                    </a>
                </p>
            <?php endif; ?>

            <script type="application/json" class="tt-psp-data" data-tt-psp-data>
                <?php echo wp_json_encode( $rows ); ?>
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build a flat array of {id, label, search} rows for the client
     * autocomplete. Label includes the parent's email when available
     * so admins can disambiguate two same-name parents quickly.
     *
     * @param object[] $people
     * @return array<int, array{id:int, label:string, team_id:int, search:string}>
     */
    private static function buildRows( array $people ): array {
        $out = [];
        foreach ( $people as $r ) {
            $name  = trim( ( (string) ( $r->first_name ?? '' ) ) . ' ' . ( (string) ( $r->last_name ?? '' ) ) );
            if ( $name === '' ) continue;
            $email = (string) ( $r->email ?? '' );
            $label = $email !== '' ? sprintf( '%s — %s', $name, $email ) : $name;
            $out[] = [
                'id'      => (int) $r->id,
                'label'   => $label,
                // team_id kept for shared JS hydrator schema parity with PlayerSearchPicker.
                'team_id' => 0,
                'search'  => strtolower( $name . ' ' . $email ),
            ];
        }
        return $out;
    }
}
