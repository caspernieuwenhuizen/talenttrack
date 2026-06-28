<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendMeasurementTestsView (#2121, epic #2116) — the "Manage tests"
 * configuration surface for the test catalogue.
 *
 * Lists every test definition (name, category, unit, direction, cadence,
 * active state). Create runs through the existing `measurement` wizard
 * (§3 wizard-first); edit is a flat form with Save + Cancel (§6) covering
 * the definition fields plus per-age-group target bands. Row actions:
 * activate / deactivate and soft-archive (recycle-bin pattern).
 *
 * Slug: `measurement-tests`. Matrix-gated on `measurement_definitions`
 * change. Composition only — all list / band / archive logic lives in the
 * repositories + ArchiveRepository (§4); the same domain layer the REST
 * controller (#2120) consumes, so a future SaaS front end gets identical
 * answers.
 */
final class FrontendMeasurementTestsView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_measurement_tests';
    public const NONCE_FIELD  = '_tt_measurement_tests_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Manage tests', 'talenttrack' );

        if ( ! MatrixGate::canAnyScope( $user_id, 'measurement_definitions', 'change' ) && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage tests.', 'talenttrack' ) . '</p>';
            return;
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        // POST handling (edit save, toggle active, archive). Always
        // re-renders the list afterwards via a flash + redirect-free
        // refresh of the current request.
        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $result = self::handlePost();
            $flash  = $result['flash'];
            if ( $result['back_to_list'] ) {
                $action = 'list';
                $id     = 0;
            }
        }

        self::enqueueAssets();
        self::enqueueViewCss();

        if ( $action === 'edit' && $id > 0 ) {
            self::renderEdit( $id, $title );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $flash ) . '</div>';
        }

        self::renderModuleLinks();
        self::renderList();
    }

    /**
     * In-body action links for the test catalogue: the "+ New test" wizard
     * entry plus back-aware cross-links to the two execution surfaces so
     * staff move Configure → Record → Review without the dashboard (§5
     * back-pill via BackLink::appendTo). Not breadcrumb / back chrome —
     * these are in-body actions, so they do not breach the two-affordance
     * contract.
     */
    private static function renderModuleLinks(): void {
        $base = RecordLink::dashboardUrl();

        echo '<div class="tt-mt-links">';
        self::renderNewTestButton();

        $record_url = BackLink::appendTo( add_query_arg( [ 'tt_view' => 'measurements-entry' ], $base ) );
        echo '<a class="tt-btn tt-btn-secondary tt-mt-link" href="' . esc_url( $record_url ) . '">'
            . esc_html__( 'Record measurements', 'talenttrack' ) . '</a>';

        $coverage_url = BackLink::appendTo( add_query_arg( [ 'tt_view' => 'measurements-coverage' ], $base ) );
        echo '<a class="tt-btn tt-btn-secondary tt-mt-link" href="' . esc_url( $coverage_url ) . '">'
            . esc_html__( 'Testing coverage', 'talenttrack' ) . '</a>';
        echo '</div>';
    }

    // ── list ────────────────────────────────────────────────────────

    private static function renderList(): void {
        $definitions = ( new MeasurementDefinitionsRepository() )->listAll( true );

        if ( empty( $definitions ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No tests have been set up yet. Use “+ New test” to create the first one.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-mt-list">';
        foreach ( $definitions as $def ) {
            self::renderRow( $def );
        }
        echo '</ul>';
    }

    private static function renderRow( object $def ): void {
        $id       = (int) $def->id;
        $active   = (int) $def->is_active === 1;
        $category = (string) ( $def->category_label ?: $def->category_name ?: '' );
        $meta     = self::rowMeta( $def );

        $edit_url = BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'measurement-tests', 'action' => 'edit', 'id' => $id ],
            RecordLink::dashboardUrl()
        ) );

        echo '<li class="tt-mt-row' . ( $active ? '' : ' tt-mt-row--inactive' ) . '">';

        echo '<div class="tt-mt-row__main">';
        echo '<a class="tt-mt-row__name" href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $def->name ) . '</a>';
        if ( $category !== '' ) {
            echo '<span class="tt-mt-row__cat">' . esc_html( $category ) . '</span>';
        }
        if ( $meta !== '' ) {
            echo '<span class="tt-mt-row__meta">' . esc_html( $meta ) . '</span>';
        }
        echo '<span class="tt-mt-chip tt-mt-chip--' . ( $active ? 'on' : 'off' ) . '">'
            . esc_html( $active ? __( 'Active', 'talenttrack' ) : __( 'Inactive', 'talenttrack' ) )
            . '</span>';
        echo '</div>';

        echo '<div class="tt-mt-row__actions">';
        echo '<a class="tt-btn tt-btn-secondary tt-mt-action" href="' . esc_url( $edit_url ) . '">'
            . esc_html__( 'Edit', 'talenttrack' ) . '</a>';

        // Toggle active — small POST form so the state change is auditable
        // server-side and needs no JS.
        echo '<form method="post" class="tt-mt-inline-form">';
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        echo '<input type="hidden" name="op" value="toggle" />';
        echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
        echo '<input type="hidden" name="to" value="' . ( $active ? '0' : '1' ) . '" />';
        echo '<button type="submit" class="tt-btn tt-btn-secondary tt-mt-action">'
            . esc_html( $active ? __( 'Deactivate', 'talenttrack' ) : __( 'Activate', 'talenttrack' ) )
            . '</button>';
        echo '</form>';

        // Archive — soft delete via the recycle-bin pattern.
        echo '<form method="post" class="tt-mt-inline-form">';
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        echo '<input type="hidden" name="op" value="archive" />';
        echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
        echo '<button type="submit" class="tt-btn tt-btn-secondary tt-mt-action tt-mt-action--archive">'
            . esc_html__( 'Archive', 'talenttrack' ) . '</button>';
        echo '</form>';

        echo '</div>';
        echo '</li>';
    }

    private static function rowMeta( object $def ): string {
        $parts = [];
        $unit  = (string) ( $def->unit ?? '' );
        if ( $unit !== '' ) {
            $parts[] = $unit;
        }
        $dir = self::directionLabel( (string) $def->direction );
        if ( $dir !== '' ) {
            $parts[] = $dir;
        }
        $freq = self::frequencyLabel( (string) $def->frequency );
        if ( $freq !== '' ) {
            $parts[] = $freq;
        }
        return implode( ' · ', $parts );
    }

    // ── edit ────────────────────────────────────────────────────────

    private static function renderEdit( int $id, string $list_title ): void {
        $repo = new MeasurementDefinitionsRepository();
        $def  = $repo->find( $id );

        $title = __( 'Edit test', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title, [
            FrontendBreadcrumbs::viewCrumb( 'measurement-tests', $list_title ),
        ] );

        if ( ! $def ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'That test could not be found.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderHeader( $title . ' — ' . (string) $def->name );

        $value_type = (string) $def->value_type;
        $categories = QueryHelpers::get_lookups( 'measurement_category' );
        $units      = QueryHelpers::get_lookups( 'measurement_unit' );
        $age_groups = QueryHelpers::get_lookups( 'age_group' );

        $unit_names = array_map( static fn ( $r ) => (string) $r->name, $units );
        $unit       = (string) ( $def->unit ?? '' );
        $unit_listed = in_array( $unit, $unit_names, true );

        $targets = [];
        foreach ( ( new MeasurementTargetsRepository() )->listForDefinition( $id ) as $t ) {
            $targets[ (string) $t->age_group ] = $t;
        }

        // Cancel target: list view, unless tt_back captured a referrer.
        $list_url   = add_query_arg( [ 'tt_view' => 'measurement-tests' ], RecordLink::dashboardUrl() );
        $back       = BackLink::resolve();
        $cancel_url = $back !== null ? $back['url'] : $list_url;
        ?>
        <form method="post" class="tt-mt-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="op" value="edit" />
            <input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-name"><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-mt-name" class="tt-input" name="test_name" maxlength="190" required
                       value="<?php echo esc_attr( (string) $def->name ); ?>" />
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-category"><?php esc_html_e( 'Category', 'talenttrack' ); ?></label>
                <select id="tt-mt-category" class="tt-input" name="category_id">
                    <option value="0"><?php esc_html_e( '— choose —', 'talenttrack' ); ?></option>
                    <?php foreach ( $categories as $c ) : ?>
                        <option value="<?php echo (int) $c->id; ?>"<?php selected( (int) $def->category_id, (int) $c->id ); ?>>
                            <?php echo esc_html( LookupTranslator::name( $c ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-value-type"><?php esc_html_e( 'Value type', 'talenttrack' ); ?></label>
                <select id="tt-mt-value-type" class="tt-input" name="value_type">
                    <?php foreach ( self::valueTypes() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $value_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-unit"><?php esc_html_e( 'Unit', 'talenttrack' ); ?></label>
                <select id="tt-mt-unit" class="tt-input" name="unit">
                    <option value=""><?php esc_html_e( '— none —', 'talenttrack' ); ?></option>
                    <?php foreach ( $units as $u ) :
                        $uname = (string) $u->name; ?>
                        <option value="<?php echo esc_attr( $uname ); ?>"<?php selected( $unit_listed ? $unit : '', $uname ); ?>>
                            <?php echo esc_html( LookupTranslator::name( $u ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-unit-custom"><?php esc_html_e( 'Custom unit (overrides the list)', 'talenttrack' ); ?></label>
                <input type="text" id="tt-mt-unit-custom" class="tt-input" name="unit_custom" maxlength="50"
                       value="<?php echo esc_attr( ! $unit_listed ? $unit : '' ); ?>"
                       placeholder="<?php esc_attr_e( 'e.g. watt/kg', 'talenttrack' ); ?>" />
            </div>

            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mt-scale-min"><?php esc_html_e( 'Scale minimum', 'talenttrack' ); ?></label>
                    <input type="number" step="any" inputmode="decimal" id="tt-mt-scale-min" class="tt-input" name="scale_min"
                           value="<?php echo esc_attr( $def->scale_min !== null ? (string) $def->scale_min : '' ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-mt-scale-max"><?php esc_html_e( 'Scale maximum', 'talenttrack' ); ?></label>
                    <input type="number" step="any" inputmode="decimal" id="tt-mt-scale-max" class="tt-input" name="scale_max"
                           value="<?php echo esc_attr( $def->scale_max !== null ? (string) $def->scale_max : '' ); ?>" />
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-direction"><?php esc_html_e( 'Direction', 'talenttrack' ); ?></label>
                <select id="tt-mt-direction" class="tt-input" name="direction">
                    <?php foreach ( self::directions() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"<?php selected( (string) $def->direction, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-mt-frequency"><?php esc_html_e( 'Recurrence', 'talenttrack' ); ?></label>
                <select id="tt-mt-frequency" class="tt-input" name="frequency">
                    <?php foreach ( self::frequencies() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"<?php selected( (string) $def->frequency, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-field tt-field--check">
                <label class="tt-mt-check" for="tt-mt-active">
                    <input type="checkbox" id="tt-mt-active" name="is_active" value="1"<?php checked( (int) $def->is_active, 1 ); ?> />
                    <span><?php esc_html_e( 'Active (available when recording results)', 'talenttrack' ); ?></span>
                </label>
            </div>

            <?php if ( $value_type !== 'passfail' && ! empty( $age_groups ) ) : ?>
                <fieldset class="tt-mt-targets">
                    <legend><?php esc_html_e( 'Target bands per age group', 'talenttrack' ); ?></legend>
                    <p class="tt-mt-targets__hint">
                        <?php esc_html_e( 'Optional. The green band is on target and the amber band is a warning; anything outside flags red. Leave blank to skip an age group.', 'talenttrack' ); ?>
                    </p>
                    <?php foreach ( $age_groups as $ag ) :
                        $name   = (string) $ag->name;
                        $label  = LookupTranslator::name( $ag );
                        $band   = $targets[ $name ] ?? null;
                    ?>
                        <div class="tt-mt-target-set">
                            <h3 class="tt-mt-target-set__label"><?php echo esc_html( $label ); ?></h3>
                            <div class="tt-grid tt-grid-2">
                                <?php
                                self::targetField( $name, 'green_min', __( 'Green from', 'talenttrack' ), $band );
                                self::targetField( $name, 'green_max', __( 'Green to', 'talenttrack' ), $band );
                                self::targetField( $name, 'amber_min', __( 'Amber from', 'talenttrack' ), $band );
                                self::targetField( $name, 'amber_max', __( 'Amber to', 'talenttrack' ), $band );
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <?php
            echo FormSaveButton::render( [
                'label'      => __( 'Save test', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </form>
        <?php
    }

    private static function targetField( string $ag, string $key, string $label, ?object $band ): void {
        $fid = 'tt-mt-band-' . sanitize_html_class( $ag . '-' . $key );
        $val = ( $band && isset( $band->{$key} ) && $band->{$key} !== null ) ? (string) $band->{$key} : '';
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . '</label>';
        echo '<input type="number" step="any" inputmode="decimal" id="' . esc_attr( $fid ) . '" class="tt-input" '
            . 'name="band[' . esc_attr( $ag ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" />';
        echo '</div>';
    }

    // ── POST handling ───────────────────────────────────────────────

    /**
     * @return array{flash:string, back_to_list:bool}
     */
    private static function handlePost(): array {
        $op = isset( $_POST['op'] ) ? sanitize_key( (string) $_POST['op'] ) : '';
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( $id <= 0 ) {
            return [ 'flash' => '', 'back_to_list' => false ];
        }

        $repo = new MeasurementDefinitionsRepository();
        if ( ! $repo->find( $id ) ) {
            return [ 'flash' => __( 'That test could not be found.', 'talenttrack' ), 'back_to_list' => true ];
        }

        switch ( $op ) {
            case 'toggle':
                $to = isset( $_POST['to'] ) ? (int) (bool) absint( $_POST['to'] ) : 0;
                $repo->update( $id, [ 'is_active' => $to ] );
                return [
                    'flash'        => $to === 1
                        ? __( 'Test activated.', 'talenttrack' )
                        : __( 'Test deactivated.', 'talenttrack' ),
                    'back_to_list' => true,
                ];

            case 'archive':
                $archived = ( new ArchiveRepository() )->archive( 'measurement_definition', [ $id ], get_current_user_id() );
                return [
                    'flash'        => $archived > 0
                        ? __( 'Test archived.', 'talenttrack' )
                        : __( 'That test could not be archived.', 'talenttrack' ),
                    'back_to_list' => true,
                ];

            case 'edit':
                self::saveEdit( $repo, $id );
                return [ 'flash' => __( 'Test saved.', 'talenttrack' ), 'back_to_list' => true ];
        }

        return [ 'flash' => '', 'back_to_list' => false ];
    }

    private static function saveEdit( MeasurementDefinitionsRepository $repo, int $id ): void {
        $name        = sanitize_text_field( wp_unslash( (string) ( $_POST['test_name'] ?? '' ) ) );
        $value_type  = self::safeIn( (string) ( $_POST['value_type'] ?? 'numeric' ), [ 'numeric', 'scale', 'passfail' ], 'numeric' );

        $unit_listed = sanitize_text_field( wp_unslash( (string) ( $_POST['unit'] ?? '' ) ) );
        $unit_custom = sanitize_text_field( wp_unslash( (string) ( $_POST['unit_custom'] ?? '' ) ) );
        $unit        = $unit_custom !== '' ? $unit_custom : $unit_listed;

        $direction = self::safeIn( (string) ( $_POST['direction'] ?? 'higher' ), [ 'higher', 'lower', 'neutral' ], 'higher' );
        $frequency = self::safeIn( (string) ( $_POST['frequency'] ?? 'adhoc' ), [ 'annual', 'biannual', 'quarterly', 'monthly', 'adhoc' ], 'adhoc' );

        $scale_min = self::numOrNull( $_POST['scale_min'] ?? '' );
        $scale_max = self::numOrNull( $_POST['scale_max'] ?? '' );

        $data = [
            'category_id' => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
            'value_type'  => $value_type,
            'unit'        => $value_type === 'numeric' ? $unit : '',
            'scale_min'   => $scale_min,
            'scale_max'   => $scale_max,
            'direction'   => $value_type === 'numeric' ? $direction : 'neutral',
            'frequency'   => $frequency,
            'is_active'   => isset( $_POST['is_active'] ) ? 1 : 0,
        ];
        if ( $name !== '' ) {
            $data['name'] = $name;
        }
        $repo->update( $id, $data );

        // Targets — pass/fail tests carry no bands.
        if ( $value_type !== 'passfail' && isset( $_POST['band'] ) && is_array( $_POST['band'] ) ) {
            $targets_repo = new MeasurementTargetsRepository();
            $bands        = wp_unslash( $_POST['band'] );
            foreach ( $bands as $age_group => $row ) {
                if ( ! is_array( $row ) ) continue;
                $age_group = sanitize_text_field( (string) $age_group );
                if ( $age_group === '' ) continue;
                $targets_repo->upsert( $id, $age_group, [
                    'green_min' => self::numOrNull( $row['green_min'] ?? '' ),
                    'green_max' => self::numOrNull( $row['green_max'] ?? '' ),
                    'amber_min' => self::numOrNull( $row['amber_min'] ?? '' ),
                    'amber_max' => self::numOrNull( $row['amber_max'] ?? '' ),
                ] );
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * @param mixed $raw
     * @return float|null
     */
    private static function numOrNull( $raw ): ?float {
        $raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
        return ( $raw !== '' && is_numeric( $raw ) ) ? (float) $raw : null;
    }

    /**
     * @param string[] $allowed
     */
    private static function safeIn( string $value, array $allowed, string $fallback ): string {
        $value = sanitize_text_field( wp_unslash( $value ) );
        return in_array( $value, $allowed, true ) ? $value : $fallback;
    }

    /** @return array<string, string> */
    private static function valueTypes(): array {
        return [
            'numeric'  => __( 'A number (with a unit)', 'talenttrack' ),
            'scale'    => __( 'A scale score', 'talenttrack' ),
            'passfail' => __( 'Pass / fail', 'talenttrack' ),
        ];
    }

    /** @return array<string, string> */
    private static function directions(): array {
        return [
            'higher'  => __( 'Higher is better', 'talenttrack' ),
            'lower'   => __( 'Lower is better', 'talenttrack' ),
            'neutral' => __( 'Neither (just track it)', 'talenttrack' ),
        ];
    }

    /** @return array<string, string> */
    private static function frequencies(): array {
        return [
            'annual'    => __( 'Once a season', 'talenttrack' ),
            'biannual'  => __( 'Twice a season', 'talenttrack' ),
            'quarterly' => __( 'Four times a season', 'talenttrack' ),
            'monthly'   => __( 'Monthly', 'talenttrack' ),
            'adhoc'     => __( 'No fixed cadence', 'talenttrack' ),
        ];
    }

    private static function directionLabel( string $direction ): string {
        $map = self::directions();
        return $map[ $direction ] ?? '';
    }

    private static function frequencyLabel( string $frequency ): string {
        $map = self::frequencies();
        return $map[ $frequency ] ?? '';
    }

    /**
     * The "+ New test" entry point — launches the wizard. Only rendered
     * when the wizard is available; WizardEntryPoint returns the fallback
     * otherwise, which we suppress.
     */
    private static function renderNewTestButton(): void {
        if ( ! class_exists( '\TT\Shared\Wizards\WizardRegistry' )
             || ! \TT\Shared\Wizards\WizardRegistry::isAvailable( 'measurement' ) ) {
            return;
        }
        $url = \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'measurement', '' );
        if ( $url === '' ) return;
        echo '<a class="tt-btn tt-btn-primary tt-mt-link" href="' . esc_url( $url ) . '">'
            . esc_html__( '+ New test', 'talenttrack' ) . '</a>';
    }

    private static function enqueueViewCss(): void {
        wp_enqueue_style(
            'tt-frontend-measurement-tests',
            TT_PLUGIN_URL . 'assets/css/frontend-measurement-tests.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }
}
