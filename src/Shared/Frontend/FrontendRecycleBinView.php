<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Archive\AutoPurgeCron;
use TT\Infrastructure\Archive\CascadeRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\RecycleBin\RecycleBinEntities;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendRecycleBinView (#2024, epic #2018) — the centralized recycle bin
 * (mockup surfaces 4 & 6). Reachable at `?tt_view=recycle-bin`, gated on
 * `tt_manage_recycle_bin` (academy-admin only).
 *
 * Cross-entity list of trashed rows, grouped by entity type with counts.
 * Each row shows its identity anchor, who/when it was binned, a
 * days-until-purge badge (red ≤ 7 days), and two inline actions:
 *
 *   - Restore — moves the row back to the archive tier
 *     (POST /recycle-bin/{entity}/{id}/restore).
 *   - Delete now — permanently purges it after a cascade-preview confirm
 *     (DELETE /recycle-bin/{entity}/{id}).
 *
 * Action-only: there is no drill-in. All shaping lives in
 * {@see ArchiveRepository} (CLAUDE.md §4) — this view composes HTML and
 * defers every mutation to the REST surface, which re-checks the cap +
 * ownership. The two nav affordances are the canonical breadcrumb chain
 * (Dashboard › Recycle bin, top-level, no tt_back) plus the auto-rendered
 * tt_back pill (there is none for a top-level view). See
 * docs/back-navigation.md.
 */
class FrontendRecycleBinView extends FrontendViewBase {

    private const CAP = 'tt_manage_recycle_bin';

    public static function render( int $user_id, bool $is_admin ): void {
        // Breadcrumb on EVERY path, including permission-denied (CLAUDE.md §5).
        FrontendBreadcrumbs::fromDashboard( __( 'Recycle bin', 'talenttrack' ) );

        if ( ! current_user_can( self::CAP ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to view the recycle bin.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueBinAssets();

        self::renderHeader( __( 'Recycle bin', 'talenttrack' ) );

        $repo      = new ArchiveRepository();
        $groups    = $repo->trashedAcrossEntities();
        $retention = $repo->retentionDays();

        echo '<div class="tt-rb">';

        echo '<p class="tt-rb__intro">'
            . esc_html(
                sprintf(
                    /* translators: %d is the retention window in days. */
                    _n(
                        'Records here are staged for permanent deletion. They are purged automatically after %d day, or you can restore or delete them now.',
                        'Records here are staged for permanent deletion. They are purged automatically after %d days, or you can restore or delete them now.',
                        $retention,
                        'talenttrack'
                    ),
                    $retention
                )
            )
            . '</p>';

        // #2025 — surface records the daily auto-purge could not delete
        // because the cascade fail-closed on an undeclared reference. They
        // stay in the bin (never force-deleted); the operator clears the
        // dependents and they purge on a later sweep.
        self::renderBlockedNotice();

        if ( empty( $groups ) ) {
            self::renderEmptyState();
            echo '</div>';
            return;
        }

        foreach ( $groups as $entity => $rows ) {
            self::renderGroup( (string) $entity, $rows, $repo );
        }

        echo '</div>';
    }

    /**
     * One entity group: a heading with its count, then one card per trashed
     * row.
     *
     * @param list<array{id:int,trashed_at:string,trashed_by:int,trashed_by_name:string,days_until_purge:int}> $rows
     */
    private static function renderGroup( string $entity, array $rows, ArchiveRepository $repo ): void {
        $label = RecycleBinEntities::label( $entity );
        $count = count( $rows );

        echo '<section class="tt-rb-group" aria-label="' . esc_attr( $label ) . '">';
        echo '<h2 class="tt-rb-group__head">'
            . esc_html( $label )
            . ' <span class="tt-rb-group__count">' . esc_html( (string) $count ) . '</span>'
            . '</h2>';

        // #2025 — block_only entities (trial_track, measurement_definition)
        // can never auto-purge: their cascade plan blocks on any dependent,
        // by design (template / definition data). Flag the group so the
        // 30-day countdown isn't read as "these vanish at 30 days".
        if ( self::isBlockOnly( $entity ) ) {
            echo '<p class="tt-rb-group__flag tt-notice">'
                . esc_html__( 'These records are kept until you remove what still depends on them — the automatic 30-day purge cannot delete this record type.', 'talenttrack' )
                . '</p>';
        }

        echo '<ul class="tt-rb-list">';
        foreach ( $rows as $row ) {
            self::renderRow( $entity, $row, $repo );
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * One trashed row: identity anchor, who/when binned, days-until-purge
     * badge, and the Restore + Delete-now actions. Both actions are
     * client-side buttons that call the bin REST surface; the view carries
     * the data attributes the recycle-bin script reads.
     *
     * @param array{id:int,trashed_at:string,trashed_by:int,trashed_by_name:string,days_until_purge:int} $row
     */
    private static function renderRow( string $entity, array $row, ArchiveRepository $repo ): void {
        $id    = (int) $row['id'];
        $found = $repo->findIncludingArchived( $entity, $id );
        $identity = ( $found !== null )
            ? RecycleBinEntities::identity( $found['row'] )
            /* translators: %d is a record id. */
            : sprintf( __( 'Record #%d', 'talenttrack' ), $id );

        $days = (int) $row['days_until_purge'];
        $who  = trim( (string) $row['trashed_by_name'] );
        $when = (string) $row['trashed_at'];

        // Who/when binned, localised. Date formatted with the site format.
        $when_h = $when !== '' ? date_i18n( get_option( 'date_format', 'Y-m-d' ), strtotime( $when ) ) : '';
        if ( $who !== '' && $when_h !== '' ) {
            $meta = sprintf(
                /* translators: 1: user display name, 2: date. */
                __( 'Binned by %1$s on %2$s', 'talenttrack' ),
                $who,
                $when_h
            );
        } elseif ( $when_h !== '' ) {
            /* translators: %s is a date. */
            $meta = sprintf( __( 'Binned on %s', 'talenttrack' ), $when_h );
        } else {
            $meta = '';
        }

        $badge_class = 'tt-rb-badge';
        if ( $days <= 7 ) {
            $badge_class .= ' tt-rb-badge--urgent';
        }
        $badge_text = sprintf(
            /* translators: %d is the number of days until automatic purge. */
            _n( '%d day left', '%d days left', $days, 'talenttrack' ),
            $days
        );

        $restore_path = 'recycle-bin/' . $entity . '/' . $id . '/restore';
        $purge_path   = 'recycle-bin/' . $entity . '/' . $id;
        $preview_path = 'recycle-bin/preview/' . $entity . '/' . $id;

        echo '<li class="tt-rb-row">';

        echo '<div class="tt-rb-row__main">';
        echo '<span class="tt-rb-row__identity">' . esc_html( $identity ) . '</span>';
        if ( $meta !== '' ) {
            echo '<span class="tt-rb-row__meta">' . esc_html( $meta ) . '</span>';
        }
        echo '</div>';

        echo '<span class="' . esc_attr( $badge_class ) . '">' . esc_html( $badge_text ) . '</span>';

        echo '<div class="tt-rb-row__actions">';
        // Restore — POST .../restore. No cascade preview needed (reversible).
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-rb-action"'
            . ' data-tt-rb-action="restore"'
            . ' data-tt-rb-path="' . esc_attr( $restore_path ) . '"'
            . ' data-tt-rb-label="' . esc_attr( $identity ) . '">'
            . esc_html__( 'Restore', 'talenttrack' )
            . '</button>';
        // Delete now — fetch the cascade preview, then DELETE the row.
        echo '<button type="button" class="tt-btn tt-btn-danger tt-rb-action"'
            . ' data-tt-rb-action="purge"'
            . ' data-tt-rb-path="' . esc_attr( $purge_path ) . '"'
            . ' data-tt-rb-preview="' . esc_attr( $preview_path ) . '"'
            . ' data-tt-rb-label="' . esc_attr( $identity ) . '">'
            . esc_html__( 'Delete now', 'talenttrack' )
            . '</button>';
        echo '</div>';

        echo '</li>';
    }

    /**
     * #2025 — banner shown when the most recent auto-purge sweep skipped one
     * or more records because the fail-closed cascade blocked them (an
     * undeclared reference still points at the record). Reads the per-club
     * count the cron persists to tt_config; renders nothing when zero.
     */
    private static function renderBlockedNotice(): void {
        $count = ( new ConfigService() )->getInt( AutoPurgeCron::BLOCKED_COUNT_CONFIG_KEY, 0 );
        if ( $count <= 0 ) {
            return;
        }

        echo '<div class="tt-rb-blocked tt-notice tt-notice--warning" role="status">';
        echo '<p class="tt-rb-blocked__text">'
            . esc_html(
                sprintf(
                    /* translators: %d is the number of records the auto-purge could not delete. */
                    _n(
                        '%d record couldn\'t be auto-deleted because other records still reference it. It stays here until you remove what depends on it.',
                        '%d records couldn\'t be auto-deleted because other records still reference them. They stay here until you remove what depends on them.',
                        $count,
                        'talenttrack'
                    ),
                    $count
                )
            )
            . '</p>';
        echo '</div>';
    }

    /**
     * Whether $entity is `block_only` in CascadeRegistry — i.e. it can never
     * cascade-delete its dependents and so can never be auto-purged. Today
     * that is `trial_track` and `measurement_definition` (team + activity got
     * full cascades in #2027). Sourced from the registry so this view can
     * never drift from the cascade plans.
     */
    private static function isBlockOnly( string $entity ): bool {
        $plan = CascadeRegistry::plan( $entity );
        return is_array( $plan ) && ! empty( $plan['block_only'] );
    }

    private static function renderEmptyState(): void {
        echo '<div class="tt-rb-empty">';
        echo '<span class="tt-rb-empty__icon" aria-hidden="true">&#128465;</span>';
        echo '<p class="tt-rb-empty__title">' . esc_html__( 'The recycle bin is empty.', 'talenttrack' ) . '</p>';
        echo '<p class="tt-rb-empty__hint">'
            . esc_html__( 'Records moved to the bin from an archived list will appear here until they are restored or purged.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    /**
     * Enqueue the bin's own sheet + script. The script reuses the global
     * window.TT REST config (root + nonce) and localised strings.
     */
    private static function enqueueBinAssets(): void {
        wp_enqueue_style(
            'tt-frontend-recycle-bin',
            TT_PLUGIN_URL . 'assets/css/frontend-recycle-bin.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-recycle-bin',
            TT_PLUGIN_URL . 'assets/js/frontend-recycle-bin.js',
            [ 'tt-public' ],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-recycle-bin',
            'TT_RecycleBinI18n',
            [
                'restoreTitle'   => __( 'Restore record', 'talenttrack' ),
                'restoreConfirm' => __( 'Restore this record to the archive?', 'talenttrack' ),
                'restoreAction'  => __( 'Restore', 'talenttrack' ),
                'purgeTitle'     => __( 'Delete permanently', 'talenttrack' ),
                'purgeIntro'     => __( 'This permanently deletes the record and cannot be undone. The following will also be removed or cleared:', 'talenttrack' ),
                'purgeNothing'   => __( 'No other records depend on this one.', 'talenttrack' ),
                'purgeKept'      => __( 'Kept (references cleared, not deleted):', 'talenttrack' ),
                'purgeBlocked'   => __( 'This record cannot be deleted yet — other records still depend on it:', 'talenttrack' ),
                'purgeAction'    => __( 'Delete permanently', 'talenttrack' ),
                'cancel'         => __( 'Cancel', 'talenttrack' ),
                'genericError'   => __( 'Action failed. Please try again.', 'talenttrack' ),
                'networkError'   => __( 'Network error. Please try again.', 'talenttrack' ),
                'configError'    => __( 'Recycle bin is unavailable: REST configuration missing.', 'talenttrack' ),
                'removedLabel'   => __( 'Removed:', 'talenttrack' ),
            ]
        );
    }

    /**
     * Static breadcrumb override is unused — the dynamic chain is set via
     * FrontendBreadcrumbs::fromDashboard() in render() (which runs on every
     * path). Kept empty so renderHeader() doesn't double-emit.
     *
     * @return array<int,array{label:string,url?:?string}>
     */
    protected static function breadcrumbs(): array {
        return [];
    }
}
