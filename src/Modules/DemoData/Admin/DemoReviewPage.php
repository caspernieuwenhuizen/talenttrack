<?php
namespace TT\Modules\DemoData\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoConversionService;
use TT\Modules\DemoData\DemoMode;

/**
 * DemoReviewPage (#1272 PR1) — read-only inventory of demo-tagged rows
 * per entity, split by `batch_id` provenance.
 *
 * Lands at `?page=tt-demo-review` under TalentTrack → Demo data
 * review. Zero mutation; the pilot uses this to decide whether to
 * proceed with the conversion wizard (#1272 PR2).
 *
 * Split shape per entity:
 *   - `batch_id = 'user-created'` → recommended keep (the operator
 *     created the row themselves mid-demo).
 *   - any other batch_id → recommended delete (seeded by
 *     DemoGenerator / DemoBatchRegistry).
 *
 * Total per-entity counts come straight from `tt_demo_tags` so the
 * inventory matches what's actually scoped, not what the seed shipped.
 */
final class DemoReviewPage {

    private const CAP = 'tt_edit_settings';

    /** Entity types tracked by `tt_demo_tags` per DemoMode::TRACKED_ENTITY_TYPES. */
    private const ENTITIES = [
        'team', 'player', 'person', 'activity', 'evaluation', 'goal',
    ];

    /**
     * #1272 PR2 — admin-post hook for the conversion form.
     * Wired from DemoDataModule::boot().
     */
    public static function init(): void {
        add_action( 'admin_post_tt_demo_convert', [ __CLASS__, 'handleConvert' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to review demo data.', 'talenttrack' ) );
        }

        $breakdown = self::breakdown();
        $totals    = self::totalsFromBreakdown( $breakdown );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Demo data review', 'talenttrack' ); ?></h1>

            <p style="max-width:720px; color:#5b6e75;">
                <?php esc_html_e(
                    'Per-entity inventory of demo-tagged rows split by their origin. User-created rows (you created them mid-demo) are recommended to keep; seeded rows are recommended to delete when you convert this install to production.',
                    'talenttrack'
                ); ?>
            </p>

            <?php if ( $totals['all'] === 0 ) : ?>
                <p><em><?php esc_html_e( 'No demo-tagged rows on this install. Nothing to convert.', 'talenttrack' ); ?></em></p>
                <?php return; ?>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:900px; margin-top:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Entity',         'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Total',         'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'User-created',  'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Seeded',        'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Seed batches',   'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( self::ENTITIES as $entity ) :
                    $row     = $breakdown[ $entity ] ?? [ 'total' => 0, 'user' => 0, 'seeded' => 0, 'batches' => [] ];
                    $batches = (array) $row['batches'];
                    $batch_list = '';
                    foreach ( $batches as $batch_id => $cnt ) {
                        $batch_list .= sprintf(
                            '<div><code>%s</code> · %d</div>',
                            esc_html( (string) $batch_id ),
                            (int) $cnt
                        );
                    }
                    if ( $batch_list === '' ) {
                        $batch_list = '<span style="color:#5b6e75;">—</span>';
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $entity ); ?></strong></td>
                        <td style="text-align:right;"><?php echo (int) $row['total']; ?></td>
                        <td style="text-align:right; color:#2e7d4f;"><?php echo (int) $row['user']; ?></td>
                        <td style="text-align:right; color:#c75c1f;"><?php echo (int) $row['seeded']; ?></td>
                        <td><?php echo $batch_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php esc_html_e( 'Totals', 'talenttrack' ); ?></th>
                        <th style="text-align:right;"><?php echo (int) $totals['all']; ?></th>
                        <th style="text-align:right; color:#2e7d4f;"><?php echo (int) $totals['user']; ?></th>
                        <th style="text-align:right; color:#c75c1f;"><?php echo (int) $totals['seeded']; ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

            <?php self::renderConvertForm( $breakdown, $totals ); ?>
        </div>
        <?php
    }

    /**
     * #1272 PR2 — destructive convert form. Lists every batch_id with
     * a smart-default checkbox (delete seeded; keep user-created).
     * Submit goes through admin-post → `handleConvert()` → service.
     *
     * #1295 — each batch row gains a `<details>` expander listing every
     * tagged row in that batch with a per-record override radio
     * (`delete` / `promote` / `inherit`; default `inherit`). The
     * per-record overrides are denormalised into the POST payload
     * keyed by `record_<entity_type>_<entity_id>` and expanded by
     * `handleConvert()` before the service call.
     *
     * @param array<string, array{total:int, user:int, seeded:int, batches:array<string,int>}> $breakdown
     * @param array{all:int, user:int, seeded:int} $totals
     */
    private static function renderConvertForm( array $breakdown, array $totals ): void {
        // #1272 PR3 — terminal-state lock. Once converted, the form is
        // permanently hidden and replaced with a "locked since YYYY-MM-DD"
        // badge. The install can never re-enter demo mode (see
        // DemoMode::set / ::markConverted) so re-running the convert
        // operation is meaningless.
        if ( DemoMode::isConverted() ) {
            $converted_at = DemoMode::convertedAt();
            echo '<div class="notice notice-info" style="margin-top:20px; border-left-color:#1d7874;"><p>'
                . esc_html( sprintf(
                    /* translators: %s = UTC datetime the install was marked converted (YYYY-MM-DD HH:MM:SS) */
                    __( 'This install was converted to production on %s — demo mode is permanently disabled.', 'talenttrack' ),
                    $converted_at !== '' ? $converted_at . ' UTC' : __( 'an earlier date', 'talenttrack' )
                ) )
                . '</p></div>';
            return;
        }

        // Result flash from a recently-completed conversion.
        if ( isset( $_GET['tt_convert_msg'] ) ) {
            echo '<div class="notice notice-success" style="margin-top:20px;"><p>'
                . esc_html__( 'Conversion complete. The selected batches were processed.', 'talenttrack' )
                . '</p></div>';
        }
        if ( isset( $_GET['tt_convert_err'] ) ) {
            echo '<div class="notice notice-error" style="margin-top:20px;"><p>'
                . esc_html( (string) wp_unslash( $_GET['tt_convert_err'] ) )
                . '</p></div>';
        }

        // Build the union of batch_ids from the breakdown so we can
        // render one checkbox per batch (rather than one per entity).
        $all_batches = [];
        foreach ( $breakdown as $entity_row ) {
            foreach ( (array) $entity_row['batches'] as $batch_id => $cnt ) {
                $all_batches[ (string) $batch_id ] = ( $all_batches[ $batch_id ] ?? 0 ) + (int) $cnt;
            }
        }
        ksort( $all_batches );
        // user-created appears separately (always keep — operator can opt-in to delete).
        $user_total = (int) $totals['user'];

        if ( empty( $all_batches ) && $user_total === 0 ) {
            return;
        }
        ?>
        <h2 style="margin-top:32px;"><?php esc_html_e( 'Convert to production', 'talenttrack' ); ?></h2>
        <p style="max-width:720px; color:#5b6e75;">
            <?php esc_html_e(
                'For each batch below, choose whether to DELETE the rows (along with their demo tags) or PROMOTE them to production (entity rows stay; only the demo tags are removed so they stop being scoped by demo mode).',
                'talenttrack'
            ); ?>
        </p>
        <p style="max-width:720px; color:#5b6e75;">
            <strong><?php esc_html_e( 'Smart defaults:', 'talenttrack' ); ?></strong>
            <?php esc_html_e( 'Seed batches are pre-selected to delete; user-created rows are pre-selected to promote.', 'talenttrack' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              style="max-width:720px; margin-top:12px; padding:16px; border:1px solid #d6dadd; border-radius:6px; background:#fafafa;"
              onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Run conversion now? Deletions cannot be undone.', 'talenttrack' ) ) ); ?>);">
            <?php wp_nonce_field( 'tt_demo_convert', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_demo_convert" />

            <h3 style="margin-top:0;"><?php esc_html_e( 'Seeded batches', 'talenttrack' ); ?></h3>
            <?php if ( empty( $all_batches ) ) : ?>
                <p style="color:#5b6e75;"><em><?php esc_html_e( 'No seeded batches.', 'talenttrack' ); ?></em></p>
            <?php else : foreach ( $all_batches as $batch_id => $cnt ) : ?>
                <div style="margin:6px 0;">
                    <label>
                        <input type="radio" name="<?php echo esc_attr( 'batch_' . $batch_id ); ?>" value="delete" checked />
                        <strong><?php esc_html_e( 'Delete', 'talenttrack' ); ?></strong>
                    </label>
                    &nbsp;
                    <label>
                        <input type="radio" name="<?php echo esc_attr( 'batch_' . $batch_id ); ?>" value="promote" />
                        <?php esc_html_e( 'Promote to production', 'talenttrack' ); ?>
                    </label>
                    &nbsp;
                    <code><?php echo esc_html( (string) $batch_id ); ?></code>
                    · <?php echo (int) $cnt; ?> <?php esc_html_e( 'rows', 'talenttrack' ); ?>
                    <?php self::renderRecordsExpander( (string) $batch_id ); ?>
                </div>
            <?php endforeach; endif; ?>

            <h3 style="margin-top:16px;"><?php esc_html_e( 'User-created rows', 'talenttrack' ); ?></h3>
            <div style="margin:6px 0;">
                <label>
                    <input type="radio" name="batch_user-created" value="promote" checked />
                    <strong><?php esc_html_e( 'Promote to production', 'talenttrack' ); ?></strong> <?php esc_html_e( '(recommended — these are real records)', 'talenttrack' ); ?>
                </label>
                <br>
                <label style="margin-top:4px; display:inline-block;">
                    <input type="radio" name="batch_user-created" value="delete" />
                    <?php esc_html_e( 'Delete', 'talenttrack' ); ?> <?php esc_html_e( '(careful — these are NOT seeded data)', 'talenttrack' ); ?>
                </label>
                &nbsp;
                <code>user-created</code> · <?php echo (int) $user_total; ?> <?php esc_html_e( 'rows', 'talenttrack' ); ?>
                <?php self::renderRecordsExpander( 'user-created' ); ?>
            </div>

            <p style="margin-top:16px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Run conversion', 'talenttrack' ); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * #1295 — Per-record override expander rendered inline under each
     * batch row. Lists every tagged row in this batch with a
     * `delete / promote / inherit` radio (default `inherit` so the
     * per-batch decision wins unless the operator picks an override).
     * Hidden behind a native `<details>` so the form stays compact for
     * the 95% case where no overrides are needed.
     */
    private static function renderRecordsExpander( string $batch_id ): void {
        $records = self::recordsInBatch( $batch_id );
        if ( empty( $records ) ) return;
        ?>
        <details style="margin-top:6px; margin-left:18px;">
            <summary style="cursor:pointer; color:#1d7874;"><?php esc_html_e( 'Show records', 'talenttrack' ); ?></summary>
            <p style="color:#5b6e75; margin:6px 0;"><em><?php esc_html_e( 'Override per row', 'talenttrack' ); ?></em></p>
            <table class="widefat" style="background:#fff; margin-top:4px;">
                <tbody>
                <?php foreach ( $records as $rec ) :
                    $name_attr = sprintf( 'record_%s_%d', $rec['entity_type'], (int) $rec['entity_id'] );
                ?>
                    <tr>
                        <td style="padding:4px 8px;">
                            <code><?php echo esc_html( $rec['entity_type'] . ' #' . (int) $rec['entity_id'] ); ?></code>
                            &nbsp;<?php echo esc_html( $rec['display_name'] ); ?>
                        </td>
                        <td style="padding:4px 8px; white-space:nowrap;">
                            <label style="margin-right:8px;">
                                <input type="radio" name="<?php echo esc_attr( $name_attr ); ?>" value="delete" />
                                <?php esc_html_e( 'Delete', 'talenttrack' ); ?>
                            </label>
                            <label style="margin-right:8px;">
                                <input type="radio" name="<?php echo esc_attr( $name_attr ); ?>" value="promote" />
                                <?php esc_html_e( 'Promote', 'talenttrack' ); ?>
                            </label>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( $name_attr ); ?>" value="inherit" checked />
                                <?php esc_html_e( 'Inherit', 'talenttrack' ); ?>
                            </label>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php
    }

    /**
     * #1295 — Returns every tagged row for the given batch, resolved
     * against the underlying entity table for a display name. Used by
     * `renderRecordsExpander()`. Returns an empty list when the batch
     * has no tagged rows or the tag table is missing.
     *
     * @return array<int, array{entity_type:string, entity_id:int, display_name:string}>
     */
    private static function recordsInBatch( string $batch_id ): array {
        global $wpdb;
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        $club_id   = (int) CurrentClub::id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_type, entity_id
               FROM {$tag_table}
              WHERE club_id = %d AND batch_id = %s
              ORDER BY entity_type ASC, entity_id ASC",
            $club_id, $batch_id
        ) );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $entity_type = (string) ( $r->entity_type ?? '' );
            $entity_id   = (int) ( $r->entity_id ?? 0 );
            if ( $entity_type === '' || $entity_id <= 0 ) continue;
            $out[] = [
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'display_name' => self::displayNameFor( $entity_type, $entity_id ),
            ];
        }
        return $out;
    }

    /**
     * #1295 — Resolve a human-readable display name for one tagged
     * entity. Per-type column choice:
     *   - team       → name
     *   - player     → first_name + last_name
     *   - person     → first_name + last_name (no display_name column
     *                  on tt_people; the spec called for display_name
     *                  but the schema only has first/last)
     *   - activity   → title
     *   - goal       → title
     *   - evaluation → composite `eval #<id>` — no name column on
     *                  tt_evaluations; the entity is described by its
     *                  player + date but those are out of scope here
     * Returns an empty string when the row no longer exists.
     */
    private static function displayNameFor( string $entity_type, int $entity_id ): string {
        global $wpdb;
        $club_id = (int) CurrentClub::id();

        switch ( $entity_type ) {
            case 'team':
                $name = $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
                    $entity_id, $club_id
                ) );
                return is_string( $name ) ? $name : '';

            case 'player':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
                    $entity_id, $club_id
                ) );
                if ( ! $row ) return '';
                return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );

            case 'person':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_people WHERE id = %d AND club_id = %d",
                    $entity_id, $club_id
                ) );
                if ( ! $row ) return '';
                return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );

            case 'activity':
                $title = $wpdb->get_var( $wpdb->prepare(
                    "SELECT title FROM {$wpdb->prefix}tt_activities WHERE id = %d AND club_id = %d",
                    $entity_id, $club_id
                ) );
                return is_string( $title ) ? $title : '';

            case 'goal':
                $title = $wpdb->get_var( $wpdb->prepare(
                    "SELECT title FROM {$wpdb->prefix}tt_goals WHERE id = %d AND club_id = %d",
                    $entity_id, $club_id
                ) );
                return is_string( $title ) ? $title : '';

            case 'evaluation':
                return sprintf( 'eval_id #%d', $entity_id );
        }
        return '';
    }

    /**
     * Admin-post handler for the convert form. Reads per-batch
     * radio values (delete / promote) and dispatches to the service.
     */
    public static function handleConvert(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Forbidden.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_demo_convert', 'tt_nonce' );

        $back = admin_url( 'admin.php?page=tt-demo-review' );

        // #1272 PR3 — refuse to re-run once converted. Defence in depth
        // alongside the form being hidden when DemoMode::isConverted.
        // A stale form on an open tab would otherwise hit the handler.
        if ( DemoMode::isConverted() ) {
            wp_safe_redirect( add_query_arg( 'tt_convert_err', urlencode( __( 'This install is already converted — demo mode is permanently disabled.', 'talenttrack' ) ), $back ) );
            exit;
        }

        $delete_batches  = [];
        $promote_batches = [];
        // #1295 — per-record overrides shaped
        //   [ entity_type => [ entity_id => 'delete'|'promote' ] ]
        // `inherit` is the default and is dropped here (no override).
        $per_record_overrides = [];
        $allowed_entity_types = [ 'team', 'player', 'person', 'activity', 'evaluation', 'goal' ];

        foreach ( (array) $_POST as $key => $value ) {
            $key_str = (string) $key;

            if ( strncmp( $key_str, 'batch_', 6 ) === 0 ) {
                $batch_id = substr( $key_str, 6 );
                if ( $batch_id === '' ) continue;
                $choice = sanitize_key( (string) wp_unslash( $value ) );
                if ( $choice === 'delete' )      $delete_batches[]  = $batch_id;
                elseif ( $choice === 'promote' ) $promote_batches[] = $batch_id;
                continue;
            }

            if ( strncmp( $key_str, 'record_', 7 ) === 0 ) {
                $rest = substr( $key_str, 7 );
                // Last underscore separates entity_type from entity_id.
                $sep = strrpos( $rest, '_' );
                if ( $sep === false ) continue;
                $entity_type = substr( $rest, 0, $sep );
                $entity_id   = (int) substr( $rest, $sep + 1 );
                if ( $entity_id <= 0 ) continue;
                if ( ! in_array( $entity_type, $allowed_entity_types, true ) ) continue;
                $choice = sanitize_key( (string) wp_unslash( $value ) );
                if ( $choice === 'delete' || $choice === 'promote' ) {
                    $per_record_overrides[ $entity_type ][ $entity_id ] = $choice;
                }
                // 'inherit' (or anything else) → no entry; per-batch wins.
            }
        }

        if ( empty( $delete_batches ) && empty( $promote_batches ) ) {
            wp_safe_redirect( add_query_arg( 'tt_convert_err', urlencode( __( 'No batches selected.', 'talenttrack' ) ), $back ) );
            exit;
        }

        ( new DemoConversionService() )->run( $delete_batches, $promote_batches, $per_record_overrides );
        wp_safe_redirect( add_query_arg( 'tt_convert_msg', '1', $back ) );
        exit;
    }

    /**
     * Returns per-entity breakdown:
     *
     *   [
     *     'team' => [
     *       'total'   => int,
     *       'user'    => int,
     *       'seeded'  => int,
     *       'batches' => [ '<batch_id>' => int, ... ], // excludes 'user-created'
     *     ],
     *     ...
     *   ]
     *
     * Filtered to the current club. Uses one query per entity so the
     * batches breakdown comes back grouped without an in-PHP partition.
     *
     * @return array<string, array{total:int, user:int, seeded:int, batches:array<string,int>}>
     */
    private static function breakdown(): array {
        global $wpdb;
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        $club_id   = (int) CurrentClub::id();

        $out = [];
        foreach ( self::ENTITIES as $entity ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT batch_id, COUNT(*) AS cnt
                   FROM {$tag_table}
                  WHERE club_id = %d AND entity_type = %s
                  GROUP BY batch_id",
                $club_id, $entity
            ) );
            $user    = 0;
            $batches = [];
            $total   = 0;
            foreach ( (array) $rows as $r ) {
                $batch = (string) ( $r->batch_id ?? '' );
                $cnt   = (int) $r->cnt;
                $total += $cnt;
                if ( $batch === 'user-created' ) {
                    $user += $cnt;
                    continue;
                }
                $batches[ $batch ] = ( $batches[ $batch ] ?? 0 ) + $cnt;
            }
            $out[ $entity ] = [
                'total'   => $total,
                'user'    => $user,
                'seeded'  => $total - $user,
                'batches' => $batches,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array{total:int, user:int, seeded:int, batches:array<string,int>}> $breakdown
     * @return array{all:int, user:int, seeded:int}
     */
    private static function totalsFromBreakdown( array $breakdown ): array {
        $all = 0; $user = 0; $seeded = 0;
        foreach ( $breakdown as $row ) {
            $all    += (int) $row['total'];
            $user   += (int) $row['user'];
            $seeded += (int) $row['seeded'];
        }
        return [ 'all' => $all, 'user' => $user, 'seeded' => $seeded ];
    }
}
