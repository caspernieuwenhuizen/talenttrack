<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * FrontendPlayersManageView — full-CRUD frontend for players.
 *
 * #0019 Sprint 3 session 3.1. Replaces the v3.0.0 placeholder
 * `FrontendPlayersView` (which only rendered list-of-tiles + a
 * detail/rate-card view). Modes selected via query string:
 *
 *   ?tt_view=players                    — list view (FrontendListTable + filters + Create CTA)
 *   ?tt_view=players&action=new         — create form
 *   ?tt_view=players&id=<int>           — edit form (prefilled, with photo + custom fields)
 *   ?tt_view=players&player_id=<int>    — detail view (rate card + radar + facts) — preserved
 *                                          for deep links from other surfaces
 *
 * The detail view (player_id) is the one the rest of the dashboard
 * already links to (search, podium, etc.). Keeping that route stable
 * means no cross-surface link rot. The new manage UI uses `id` (not
 * `player_id`) so the two modes never collide.
 *
 * Saves go through `Players_Controller` (full CRUD already exists).
 * Photo upload uses WP's media uploader — `wp_enqueue_media()` on the
 * frontend, confirmed working during Sprint 3 shaping.
 */
class FrontendPlayersManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        // Detail / rate-card route — preserved for deep links.
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        if ( $player_id > 0 ) {
            $player = QueryHelpers::get_player( $player_id );
            if ( $player ) {
                self::renderDetail( $player );
                return;
            }
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New player', 'talenttrack' ) );
            self::renderForm( $user_id, $is_admin, null );
            return;
        }

        if ( $id > 0 ) {
            $player = self::loadPlayer( $id );
            self::renderHeader( $player ? sprintf( __( 'Edit player — %s', 'talenttrack' ), QueryHelpers::player_display_name( $player ) ) : __( 'Player not found', 'talenttrack' ) );
            if ( ! $player ) {
                echo '<p class="tt-notice">' . esc_html__( 'That player no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $user_id, $is_admin, $player );
            return;
        }

        self::renderHeader( __( 'Players', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    /**
     * List view — FrontendListTable with name/team/position/foot/age-group/archived filters.
     */
    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id', 'player_id' ] );
        $new_url  = add_query_arg( [ 'tt_view' => 'players', 'action' => 'new' ], $base_url );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New player', 'talenttrack' )
            . '</a></p>';

        $position_options = [];
        foreach ( QueryHelpers::get_lookup_names( 'position' ) as $pos ) {
            $position_options[ (string) $pos ] = (string) $pos;
        }
        $foot_options = [];
        foreach ( QueryHelpers::get_lookup_names( 'foot_option' ) as $f ) {
            $foot_options[ (string) $f ] = (string) $f;
        }
        $age_group_options = [];
        foreach ( QueryHelpers::get_lookup_names( 'age_group' ) as $ag ) {
            $age_group_options[ (string) $ag ] = (string) $ag;
        }

        $row_actions = [
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'players', 'id' => '{id}' ], $base_url ),
            ],
            'card' => [
                'label' => __( 'Rate card', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'players', 'player_id' => '{id}' ], $base_url ),
            ],
            'delete' => [
                'label'       => __( 'Delete', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'players/{id}',
                'confirm'     => __( 'Delete this player? They will be archived.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'players',
            'columns' => [
                'last_name'     => [ 'label' => __( 'Name',   'talenttrack' ), 'sortable' => true, 'value_key' => 'name' ],
                'team_name'     => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true ],
                'jersey_number' => [ 'label' => __( '#',      'talenttrack' ), 'sortable' => true ],
                'preferred_foot'=> [ 'label' => __( 'Foot',   'talenttrack' ) ],
            ],
            'filters' => [
                'team_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Team', 'talenttrack' ),
                    'options' => TeamPickerComponent::filterOptions( $user_id, $is_admin ),
                ],
                'position' => [
                    'type'    => 'select',
                    'label'   => __( 'Position', 'talenttrack' ),
                    'options' => $position_options,
                ],
                'preferred_foot' => [
                    'type'    => 'select',
                    'label'   => __( 'Foot', 'talenttrack' ),
                    'options' => $foot_options,
                ],
                'age_group' => [
                    'type'    => 'select',
                    'label'   => __( 'Age group', 'talenttrack' ),
                    'options' => $age_group_options,
                ],
                'archived' => [
                    'type'    => 'select',
                    'label'   => __( 'Status', 'talenttrack' ),
                    'options' => [
                        'active'   => __( 'Active',   'talenttrack' ),
                        'archived' => __( 'Archived', 'talenttrack' ),
                    ],
                ],
            ],
            'row_actions'  => $row_actions,
            'search'       => [ 'placeholder' => __( 'Search by name…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'last_name', 'order' => 'asc' ],
            'empty_state'  => __( 'No players match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    /**
     * Create / edit form. PUT vs POST decided by `$player`.
     *
     * @param object|null $player
     */
    private static function renderForm( int $user_id, bool $is_admin, ?object $player ): void {
        // wp_enqueue_media() works on the frontend; the modal styling
        // imports a small chunk of wp-admin CSS, but it lays out
        // correctly. Confirmed during epic shaping — see Sprint 3 spec
        // "Media uploader on frontend".
        wp_enqueue_media();

        $is_edit   = $player !== null;
        $rest_path = $is_edit ? 'players/' . (int) $player->id : 'players';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-player-form';
        $draft_key = $is_edit ? '' : 'player-form';

        $teams      = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $positions  = QueryHelpers::get_lookup_names( 'position' );
        $foot_opts  = QueryHelpers::get_lookup_names( 'foot_option' );
        $current_positions = $player ? ( json_decode( (string) $player->preferred_positions, true ) ?: [] ) : [];

        $rate_card_url = $is_edit
            ? esc_url( add_query_arg( [ 'tt_view' => 'players', 'player_id' => (int) $player->id ], remove_query_arg( [ 'action', 'id' ] ) ) )
            : '';

        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>" data-redirect-after-save="list"<?php if ( $draft_key !== '' ) : ?> data-draft-key="<?php echo esc_attr( $draft_key ); ?>"<?php endif; ?>>
            <?php if ( $is_edit ) : ?>
                <p style="margin:0 0 var(--tt-sp-3, 12px);">
                    <a class="tt-btn tt-btn-secondary" href="<?php echo $rate_card_url; ?>"><?php esc_html_e( 'View rate card', 'talenttrack' ); ?></a>
                </p>
            <?php endif; ?>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-player-first"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-player-first" class="tt-input" name="first_name" required value="<?php echo esc_attr( (string) ( $player->first_name ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-player-last"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-player-last" class="tt-input" name="last_name" required value="<?php echo esc_attr( (string) ( $player->last_name ?? '' ) ); ?>" />
                </div>
                <?php echo DateInputComponent::render( [
                    'name'  => 'date_of_birth',
                    'label' => __( 'Date of birth', 'talenttrack' ),
                    'value' => (string) ( $player->date_of_birth ?? '' ),
                ] ); ?>
                <?php echo TeamPickerComponent::render( [
                    'name'     => 'team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'teams'    => $teams,
                    'selected' => (int) ( $player->team_id ?? 0 ),
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-jersey"><?php esc_html_e( 'Jersey number', 'talenttrack' ); ?></label>
                    <input type="number" id="tt-player-jersey" class="tt-input" name="jersey_number" min="1" max="999" value="<?php echo esc_attr( (string) ( $player->jersey_number ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-foot"><?php esc_html_e( 'Preferred foot', 'talenttrack' ); ?></label>
                    <select id="tt-player-foot" class="tt-input" name="preferred_foot">
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $foot_opts as $f ) : ?>
                            <option value="<?php echo esc_attr( (string) $f ); ?>" <?php selected( (string) ( $player->preferred_foot ?? '' ), (string) $f ); ?>><?php echo esc_html( (string) $f ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-height"><?php esc_html_e( 'Height (cm)', 'talenttrack' ); ?></label>
                    <input type="number" id="tt-player-height" class="tt-input" name="height_cm" min="100" max="250" value="<?php echo esc_attr( (string) ( $player->height_cm ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-weight"><?php esc_html_e( 'Weight (kg)', 'talenttrack' ); ?></label>
                    <input type="number" id="tt-player-weight" class="tt-input" name="weight_kg" min="20" max="200" value="<?php echo esc_attr( (string) ( $player->weight_kg ?? '' ) ); ?>" />
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label"><?php esc_html_e( 'Preferred positions', 'talenttrack' ); ?></label>
                <div class="tt-multitag-picker">
                    <?php foreach ( $positions as $pos ) :
                        $is_sel = in_array( (string) $pos, (array) $current_positions, true );
                        ?>
                        <label class="tt-multitag-option<?php echo $is_sel ? ' is-selected' : ''; ?>">
                            <input type="checkbox" name="preferred_positions[]" value="<?php echo esc_attr( (string) $pos ); ?>" <?php checked( $is_sel ); ?> style="display:none;" />
                            <?php echo esc_html( (string) $pos ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php // Photo (WP media uploader) ?>
            <div class="tt-field tt-player-photo">
                <span class="tt-field-label"><?php esc_html_e( 'Photo', 'talenttrack' ); ?></span>
                <input type="hidden" name="photo_url" id="tt-player-photo-url" value="<?php echo esc_attr( (string) ( $player->photo_url ?? '' ) ); ?>" />
                <div class="tt-player-photo-preview" id="tt-player-photo-preview" style="margin-bottom:8px;">
                    <?php if ( ! empty( $player->photo_url ) ) : ?>
                        <img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" style="max-height:120px; border-radius:6px; border:1px solid var(--tt-line);" />
                    <?php endif; ?>
                </div>
                <button type="button" class="tt-btn tt-btn-secondary" id="tt-player-photo-pick"><?php esc_html_e( 'Choose photo…', 'talenttrack' ); ?></button>
                <button type="button" class="tt-btn tt-btn-secondary" id="tt-player-photo-clear" style="margin-left:6px;"><?php esc_html_e( 'Remove', 'talenttrack' ); ?></button>
            </div>

            <?php self::renderCustomFields( (int) ( $player->id ?? 0 ) ); ?>

            <h4 style="margin: 18px 0 6px; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--tt-muted);">
                <?php esc_html_e( 'Parent / guardian', 'talenttrack' ); ?>
            </h4>
            <p class="tt-help-text" style="font-size:12px; color:var(--tt-muted, #5b6470); margin: 0 0 10px; max-width: 720px;">
                <?php
                if ( $is_edit ) {
                    self::renderLinkedParents( (int) $player->id );
                } else {
                    esc_html_e( 'Link a parent account after saving the player from the People page. Otherwise, fill in their name + contact below; a coach can convert them to a real account later.', 'talenttrack' );
                }
                ?>
            </p>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-guardian-name"><?php esc_html_e( 'Guardian name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-player-guardian-name" class="tt-input" name="guardian_name" value="<?php echo esc_attr( (string) ( $player->guardian_name ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-guardian-email"><?php esc_html_e( 'Guardian email', 'talenttrack' ); ?></label>
                    <input type="email" id="tt-player-guardian-email" class="tt-input" name="guardian_email" value="<?php echo esc_attr( (string) ( $player->guardian_email ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-player-guardian-phone"><?php esc_html_e( 'Guardian phone', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-player-guardian-phone" class="tt-input" name="guardian_phone" value="<?php echo esc_attr( (string) ( $player->guardian_phone ?? '' ) ); ?>" />
                </div>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update player', 'talenttrack' ) : __( 'Save player', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id', 'player_id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>

        <script>
        (function(){
            // Position multi-tag toggle (re-uses the multitag CSS without
            // the JS hydrator since we want raw checkboxes for FormData).
            document.querySelectorAll('.tt-player-photo + script, #<?php echo esc_attr( $form_id ); ?> .tt-multitag-picker .tt-multitag-option').forEach(function(opt){
                if (opt.tagName !== 'LABEL') return;
                var cb = opt.querySelector('input[type="checkbox"]');
                if (!cb) return;
                opt.addEventListener('click', function(e){
                    if (e.target === cb) return;
                    e.preventDefault();
                    cb.checked = !cb.checked;
                    opt.classList.toggle('is-selected', cb.checked);
                });
            });

            // wp.media uploader for the player photo.
            if (typeof wp !== 'undefined' && wp.media) {
                var frame;
                var pickBtn = document.getElementById('tt-player-photo-pick');
                var clearBtn = document.getElementById('tt-player-photo-clear');
                var hidden = document.getElementById('tt-player-photo-url');
                var preview = document.getElementById('tt-player-photo-preview');
                if (pickBtn) pickBtn.addEventListener('click', function(){
                    if (!frame) {
                        frame = wp.media({
                            title: '<?php echo esc_js( __( 'Select photo', 'talenttrack' ) ); ?>',
                            button: { text: '<?php echo esc_js( __( 'Use', 'talenttrack' ) ); ?>' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function(){
                            var att = frame.state().get('selection').first().toJSON();
                            hidden.value = att.url;
                            preview.innerHTML = '<img src="' + att.url + '" alt="" style="max-height:120px; border-radius:6px; border:1px solid var(--tt-line);" />';
                            hidden.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    }
                    frame.open();
                });
                if (clearBtn) clearBtn.addEventListener('click', function(){
                    hidden.value = '';
                    preview.innerHTML = '';
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Render custom fields for the player entity, prefilled if editing.
     */
    private static function renderCustomFields( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( ! $fields ) return;

        $values = $player_id > 0
            ? ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id )
            : [];

        echo '<h3 style="margin:24px 0 12px;">' . esc_html__( 'Additional information', 'talenttrack' ) . '</h3>';
        foreach ( $fields as $field ) {
            $key   = (string) $field->field_key;
            $value = $values[ $key ] ?? null;
            $required = ! empty( $field->is_required );

            echo '<div class="tt-field">';
            echo '<label class="tt-field-label' . ( $required ? ' tt-field-required' : '' ) . '" for="tt_cf_' . esc_attr( $key ) . '">';
            echo esc_html( (string) $field->label );
            echo '</label>';
            echo CustomFieldRenderer::input( $field, $value );
            echo '</div>';
        }
    }

    /**
     * Detail / rate-card view — preserves the v3.0.0 deep-link surface
     * at `?tt_view=players&player_id=N`.
     */
    private static function renderDetail( object $player ): void {
        $list_url  = add_query_arg( [ 'tt_view' => 'players' ], remove_query_arg( [ 'tt_view', 'player_id', 'id', 'action' ] ) );
        $edit_url  = add_query_arg( [ 'tt_view' => 'players', 'id' => (int) $player->id ], remove_query_arg( [ 'tt_view', 'player_id' ] ) );
        $print_url = add_query_arg( [ 'tt_print' => (int) $player->id ], remove_query_arg( [ 'tt_view', 'player_id' ] ) );
        ?>
        <p class="tt-back-link" style="margin:6px 0 12px;">
            <a href="<?php echo esc_url( $list_url ); ?>" style="text-decoration:none; color:#555; font-size:14px;">
                <?php esc_html_e( '← Back to players', 'talenttrack' ); ?>
            </a>
        </p>
        <h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">
            <?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?>
        </h1>
        <div style="margin-bottom:10px; display:flex; gap:8px; flex-wrap:wrap;">
            <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit player', 'talenttrack' ); ?></a>
            <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '🖨 Print report', 'talenttrack' ); ?></a>
        </div>

        <?php
        $max = (float) QueryHelpers::get_config( 'rating_max', '5' );
        ?>
        <div style="display:flex; gap:30px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
            </div>
            <div style="flex:1; min-width:280px;">
                <?php self::renderPlayerFacts( $player ); ?>
                <?php self::renderCustomFieldsBlock( (int) $player->id ); ?>
                <?php
                $r = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
                if ( ! empty( $r['datasets'] ) ) {
                    echo '<div class="tt-radar-wrap" style="margin-top:16px;">'
                        . QueryHelpers::radar_chart_svg( $r['labels'], $r['datasets'], $max )
                        . '</div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function renderPlayerFacts( object $player ): void {
        $pos  = json_decode( (string) $player->preferred_positions, true );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        ?>
        <div class="tt-card">
            <?php if ( ! empty( $player->photo_url ) ) : ?>
                <div class="tt-card-thumb"><img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" /></div>
            <?php endif; ?>
            <div class="tt-card-body">
                <h3><?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?></h3>
                <?php if ( $team ) : ?>
                    <p><strong><?php esc_html_e( 'Team:', 'talenttrack' ); ?></strong> <?php echo esc_html( (string) $team->name ); ?></p>
                <?php endif; ?>
                <?php if ( is_array( $pos ) && $pos ) : ?>
                    <p><strong><?php esc_html_e( 'Pos:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $pos ) ); ?></p>
                <?php endif; ?>
                <?php if ( $player->preferred_foot ) : ?>
                    <p><strong><?php esc_html_e( 'Foot:', 'talenttrack' ); ?></strong> <?php echo esc_html( (string) $player->preferred_foot ); ?></p>
                <?php endif; ?>
                <?php if ( $player->jersey_number ) : ?>
                    <p><strong>#</strong><?php echo esc_html( (string) $player->jersey_number ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderCustomFieldsBlock( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) return;
        $values = ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id );

        $has_any = false;
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v !== null && $v !== '' ) { $has_any = true; break; }
        }
        if ( ! $has_any ) return;

        echo '<div class="tt-custom-fields" style="margin-top:12px;">';
        echo '<h4>' . esc_html__( 'Additional information', 'talenttrack' ) . '</h4>';
        echo '<dl class="tt-custom-fields-list">';
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v === null || $v === '' ) continue;
            echo '<dt>' . esc_html( (string) $f->label ) . '</dt>';
            echo '<dd>' . CustomFieldRenderer::display( $f, $v ) . '</dd>';
        }
        echo '</dl>';
        echo '</div>';
    }

    private static function loadPlayer( int $id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 'p', 'player' );
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.* FROM {$p}tt_players p WHERE p.id = %d AND p.archived_at IS NULL {$scope}",
            $id
        ) );
        return $row ?: null;
    }

    /**
     * Read-only summary of WP user accounts currently linked to this
     * player as parents (#0008). Linking + unlinking happens on the
     * People page; the inline guardian_* fields below stay the path
     * for "this parent doesn't have an account yet".
     */
    private static function renderLinkedParents( int $player_id ): void {
        if ( $player_id <= 0 ) return;

        $repo = new \TT\Modules\Invitations\PlayerParentsRepository();
        $parent_ids = $repo->parentsForPlayer( $player_id );

        if ( empty( $parent_ids ) ) {
            esc_html_e( 'No parent accounts linked yet. Either link an existing user from the People page, or fill in the contact details below.', 'talenttrack' );
            return;
        }

        $names = [];
        foreach ( $parent_ids as $uid ) {
            $u = get_userdata( (int) $uid );
            if ( $u ) $names[] = sprintf( '%s (%s)', $u->display_name, $u->user_email );
        }
        if ( empty( $names ) ) return;

        printf(
            /* translators: %s is a comma-separated list of "Name (email)" entries. */
            esc_html__( 'Linked parent accounts: %s. Manage from People → user → Edit. The contact fields below are still useful for any parent without an account.', 'talenttrack' ),
            esc_html( implode( ', ', $names ) )
        );
    }
}
