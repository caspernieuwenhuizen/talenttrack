<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * ArchivedDetailCard (#2022, epic #2018) — the SINGLE shared renderer for a
 * read-only detail of a non-active (archived / trashed) record.
 *
 * Every entity detail view (player, evaluation, goal, team, …) ends its
 * active lookup in `WHERE archived_at IS NULL`, so an archived or trashed row
 * never reaches the view and the page rendered "does not exist" (Bug 1). The
 * fix: on a `null` active lookup, the view RETRIES through this helper's
 * `resolve()` before falling to a genuine not-found.
 *
 * `resolve()` delegates to `ArchiveRepository::findIncludingArchived()`, where
 * the trashed-visibility gate lives (#2021). A `null` return means EITHER the
 * row genuinely doesn't exist OR it's a trashed minor's record the caller may
 * not see — in both cases the view renders a clean 404, NEVER a
 * permission-denied page that would confirm the record exists. This helper
 * therefore exposes only the same two outcomes the domain method does:
 *   - non-null → render the compact read-only card + status banner.
 *   - null     → caller renders not-found.
 *
 * Decision B (compact summary, not a full profile): the card shows an identity
 * anchor + a handful of key fields + the status banner. Edit / add affordances
 * are deliberately ABSENT — a non-active record must be restored before it can
 * be edited. A full read-only profile is an explicit non-goal.
 *
 * Composition only (CLAUDE.md §4): all data is supplied by the caller; the
 * helper computes no business logic. The state + who/when come from the
 * resolved row; the caller maps the row into a display summary.
 */
final class ArchivedDetailCard {

    /** @var bool */
    private static $css_enqueued = false;

    /**
     * Enqueue the read-only card stylesheet. Idempotent; call from a view's
     * non-active branch before render(). Depends on the mobile base sheet so
     * the design tokens + .tt-notice colours are present.
     */
    public static function enqueue(): void {
        if ( self::$css_enqueued ) return;
        wp_enqueue_style(
            'tt-frontend-archived-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-archived-detail.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        self::$css_enqueued = true;
    }

    /**
     * Resolve a record across all soft-delete states. Thin pass-through to the
     * domain gate so views never call two different lookups (one active, one
     * archive-aware) with subtly different visibility rules.
     *
     * @return array{row:object, state:string}|null  null → caller renders 404.
     */
    public static function resolve( string $entity, int $id ): ?array {
        if ( $id <= 0 ) return null;
        return ( new ArchiveRepository() )->findIncludingArchived( $entity, $id );
    }

    /**
     * Render the compact read-only card for a non-active record.
     *
     * Call ONLY when `$resolved['state']` is `archived` or `trashed` — an
     * `active` row should render through the entity's normal detail surface,
     * not this fallback.
     *
     * @param string $entity   ArchiveRepository entity key ('player', 'goal', …).
     * @param array  $resolved The `['row'=>…, 'state'=>…]` from resolve().
     * @param array{
     *     title:string,
     *     initials?:string,
     *     photo_url?:string,
     *     fields?: array<int,array{0:string,1:string}>,
     *     list_url?:string,
     *     restore_redirect?:string
     * } $summary  Caller-shaped display data. `fields` values are emitted as
     *             HTML and MUST be escaped by the caller; everything else is
     *             escaped here.
     */
    public static function render( string $entity, array $resolved, array $summary ): void {
        $state = (string) ( $resolved['state'] ?? '' );
        if ( $state !== 'archived' && $state !== 'trashed' ) return;
        $row = $resolved['row'] ?? null;
        if ( ! is_object( $row ) ) return;

        $id    = (int) ( $row->id ?? 0 );
        $title = (string) ( $summary['title'] ?? '' );

        echo '<article class="tt-archived-detail tt-record-detail" data-state="' . esc_attr( $state ) . '">';

        self::renderBanner( $entity, $id, $row, $state, $summary );

        echo '<div class="tt-archived-detail__card">';
        self::renderIdentity( $title, $summary );

        $fields = isset( $summary['fields'] ) && is_array( $summary['fields'] ) ? $summary['fields'] : [];
        if ( ! empty( $fields ) ) {
            echo '<dl class="tt-archived-detail__facts">';
            foreach ( $fields as $field ) {
                $label = (string) ( $field[0] ?? '' );
                $value = (string) ( $field[1] ?? '' );
                if ( $label === '' && $value === '' ) continue;
                echo '<div class="tt-archived-detail__fact">';
                echo '<dt class="tt-archived-detail__fact-k">' . esc_html( $label ) . '</dt>';
                // Value is pre-escaped at the call site (may carry a link).
                echo '<dd class="tt-archived-detail__fact-v">' . $value . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '</div>';
            }
            echo '</dl>';
        }
        echo '</div>'; // .tt-archived-detail__card

        echo '</article>';
    }

    /**
     * The status banner + lifecycle actions. Archived → amber warning with
     * Restore + Move to recycle bin; trashed → red danger with Restore to
     * archive + Delete permanently. Action buttons reuse the generic
     * `frontend-archive-button.js` plumbing (data-tt-archive-* attributes,
     * enqueued by every detail view via FrontendViewBase::enqueueAssets) — no
     * new JS, no inline scripts.
     */
    private static function renderBanner( string $entity, int $id, object $row, string $state, array $summary ): void {
        $list_url = (string) ( $summary['list_url'] ?? '' );
        // Restore lands the user back on the record; trash lands them on the list.
        $restore_redirect = (string) ( $summary['restore_redirect'] ?? '' );

        if ( $state === 'archived' ) {
            $who_when = self::whoWhen(
                isset( $row->archived_by ) ? (int) $row->archived_by : 0,
                isset( $row->archived_at ) ? (string) $row->archived_at : ''
            );
            echo '<div class="tt-notice tt-notice-warning tt-archived-detail__banner">';
            echo '<p class="tt-archived-detail__banner-msg"><strong>'
                . esc_html__( 'This record is archived.', 'talenttrack' ) . '</strong>';
            if ( $who_when !== '' ) {
                echo ' <span class="tt-archived-detail__banner-meta">' . esc_html( $who_when ) . '</span>';
            }
            echo '</p>';
            echo '<div class="tt-archived-detail__actions">';
            // Restore (archived → active): POST {entity-plural}/{id}/restore.
            self::actionButton( [
                'rest_path'     => self::pluralPath( $entity ) . '/' . $id . '/restore',
                'method'        => 'POST',
                'label'         => __( 'Restore', 'talenttrack' ),
                'confirm'       => __( 'Restore this record? It returns to the active list.', 'talenttrack' ),
                'confirm_label' => __( 'Restore', 'talenttrack' ),
                'variant'       => 'primary',
                'redirect'      => $restore_redirect,
            ] );
            // Move to recycle bin (archived → trashed): POST {entity-plural}/{id}/trash.
            self::actionButton( [
                'rest_path'     => self::pluralPath( $entity ) . '/' . $id . '/trash',
                'method'        => 'POST',
                'label'         => __( 'Move to recycle bin', 'talenttrack' ),
                'confirm'       => __( 'Move this record to the recycle bin? It will be permanently deleted after the retention window unless restored.', 'talenttrack' ),
                'confirm_label' => __( 'Move to recycle bin', 'talenttrack' ),
                'variant'       => 'danger',
                'redirect'      => $list_url,
            ] );
            echo '</div>';
            echo '</div>';
            return;
        }

        // Trashed.
        $who_when = self::whoWhen(
            isset( $row->trashed_by ) ? (int) $row->trashed_by : 0,
            isset( $row->trashed_at ) ? (string) $row->trashed_at : ''
        );
        $days = self::daysUntilPurge( isset( $row->trashed_at ) ? (string) $row->trashed_at : '' );
        echo '<div class="tt-notice tt-notice-danger tt-archived-detail__banner">';
        echo '<p class="tt-archived-detail__banner-msg"><strong>';
        if ( $days !== null ) {
            /* translators: %d: number of days until the record is permanently deleted */
            echo esc_html( sprintf( _n( 'In the recycle bin — deletes in %d day.', 'In the recycle bin — deletes in %d days.', $days, 'talenttrack' ), $days ) );
        } else {
            echo esc_html__( 'In the recycle bin.', 'talenttrack' );
        }
        echo '</strong>';
        if ( $who_when !== '' ) {
            echo ' <span class="tt-archived-detail__banner-meta">' . esc_html( $who_when ) . '</span>';
        }
        echo '</p>';
        echo '<div class="tt-archived-detail__actions">';
        // Restore to archive (trashed → archived): POST recycle-bin/{entity}/{id}/restore (#2024).
        self::actionButton( [
            'rest_path'     => 'recycle-bin/' . $entity . '/' . $id . '/restore',
            'method'        => 'POST',
            'label'         => __( 'Restore to archive', 'talenttrack' ),
            'confirm'       => __( 'Restore this record out of the recycle bin? It returns to the archive.', 'talenttrack' ),
            'confirm_label' => __( 'Restore to archive', 'talenttrack' ),
            'variant'       => 'primary',
            'redirect'      => $restore_redirect,
        ] );
        // Delete permanently (trashed → gone): DELETE recycle-bin/{entity}/{id} (#2024).
        self::actionButton( [
            'rest_path'     => 'recycle-bin/' . $entity . '/' . $id,
            'method'        => 'DELETE',
            'label'         => __( 'Delete permanently now', 'talenttrack' ),
            'confirm'       => __( 'Permanently delete this record now? This cannot be undone.', 'talenttrack' ),
            'confirm_label' => __( 'Delete permanently', 'talenttrack' ),
            'variant'       => 'danger',
            'redirect'      => $list_url,
        ] );
        echo '</div>';
        echo '</div>';
    }

    /**
     * Identity anchor — initials/photo avatar (where the caller supplies one)
     * beside the record title. Mirrors the active detail hero so an archived
     * record still reads as the same record, just in a calmer key.
     */
    private static function renderIdentity( string $title, array $summary ): void {
        $photo    = (string) ( $summary['photo_url'] ?? '' );
        $initials = (string) ( $summary['initials'] ?? '' );
        echo '<header class="tt-archived-detail__head">';
        if ( $photo !== '' ) {
            echo '<span class="tt-archived-detail__avatar" aria-hidden="true">'
                . '<img src="' . esc_url( $photo ) . '" alt="" width="48" height="48" />'
                . '</span>';
        } elseif ( $initials !== '' ) {
            echo '<span class="tt-archived-detail__avatar" aria-hidden="true">' . esc_html( $initials ) . '</span>';
        }
        echo '<h2 class="tt-archived-detail__title">' . esc_html( $title ) . '</h2>';
        echo '</header>';
    }

    /**
     * One lifecycle action button, wired to the generic
     * frontend-archive-button.js handler. `<button>` not `<a>` so the JS modal
     * confirm + nonce'd fetch governs; semantic native element, 48px target via
     * the .tt-btn sizing.
     *
     * @param array{rest_path:string,method:string,label:string,confirm:string,confirm_label:string,variant:string,redirect:string} $a
     */
    private static function actionButton( array $a ): void {
        $variant_class = $a['variant'] === 'primary' ? 'tt-btn-primary' : 'tt-btn-danger';
        echo '<button type="button" class="tt-btn ' . esc_attr( $variant_class ) . ' tt-archived-detail__action"'
            . ' data-tt-archive-rest-path="' . esc_attr( $a['rest_path'] ) . '"'
            . ' data-tt-archive-method="' . esc_attr( $a['method'] ) . '"'
            . ' data-tt-archive-confirm="' . esc_attr( $a['confirm'] ) . '"'
            . ' data-tt-archive-confirm-label="' . esc_attr( $a['confirm_label'] ) . '"'
            . ' data-tt-archive-variant="' . esc_attr( $a['variant'] ) . '"'
            . ( $a['redirect'] !== '' ? ' data-tt-archive-redirect="' . esc_attr( $a['redirect'] ) . '"' : '' )
            . '>' . esc_html( $a['label'] ) . '</button>';
    }

    /**
     * "by Jane Coach on 2026-06-20" style meta line. Empty when neither the
     * actor nor the timestamp is known.
     */
    private static function whoWhen( int $by_user_id, string $at ): string {
        $name = '';
        if ( $by_user_id > 0 ) {
            $user = get_userdata( $by_user_id );
            if ( $user ) {
                $name = (string) $user->display_name;
            }
        }
        $when = '';
        if ( $at !== '' && $at !== '0000-00-00 00:00:00' ) {
            $ts = strtotime( $at );
            if ( $ts !== false ) {
                $when = gmdate( 'Y-m-d', $ts );
            }
        }
        if ( $name !== '' && $when !== '' ) {
            /* translators: 1: user display name, 2: ISO date */
            return sprintf( __( 'by %1$s on %2$s', 'talenttrack' ), $name, $when );
        }
        if ( $name !== '' ) {
            /* translators: %s: user display name */
            return sprintf( __( 'by %s', 'talenttrack' ), $name );
        }
        if ( $when !== '' ) {
            /* translators: %s: ISO date */
            return sprintf( __( 'on %s', 'talenttrack' ), $when );
        }
        return '';
    }

    /**
     * Days until the purge cron removes a trashed row, via the same retention
     * window the bin list uses. null when the timestamp is unparseable.
     */
    private static function daysUntilPurge( string $trashed_at ): ?int {
        if ( $trashed_at === '' || $trashed_at === '0000-00-00 00:00:00' ) return null;
        $ts = strtotime( $trashed_at );
        if ( $ts === false ) return null;
        $retention = ( new ArchiveRepository() )->retentionDays();
        $purge_ts  = $ts + ( $retention * DAY_IN_SECONDS );
        $remaining = (int) ceil( ( $purge_ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
        return max( 0, $remaining );
    }

    /**
     * Entity key → REST plural path segment for the per-entity archive
     * lifecycle routes (restore / trash). Mirrors the controller route
     * prefixes registered in #1470 / #2023. Falls back to a naive `+ s` for
     * entities whose plural is regular.
     */
    private static function pluralPath( string $entity ): string {
        $map = [
            'player'     => 'players',
            'team'       => 'teams',
            'evaluation' => 'evaluations',
            'goal'       => 'goals',
            'activity'   => 'activities',
            'person'     => 'people',
            'tournament' => 'tournaments',
            'holiday'    => 'holidays',
        ];
        return $map[ $entity ] ?? ( $entity . 's' );
    }
}
