<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * GuestAddModal — markup for the "+ Add guest" panel rendered inside
 * the activity create / edit form (#0026, #0037).
 *
 * Two tabs: linked (cross-team fuzzy player search) and anonymous
 * (text inputs). The component emits a hidden `<dialog>`-style block;
 * the paired JS in `assets/js/components/guest-add.js` toggles
 * visibility, switches tabs, and POSTs to `/activities/{id}/guests`.
 *
 * #0037 — the linked-tab picker now uses PlayerSearchPickerComponent
 * with cross_team + show_team_filter, so a coach can either fuzzy-
 * search by name or first narrow by team (including teams they don't
 * manage). Replaces the long unfiltered <select> the v3.22.0 build
 * shipped with.
 *
 * Server-render so the player list ships in the page payload — no
 * extra fetch needed when the modal opens.
 */
final class GuestAddModal {

    /**
     * @param array{
     *   user_id?: int,
     *   is_admin?: bool,
     *   exclude_team_id?: int
     * } $args
     */
    public static function render( array $args = [] ): string {
        $user_id   = (int) ( $args['user_id'] ?? get_current_user_id() );
        $is_admin  = (bool) ( $args['is_admin'] ?? false );
        $exclude   = (int) ( $args['exclude_team_id'] ?? 0 );
        $positions = QueryHelpers::get_lookup_names( 'position' );

        ob_start();
        ?>
        <div class="tt-guest-modal" data-tt-guest-modal hidden role="dialog" aria-labelledby="tt-guest-modal-title">
            <div class="tt-guest-modal-backdrop" data-tt-guest-modal-close></div>
            <div class="tt-guest-modal-panel">
                <div class="tt-guest-modal-header">
                    <h3 id="tt-guest-modal-title" style="margin:0;"><?php esc_html_e( 'Gast toevoegen', 'talenttrack' ); ?></h3>
                    <button type="button" class="tt-guest-modal-close" data-tt-guest-modal-close aria-label="<?php esc_attr_e( 'Sluiten', 'talenttrack' ); ?>">×</button>
                </div>

                <div class="tt-guest-modal-tabs" role="tablist">
                    <button type="button" class="tt-guest-modal-tab tt-guest-modal-tab--active" data-tt-guest-tab="linked" role="tab">
                        <?php esc_html_e( 'Gekoppelde speler', 'talenttrack' ); ?>
                    </button>
                    <button type="button" class="tt-guest-modal-tab" data-tt-guest-tab="anonymous" role="tab">
                        <?php esc_html_e( 'Anonieme gast', 'talenttrack' ); ?>
                    </button>
                </div>

                <div class="tt-guest-modal-body">
                    <!-- Linked tab -->
                    <div data-tt-guest-pane="linked">
                        <p class="tt-help-text" style="margin:0 0 8px; font-size:12px; color:#5b6470;">
                            <?php esc_html_e( 'Kies een speler van een ander team. De evaluatie van vandaag verschijnt op zijn/haar profiel.', 'talenttrack' ); ?>
                        </p>
                        <?php echo PlayerSearchPickerComponent::render( [
                            'name'             => 'tt_guest_linked_player_id',
                            'label'            => __( 'Speler', 'talenttrack' ),
                            'required'         => false,
                            'user_id'          => $user_id,
                            'is_admin'         => $is_admin,
                            'cross_team'       => true,
                            'exclude_team_id'  => $exclude,
                            'show_team_filter' => true,
                            'placeholder'      => __( 'Type een naam om te zoeken…', 'talenttrack' ),
                        ] ); ?>
                    </div>

                    <!-- Anonymous tab -->
                    <div data-tt-guest-pane="anonymous" hidden>
                        <p class="tt-help-text" style="margin:0 0 8px; font-size:12px; color:#5b6470;">
                            <?php esc_html_e( 'Geen TalentTrack-record nodig. Vul de basisgegevens in; je kunt deze gast later promoveren naar een echte speler.', 'talenttrack' ); ?>
                        </p>
                        <div class="tt-field">
                            <label class="tt-field-label tt-field-required" for="tt-guest-anon-name"><?php esc_html_e( 'Naam', 'talenttrack' ); ?></label>
                            <input type="text" id="tt-guest-anon-name" class="tt-input" maxlength="120" />
                        </div>
                        <div class="tt-grid tt-grid-2">
                            <div class="tt-field">
                                <label class="tt-field-label" for="tt-guest-anon-age"><?php esc_html_e( 'Leeftijd', 'talenttrack' ); ?></label>
                                <input type="number" id="tt-guest-anon-age" class="tt-input" min="6" max="19" />
                            </div>
                            <div class="tt-field">
                                <label class="tt-field-label" for="tt-guest-anon-position"><?php esc_html_e( 'Positie', 'talenttrack' ); ?></label>
                                <?php if ( ! empty( $positions ) ) : ?>
                                    <select id="tt-guest-anon-position" class="tt-input">
                                        <option value=""><?php esc_html_e( '— Onbekend —', 'talenttrack' ); ?></option>
                                        <?php foreach ( $positions as $pos ) : ?>
                                            <option value="<?php echo esc_attr( $pos ); ?>"><?php echo esc_html( $pos ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input type="text" id="tt-guest-anon-position" class="tt-input" maxlength="60" />
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tt-guest-modal-actions">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-guest-modal-close>
                        <?php esc_html_e( 'Annuleren', 'talenttrack' ); ?>
                    </button>
                    <button type="button" class="tt-btn tt-btn-primary" data-tt-guest-modal-submit>
                        <?php esc_html_e( 'Toevoegen', 'talenttrack' ); ?>
                    </button>
                </div>

                <p class="tt-guest-modal-msg" data-tt-guest-modal-msg hidden></p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
