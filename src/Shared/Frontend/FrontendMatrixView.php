<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\Admin\MatrixEntityCatalog;
use TT\Modules\Authorization\Matrix\MatrixEditService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendMatrixView (#2654) — `?tt_view=matrix`.
 *
 * ## Why an academy admin needs this
 *
 * The matrix decides which staff persona can open a player's evaluations,
 * notes and medical fields. Until now it could only be edited from
 * wp-admin, by a WordPress administrator. An academy without one on hand
 * could not correct an over-broad or too-narrow grant at all — the person
 * accountable for safeguarding had to wait on somebody else to fix a
 * safeguarding-relevant setting.
 *
 * ## What a club admin may not do
 *
 * Everything except the parts of the matrix that decide who may edit the
 * matrix. Their own persona row and a small protected entity set render
 * disabled with the reason on the cell, and `MatrixEditService` rejects
 * them again on write — the disabled attribute is a courtesy to the
 * person looking at the screen, not the control.
 *
 * A WordPress administrator sees the same screen with nothing disabled,
 * and wp-admin stays exactly as it was. That page is the recovery path: a
 * matrix edit can hide the frontend surfaces that lead back to the
 * matrix, and something has to survive that.
 *
 * ## Not delegated
 *
 * The `tt_authorization_active` bridge switch, the seed export/import
 * round-trip, and reset-to-defaults stay administrator-only in wp-admin.
 * Editing the grid is a day-to-day correction; switching the whole
 * authorization model on or off, or discarding every edit anybody made,
 * is not.
 */
class FrontendMatrixView extends FrontendViewBase {

    public const CAP  = 'tt_manage_authorization';
    public const SLUG = 'matrix';

    /** Cap-ensure, save handler, config tile, REST. Called from Kernel::boot. */
    public static function init(): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        add_action( 'admin_post_tt_matrix_frontend_save', [ self::class, 'handleSave' ] );
        add_filter( 'tt_config_tile_groups', [ self::class, 'addConfigTile' ], 10, 1 );
        add_action( 'rest_api_init', [ self::class, 'registerRest' ] );
    }

    /**
     * Grant `tt_manage_authorization` to administrator + tt_club_admin.
     * Idempotent, and the same pattern #1451 used for `tt_manage_modules`.
     */
    public static function ensureCapabilities(): void {
        foreach ( [ 'administrator', 'tt_club_admin' ] as $role_key ) {
            $role = get_role( $role_key );
            if ( $role && ! $role->has_cap( self::CAP ) ) {
                $role->add_cap( self::CAP );
            }
        }
    }

    public static function registerRest(): void {
        \TT\Modules\Authorization\Rest\MatrixRestController::register();
    }

    // ---- render -----------------------------------------------------------

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to edit the authorization matrix.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-matrix',
            TT_PLUGIN_URL . 'assets/css/frontend-matrix.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-matrix',
            TT_PLUGIN_URL . 'assets/js/frontend-matrix.js',
            [],
            TT_VERSION,
            true
        );

        FrontendBreadcrumbs::fromDashboard( __( 'Authorization matrix', 'talenttrack' ) );
        self::renderHeader( __( 'Authorization matrix', 'talenttrack' ) );

        $repo     = new MatrixRepository();
        $personas = $repo->personas();
        $entities = $repo->entities();
        $grid     = $repo->asGrid();
        $editable = MatrixEditService::editableFor( $user_id );

        self::renderIntro();
        self::renderDormantNotice();
        self::renderGuardrailNotice( $editable );
        self::renderSavedNotice();
        self::renderSearch();

        ?>
        <form id="tt-matrix-form" class="tt-matrix-form" method="post"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_matrix_frontend_save', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_matrix_frontend_save" />
            <input type="hidden" name="tt_back" value="<?php echo esc_attr( self::backParam() ); ?>" />

            <?php self::renderGrid( $personas, $entities, $grid, $editable ); ?>

            <p class="tt-matrix-empty" hidden>
                <?php esc_html_e( 'No entity matches your search.', 'talenttrack' ); ?>
            </p>

            <div class="tt-matrix-actions">
                <span class="tt-matrix-dirty" data-tt-matrix-dirty hidden>
                    <?php esc_html_e( 'Unsaved changes', 'talenttrack' ); ?>
                </span>
                <?php
                echo FormSaveButton::render( [
                    'label'      => __( 'Save', 'talenttrack' ),
                    'cancel_url' => self::cancelUrl(),
                ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
                ?>
            </div>
        </form>
        <?php
    }

    private static function renderIntro(): void {
        echo '<p class="tt-matrix-intro">'
            . esc_html__( 'What each persona may do with each kind of record. R is read, C is change, D is create and delete. Scope narrows a grant: global applies everywhere, while team and player also require the user to hold that assignment.', 'talenttrack' )
            . '</p>';
    }

    /**
     * The matrix can be edited while the bridge that applies it is off, in
     * which case nothing the user does here has any runtime effect. Saying
     * so is the difference between "my edit did not work" being a bug
     * report and being a setting.
     *
     * The switch itself is not delegated, so this points at wp-admin
     * rather than offering a button somebody may not press.
     */
    private static function renderDormantNotice(): void {
        if ( self::matrixActive() ) return;

        echo '<p class="tt-notice tt-notice-warning tt-matrix-dormant">'
            . '<strong>' . esc_html__( 'The matrix is dormant.', 'talenttrack' ) . '</strong> '
            . esc_html__( 'Your edits are saved but decide nothing yet: WordPress capabilities are answering instead. A WordPress administrator switches the matrix on under Authorization in wp-admin.', 'talenttrack' )
            . '</p>';
    }

    /**
     * @param array{unrestricted:bool, protected_entities:list<string>, protected_personas:list<string>} $editable
     */
    private static function renderGuardrailNotice( array $editable ): void {
        if ( ! empty( $editable['unrestricted'] ) ) return;

        echo '<p class="tt-notice tt-notice-info tt-matrix-guardrail">'
            . esc_html__( 'Some cells are locked: your own persona, and the settings that decide who may edit permissions, schema and backups. Everything else is yours to change. A WordPress administrator can edit the locked cells in wp-admin.', 'talenttrack' )
            . '</p>';
    }

    private static function renderSavedNotice(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';
        if ( $msg !== 'saved' ) return;

        echo '<p class="tt-notice tt-notice-success tt-matrix-saved">'
            . esc_html__( 'Matrix saved. Reload other open tabs to see the effect.', 'talenttrack' )
            . '</p>';
    }

    private static function renderSearch(): void {
        ?>
        <div class="tt-matrix-search" role="search">
            <label for="tt-matrix-search-input" class="tt-screen-reader-text">
                <?php esc_html_e( 'Search entities', 'talenttrack' ); ?>
            </label>
            <input type="search" id="tt-matrix-search-input" class="tt-matrix-search-input"
                   inputmode="search" autocomplete="off" spellcheck="false"
                   aria-controls="tt-matrix-form"
                   placeholder="<?php esc_attr_e( 'Search entities…', 'talenttrack' ); ?>" />
            <span class="tt-matrix-search-count" data-tt-matrix-count aria-live="polite"></span>
        </div>
        <?php
    }

    /**
     * The grid, inside its own scroll container.
     *
     * A persona × entity matrix cannot be made to fit 360px, and pretending
     * otherwise produces a grid nobody can read. So the container scrolls
     * horizontally and the page does not (CLAUDE.md §2), the entity column
     * stays put while the personas move under it, and the phone gets the
     * banner saying this is easier on a laptop.
     *
     * @param list<string>                                    $personas
     * @param list<array{entity:string, module_class:string}> $entities
     * @param array<string, array<string, array<string, array{scope_kind:string, is_default:int}>>> $grid
     * @param array{unrestricted:bool, protected_entities:list<string>, protected_personas:list<string>} $editable
     */
    private static function renderGrid( array $personas, array $entities, array $grid, array $editable ): void {
        $activities = [
            'read'          => _x( 'R', 'matrix column abbreviation for Read', 'talenttrack' ),
            'change'        => _x( 'C', 'matrix column abbreviation for Change', 'talenttrack' ),
            'create_delete' => _x( 'D', 'matrix column abbreviation for Delete', 'talenttrack' ),
        ];
        $user_id = get_current_user_id();
        $grouped = MatrixEntityCatalog::groupByCategory( $entities );
        ?>
        <div class="tt-matrix-scroll" tabindex="0" role="region"
             aria-label="<?php esc_attr_e( 'Authorization matrix', 'talenttrack' ); ?>">
            <table class="tt-matrix-table">
                <thead>
                    <tr>
                        <th scope="col" class="tt-matrix-th-entity"><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                        <?php foreach ( $personas as $persona ) : ?>
                            <th scope="col"><?php echo esc_html( MatrixEditService::personaLabel( $persona ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $grouped as $category => $rows ) : ?>
                    <?php
                    /*
                     * The category band doubles as the column legend.
                     *
                     * A `position: sticky` header row cannot pin to the
                     * viewport from inside a horizontal scroll container:
                     * `overflow-x: auto` forces `overflow-y` to `auto` too,
                     * so the header sticks to a scrollport that never
                     * scrolls once the grid's own vertical scrollbar is
                     * gone. Repeating the persona names on every category
                     * band gives the same answer — "which persona is this
                     * column?" — without a second scrollbar to reach it.
                     */
                    ?>
                    <tr class="tt-matrix-cat">
                        <th scope="row" class="tt-matrix-entity tt-matrix-cat-name">
                            <?php echo esc_html( (string) $category ); ?>
                        </th>
                        <?php foreach ( $personas as $persona ) : ?>
                            <th scope="col" class="tt-matrix-cat-persona">
                                <?php echo esc_html( MatrixEditService::personaLabel( $persona ) ); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <?php foreach ( $rows as $row ) :
                        $entity    = (string) $row['entity'];
                        $label     = MatrixEntityCatalog::entityLabel( $entity );
                        $module    = MatrixEntityCatalog::shortModule( (string) $row['module_class'] );
                        $haystack  = strtolower( $entity . ' ' . $label . ' ' . $module );
                        $scope_id  = 'tt-matrix-scope-' . sanitize_html_class( $entity );
                        ?>
                        <tr data-tt-matrix-haystack="<?php echo esc_attr( $haystack ); ?>">
                            <th scope="row" class="tt-matrix-entity">
                                <span class="tt-matrix-entity-label"><?php echo esc_html( $label ); ?></span>
                                <code class="tt-matrix-entity-slug"><?php echo esc_html( $entity ); ?></code>
                                <button type="button" class="tt-matrix-scope-toggle"
                                        data-tt-matrix-scope-toggle="<?php echo esc_attr( $scope_id ); ?>"
                                        aria-controls="<?php echo esc_attr( $scope_id ); ?>"
                                        aria-expanded="true" hidden>
                                    <?php echo esc_html( _x( 'Scope', 'matrix per-entity scope row', 'talenttrack' ) ); ?>
                                </button>
                            </th>
                            <?php foreach ( $personas as $persona ) :
                                self::renderCell(
                                    $persona,
                                    $entity,
                                    $grid[ $persona ][ $entity ] ?? [],
                                    $activities,
                                    MatrixEditService::canEditCell( $user_id, $persona, $entity )
                                );
                            endforeach; ?>
                        </tr>
                        <?php self::renderScopeRow( $scope_id, $haystack, $entity, $personas, $grid, $activities, $user_id ); ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * One persona's grants on one entity: three toggles and a scope.
     *
     * The toggles are real checkboxes with a visible label, so the grid is
     * operable by keyboard and readable by a screen reader — the wp-admin
     * version hides its input and paints a `<label>`, which works with a
     * mouse and with nothing else.
     *
     * @param array<string, array{scope_kind:string, is_default:int}> $cell
     * @param array<string, string>                                   $activities
     */
    private static function renderCell( string $persona, string $entity, array $cell, array $activities, bool $can_edit ): void {
        $locked_title = $can_edit
            ? ''
            : __( 'Locked: only a WordPress administrator can change this.', 'talenttrack' );
        ?>
        <td class="tt-matrix-cell<?php echo $can_edit ? '' : ' is-locked'; ?>"
            title="<?php echo esc_attr( $locked_title ); ?>">
            <div class="tt-matrix-toggles">
                <?php foreach ( $activities as $activity => $abbrev ) :
                    $details    = $cell[ $activity ] ?? null;
                    $is_set     = (bool) $details;
                    $is_default = $details ? (int) $details['is_default'] : 1;
                    $classes    = 'tt-matrix-toggle';
                    if ( $is_set ) {
                        $classes .= $is_default ? ' is-on is-default' : ' is-on is-edited';
                    }
                    ?>
                    <label class="<?php echo esc_attr( $classes ); ?>">
                        <input type="checkbox"
                               name="cell[<?php echo esc_attr( $persona . '|' . $entity . '|' . $activity ); ?>]"
                               value="1"
                               <?php checked( $is_set ); ?>
                               <?php disabled( ! $can_edit ); ?> />
                        <span class="tt-matrix-toggle-face" aria-hidden="true"><?php echo esc_html( $abbrev ); ?></span>
                        <span class="tt-screen-reader-text">
                            <?php echo esc_html( self::cellLabel( $persona, $entity, $activity ) ); ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </td>
        <?php
    }

    /**
     * The scope controls for one entity, on their own row under it.
     *
     * They used to sit inside every cell, under the three toggles, which
     * is what made each entity two rows tall — the single biggest reason
     * the grid needed a viewport of its own to be read at all.
     *
     * **One select per persona, not one per row.** Scope is stored per
     * (persona, entity) — a coach may read players at team scope while a
     * scout reads them globally — so collapsing the row to a single
     * control would change what the matrix can express. The row is a
     * summary of where the controls live, not of what they mean.
     *
     * Rendered expanded, and collapsed by the script on load: without
     * JavaScript the scopes stay reachable, which they would not be if
     * the markup shipped hidden behind a button nothing could press.
     *
     * @param list<string>                                                                            $personas
     * @param array<string, array<string, array<string, array{scope_kind:string, is_default:int}>>>   $grid
     * @param array<string, string>                                                                   $activities
     */
    private static function renderScopeRow(
        string $scope_id,
        string $haystack,
        string $entity,
        array $personas,
        array $grid,
        array $activities,
        int $user_id
    ): void {
        ?>
        <tr class="tt-matrix-scope-row" id="<?php echo esc_attr( $scope_id ); ?>"
            data-tt-matrix-scope-row
            data-tt-matrix-haystack-follow="<?php echo esc_attr( $haystack ); ?>">
            <th scope="row" class="tt-matrix-entity tt-matrix-scope-head">
                <?php echo esc_html( _x( 'Scope', 'matrix per-entity scope row', 'talenttrack' ) ); ?>
            </th>
            <?php foreach ( $personas as $persona ) :
                $cell     = $grid[ $persona ][ $entity ] ?? [];
                $scope    = self::cellScope( $cell, $activities );
                $can_edit = MatrixEditService::canEditCell( $user_id, $persona, $entity );
                ?>
                <td class="tt-matrix-scope-cell<?php echo $can_edit ? '' : ' is-locked'; ?>">
                    <label class="tt-matrix-scope">
                        <span class="tt-screen-reader-text">
                            <?php
                            printf(
                                /* translators: 1: persona name, 2: entity name */
                                esc_html__( 'Scope for %1$s on %2$s', 'talenttrack' ),
                                esc_html( MatrixEditService::personaLabel( $persona ) ),
                                esc_html( MatrixEntityCatalog::entityLabel( $entity ) )
                            );
                            ?>
                        </span>
                        <select name="scope[<?php echo esc_attr( $persona . '|' . $entity ); ?>]" <?php disabled( ! $can_edit ); ?>>
                            <?php foreach ( self::scopeLabels() as $kind => $scope_label ) : ?>
                                <option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $scope, $kind ); ?>>
                                    <?php echo esc_html( $scope_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </td>
            <?php endforeach; ?>
        </tr>
        <?php
    }

    /**
     * A cell's scope: the first activity that carries one. Every activity
     * on a (persona, entity) pair shares a scope in practice, and this
     * read is unchanged from when the select lived inside the cell.
     *
     * @param array<string, array{scope_kind:string, is_default:int}> $cell
     * @param array<string, string>                                   $activities
     */
    private static function cellScope( array $cell, array $activities ): string {
        foreach ( array_keys( $activities ) as $activity ) {
            if ( isset( $cell[ $activity ]['scope_kind'] ) ) {
                return (string) $cell[ $activity ]['scope_kind'];
            }
        }
        return 'global';
    }

    /** @return array<string, string> */
    private static function scopeLabels(): array {
        return [
            'global' => _x( 'global', 'matrix scope', 'talenttrack' ),
            'team'   => _x( 'team', 'matrix scope', 'talenttrack' ),
            'player' => _x( 'player', 'matrix scope', 'talenttrack' ),
            'self'   => _x( 'self', 'matrix scope', 'talenttrack' ),
        ];
    }

    private static function cellLabel( string $persona, string $entity, string $activity ): string {
        // `_x` rather than `__`: these are verbs inside "X may … Y", and a
        // bare one-word msgid picks up whichever sense the translator met
        // first — "change" as a noun, "read" as a past tense.
        $activity_labels = [
            'read'          => _x( 'read', 'matrix activity, as a verb', 'talenttrack' ),
            'change'        => _x( 'change', 'matrix activity, as a verb', 'talenttrack' ),
            'create_delete' => _x( 'create and delete', 'matrix activity, as a verb', 'talenttrack' ),
        ];

        return sprintf(
            /* translators: 1: persona name, 2: what they may do, 3: entity name */
            __( '%1$s may %2$s %3$s', 'talenttrack' ),
            MatrixEditService::personaLabel( $persona ),
            $activity_labels[ $activity ] ?? $activity,
            MatrixEntityCatalog::entityLabel( $entity )
        );
    }

    // ---- save -------------------------------------------------------------

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_matrix_frontend_save', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'matrix.save' );

        $cells = isset( $_POST['cell'] ) && is_array( $_POST['cell'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['cell'] ) )
            : [];
        $scopes = isset( $_POST['scope'] ) && is_array( $_POST['scope'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['scope'] ) )
            : [];

        // The service is what rejects a protected cell — a hand-crafted POST
        // reaches here with the disabled inputs re-enabled, and must not get
        // further than a rejection count.
        ( new MatrixEditService() )->applyGrid( $cells, $scopes, get_current_user_id() );

        $url = add_query_arg( /* tt-xview-ok — back to this same view after saving it. */
            [ 'tt_view' => self::SLUG, 'tt_msg' => 'saved' ],
            RecordLink::dashboardUrl()
        );

        // Carry the back-target across the save so Cancel still leads home
        // afterwards. Encoded once, the way `BackLink::appendTo()` does it —
        // `add_query_arg` does not encode values, so a second pass here
        // would leave `resolve()` parsing a URL with no host.
        $back = isset( $_POST['tt_back'] ) ? (string) wp_unslash( $_POST['tt_back'] ) : '';
        if ( $back !== '' ) {
            $url = add_query_arg( 'tt_back', urlencode( $back ), $url );
        }

        wp_safe_redirect( $url );
        exit;
    }

    // ---- plumbing ---------------------------------------------------------

    /**
     * Where Cancel goes: back where the user came from when the entry URL
     * said so, and to the roles overview otherwise — the surface that links
     * here (CLAUDE.md §6).
     */
    private static function cancelUrl(): string {
        $back = BackLink::resolve();
        if ( $back !== null ) return $back['url'];

        // Roles & rights is where this view is linked from, so it is where
        // Cancel belongs — unless the user cannot open it, in which case
        // sending them there would be an exit that exits nowhere.
        if ( \TT\Shared\Frontend\Components\CrossViewLink::allows( 'roles' ) ) {
            return add_query_arg( [ 'tt_view' => 'roles' ], RecordLink::dashboardUrl() ); /* tt-xview-ok — gated by CrossViewLink::allows above. */
        }

        return RecordLink::dashboardUrl();
    }

    /** The raw `tt_back` value, carried through the save so Cancel still works after it. */
    private static function backParam(): string {
        $back = BackLink::resolve();

        return $back === null ? '' : $back['url'];
    }

    private static function matrixActive(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return false;

        return (bool) ( new \TT\Infrastructure\Config\ConfigService() )->getBool( 'tt_authorization_active', false );
    }

    /**
     * Add a tile to the Configuration landing, the way #1451's Modules
     * view does. Cap-gated, so a coach never sees a card they cannot open.
     *
     * The shape is a filter payload, so neither key is guaranteed: another
     * module's contribution is whatever it decided to pass.
     *
     * @param array<int, array{label?:string, tiles?:array<int,array<string,mixed>>}> $groups
     * @return array<int, array{label?:string, tiles?:array<int,array<string,mixed>>}>
     */
    public static function addConfigTile( array $groups ): array {
        if ( ! current_user_can( self::CAP ) ) return $groups;

        $tile = [
            'label'       => __( 'Authorization matrix', 'talenttrack' ),
            'description' => __( 'What each persona may do with each kind of record.', 'talenttrack' ),
            'icon'        => '🔐',
            'url'         => add_query_arg( [ 'tt_view' => self::SLUG ], RecordLink::dashboardUrl() ), /* tt-xview-ok — the tile carries its own cap, and the whole tile is suppressed above. */
            'cap'         => self::CAP,
        ];

        foreach ( $groups as &$group ) {
            if ( ! is_array( $group ) ) continue;
            if ( strpos( (string) ( $group['label'] ?? '' ), 'Branding' ) !== false ) {
                $group['tiles'][] = $tile;
                return $groups;
            }
        }
        unset( $group );

        $groups[] = [ 'label' => __( 'Configuration', 'talenttrack' ), 'tiles' => [ $tile ] ];

        return $groups;
    }
}
