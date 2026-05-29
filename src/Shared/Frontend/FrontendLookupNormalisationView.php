<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Configuration\LookupCanonicalSeeds;

/**
 * FrontendLookupNormalisationView (#987 v4.12.0) — one-shot admin tool
 * for reviewing canonical-language drift in `tt_lookups.name`.
 *
 * Surfaces the rows migration 0132 flagged via
 * `tt_audit_log.action = 'lookup.needs_review'` and lets an operator
 * accept the rewrite (one row at a time) or skip a row they want to
 * leave as-is. Accept and skip both write follow-up audit entries
 * (`lookup.normalisation.applied` / `lookup.normalisation.skipped`)
 * so the queue stops surfacing the row.
 *
 * Reachable via `?tt_view=lookup-normalisation`. Cap gate
 * `tt_access_frontend_admin` — mirrors `FrontendConfigurationView`.
 *
 * Reads server-side, writes via REST. No JS framework — vanilla
 * `fetch()` against `/wp-json/talenttrack/v1/lookup-normalisation/...`,
 * row swap on success, error pill on failure. Mobile-first per
 * CLAUDE.md § 2.
 */
class FrontendLookupNormalisationView extends FrontendViewBase {

    private const CAP = 'tt_access_frontend_admin';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $config_label = __( 'Configuration', 'talenttrack' );
        $page_label   = __( 'Lookup canonical-language review', 'talenttrack' );

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            $page_label,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'configuration', $config_label ) ]
        );

        self::renderHeader( $page_label );

        $pending = self::fetchPending();

        self::renderIntro( count( $pending ) );

        if ( empty( $pending ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No drifted lookup values remain. Every row matches its canonical English internal key.', 'talenttrack' ) . '</p>';
            self::renderResolvedSummary();
            return;
        }

        self::renderInlineStyles();
        self::renderQueue( $pending );
        self::renderInlineScript();
    }

    private static function renderIntro( int $pending_count ): void {
        $intro = $pending_count === 1
            ? esc_html__( 'One lookup value drifted from its canonical English internal key. Review the suggestion and accept the rewrite, or skip to leave the value as-is.', 'talenttrack' )
            : sprintf(
                /* translators: %d is the number of drifted lookup rows pending review. */
                esc_html__( '%d lookup values drifted from their canonical English internal key. Review each suggestion and accept the rewrite, or skip to leave the value as-is.', 'talenttrack' ),
                $pending_count
            );
        echo '<p class="tt-notice tt-lkpn-intro">' . $intro . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above.
    }

    /**
     * @param array<int, object> $rows
     */
    private static function renderQueue( array $rows ): void {
        echo '<div class="tt-lkpn" data-tt-lkpn-config="' . esc_attr( (string) wp_json_encode( [
            'rest_base' => esc_url_raw( rest_url( 'talenttrack/v1/lookup-normalisation' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'i18n'      => [
                'accept_in_flight'  => __( 'Accepting…', 'talenttrack' ),
                'skip_in_flight'    => __( 'Skipping…', 'talenttrack' ),
                'accepted'          => __( 'Accepted. Canonical value applied.', 'talenttrack' ),
                'skipped'           => __( 'Skipped. Row left as-is.', 'talenttrack' ),
                'generic_error'     => __( 'Action failed. Refresh and try again.', 'talenttrack' ),
                'confirm_skip'      => __( 'Skip this row? It will be left as-is and will not appear here again.', 'talenttrack' ),
            ],
        ] ) ) . '">';

        foreach ( $rows as $row ) {
            self::renderRow( $row );
        }

        echo '</div>';
    }

    private static function renderRow( object $row ): void {
        $payload = json_decode( (string) $row->payload, true );
        if ( ! is_array( $payload ) ) $payload = [];

        $audit_id    = (int) $row->id;
        $entity_id   = (int) $row->entity_id;
        $lookup_type = (string) ( $payload['lookup_type'] ?? '' );
        $current     = (string) ( $payload['current_name'] ?? '' );
        $suggested   = (string) ( $payload['suggested'] ?? '' );
        $detected    = (string) ( $payload['detected_locale'] ?? '' );
        $options     = is_array( $payload['canonical_options'] ?? null ) ? $payload['canonical_options'] : LookupCanonicalSeeds::canonicalFor( $lookup_type );

        echo '<article class="tt-lkpn-row" data-audit-id="' . esc_attr( (string) $audit_id ) . '" data-entity-id="' . esc_attr( (string) $entity_id ) . '">';
        echo '<header class="tt-lkpn-row__head">';
        echo '<span class="tt-lkpn-row__type">' . esc_html( $lookup_type ) . '</span>';
        echo '<span class="tt-lkpn-row__id">#' . esc_html( (string) $entity_id ) . '</span>';
        echo '</header>';

        echo '<div class="tt-lkpn-row__body">';

        echo '<div class="tt-lkpn-pair">';
        echo '<dt>' . esc_html__( 'Current value', 'talenttrack' ) . '</dt>';
        echo '<dd><code>' . esc_html( $current ) . '</code></dd>';
        echo '</div>';

        echo '<div class="tt-lkpn-pair">';
        echo '<dt>' . esc_html__( 'Suggested canonical', 'talenttrack' ) . '</dt>';
        echo '<dd>';
        echo '<label for="tt-lkpn-canonical-' . esc_attr( (string) $audit_id ) . '" class="tt-screen-reader-text">' . esc_html__( 'Canonical value', 'talenttrack' ) . '</label>';
        echo '<select id="tt-lkpn-canonical-' . esc_attr( (string) $audit_id ) . '" class="tt-lkpn-row__select" name="canonical">';
        if ( $suggested === '' ) {
            echo '<option value="">' . esc_html__( '— Choose a canonical value —', 'talenttrack' ) . '</option>';
        }
        foreach ( $options as $opt ) {
            $opt = (string) $opt;
            $selected = ( $opt === $suggested ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $opt ) . '"' . $selected . '>' . esc_html( $opt ) . '</option>';
        }
        echo '</select>';
        echo '</dd>';
        echo '</div>';

        echo '<div class="tt-lkpn-pair">';
        echo '<dt>' . esc_html__( 'Preserve current value as', 'talenttrack' ) . '</dt>';
        echo '<dd>';
        echo '<label for="tt-lkpn-locale-' . esc_attr( (string) $audit_id ) . '" class="tt-screen-reader-text">' . esc_html__( 'Locale for preserved value', 'talenttrack' ) . '</label>';
        echo '<select id="tt-lkpn-locale-' . esc_attr( (string) $audit_id ) . '" class="tt-lkpn-row__select" name="locale">';
        echo '<option value="">' . esc_html__( 'Do not preserve', 'talenttrack' ) . '</option>';
        foreach ( [ 'en_US', 'nl_NL', 'de_DE', 'es_ES', 'fr_FR' ] as $loc ) {
            $selected = ( $loc === $detected ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $loc ) . '"' . $selected . '>' . esc_html( $loc ) . '</option>';
        }
        echo '</select>';
        echo '</dd>';
        echo '</div>';

        echo '</div>';

        echo '<footer class="tt-lkpn-row__foot">';
        echo '<span class="tt-lkpn-row__msg" role="status" aria-live="polite"></span>';
        echo '<div class="tt-lkpn-row__actions">';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-lkpn-action="skip">' . esc_html__( 'Skip', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-lkpn-action="accept">' . esc_html__( 'Accept', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</footer>';

        echo '</article>';
    }

    private static function renderResolvedSummary(): void {
        global $wpdb;
        $applied = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log
              WHERE action = %s AND club_id = %d",
            'lookup.normalisation.applied', CurrentClub::id()
        ) );
        $skipped = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log
              WHERE action = %s AND club_id = %d",
            'lookup.normalisation.skipped', CurrentClub::id()
        ) );
        if ( $applied + $skipped === 0 ) return;

        echo '<p style="margin-top:var(--tt-sp-3); color:var(--tt-muted);">';
        printf(
            /* translators: 1: number of accepted rewrites. 2: number of skipped rows. */
            esc_html__( 'History: %1$d rewrite(s) applied, %2$d row(s) skipped.', 'talenttrack' ),
            $applied,
            $skipped
        );
        echo '</p>';
    }

    /**
     * @return array<int, object>
     */
    private static function fetchPending(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        // A row is "pending review" if it has a 'lookup.needs_review'
        // entry AND no subsequent applied/skipped entry. We join the
        // table to itself to filter resolved rows out in one query.
        $club_id = CurrentClub::id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.id, n.entity_id, n.payload, n.created_at
               FROM {$table} n
              WHERE n.action     = %s
                AND n.entity_type = 'lookup'
                AND n.club_id     = %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$table} r
                     WHERE r.entity_type = 'lookup'
                       AND r.entity_id   = n.entity_id
                       AND r.club_id     = n.club_id
                       AND r.action IN ('lookup.normalisation.applied', 'lookup.normalisation.skipped')
                )
              ORDER BY n.created_at ASC, n.id ASC
              LIMIT 200",
            'lookup.needs_review', $club_id
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    private static function renderInlineStyles(): void {
        ?>
        <style>
        .tt-lkpn { display: flex; flex-direction: column; gap: 12px; margin-top: var(--tt-sp-3); }
        .tt-lkpn-intro { margin-bottom: var(--tt-sp-3); }
        .tt-lkpn-row {
            border: 1px solid #d8dde3;
            border-radius: 6px;
            padding: 12px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .tt-lkpn-row__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--tt-muted, #6b7280);
        }
        .tt-lkpn-row__type {
            font-family: ui-monospace, "SFMono-Regular", Menlo, monospace;
            background: #f1f3f5;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .tt-lkpn-row__body {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }
        .tt-lkpn-pair { display: flex; flex-direction: column; gap: 4px; }
        .tt-lkpn-pair dt {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--tt-muted, #6b7280);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .tt-lkpn-pair dd { margin: 0; font-size: 1rem; }
        .tt-lkpn-pair dd code {
            background: #f1f3f5;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: ui-monospace, "SFMono-Regular", Menlo, monospace;
        }
        .tt-lkpn-row__select {
            min-height: 48px;
            font-size: 1rem;
            padding: 0 10px;
            border-radius: 4px;
            border: 1px solid #c4cad1;
            background: #fff;
            width: 100%;
        }
        .tt-lkpn-row__foot {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: stretch;
            border-top: 1px dashed #e3e7eb;
            padding-top: 10px;
        }
        .tt-lkpn-row__actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .tt-lkpn-row__actions .tt-btn {
            min-height: 48px;
            min-width: 96px;
        }
        .tt-lkpn-row__msg {
            font-size: 0.9rem;
            color: var(--tt-muted, #6b7280);
            min-height: 1.2em;
        }
        .tt-lkpn-row.is-applied { border-color: #5cb874; background: #f4fcf6; }
        .tt-lkpn-row.is-skipped { border-color: #c4cad1; background: #f8f9fa; }
        .tt-lkpn-row.is-error   { border-color: #d33f4f; background: #fdf6f7; }
        @media (min-width: 640px) {
            .tt-lkpn-row { padding: 16px; }
            .tt-lkpn-row__body { grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
            .tt-lkpn-row__foot { flex-direction: row; justify-content: space-between; align-items: center; }
        }
        .tt-screen-reader-text {
            position: absolute !important;
            clip: rect(1px,1px,1px,1px);
            width: 1px; height: 1px;
            overflow: hidden;
        }
        </style>
        <?php
    }

    private static function renderInlineScript(): void {
        ?>
        <script>
        (function () {
            var root = document.querySelector('[data-tt-lkpn-config]');
            if (!root) return;
            var cfg;
            try { cfg = JSON.parse(root.getAttribute('data-tt-lkpn-config') || '{}'); }
            catch (e) { cfg = {}; }
            if (!cfg.rest_base || !cfg.nonce) return;

            root.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-tt-lkpn-action]');
                if (!btn) return;
                var row = btn.closest('.tt-lkpn-row');
                if (!row) return;
                var action  = btn.getAttribute('data-tt-lkpn-action');
                var auditId = row.getAttribute('data-audit-id');
                if (!auditId) return;

                if (action === 'skip' && cfg.i18n && cfg.i18n.confirm_skip) {
                    if (!window.confirm(cfg.i18n.confirm_skip)) return;
                }

                var canonical = row.querySelector('select[name="canonical"]');
                var locale    = row.querySelector('select[name="locale"]');
                var msg       = row.querySelector('.tt-lkpn-row__msg');

                row.classList.remove('is-error');
                if (msg) msg.textContent = (cfg.i18n && cfg.i18n[action + '_in_flight']) || '';
                Array.prototype.forEach.call(row.querySelectorAll('button, select'), function (el) {
                    el.disabled = true;
                });

                var body = action === 'accept'
                    ? { canonical: canonical ? canonical.value : '', locale: locale ? locale.value : '' }
                    : {};

                fetch(cfg.rest_base + '/' + encodeURIComponent(auditId) + '/' + action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': cfg.nonce
                    },
                    body: JSON.stringify(body)
                }).then(function (resp) {
                    return resp.json().then(function (json) { return { ok: resp.ok, json: json }; });
                }).then(function (result) {
                    if (!result.ok || !result.json || !result.json.success) {
                        row.classList.add('is-error');
                        var err = result.json && Array.isArray(result.json.errors) && result.json.errors[0] && result.json.errors[0].message
                            ? result.json.errors[0].message
                            : (cfg.i18n && cfg.i18n.generic_error) || 'Error';
                        if (msg) msg.textContent = err;
                        Array.prototype.forEach.call(row.querySelectorAll('button, select'), function (el) {
                            el.disabled = false;
                        });
                        return;
                    }
                    row.classList.add(action === 'accept' ? 'is-applied' : 'is-skipped');
                    if (msg) msg.textContent = (cfg.i18n && cfg.i18n[action === 'accept' ? 'accepted' : 'skipped']) || '';
                }).catch(function () {
                    row.classList.add('is-error');
                    if (msg) msg.textContent = (cfg.i18n && cfg.i18n.generic_error) || 'Error';
                    Array.prototype.forEach.call(row.querySelectorAll('button, select'), function (el) {
                        el.disabled = false;
                    });
                });
            });
        }());
        </script>
        <?php
    }
}
