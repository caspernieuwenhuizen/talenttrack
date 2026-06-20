<?php
namespace TT\Modules\Vct\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendVctConfigView (#0095 VCT-12 / #952).
 *
 * VCT configuration tile at ?tt_view=vct-config with three sub-tabs:
 *
 *   ?tab=blocks       — macro-block calendar editor (season periodization)
 *   ?tab=age-profiles — per-age intensity ceiling + envelope tuning
 *   ?tab=schedules    — per-team weekly training-day preferences
 *
 * All three are settings sub-forms; Save+Cancel exempt per
 * CLAUDE.md §6 (a). Cap: tt_vct_admin_library (HoD/admin only).
 *
 * Form POSTs route back to the same view via the standard
 * shortcode dispatch; handlers call the repos directly.
 */
class FrontendVctConfigView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_vct_admin_library' ) && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'VCT configuration', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the VCT configuration tile.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            self::handlePost();
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'blocks';
        if ( ! in_array( $tab, [ 'blocks', 'age-profiles', 'schedules' ], true ) ) $tab = 'blocks';

        FrontendBreadcrumbs::fromDashboard( __( 'VCT configuration', 'talenttrack' ) );
        self::renderHeader( __( 'VCT configuration', 'talenttrack' ) );

        self::renderTabBar( $tab );

        switch ( $tab ) {
            case 'age-profiles': self::renderAgeProfilesTab(); break;
            case 'schedules':    self::renderSchedulesTab();    break;
            case 'blocks':
            default:             self::renderBlocksTab();       break;
        }
    }

    private static function renderTabBar( string $current ): void {
        $tabs = [
            'blocks'       => __( 'Macro-blocks',       'talenttrack' ),
            'age-profiles' => __( 'Age profiles',       'talenttrack' ),
            'schedules'    => __( 'Team schedules',     'talenttrack' ),
        ];
        echo '<div class="tt-vct-config-tabs" style="display:flex;gap:6px;margin:8px 0 16px;border-bottom:1px solid #ddd;">';
        foreach ( $tabs as $slug => $label ) {
            $active = $slug === $current;
            $href = add_query_arg( [ 'tab' => $slug ] );
            echo '<a href="' . esc_url( $href ) . '" style="padding:8px 14px;text-decoration:none;border-bottom:3px solid '
                . ( $active ? '#0b3d2e' : 'transparent' ) . ';color:' . ( $active ? '#0b3d2e;font-weight:600' : '#555' ) . ';">'
                . esc_html( $label )
                . '</a>';
        }
        echo '</div>';
    }

    // ── BLOCKS ───────────────────────────────────────────────────────

    private static function renderBlocksTab(): void {
        $repo = new VctMacroBlocksRepository();
        $references = $repo->listReferenceTemplates();

        $season_id = isset( $_GET['season_id'] ) ? absint( $_GET['season_id'] ) : 0;
        $team_id   = isset( $_GET['team_id'] )   ? absint( $_GET['team_id'] )   : 0;

        echo '<p>' . esc_html__( 'Define the macro-block calendar for a season. `team_id = 0` is the club-wide default; non-zero is a per-team override.', 'talenttrack' ) . '</p>';

        // Picker.
        echo '<form method="GET" action="" style="display:flex;gap:8px;align-items:end;margin:0 0 16px;">';
        echo '<input type="hidden" name="tt_view" value="vct-config">';
        echo '<input type="hidden" name="tab"     value="blocks">';
        echo '<label><span>' . esc_html__( 'Season ID', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="season_id" min="1" value="' . esc_attr( (string) $season_id ) . '"></label>';
        echo '<label><span>' . esc_html__( 'Team ID (0 = club default)', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="team_id" min="0" value="' . esc_attr( (string) $team_id ) . '"></label>';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Load', 'talenttrack' ) . '</button>';
        echo '</form>';

        // Reference templates.
        if ( $references ) {
            echo '<h3 style="margin-top:24px;font-size:14px;">' . esc_html__( 'Reference phase profiles', 'talenttrack' ) . '</h3>';
            echo '<ul style="margin:0 0 16px;padding-left:18px;font-size:13px;">';
            foreach ( $references as $r ) {
                $weeks = is_array( $r['phase_profile'] ) ? count( $r['phase_profile'] ) : 0;
                echo '<li>' . esc_html( (string) $r['label'] )
                    . ' — ' . esc_html( sprintf(
                        /* translators: %d is the week count */
                        __( '%d-week profile', 'talenttrack' ),
                        $weeks
                    ) )
                    . '</li>';
            }
            echo '</ul>';
        }

        if ( $season_id <= 0 ) {
            echo '<p class="tt-empty">' . esc_html__( 'Enter a Season ID to load or edit its macro-blocks.', 'talenttrack' ) . '</p>';
            return;
        }

        $blocks = $repo->listForSeason( $team_id, $season_id );

        if ( $blocks ) {
            echo '<h3 style="margin-top:24px;font-size:14px;">' . esc_html(
                sprintf(
                    /* translators: 1: season id, 2: team id */
                    __( 'Current blocks for season %1$d / team %2$d', 'talenttrack' ),
                    $season_id, $team_id
                )
            ) . '</h3>';
            echo '<table class="tt-table"><thead><tr><th>#</th><th>' . esc_html__( 'Label', 'talenttrack' ) . '</th><th>' . esc_html__( 'Start', 'talenttrack' ) . '</th><th>' . esc_html__( 'End', 'talenttrack' ) . '</th><th>' . esc_html__( 'Weeks', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $blocks as $b ) {
                $weeks = is_array( $b['phase_profile'] ) ? count( $b['phase_profile'] ) : 0;
                echo '<tr><td>' . esc_html( (string) $b['sequence'] ) . '</td><td>' . esc_html( (string) $b['label'] ) . '</td><td>' . esc_html( (string) $b['start_date'] ) . '</td><td>' . esc_html( (string) $b['end_date'] ) . '</td><td>' . esc_html( (string) $weeks ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="tt-empty">' . esc_html__( 'No macro-blocks configured for this season/team yet.', 'talenttrack' ) . '</p>';
        }

        // Bulk JSON replace form (v1 power-user form; richer UI in Phase 2).
        echo '<details style="margin-top:24px;padding:12px;background:#f5f5f5;border-radius:8px;">';
        echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Replace block set (paste JSON)', 'talenttrack' ) . '</summary>';
        echo '<form method="POST" action="" style="margin-top:12px;">';
        wp_nonce_field( 'tt_vct_cfg_blocks_save', '_tt_vct_cfg_nonce' );
        echo '<input type="hidden" name="_tt_action" value="save_blocks">';
        echo '<input type="hidden" name="season_id"  value="' . esc_attr( (string) $season_id ) . '">';
        echo '<input type="hidden" name="team_id"    value="' . esc_attr( (string) $team_id ) . '">';
        echo '<label style="display:block;"><span>' . esc_html__( 'Blocks JSON array', 'talenttrack' ) . '</span>'
            . '<textarea name="blocks_json" rows="10" style="width:100%;font-family:monospace;font-size:12px;" placeholder=\'[{"sequence":1,"label":"Block 1","start_date":"2026-08-01","end_date":"2026-09-12","phase_profile":[{"week":1,"phase":"introductie","multiplier":0.85}]}]\'></textarea>'
            . '</label>';
        echo '<p class="description">' . esc_html__( 'Each block: { sequence, label, start_date, end_date, phase_profile: [{week, phase, multiplier}] }. Server validates contiguous sequences, no overlaps, valid YYYY-MM-DD.', 'talenttrack' ) . '</p>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Replace block set', 'talenttrack' ) . '</button>';
        echo '</form></details>';
    }

    // ── AGE PROFILES ─────────────────────────────────────────────────

    private static function renderAgeProfilesTab(): void {
        $profiles = ( new VctAgeProfilesRepository() )->listAll();
        if ( ! $profiles ) {
            echo '<div class="tt-notice tt-notice--info" style="padding:12px 16px;">';
            echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'No age profiles are set up yet.', 'talenttrack' ) . '</strong></p>';
            echo '<p style="margin:0;">' . esc_html__( 'Age profiles cap how long and how intensely each team can train safely, by age group. Until they exist, the VCT planner can\'t build a training. Ask your academy administrator to add them — they\'re part of the standard VCT setup for your club.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<p>' . esc_html__( 'Tune the per-age workload envelope. Coaches see the resulting ceiling on the wizard\'s Duration step + the engine enforces it everywhere.', 'talenttrack' ) . '</p>';

        foreach ( $profiles as $p ) {
            echo '<details style="margin:8px 0;padding:12px;background:#f9f9f9;border-radius:6px;">';
            echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html( (string) $p['age_group'] )
                . ' — ' . esc_html( sprintf(
                    /* translators: 1: minutes max, 2: intensity ceiling */
                    __( '%1$d min · band %2$d', 'talenttrack' ),
                    (int) $p['session_minutes_max'], (int) $p['intensity_band_max']
                ) ) . '</summary>';
            echo '<form method="POST" action="" style="margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
            wp_nonce_field( 'tt_vct_cfg_age_save_' . (int) $p['id'], '_tt_vct_cfg_nonce' );
            echo '<input type="hidden" name="_tt_action" value="save_age_profile">';
            echo '<input type="hidden" name="id"         value="' . esc_attr( (string) $p['id'] ) . '">';
            self::renderNumberInput( 'session_minutes_max',               __( 'Session minutes max',               'talenttrack' ), (int) $p['session_minutes_max'],               30, 180 );
            self::renderNumberInput( 'intensity_band_max',                __( 'Intensity band max (1-10)',         'talenttrack' ), (int) $p['intensity_band_max'],                1, 10 );
            self::renderNumberInput( 'min_recovery_hours_between_high',   __( 'Min recovery hours between high',   'talenttrack' ), (int) $p['min_recovery_hours_between_high'],   12, 168 );
            self::renderNumberInput( 'growth_spurt_load_reduction_pct',   __( 'PHV load reduction %',              'talenttrack' ), (int) $p['growth_spurt_load_reduction_pct'],   0, 50 );
            self::renderNumberInput( 'weekly_load_envelope',              __( 'Weekly load envelope',              'talenttrack' ), (int) $p['weekly_load_envelope'],              50, 10000 );
            echo '<label><span>' . esc_html__( 'Match load multiplier per minute', 'talenttrack' ) . '</span>'
                . '<input type="number" inputmode="decimal" step="0.1" min="0" max="20" name="match_load_multiplier_per_minute" value="' . esc_attr( (string) $p['match_load_multiplier_per_minute'] ) . '"></label>';
            echo '<label style="grid-column:1 / -1;"><input type="checkbox" name="md_logic_enabled" value="1" ' . checked( $p['md_logic_enabled'], true, false ) . '> '
                . esc_html__( 'MD logic enabled (off for U10/U11 per Appendix A)', 'talenttrack' )
                . '</label>';
            echo '<button type="submit" class="tt-btn tt-btn-primary" style="grid-column:1 / -1;margin-top:8px;">' . esc_html__( 'Save', 'talenttrack' ) . '</button>';
            echo '</form>';
            echo '</details>';
        }
    }

    // ── SCHEDULES ────────────────────────────────────────────────────

    private static function renderSchedulesTab(): void {
        $season_id = isset( $_GET['season_id'] ) ? absint( $_GET['season_id'] ) : 0;

        echo '<p>' . esc_html__( 'Set per-team weekly VCT training days. Drives the wizard\'s date-default to the next configured weekday.', 'talenttrack' ) . '</p>';

        echo '<form method="GET" action="" style="display:flex;gap:8px;align-items:end;margin:0 0 16px;">';
        echo '<input type="hidden" name="tt_view" value="vct-config">';
        echo '<input type="hidden" name="tab"     value="schedules">';
        echo '<label><span>' . esc_html__( 'Season ID', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="season_id" min="1" value="' . esc_attr( (string) $season_id ) . '"></label>';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Load', 'talenttrack' ) . '</button>';
        echo '</form>';

        if ( $season_id <= 0 ) {
            echo '<p class="tt-empty">' . esc_html__( 'Enter a Season ID to edit team schedules for that season.', 'talenttrack' ) . '</p>';
            return;
        }

        $teams_repo = new VctTeamSchedulesRepository();
        $teams = QueryHelpers::get_teams();
        if ( ! $teams ) {
            echo '<p class="tt-empty">' . esc_html__( 'No teams yet. Create your teams under Teams first, then come back here to set their training days.', 'talenttrack' ) . '</p>';
            return;
        }

        $weekday_labels = [ 1 => __( 'Mon', 'talenttrack' ), 2 => __( 'Tue', 'talenttrack' ), 4 => __( 'Wed', 'talenttrack' ), 8 => __( 'Thu', 'talenttrack' ), 16 => __( 'Fri', 'talenttrack' ), 32 => __( 'Sat', 'talenttrack' ), 64 => __( 'Sun', 'talenttrack' ) ];

        foreach ( $teams as $t ) {
            $team_id = (int) $t->id;
            $row = $teams_repo->findForTeamSeason( $team_id, $season_id );
            $bitmask = $row !== null ? (int) $row['weekdays_bitmask'] : 0;
            $start   = $row !== null ? (string) ( $row['default_start_time'] ?? '' ) : '';
            $dur     = $row !== null && $row['default_duration_minutes'] !== null ? (string) $row['default_duration_minutes'] : '';

            echo '<details style="margin:6px 0;padding:12px;background:#f9f9f9;border-radius:6px;">';
            echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html( (string) $t->name )
                . ( ! empty( $t->age_group ) ? ' (' . esc_html( (string) $t->age_group ) . ')' : '' )
                . '</summary>';
            echo '<form method="POST" action="" style="margin-top:8px;">';
            wp_nonce_field( 'tt_vct_cfg_schedule_save_' . $team_id . '_' . $season_id, '_tt_vct_cfg_nonce' );
            echo '<input type="hidden" name="_tt_action" value="save_schedule">';
            echo '<input type="hidden" name="team_id"    value="' . esc_attr( (string) $team_id ) . '">';
            echo '<input type="hidden" name="season_id"  value="' . esc_attr( (string) $season_id ) . '">';

            echo '<fieldset style="margin:8px 0;padding:8px;border:1px solid #ddd;">';
            echo '<legend>' . esc_html__( 'Training days', 'talenttrack' ) . '</legend>';
            foreach ( $weekday_labels as $bit => $label ) {
                $checked = ( $bitmask & $bit ) === $bit ? 'checked' : '';
                echo '<label style="display:inline-block;margin-right:10px;font-weight:normal;"><input type="checkbox" name="weekday_bits[]" value="' . esc_attr( (string) $bit ) . '" ' . $checked . '> ' . esc_html( $label ) . '</label>';
            }
            echo '</fieldset>';

            echo '<label><span>' . esc_html__( 'Default start time', 'talenttrack' ) . '</span><input type="time" name="default_start_time" value="' . esc_attr( $start ) . '"></label>';
            echo '<label style="margin-left:12px;"><span>' . esc_html__( 'Default duration (minutes)', 'talenttrack' ) . '</span><input type="number" inputmode="numeric" name="default_duration_minutes" min="20" max="180" step="5" value="' . esc_attr( $dur ) . '"></label>';
            echo '<br><button type="submit" class="tt-btn tt-btn-primary" style="margin-top:8px;">' . esc_html__( 'Save schedule', 'talenttrack' ) . '</button>';
            echo '</form></details>';
        }
    }

    private static function renderNumberInput( string $name, string $label, int $value, int $min, int $max ): void {
        echo '<label><span>' . esc_html( $label ) . '</span>'
            . '<input type="number" inputmode="numeric" name="' . esc_attr( $name ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $value ) . '" required></label>';
    }

    // ── POST handlers ────────────────────────────────────────────────

    private static function handlePost(): void {
        $action = isset( $_POST['_tt_action'] ) ? sanitize_key( (string) $_POST['_tt_action'] ) : '';

        if ( $action === 'save_blocks' ) {
            if ( ! wp_verify_nonce( (string) ( $_POST['_tt_vct_cfg_nonce'] ?? '' ), 'tt_vct_cfg_blocks_save' ) ) {
                self::notice( 'error', __( 'Save failed: session expired. Please reload.', 'talenttrack' ) );
                return;
            }
            $season_id = absint( $_POST['season_id'] ?? 0 );
            $team_id   = absint( $_POST['team_id']   ?? 0 );
            $raw       = (string) ( $_POST['blocks_json'] ?? '' );
            $blocks    = json_decode( $raw, true );
            if ( ! is_array( $blocks ) ) {
                self::notice( 'error', __( 'Save failed: blocks_json is not valid JSON.', 'talenttrack' ) );
                return;
            }
            $ok = ( new VctMacroBlocksRepository() )->replaceForSeason( $team_id, $season_id, $blocks );
            self::notice(
                $ok ? 'success' : 'error',
                $ok
                    ? sprintf(
                        /* translators: %d is the block count */
                        __( 'Replaced macro-blocks for the season (%d blocks).', 'talenttrack' ),
                        count( $blocks )
                    )
                    : __( 'Save failed: database error.', 'talenttrack' )
            );
            return;
        }

        if ( $action === 'save_age_profile' ) {
            $id = absint( $_POST['id'] ?? 0 );
            if ( ! wp_verify_nonce( (string) ( $_POST['_tt_vct_cfg_nonce'] ?? '' ), 'tt_vct_cfg_age_save_' . $id ) ) {
                self::notice( 'error', __( 'Save failed: session expired. Please reload.', 'talenttrack' ) );
                return;
            }
            $patch = [
                'session_minutes_max'              => (int)   ( $_POST['session_minutes_max']             ?? 0 ),
                'intensity_band_max'               => (int)   ( $_POST['intensity_band_max']              ?? 0 ),
                'md_logic_enabled'                 => ! empty( $_POST['md_logic_enabled'] ) ? 1 : 0,
                'min_recovery_hours_between_high'  => (int)   ( $_POST['min_recovery_hours_between_high'] ?? 0 ),
                'growth_spurt_load_reduction_pct'  => (int)   ( $_POST['growth_spurt_load_reduction_pct'] ?? 0 ),
                'weekly_load_envelope'             => (int)   ( $_POST['weekly_load_envelope']            ?? 0 ),
                'match_load_multiplier_per_minute' => (float) ( $_POST['match_load_multiplier_per_minute'] ?? 7.0 ),
            ];
            $ok = ( new VctAgeProfilesRepository() )->update( $id, $patch );
            self::notice(
                $ok ? 'success' : 'error',
                $ok ? __( 'Age profile updated.', 'talenttrack' ) : __( 'Save failed: database error.', 'talenttrack' )
            );
            return;
        }

        if ( $action === 'save_schedule' ) {
            $team_id   = absint( $_POST['team_id']   ?? 0 );
            $season_id = absint( $_POST['season_id'] ?? 0 );
            if ( ! wp_verify_nonce( (string) ( $_POST['_tt_vct_cfg_nonce'] ?? '' ), 'tt_vct_cfg_schedule_save_' . $team_id . '_' . $season_id ) ) {
                self::notice( 'error', __( 'Save failed: session expired. Please reload.', 'talenttrack' ) );
                return;
            }
            $bits = 0;
            foreach ( (array) ( $_POST['weekday_bits'] ?? [] ) as $b ) {
                $bits |= (int) $b;
            }
            $start_time = isset( $_POST['default_start_time'] ) ? (string) $_POST['default_start_time'] : '';
            $duration   = isset( $_POST['default_duration_minutes'] ) ? (int) $_POST['default_duration_minutes'] : 0;
            $ok = ( new VctTeamSchedulesRepository() )->upsert(
                $team_id, $season_id, $bits,
                $start_time !== '' ? $start_time : null,
                $duration > 0 ? $duration : null,
                get_current_user_id()
            );
            self::notice(
                $ok ? 'success' : 'error',
                $ok ? __( 'Team schedule saved.', 'talenttrack' ) : __( 'Save failed: database error.', 'talenttrack' )
            );
        }
    }

    private static function notice( string $variant, string $msg ): void {
        $bg = $variant === 'error' ? '#fdecea' : ( $variant === 'success' ? '#e9f5e9' : '#fff8e1' );
        $bar = $variant === 'error' ? '#b32d2e' : ( $variant === 'success' ? '#2c8a2c' : '#dba617' );
        echo '<div class="tt-notice tt-notice--' . esc_attr( $variant ) . '" style="margin:8px 0 16px;padding:12px;background:' . esc_attr( $bg ) . ';border-left:4px solid ' . esc_attr( $bar ) . ';">'
            . esc_html( $msg ) . '</div>';
    }
}
