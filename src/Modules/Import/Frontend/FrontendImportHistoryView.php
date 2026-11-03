<?php
namespace TT\Modules\Import\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Import\ImportBatchRegistry;
use TT\Modules\Import\ImportUndoService;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\StackTable;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendImportHistoryView (#2959, epic #2954) — what came in from a
 * spreadsheet, and a way back out.
 *
 * An import is a starting point, not a commitment. A club that uploads
 * the wrong file, or the right file with the columns mapped wrong, needs
 * one button rather than two hundred deletions — first-run mistakes are
 * the likeliest mistakes.
 *
 * Navigation (CLAUDE.md §5): breadcrumb chain only. No back button, no
 * module-level nav.
 */
class FrontendImportHistoryView extends FrontendViewBase {

    public const VIEW_SLUG = 'import-history';
    public const CAP       = 'manage_options';

    public static function render( int $user_id = 0, bool $is_admin = false ): void {
        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage imports.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Import history', 'talenttrack' ) );
        self::renderHeader( __( 'Import history', 'talenttrack' ) );

        $batches = ImportBatchRegistry::listBatches();

        if ( empty( $batches ) ) {
            echo '<p><em>' . esc_html__( 'Nothing has been imported from a spreadsheet yet.', 'talenttrack' ) . '</em></p>';
            return;
        }

        ?>
        <?php
        // #4918 — a card per import on a phone, the table from 768px.
        $c_file     = __( 'File', 'talenttrack' );
        $c_imported = __( 'Imported', 'talenttrack' );
        // "By" alone reads as either a person or a date; say which.
        $c_by       = _x( 'By', 'who ran the import', 'talenttrack' );
        $c_records  = __( 'Records', 'talenttrack' );
        $th         = StackTable::attrs( 'columnheader' );
        ?>
        <table class="tt-table <?php echo esc_attr( StackTable::classes( StackTable::TO_768, true ) ); ?>"<?php echo StackTable::attrs( 'table' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <thead<?php echo StackTable::attrs( 'rowgroup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <tr<?php echo StackTable::attrs( 'row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                    <th<?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $c_file ); ?></th>
                    <th<?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $c_imported ); ?></th>
                    <th<?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $c_by ); ?></th>
                    <th<?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $c_records ); ?></th>
                    <th<?php echo $th; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody<?php echo StackTable::attrs( 'rowgroup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <?php foreach ( $batches as $batch ) :
                $counts  = ImportUndoService::describeCounts( $batch['counts'] );
                $edited  = ImportUndoService::editedSince( (string) $batch['batch_key'], (string) $batch['created_at'] );
                ?>
                <tr<?php echo StackTable::attrs( 'row' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                    <td<?php echo StackTable::attrs( 'cell', $c_file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $batch['source_filename'] !== '' ? $batch['source_filename'] : __( '(unnamed file)', 'talenttrack' ) ); ?></td>
                    <td<?php echo StackTable::attrs( 'cell', $c_imported ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( (string) $batch['created_at'] ); ?></td>
                    <td<?php echo StackTable::attrs( 'cell', $c_by ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( self::userName( (int) $batch['created_by'] ) ); ?></td>
                    <td<?php echo StackTable::attrs( 'cell', $c_records ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $counts ); ?></td>
                    <td<?php echo StackTable::attrs( 'cell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><div class="<?php echo esc_attr( StackTable::VALUE ); ?>">
                        <?php if ( $counts === '' ) : ?>
                            <small><?php esc_html_e( 'Already undone', 'talenttrack' ); ?></small>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( self::confirmText( $counts, $edited ) ) ); ?>);">
                                <?php wp_nonce_field( 'tt_import_undo' ); ?>
                                <input type="hidden" name="action" value="tt_import_undo" />
                                <input type="hidden" name="batch_key" value="<?php echo esc_attr( (string) $batch['batch_key'] ); ?>" />
                                <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::currentUrl() ); ?>" />
                                <button type="submit" class="tt-btn tt-btn-secondary">
                                    <?php esc_html_e( 'Undo this import', 'talenttrack' ); ?>
                                </button>
                            </form>
                            <?php if ( $edited > 0 ) : ?>
                                <p class="tt-notice">
                                    <?php
                                    printf(
                                        esc_html(
                                            /* translators: %d: number of records changed since the import */
                                            _n(
                                                '%d of these records has been changed since it was imported.',
                                                '%d of these records have been changed since they were imported.',
                                                $edited,
                                                'talenttrack'
                                            )
                                        ),
                                        (int) $edited
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * The confirm copy. Names what will go, and says plainly when some of
     * it has been worked on since — an undo that quietly discards a
     * coach's afternoon is worse than no undo at all.
     */
    private static function confirmText( string $counts, int $edited ): string {
        $text = sprintf(
            /* translators: %s: a list like "3 teams, 24 players" */
            __( 'This will permanently delete %s that came from this file.', 'talenttrack' ),
            $counts
        );

        if ( $edited > 0 ) {
            $text .= ' ' . sprintf(
                /* translators: %d: number of records changed since the import */
                _n(
                    '%d of them has been changed since the import, and those changes will go too.',
                    '%d of them have been changed since the import, and those changes will go too.',
                    $edited,
                    'talenttrack'
                ),
                $edited
            );
        }

        return $text;
    }

    private static function userName( int $user_id ): string {
        if ( $user_id <= 0 ) return '—';
        $u = get_userdata( $user_id );
        return $u ? (string) $u->display_name : '—';
    }

    private static function currentUrl(): string {
        $req = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
        return $req !== '' ? $req : home_url( '/' );
    }
}
