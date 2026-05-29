<?php
namespace TT\Modules\MatchPrep\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMatchPrepView (#965) — head-coach match preparation surface.
 *
 * 3-column layout matching the pilot's working spreadsheet:
 *   - Left (200px): roster with minute counters per half + totals.
 *   - Middle (flex): two half-pitches side by side with a copy button
 *     between them; below them the Wedstrijddoelen panel (Algemeen
 *     full-width, then 2×2 of Aanvallen/Verdedigen and Spelhervattingen).
 *   - Right (320px): stacked panels — Doen per speler (per-player
 *     attention + ! flag + camera-icon analyst flag) and Rollen &
 *     standaardsituaties (captain + 5 set-piece takers).
 *
 * Player picker is the single interaction model for both pitch slots
 * and role rows; drag-drop from the roster is the desktop enhancement
 * for slot assignment. All edits live-save via REST.
 *
 * Desktop-only per the original #838 spec; the layout collapses to one
 * column below 1099px without crashing, but mobile polish is a
 * follow-up.
 */
class FrontendMatchPrepView extends FrontendViewBase {

    /**
     * Slot positions per formation shape. Coordinates are percentage
     * offsets from the pitch container (0 = top/left, 100 =
     * bottom/right). The 4-3-3 default mirrors the mockup; the rest
     * are sensible starting layouts. v2 can read from
     * tt_formation_templates.slots_json instead — keeping this in PHP
     * keeps the v1 shipping surface narrow.
     *
     * @return array<string, list<array{num:int,label:string,x:float,y:float}>>
     */
    public static function defaultSlotLayouts(): array {
        return [
            '4-3-3' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 12 ],
                [ 'num' => 11, 'label' => 'LW',  'x' => 18, 'y' => 28 ],
                [ 'num' => 10, 'label' => 'AM',  'x' => 50, 'y' => 28 ],
                [ 'num' =>  7, 'label' => 'RW',  'x' => 82, 'y' => 28 ],
                [ 'num' =>  8, 'label' => 'LCM', 'x' => 36, 'y' => 45 ],
                [ 'num' =>  6, 'label' => 'RCM', 'x' => 64, 'y' => 45 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 64 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 64 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 64 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 64 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '4-2-3-1' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LAM', 'x' => 20, 'y' => 30 ],
                [ 'num' => 10, 'label' => 'AM',  'x' => 50, 'y' => 30 ],
                [ 'num' =>  7, 'label' => 'RAM', 'x' => 80, 'y' => 30 ],
                [ 'num' =>  8, 'label' => 'LDM', 'x' => 36, 'y' => 50 ],
                [ 'num' =>  6, 'label' => 'RDM', 'x' => 64, 'y' => 50 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 68 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 68 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 68 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 68 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '4-4-2' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 38, 'y' => 14 ],
                [ 'num' => 10, 'label' => 'ST',  'x' => 62, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LM',  'x' => 14, 'y' => 38 ],
                [ 'num' =>  8, 'label' => 'LCM', 'x' => 38, 'y' => 38 ],
                [ 'num' =>  6, 'label' => 'RCM', 'x' => 62, 'y' => 38 ],
                [ 'num' =>  7, 'label' => 'RM',  'x' => 86, 'y' => 38 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 64 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 64 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 64 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 64 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '3-5-2' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 38, 'y' => 14 ],
                [ 'num' => 10, 'label' => 'ST',  'x' => 62, 'y' => 14 ],
                [ 'num' =>  7, 'label' => 'RWB', 'x' => 88, 'y' => 38 ],
                [ 'num' =>  8, 'label' => 'CM',  'x' => 36, 'y' => 40 ],
                [ 'num' =>  6, 'label' => 'CM',  'x' => 50, 'y' => 46 ],
                [ 'num' =>  4, 'label' => 'CM',  'x' => 64, 'y' => 40 ],
                [ 'num' => 11, 'label' => 'LWB', 'x' => 12, 'y' => 38 ],
                [ 'num' =>  5, 'label' => 'LCB', 'x' => 26, 'y' => 66 ],
                [ 'num' =>  3, 'label' => 'CB',  'x' => 50, 'y' => 68 ],
                [ 'num' =>  2, 'label' => 'RCB', 'x' => 74, 'y' => 66 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '3-4-3' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LW',  'x' => 20, 'y' => 22 ],
                [ 'num' =>  7, 'label' => 'RW',  'x' => 80, 'y' => 22 ],
                [ 'num' => 10, 'label' => 'LM',  'x' => 26, 'y' => 44 ],
                [ 'num' =>  8, 'label' => 'LCM', 'x' => 42, 'y' => 46 ],
                [ 'num' =>  6, 'label' => 'RCM', 'x' => 58, 'y' => 46 ],
                [ 'num' =>  4, 'label' => 'RM',  'x' => 74, 'y' => 44 ],
                [ 'num' =>  5, 'label' => 'LCB', 'x' => 26, 'y' => 68 ],
                [ 'num' =>  3, 'label' => 'CB',  'x' => 50, 'y' => 68 ],
                [ 'num' =>  2, 'label' => 'RCB', 'x' => 74, 'y' => 68 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '4-1-4-1' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LM',  'x' => 14, 'y' => 34 ],
                [ 'num' => 10, 'label' => 'LCM', 'x' => 38, 'y' => 34 ],
                [ 'num' =>  8, 'label' => 'RCM', 'x' => 62, 'y' => 34 ],
                [ 'num' =>  7, 'label' => 'RM',  'x' => 86, 'y' => 34 ],
                [ 'num' =>  6, 'label' => 'CDM', 'x' => 50, 'y' => 50 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 66 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 66 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 66 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 66 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
        ];
    }

    /**
     * Role keys + Dutch labels for the Rollen & standaardsituaties pane.
     * Mirrors `MatchPrepRepository::ROLE_KEYS` order.
     *
     * @return list<array{key:string,label:string}>
     */
    public static function roleDefinitions(): array {
        return [
            [ 'key' => 'captain',  'label' => __( 'Captain', 'talenttrack' ) ],
            [ 'key' => 'corner_l', 'label' => __( 'Corner left', 'talenttrack' ) ],
            [ 'key' => 'corner_r', 'label' => __( 'Corner right', 'talenttrack' ) ],
            [ 'key' => 'fk_l',     'label' => __( 'Free kick left (cross)', 'talenttrack' ) ],
            [ 'key' => 'fk_r',     'label' => __( 'Free kick right (cross)', 'talenttrack' ) ],
            [ 'key' => 'penalty',  'label' => __( 'Penalty', 'talenttrack' ) ],
        ];
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match prep is restricted to coaches and admins.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Open Match prep from a match activity\'s detail page.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity = self::loadActivity( $activity_id );
        if ( ! $activity ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>';
            return;
        }
        // Note: 'match' is a legacy synonym for ActivityTypeKey::GAME kept
        // for back-compat — see #988 follow-up.
        if ( ( $activity->activity_type_key ?? '' ) !== ActivityTypeKey::GAME && ( $activity->activity_type_key ?? '' ) !== 'match' ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match prep is only available for match-type activities.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) {
            // Send back to the wizard so AvailabilityStep can collect
            // the roster first.
            $wizard_url = add_query_arg( [
                'tt_view'     => 'wizard',
                'slug'        => 'match-prep',
                'activity_id' => $activity_id,
            ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) );
            wp_safe_redirect( $wizard_url );
            exit;
        }

        $prep_id      = (int) $prep->id;
        $availability = $repo->listAvailability( $prep_id );
        $lineup_rows  = $repo->listLineup( $prep_id );
        $player_goals = $repo->listPlayerGoals( $prep_id );
        $roles        = $repo->listRoles( $prep_id );

        // Build helper structures.
        $players_by_id = self::loadTeamRosterById( (int) $activity->team_id );
        $roster_list   = self::sortRoster( $players_by_id );

        $availability_by_pid = [];
        $available_ids       = [];
        foreach ( $availability as $a ) {
            $pid = (int) $a->player_id;
            $availability_by_pid[ $pid ] = [
                'status' => (string) ( $a->status ?? 'Present' ),
                'reason' => (string) ( $a->reason ?? '' ),
            ];
            if ( strcasecmp( (string) $a->status, 'Present' ) === 0 ) {
                $available_ids[] = $pid;
            }
        }

        $lineup_by_half = [ 1 => [], 2 => [] ];
        foreach ( $lineup_rows as $l ) {
            $lineup_by_half[ (int) $l->half ][ (int) $l->slot_number ] = (int) $l->player_id;
        }

        $pgoals_by_pid = [];
        foreach ( $player_goals as $g ) {
            $pgoals_by_pid[ (int) $g->player_id ] = $g;
        }

        $roles_by_key = [];
        foreach ( $roles as $r ) {
            $roles_by_key[ (string) $r->role_key ] = (int) $r->player_id;
        }

        $formations  = self::listFormationTemplates();
        $formation_shape = self::resolveFormationShape( (int) ( $prep->formation_template_id ?? 0 ), $formations );

        $title = sprintf(
            /* translators: 1: activity title, 2: session date */
            __( 'Match prep — %1$s · %2$s', 'talenttrack' ),
            (string) ( $activity->title ?? '—' ),
            (string) ( $activity->session_date ?? '' )
        );

        $back_to_activity = add_query_arg( [
            'tt_view' => 'activities',
            'id'      => $activity_id,
        ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) );

        FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ), [
            [ 'label' => __( 'Activities', 'talenttrack' ), 'href' => add_query_arg( [ 'tt_view' => 'activities' ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) ) ],
        ] );

        parent::enqueueAssets();
        self::enqueueViewAssets( $prep_id, $activity_id );

        $half_length = (int) ( $prep->half_length_minutes ?? 35 );
        ?>
        <h1 class="tt-fview-title tt-match-prep-title"><?php echo esc_html( $title ); ?></h1>

        <section class="tt-match-prep"
                 data-activity-id="<?php echo (int) $activity_id; ?>"
                 data-prep-id="<?php echo (int) $prep_id; ?>"
                 data-formation-shape="<?php echo esc_attr( $formation_shape ); ?>"
                 data-half-length="<?php echo (int) $half_length; ?>"
                 data-cancel-url="<?php echo esc_url( $back_to_activity ); ?>">

            <!-- Toolbar: formation / half length / availability / print / save state -->
            <div class="tt-mp-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Match prep toolbar', 'talenttrack' ); ?>">
                <div class="tt-mp-field">
                    <label for="tt-mp-formation"><?php esc_html_e( 'Formation', 'talenttrack' ); ?></label>
                    <select id="tt-mp-formation" name="formation_template_id" data-tt-mp-formation>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $formations as $tpl ) :
                            $tpl_id    = (int) ( $tpl->id ?? 0 );
                            $tpl_name  = (string) ( $tpl->name ?? '' );
                            $tpl_shape = (string) ( $tpl->formation_shape ?? '' );
                            ?>
                            <option value="<?php echo esc_attr( (string) $tpl_id ); ?>"
                                    data-shape="<?php echo esc_attr( $tpl_shape ); ?>"
                                    <?php selected( (int) ( $prep->formation_template_id ?? 0 ), $tpl_id ); ?>>
                                <?php echo esc_html( $tpl_shape !== '' ? $tpl_shape . ' — ' . $tpl_name : $tpl_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-mp-field">
                    <label for="tt-mp-halflen"><?php esc_html_e( 'Half length', 'talenttrack' ); ?></label>
                    <input id="tt-mp-halflen"
                           type="number"
                           min="1"
                           max="60"
                           inputmode="numeric"
                           value="<?php echo (int) $half_length; ?>"
                           data-tt-mp-halflen>
                    <span class="tt-mp-unit"><?php esc_html_e( 'min', 'talenttrack' ); ?></span>
                </div>
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-mp-open-availability>
                    <?php esc_html_e( 'Availability', 'talenttrack' ); ?>
                </button>
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-mp-print>
                    <?php esc_html_e( 'Print (landscape A4)', 'talenttrack' ); ?>
                </button>
                <span class="tt-mp-spacer"></span>
                <span class="tt-mp-save-state" data-tt-mp-save-state aria-live="polite"><?php esc_html_e( 'All changes saved.', 'talenttrack' ); ?></span>
            </div>

            <!-- ===== 3-COLUMN GRID ===== -->
            <div class="tt-mp-grid">

                <!-- LEFT — roster with minute counters -->
                <section class="tt-mp-panel tt-mp-roster-panel" aria-label="<?php esc_attr_e( 'Selection · minutes', 'talenttrack' ); ?>">
                    <header class="tt-mp-panel-head"><?php esc_html_e( 'Selection · minutes', 'talenttrack' ); ?></header>
                    <table class="tt-mp-roster">
                        <thead>
                            <tr>
                                <th scope="col" class="tt-mp-col-name"></th>
                                <th scope="col"><?php esc_html_e( 'min', 'talenttrack' ); ?><br>1e</th>
                                <th scope="col"><?php esc_html_e( 'min', 'talenttrack' ); ?><br>2e</th>
                                <th scope="col"><?php esc_html_e( 'tot', 'talenttrack' ); ?></th>
                            </tr>
                        </thead>
                        <tbody data-tt-mp-roster>
                        <?php if ( empty( $available_ids ) ) : ?>
                            <tr><td colspan="4" class="tt-mp-empty">
                                <?php esc_html_e( 'No availability captured yet.', 'talenttrack' ); ?><br>
                                <a href="#" data-tt-mp-open-availability><?php esc_html_e( 'Open availability', 'talenttrack' ); ?></a>
                            </td></tr>
                        <?php else :
                            foreach ( $available_ids as $pid ) :
                                if ( ! isset( $players_by_id[ $pid ] ) ) continue;
                                $pl    = $players_by_id[ $pid ];
                                $name  = QueryHelpers::player_display_name( $pl );
                                $on1   = in_array( $pid, $lineup_by_half[1], true );
                                $on2   = in_array( $pid, $lineup_by_half[2], true );
                                $min1  = $on1 ? $half_length : 0;
                                $min2  = $on2 ? $half_length : 0;
                                ?>
                                <tr data-pid="<?php echo (int) $pid; ?>"
                                    class="tt-mp-roster-row <?php echo ( $on1 || $on2 ) ? 'tt-mp-assigned' : 'tt-mp-unassigned'; ?>"
                                    draggable="true">
                                    <td class="tt-mp-col-name" data-tt-mp-name><?php echo esc_html( $name ); ?></td>
                                    <td class="tt-mp-col-min <?php echo $on1 ? 'tt-mp-on' : 'tt-mp-off'; ?>" data-tt-mp-min="1"><?php echo (int) $min1; ?></td>
                                    <td class="tt-mp-col-min <?php echo $on2 ? 'tt-mp-on' : 'tt-mp-off'; ?>" data-tt-mp-min="2"><?php echo (int) $min2; ?></td>
                                    <td class="tt-mp-col-tot <?php echo ( $on1 || $on2 ) ? 'tt-mp-on' : 'tt-mp-off'; ?>" data-tt-mp-min="tot"><?php echo (int) ( $min1 + $min2 ); ?></td>
                                </tr>
                                <?php
                            endforeach;
                        endif;
                        ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="tt-mp-foot-blank"></td>
                                <td data-tt-mp-foot="count1">0</td>
                                <td data-tt-mp-foot="count2">0</td>
                                <td class="tt-mp-foot-blank"></td>
                            </tr>
                            <tr>
                                <td class="tt-mp-foot-blank"></td>
                                <td data-tt-mp-foot="min1">0</td>
                                <td data-tt-mp-foot="min2">0</td>
                                <td data-tt-mp-foot="mintot">0</td>
                            </tr>
                            <tr class="tt-mp-foot-half">
                                <td class="tt-mp-foot-blank"></td>
                                <td><span class="tt-mp-pill tt-mp-pill-1e">1e</span></td>
                                <td><span class="tt-mp-pill tt-mp-pill-2e">2e</span></td>
                                <td><?php esc_html_e( 'tot', 'talenttrack' ); ?></td>
                            </tr>
                            <tr>
                                <td class="tt-mp-foot-blank"></td>
                                <td data-tt-mp-foot="hl1"><?php echo (int) $half_length; ?></td>
                                <td data-tt-mp-foot="hl2"><?php echo (int) $half_length; ?></td>
                                <td data-tt-mp-foot="hltot"><?php echo (int) ( $half_length * 2 ); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </section>

                <!-- MIDDLE — pitches + tactical goals -->
                <section class="tt-mp-middle">
                    <div class="tt-mp-pitches" data-tt-mp-pitches>
                        <div class="tt-mp-pitch tt-mp-pitch-1e" data-half="1" aria-label="<?php esc_attr_e( 'First half lineup', 'talenttrack' ); ?>">
                            <div class="tt-mp-pitch-label">1e</div>
                            <svg class="tt-mp-pitch-svg" viewBox="0 0 680 750" preserveAspectRatio="none" aria-hidden="true">
                                <rect class="tt-mp-pitch-ln" x="20" y="20" width="640" height="710"/>
                                <line class="tt-mp-pitch-ln" x1="20" y1="375" x2="660" y2="375"/>
                                <circle class="tt-mp-pitch-ln" cx="340" cy="375" r="78"/>
                                <rect class="tt-mp-pitch-ln" x="138.4" y="565" width="403.2" height="165"/>
                                <rect class="tt-mp-pitch-ln" x="248.4" y="675" width="183.2" height="55"/>
                            </svg>
                            <!-- slots rendered by JS -->
                        </div>
                        <div class="tt-mp-copy-col">
                            <button type="button"
                                    class="tt-mp-copy-btn"
                                    data-tt-mp-copy-half
                                    title="<?php esc_attr_e( 'Copy 1st half lineup to 2nd half', 'talenttrack' ); ?>"
                                    aria-label="<?php esc_attr_e( 'Copy 1st half lineup to 2nd half', 'talenttrack' ); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12h14M13 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>
                        <div class="tt-mp-pitch tt-mp-pitch-2e" data-half="2" aria-label="<?php esc_attr_e( 'Second half lineup', 'talenttrack' ); ?>">
                            <div class="tt-mp-pitch-label">2e</div>
                            <svg class="tt-mp-pitch-svg" viewBox="0 0 680 750" preserveAspectRatio="none" aria-hidden="true">
                                <rect class="tt-mp-pitch-ln" x="20" y="20" width="640" height="710"/>
                                <line class="tt-mp-pitch-ln" x1="20" y1="375" x2="660" y2="375"/>
                                <circle class="tt-mp-pitch-ln" cx="340" cy="375" r="78"/>
                                <rect class="tt-mp-pitch-ln" x="138.4" y="565" width="403.2" height="165"/>
                                <rect class="tt-mp-pitch-ln" x="248.4" y="675" width="183.2" height="55"/>
                            </svg>
                        </div>
                    </div>

                    <div class="tt-mp-goals">
                        <h2 class="tt-mp-goals-title"><?php esc_html_e( 'Match goals', 'talenttrack' ); ?></h2>

                        <?php
                        $goal_groups = [
                            'goals_general'         => [ 'label' => __( 'General', 'talenttrack' ),         'cls' => 'tt-mp-gbox-full' ],
                            'goals_attack'          => [ 'label' => __( 'Attacking', 'talenttrack' ),       'cls' => '' ],
                            'goals_defend'          => [ 'label' => __( 'Defending', 'talenttrack' ),       'cls' => '' ],
                            'goals_attack_setpiece' => [ 'label' => __( 'Set pieces (attack)', 'talenttrack' ),  'cls' => '' ],
                            'goals_defend_setpiece' => [ 'label' => __( 'Set pieces (defend)', 'talenttrack' ),  'cls' => '' ],
                        ];

                        $existing_values = [];
                        foreach ( $goal_groups as $field => $_ ) {
                            $existing_values[ $field ] = (string) ( $prep->{$field} ?? '' );
                        }
                        ?>

                        <div class="tt-mp-gbox tt-mp-gbox-full">
                            <div class="tt-mp-gbox-title"><?php echo esc_html( $goal_groups['goals_general']['label'] ); ?></div>
                            <div class="tt-mp-gbox-lines">
                                <?php
                                $lines = self::splitGoalLines( $existing_values['goals_general'], 4 );
                                foreach ( $lines as $i => $val ) :
                                    ?>
                                    <input type="text"
                                           data-tt-mp-goal="goals_general"
                                           data-tt-mp-goal-line="<?php echo (int) $i; ?>"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           placeholder="<?php echo esc_attr( sprintf( __( 'Goal %d…', 'talenttrack' ), $i + 1 ) ); ?>">
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="tt-mp-goals-row">
                            <?php foreach ( [ 'goals_attack', 'goals_defend' ] as $field ) :
                                $lines = self::splitGoalLines( $existing_values[ $field ], 4 );
                                ?>
                                <div class="tt-mp-gbox">
                                    <div class="tt-mp-gbox-title"><?php echo esc_html( $goal_groups[ $field ]['label'] ); ?></div>
                                    <div class="tt-mp-gbox-lines">
                                        <?php foreach ( $lines as $i => $val ) : ?>
                                            <input type="text"
                                                   data-tt-mp-goal="<?php echo esc_attr( $field ); ?>"
                                                   data-tt-mp-goal-line="<?php echo (int) $i; ?>"
                                                   value="<?php echo esc_attr( $val ); ?>"
                                                   placeholder="<?php esc_attr_e( '…', 'talenttrack' ); ?>">
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="tt-mp-goals-row">
                            <?php foreach ( [ 'goals_attack_setpiece', 'goals_defend_setpiece' ] as $field ) :
                                $lines = self::splitGoalLines( $existing_values[ $field ], 4 );
                                ?>
                                <div class="tt-mp-gbox">
                                    <div class="tt-mp-gbox-title"><?php esc_html_e( 'Set pieces', 'talenttrack' ); ?></div>
                                    <div class="tt-mp-gbox-lines">
                                        <?php foreach ( $lines as $i => $val ) : ?>
                                            <input type="text"
                                                   data-tt-mp-goal="<?php echo esc_attr( $field ); ?>"
                                                   data-tt-mp-goal-line="<?php echo (int) $i; ?>"
                                                   value="<?php echo esc_attr( $val ); ?>"
                                                   placeholder="<?php esc_attr_e( '…', 'talenttrack' ); ?>">
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- RIGHT — doen per speler + rollen -->
                <section class="tt-mp-right">
                    <div class="tt-mp-panel">
                        <header class="tt-mp-panel-head"><?php esc_html_e( 'Player focus', 'talenttrack' ); ?></header>
                        <table class="tt-mp-dps">
                            <thead>
                                <tr>
                                    <th scope="col"></th>
                                    <th scope="col" class="tt-mp-col-text"></th>
                                    <th scope="col" class="tt-mp-col-spec" title="<?php esc_attr_e( 'Specific goal', 'talenttrack' ); ?>">!</th>
                                    <th scope="col" class="tt-mp-col-cam" title="<?php esc_attr_e( 'Video analyst appointed', 'talenttrack' ); ?>">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6h11a2 2 0 0 1 2 2v2.2l4-2.4v12.4l-4-2.4V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/></svg>
                                    </th>
                                </tr>
                            </thead>
                            <tbody data-tt-mp-dps>
                            <?php if ( empty( $available_ids ) ) : ?>
                                <tr><td colspan="4" class="tt-mp-empty"><?php esc_html_e( 'No availability captured yet.', 'talenttrack' ); ?></td></tr>
                            <?php else :
                                foreach ( $available_ids as $pid ) :
                                    if ( ! isset( $players_by_id[ $pid ] ) ) continue;
                                    $pl   = $players_by_id[ $pid ];
                                    $name = QueryHelpers::player_display_name( $pl );
                                    $g    = $pgoals_by_pid[ $pid ] ?? null;
                                    $att  = (string) ( $g->attention_text ?? '' );
                                    $spec = ! empty( $g->is_specific_goal );
                                    $cam  = ! empty( $g->analyst_appointed );
                                    ?>
                                    <tr data-pid="<?php echo (int) $pid; ?>">
                                        <td class="tt-mp-col-name"><?php echo esc_html( $name ); ?></td>
                                        <td class="tt-mp-col-text">
                                            <label class="screen-reader-text" for="tt-mp-att-<?php echo (int) $pid; ?>"><?php esc_html_e( 'Attention text', 'talenttrack' ); ?></label>
                                            <input id="tt-mp-att-<?php echo (int) $pid; ?>"
                                                   type="text"
                                                   data-tt-mp-attention="<?php echo (int) $pid; ?>"
                                                   value="<?php echo esc_attr( $att ); ?>"
                                                   placeholder="<?php esc_attr_e( '…', 'talenttrack' ); ?>">
                                        </td>
                                        <td class="tt-mp-col-spec <?php echo $spec ? 'tt-mp-on' : ''; ?>"
                                            data-tt-mp-spec="<?php echo (int) $pid; ?>"
                                            role="button"
                                            tabindex="0"
                                            aria-pressed="<?php echo $spec ? 'true' : 'false'; ?>"
                                            title="<?php esc_attr_e( 'Specific goal', 'talenttrack' ); ?>">!</td>
                                        <td class="tt-mp-col-cam <?php echo $cam ? 'tt-mp-on' : ''; ?>"
                                            data-tt-mp-cam="<?php echo (int) $pid; ?>"
                                            role="button"
                                            tabindex="0"
                                            aria-pressed="<?php echo $cam ? 'true' : 'false'; ?>"
                                            title="<?php esc_attr_e( 'Video analyst appointed', 'talenttrack' ); ?>">
                                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6h11a2 2 0 0 1 2 2v2.2l4-2.4v12.4l-4-2.4V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/></svg>
                                        </td>
                                    </tr>
                                    <?php
                                endforeach;
                            endif;
                            ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="tt-mp-panel">
                        <header class="tt-mp-panel-head"><?php esc_html_e( 'Roles & set pieces', 'talenttrack' ); ?></header>
                        <ul class="tt-mp-sp-list" data-tt-mp-roles>
                            <?php foreach ( self::roleDefinitions() as $role ) :
                                $key   = (string) $role['key'];
                                $label = (string) $role['label'];
                                $rpid  = (int) ( $roles_by_key[ $key ] ?? 0 );
                                $rname = $rpid > 0 && isset( $players_by_id[ $rpid ] )
                                    ? QueryHelpers::player_display_name( $players_by_id[ $rpid ] )
                                    : '';
                                $filled = $rpid > 0;
                                ?>
                                <li class="tt-mp-sp-row"
                                    data-tt-mp-role="<?php echo esc_attr( $key ); ?>"
                                    data-pid="<?php echo (int) $rpid; ?>"
                                    role="button"
                                    tabindex="0">
                                    <span class="tt-mp-sp-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="tt-mp-sp-pick <?php echo $filled ? 'tt-mp-filled' : ''; ?>">
                                        <span class="tt-mp-sp-name"><?php echo $filled ? esc_html( $rname ) : esc_html__( '— Pick player —', 'talenttrack' ); ?></span>
                                        <?php if ( $filled ) : ?>
                                            <button type="button"
                                                    class="tt-mp-sp-clear"
                                                    data-tt-mp-clear-role="<?php echo esc_attr( $key ); ?>"
                                                    aria-label="<?php esc_attr_e( 'Clear role', 'talenttrack' ); ?>"
                                                    title="<?php esc_attr_e( 'Clear role', 'talenttrack' ); ?>">×</button>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            </div>

            <!-- Picker (slot + role) -->
            <div class="tt-mp-picker-backdrop" data-tt-mp-picker-backdrop hidden></div>
            <div class="tt-mp-picker" data-tt-mp-picker hidden role="dialog" aria-modal="true"></div>

            <!-- Availability drawer -->
            <div class="tt-mp-drawer-backdrop" data-tt-mp-drawer-backdrop hidden></div>
            <aside class="tt-mp-drawer" data-tt-mp-drawer aria-hidden="true">
                <header class="tt-mp-drawer-head">
                    <h3><?php esc_html_e( 'Availability', 'talenttrack' ); ?></h3>
                    <button type="button" class="tt-mp-drawer-x" data-tt-mp-drawer-close aria-label="<?php esc_attr_e( 'Close', 'talenttrack' ); ?>">×</button>
                </header>
                <div class="tt-mp-drawer-body" data-tt-mp-drawer-body></div>
                <footer class="tt-mp-drawer-foot">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-mp-mark-all-present><?php esc_html_e( 'Mark all present', 'talenttrack' ); ?></button>
                    <button type="button" class="tt-btn tt-btn-primary" data-tt-mp-drawer-done><?php esc_html_e( 'Done', 'talenttrack' ); ?></button>
                </footer>
            </aside>
        </section>
        <?php

        // Bootstrap data for the JS — players, formations, current state.
        $bootstrap = [
            'activity_id'    => (int) $activity_id,
            'prep_id'        => (int) $prep_id,
            'half_length'    => (int) $half_length,
            'formation_shape' => $formation_shape,
            'slot_layouts'   => self::defaultSlotLayouts(),
            'roles'          => array_map( static function( array $r ): array {
                return [ 'key' => $r['key'], 'label' => $r['label'] ];
            }, self::roleDefinitions() ),
            'players'        => array_map( static function( $p ): array {
                return [
                    'id'   => (int) ( $p->id ?? 0 ),
                    'name' => QueryHelpers::player_display_name( $p ),
                ];
            }, $roster_list ),
            'availability'   => (object) $availability_by_pid,
            'lineup'         => [
                '1' => (object) $lineup_by_half[1],
                '2' => (object) $lineup_by_half[2],
            ],
            'roles_assigned' => (object) $roles_by_key,
            'attention'      => self::projectAttention( $pgoals_by_pid ),
            'specific'       => self::projectFlag( $pgoals_by_pid, 'is_specific_goal' ),
            'analyst'        => self::projectFlag( $pgoals_by_pid, 'analyst_appointed' ),
            'cancel_url'     => $back_to_activity,
        ];
        ?>
        <script type="application/json" id="tt-match-prep-bootstrap"><?php
            echo wp_json_encode( $bootstrap );
        ?></script>
        <?php
    }

    private static function enqueueViewAssets( int $prep_id, int $activity_id ): void {
        wp_enqueue_style(
            'tt-match-prep',
            TT_PLUGIN_URL . 'assets/css/frontend-match-prep.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-match-prep',
            TT_PLUGIN_URL . 'assets/js/frontend-match-prep.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-match-prep', 'TT_MATCH_PREP', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/match-prep/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'prep_id'    => (int) $prep_id,
            'activity_id' => (int) $activity_id,
            'i18n'       => [
                'saving'          => __( 'Saving…', 'talenttrack' ),
                'saved'           => __( 'All changes saved.', 'talenttrack' ),
                'dirty'           => __( 'Unsaved changes…', 'talenttrack' ),
                'error'           => __( 'Save failed. Try again.', 'talenttrack' ),
                'search'          => __( 'Search player…', 'talenttrack' ),
                'no_players'      => __( 'No available players found.', 'talenttrack' ),
                'clear'           => __( 'Clear', 'talenttrack' ),
                'on_pitch'        => __( 'on pitch', 'talenttrack' ),
                'slot_label'      => __( 'Slot %1$s — %2$s half', 'talenttrack' ),
                'half_1'          => __( '1st', 'talenttrack' ),
                'half_2'          => __( '2nd', 'talenttrack' ),
                'present'         => __( 'Present', 'talenttrack' ),
                'absent_excused'  => __( 'Absent (excused)', 'talenttrack' ),
                'absent_injured'  => __( 'Injured', 'talenttrack' ),
                'reason'          => __( 'Reason (optional)…', 'talenttrack' ),
                'pick_player'     => __( '— Pick player —', 'talenttrack' ),
                'pick_for_role'   => __( 'Pick player for role', 'talenttrack' ),
            ],
        ] );
    }

    private static function loadActivity( int $activity_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, title, session_date, activity_type_key
               FROM {$wpdb->prefix}tt_activities
              WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @return array<int, object> id => player
     *
     * v4.5.1 — query `tt_players.team_id` directly. The pre-fix
     * query joined through a non-existent `tt_team_players` junction
     * table; `tt_players.team_id` is the canonical FK across the
     * codebase. Same defect class as AvailabilityStep::rosterForActivity().
     */
    private static function loadTeamRosterById( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.*
               FROM {$wpdb->prefix}tt_players pl
              WHERE pl.team_id = %d AND pl.club_id = %d AND pl.archived_at IS NULL",
            $team_id, CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = $r;
        }
        return $out;
    }

    /**
     * @param array<int, object> $players_by_id
     * @return list<object>
     */
    private static function sortRoster( array $players_by_id ): array {
        $out = array_values( $players_by_id );
        usort( $out, static function( $a, $b ) {
            $la = strtolower( (string) ( $a->last_name ?? '' ) );
            $lb = strtolower( (string) ( $b->last_name ?? '' ) );
            if ( $la === $lb ) {
                return strcmp(
                    strtolower( (string) ( $a->first_name ?? '' ) ),
                    strtolower( (string) ( $b->first_name ?? '' ) )
                );
            }
            return strcmp( $la, $lb );
        } );
        return $out;
    }

    /** @return list<object> */
    private static function listFormationTemplates(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_formation_templates';
        // Defensive: not every install has migration 0032 applied yet.
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $exists ) return [];
        $rows = $wpdb->get_results(
            "SELECT id, name, formation_shape
               FROM {$table}
              WHERE archived_at IS NULL
              ORDER BY is_seeded DESC, formation_shape ASC, name ASC"
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Resolve the formation shape string for the JS layer.
     *
     * Picks the template's shape if a template is bound; otherwise
     * falls back to the spreadsheet default `4-2-3-1`.
     *
     * @param list<object> $formations
     */
    private static function resolveFormationShape( int $template_id, array $formations ): string {
        if ( $template_id > 0 ) {
            foreach ( $formations as $f ) {
                if ( (int) ( $f->id ?? 0 ) === $template_id ) {
                    $shape = (string) ( $f->formation_shape ?? '' );
                    if ( $shape !== '' ) return $shape;
                }
            }
        }
        return '4-2-3-1';
    }

    /**
     * Split a stored multi-line goal string into a fixed number of
     * input slots. Existing line breaks (\n) act as separators so the
     * v1 textarea-saved data renders without surprise; new entries
     * concatenate the 4 inputs on save in JS.
     *
     * @return list<string>
     */
    private static function splitGoalLines( string $value, int $count ): array {
        $value = (string) $value;
        $lines = $value === '' ? [] : preg_split( "/\r\n|\n|\r/", $value );
        if ( ! is_array( $lines ) ) $lines = [];
        $lines = array_values( array_map( 'strval', $lines ) );
        while ( count( $lines ) < $count ) $lines[] = '';
        return array_slice( $lines, 0, $count );
    }

    /**
     * @param array<int, object> $pgoals_by_pid
     * @return object
     */
    private static function projectAttention( array $pgoals_by_pid ): object {
        $out = [];
        foreach ( $pgoals_by_pid as $pid => $g ) {
            $out[ (string) (int) $pid ] = (string) ( $g->attention_text ?? '' );
        }
        return (object) $out;
    }

    /**
     * @param array<int, object> $pgoals_by_pid
     * @return object
     */
    private static function projectFlag( array $pgoals_by_pid, string $field ): object {
        $out = [];
        foreach ( $pgoals_by_pid as $pid => $g ) {
            if ( ! empty( $g->{$field} ) ) {
                $out[ (string) (int) $pid ] = true;
            }
        }
        return (object) $out;
    }
}
