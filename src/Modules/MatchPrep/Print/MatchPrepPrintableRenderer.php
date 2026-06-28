<?php
namespace TT\Modules\MatchPrep\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchPrepPrintableRenderer (#1059) — shared body renderer for the
 * print router + PDF exporter, mirroring the on-screen match-prep
 * view's content shape and Dutch labels.
 *
 * Why a shared renderer: before #1059, the print router and PDF
 * exporter rendered their bodies independently from the legacy
 * MatchPrepPdfExporter template — table-based numbered lineup,
 * English category labels, per-player notes filtered to "has text +
 * has flag". The on-screen view shows formation pitches, Dutch
 * category labels (Aanvallen / Verdedigen / Spelhervattingen ×2), and
 * a per-player row per available player. Coaches printing for the
 * dugout were getting a different document than the one they laid out.
 *
 * This class produces a single body HTML string from the same data
 * sources the on-screen view uses. Both print surfaces wrap it in
 * their own page chrome.
 *
 * Formation pitches reuse `FrontendMatchPrepView::defaultSlotLayouts()`
 * so the slot-position vocabulary is the on-screen view's, not a
 * print-only re-invention.
 */
final class MatchPrepPrintableRenderer {

    /**
     * Render the body HTML for a match-prep printable. Empty string
     * when the activity isn't found or no prep exists (the caller's
     * page chrome surfaces the "not found" notice if it wants to).
     */
    public static function bodyHtml( int $activity_id, int $club_id ): string {
        global $wpdb;
        $p = $wpdb->prefix;

        // #1059 — ensure plugin textdomain is loaded in print contexts.
        // The print router hooks at template_redirect priority 1; on
        // some installs the textdomain isn't ready yet, which makes the
        // body emit English labels instead of the on-screen Dutch ones.
        if ( function_exists( 'load_plugin_textdomain' ) && defined( 'TT_PLUGIN_FILE' ) ) {
            load_plugin_textdomain( 'talenttrack', false, dirname( plugin_basename( TT_PLUGIN_FILE ) ) . '/languages' );
        }

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.location, a.team_id, a.activity_type_key,
                    a.opponent, a.home_away, a.kickoff_time, a.formation,
                    t.name AS team_name
                FROM {$p}tt_activities a
                LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                WHERE a.id = %d AND a.club_id = %d
                LIMIT 1",
            $activity_id, $club_id
        ) );
        if ( ! $activity ) return '';

        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) return '';

        $prep_id      = (int) $prep->id;
        $availability = $repo->listAvailability( $prep_id );
        $lineup       = $repo->listLineup( $prep_id );
        $player_goals = $repo->listPlayerGoals( $prep_id );

        $player_ids = [];
        foreach ( $availability as $a ) $player_ids[] = (int) $a->player_id;
        foreach ( $lineup as $l )       $player_ids[] = (int) $l->player_id;
        foreach ( $player_goals as $g ) $player_ids[] = (int) $g->player_id;
        $player_ids = array_values( array_unique( array_filter( $player_ids ) ) );

        $players_by_id = [];
        if ( $player_ids ) {
            $in  = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
            $sql = $wpdb->prepare(
                "SELECT id, first_name, last_name, jersey_number FROM {$p}tt_players WHERE id IN ($in) AND club_id = %d",
                array_merge( $player_ids, [ $club_id ] )
            );
            foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
                $players_by_id[ (int) $row->id ] = $row;
            }
        }

        // Lineup pivot by half + slot, same shape as the on-screen view.
        $lineup_by_half    = [ 1 => [], 2 => [] ];
        $pitch_ids_by_half = [ 1 => [], 2 => [] ];
        foreach ( $lineup as $l ) {
            $h    = (int) $l->half;
            $slot = (int) $l->slot_number;
            $pid  = (int) $l->player_id;
            $lineup_by_half[ $h ][ $slot ] = $pid;
            $pitch_ids_by_half[ $h ][] = $pid;
        }

        // Available players + per-player notes index (same data the
        // on-screen view's "Doen per speler" panel iterates).
        $available_ids = [];
        $absent        = [];
        foreach ( $availability as $a ) {
            $pid = (int) $a->player_id;
            if ( strcasecmp( (string) $a->status, 'Present' ) === 0 ) {
                $available_ids[] = $pid;
            } else {
                $absent[] = [
                    'pid'    => $pid,
                    'status' => (string) $a->status,
                    'reason' => (string) ( $a->reason ?? '' ),
                ];
            }
        }
        $pgoal_by_pid = [];
        foreach ( $player_goals as $g ) {
            $pgoal_by_pid[ (int) $g->player_id ] = $g;
        }

        // #1873 — roles (captain + set-piece takers) + half length, the
        // two pieces the on-screen view shows that the print was missing.
        $roles_by_key = [];
        foreach ( $repo->listRoles( $prep_id ) as $r ) {
            $roles_by_key[ (string) $r->role_key ] = (int) $r->player_id;
        }
        $half_length = (int) ( $prep->half_length_minutes ?? 35 );

        // Resolve the slot layout the same way the on-screen view does.
        // #2099 — the bound template's own geometry (slots_json) wins, so a
        // 3-4-3 diamond prints as a diamond. Otherwise the activity's
        // `formation` shape string (e.g. "4-3-3") selects a shape default.
        $formation_shape = (string) ( $activity->formation ?? '' );
        if ( $formation_shape === '' ) $formation_shape = '4-3-3';
        $slot_layouts = FrontendMatchPrepView::defaultSlotLayouts();
        $slots        = FrontendMatchPrepView::templateSlotLayout( (int) ( $prep->formation_template_id ?? 0 ) )
            ?? ( $slot_layouts[ $formation_shape ] ?? $slot_layouts['4-3-3'] );

        ob_start();
        ?>
        <h1><?php echo esc_html( self::title( $activity ) ); ?></h1>
        <p class="tt-mpp-meta"><?php echo esc_html( self::metaLine( $activity, $prep ) ); ?></p>

        <div class="tt-mpp-pitches">
            <?php self::renderPitch( __( '1e helft', 'talenttrack' ), $slots, $lineup_by_half[1], $players_by_id ); ?>
            <?php self::renderPitch( __( '2e helft', 'talenttrack' ), $slots, $lineup_by_half[2], $players_by_id ); ?>
        </div>

        <div class="tt-mpp-bench-row">
            <?php
            $bench_1 = self::benchNames( $availability, $pitch_ids_by_half[1], $players_by_id );
            $bench_2 = self::benchNames( $availability, $pitch_ids_by_half[2], $players_by_id );
            ?>
            <p><strong><?php esc_html_e( 'Bank 1e helft:', 'talenttrack' ); ?></strong> <?php echo esc_html( $bench_1 !== [] ? implode( ', ', $bench_1 ) : '—' ); ?></p>
            <p><strong><?php esc_html_e( 'Bank 2e helft:', 'talenttrack' ); ?></strong> <?php echo esc_html( $bench_2 !== [] ? implode( ', ', $bench_2 ) : '—' ); ?></p>
        </div>

        <?php if ( $available_ids ) :
            // #1873 — Selectie · minuten, mirroring the on-screen left rail:
            // per-player minutes per half (full half length when on the
            // pitch that half) + the total, plus column totals.
            $tot1 = 0; $tot2 = 0; ?>
            <h2><?php esc_html_e( 'Selection · minutes', 'talenttrack' ); ?></h2>
            <table class="tt-mpp-minutes-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Speler', 'talenttrack' ); ?></th>
                    <th class="tt-mpp-num-col"><?php esc_html_e( 'min', 'talenttrack' ); ?> 1e</th>
                    <th class="tt-mpp-num-col"><?php esc_html_e( 'min', 'talenttrack' ); ?> 2e</th>
                    <th class="tt-mpp-num-col"><?php esc_html_e( 'tot', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $available_ids as $pid ) :
                    if ( ! isset( $players_by_id[ $pid ] ) ) continue;
                    $on1  = in_array( $pid, $lineup_by_half[1], true );
                    $on2  = in_array( $pid, $lineup_by_half[2], true );
                    $min1 = $on1 ? $half_length : 0;
                    $min2 = $on2 ? $half_length : 0;
                    $tot1 += $min1; $tot2 += $min2;
                    ?>
                    <tr>
                        <td><?php echo esc_html( QueryHelpers::player_display_name( $players_by_id[ $pid ] ) ); ?></td>
                        <td class="tt-mpp-num-col"><?php echo (int) $min1; ?></td>
                        <td class="tt-mpp-num-col"><?php echo (int) $min2; ?></td>
                        <td class="tt-mpp-num-col"><?php echo (int) ( $min1 + $min2 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th><?php esc_html_e( 'tot', 'talenttrack' ); ?></th>
                    <th class="tt-mpp-num-col"><?php echo (int) $tot1; ?></th>
                    <th class="tt-mpp-num-col"><?php echo (int) $tot2; ?></th>
                    <th class="tt-mpp-num-col"><?php echo (int) ( $tot1 + $tot2 ); ?></th>
                </tr></tfoot>
            </table>
        <?php endif; ?>

        <div class="tt-mpp-bottom">
            <div class="tt-mpp-bottom-col">
                <h2><?php esc_html_e( 'Wedstrijddoelen', 'talenttrack' ); ?></h2>
                <table class="tt-mpp-goals-table">
                    <?php
                    // Same field-set + Dutch labels as the on-screen view
                    // (see FrontendMatchPrepView's renderGoalsPanel).
                    $rows = [
                        'goals_general'         => __( 'Algemeen', 'talenttrack' ),
                        'goals_attack'          => __( 'Aanvallen', 'talenttrack' ),
                        'goals_defend'          => __( 'Verdedigen', 'talenttrack' ),
                        'goals_attack_setpiece' => __( 'Spelhervattingen (aanvallend)', 'talenttrack' ),
                        'goals_defend_setpiece' => __( 'Spelhervattingen (verdedigend)', 'talenttrack' ),
                    ];
                    foreach ( $rows as $field => $label ) :
                        $value = trim( (string) ( $prep->$field ?? '' ) );
                        if ( $value === '' ) continue;
                        ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td><?php echo esc_html( $value ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ( $absent ) : ?>
                    <h2><?php esc_html_e( 'Niet beschikbaar', 'talenttrack' ); ?></h2>
                    <ul class="tt-mpp-absent">
                        <?php foreach ( $absent as $row ) :
                            $pl   = $players_by_id[ $row['pid'] ] ?? null;
                            $name = $pl ? QueryHelpers::player_display_name( $pl ) : '#' . $row['pid'];
                            ?>
                            <li>
                                <?php echo esc_html( $name . ' · ' . $row['status'] ); ?>
                                <?php if ( $row['reason'] !== '' ) : ?>
                                    (<?php echo esc_html( $row['reason'] ); ?>)
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="tt-mpp-bottom-col">
                <h2><?php esc_html_e( 'Doen per speler', 'talenttrack' ); ?></h2>
                <?php if ( ! $available_ids ) : ?>
                    <p class="tt-mpp-empty"><?php esc_html_e( 'Geen beschikbaarheid vastgelegd.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <table class="tt-mpp-dps-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Speler', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Aandachtspunt', 'talenttrack' ); ?></th>
                            <th class="tt-mpp-flag-col" title="<?php esc_attr_e( 'Specifiek doel', 'talenttrack' ); ?>">!</th>
                            <th class="tt-mpp-flag-col" title="<?php esc_attr_e( 'Video-analist toegewezen', 'talenttrack' ); ?>">🎥</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $available_ids as $pid ) :
                            if ( ! isset( $players_by_id[ $pid ] ) ) continue;
                            $name = QueryHelpers::player_display_name( $players_by_id[ $pid ] );
                            $g    = $pgoal_by_pid[ $pid ] ?? null;
                            $att  = trim( (string) ( $g->attention_text ?? '' ) );
                            $spec = ! empty( $g->is_specific_goal );
                            $cam  = ! empty( $g->analyst_appointed );
                            $row_cls = $spec ? ' class="tt-mpp-specific"' : '';
                            ?>
                            <tr<?php echo $row_cls; ?>>
                                <td><?php echo esc_html( $name ); ?></td>
                                <td><?php echo esc_html( $att !== '' ? $att : '—' ); ?></td>
                                <td class="tt-mpp-flag-col"><?php echo $spec ? '!' : ''; ?></td>
                                <td class="tt-mpp-flag-col"><?php echo $cam ? '🎥' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // #1873 — Rollen & standaardsituaties: captain + set-piece takers,
        // reusing the on-screen role definitions + labels.
        $role_defs = FrontendMatchPrepView::roleDefinitions();
        ?>
        <h2><?php esc_html_e( 'Roles & set pieces', 'talenttrack' ); ?></h2>
        <table class="tt-mpp-roles-table">
            <tbody>
            <?php foreach ( $role_defs as $role ) :
                $key   = (string) $role['key'];
                $rpid  = (int) ( $roles_by_key[ $key ] ?? 0 );
                $rname = ( $rpid > 0 && isset( $players_by_id[ $rpid ] ) )
                    ? QueryHelpers::player_display_name( $players_by_id[ $rpid ] )
                    : '—';
                ?>
                <tr>
                    <th><?php echo esc_html( (string) $role['label'] ); ?></th>
                    <td><?php echo esc_html( $rname ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Print-friendly CSS that pairs with `bodyHtml()`. Single block so
     * the print router's standalone document + the PDF exporter's
     * styled-section can both consume it without duplicating tokens.
     */
    public static function styleBlock(): string {
        return <<<CSS
        body { font-family: -apple-system, system-ui, "Segoe UI", Helvetica, Arial, sans-serif; color: #1a1d21; font-size: 11pt; line-height: 1.35; margin: 0; padding: 12px; }
        h1 { font-size: 16pt; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 10px 0 4px; }
        .tt-mpp-meta { color: #5b6e75; font-size: 10pt; margin: 0 0 8px; }
        .tt-mpp-pitches { display: table; width: 100%; margin: 0 0 6px; }
        .tt-mpp-pitch { display: table-cell; width: 50%; padding-right: 8px; vertical-align: top; }
        .tt-mpp-pitch:last-child { padding-right: 0; padding-left: 8px; }
        .tt-mpp-pitch-title { font-weight: 700; margin: 0 0 4px; font-size: 11pt; }
        .tt-mpp-pitch-svg { width: 100%; height: auto; aspect-ratio: 680/420; background: #f5f7f6; border: 1px solid #d6dadd; border-radius: 6px; position: relative; }
        .tt-mpp-slot { position: absolute; transform: translate(-50%, -50%); background: #1d7874; color: #fff; border-radius: 50%; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; font-size: 9pt; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.15); }
        .tt-mpp-slot-empty { background: #d6dadd; color: #5b6e75; }
        .tt-mpp-slot-label { position: absolute; transform: translate(-50%, calc(-50% + 24px)); white-space: nowrap; font-size: 8.5pt; color: #1a1d21; background: rgba(255,255,255,0.85); padding: 1px 4px; border-radius: 3px; max-width: 90px; overflow: hidden; text-overflow: ellipsis; }
        .tt-mpp-bench-row p { margin: 2px 0; font-size: 10pt; color: #5b6e75; }
        .tt-mpp-bench-row strong { color: #1a1d21; }
        .tt-mpp-bottom { display: table; width: 100%; margin-top: 6px; }
        .tt-mpp-bottom-col { display: table-cell; width: 50%; padding-right: 8px; vertical-align: top; }
        .tt-mpp-bottom-col:last-child { padding-right: 0; padding-left: 8px; }
        table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        th, td { border: 1px solid #d6dadd; padding: 3px 5px; text-align: left; font-size: 10pt; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 700; }
        .tt-mpp-flag-col { width: 28px; text-align: center; }
        .tt-mpp-specific { background: #fff5d6; }
        .tt-mpp-empty { color: #5b6e75; font-style: italic; margin: 4px 0; }
        .tt-mpp-absent { margin: 4px 0 0; padding-left: 18px; }
        .tt-mpp-absent li { margin-bottom: 2px; font-size: 10pt; }
        .tt-mpp-num-col { width: 40px; text-align: center; }
        .tt-mpp-minutes-table tfoot th, .tt-mpp-roles-table th { background: #f3f4f6; }
        .tt-mpp-minutes-table, .tt-mpp-roles-table { page-break-inside: avoid; }
        .tt-mpp-roles-table th { width: 45%; }
CSS;
    }

    private static function title( object $activity ): string {
        return trim( sprintf(
            '%s · %s · %s',
            (string) ( $activity->team_name ?? '' ),
            (string) ( $activity->title ?? '' ),
            (string) ( $activity->session_date ?? '' )
        ), ' ·' );
    }

    private static function metaLine( object $activity, object $prep ): string {
        $kick = trim( (string) ( $activity->kickoff_time ?? '' ) );
        $home = ( ( $activity->home_away ?? '' ) === 'home' ) ? __( 'thuis', 'talenttrack' ) : __( 'uit', 'talenttrack' );
        $opp  = (string) ( $activity->opponent ?? '' );
        return sprintf(
            /* translators: 1: opponent, 2: thuis/uit, 3: kickoff_time, 4: formation */
            __( 'vs %1$s · %2$s · KO %3$s · %4$s', 'talenttrack' ),
            $opp !== '' ? $opp : '—',
            $home,
            $kick !== '' ? $kick : '—',
            (string) ( $activity->formation ?? $prep->formation_template_id ?? '—' )
        );
    }

    /**
     * @param array<int,array{num:int,label:string,x:float,y:float}> $slots
     * @param array<int,int> $lineup_by_slot
     * @param array<int,object> $players_by_id
     */
    private static function renderPitch( string $title, array $slots, array $lineup_by_slot, array $players_by_id ): void {
        ?>
        <div class="tt-mpp-pitch">
            <p class="tt-mpp-pitch-title"><?php echo esc_html( $title ); ?></p>
            <div class="tt-mpp-pitch-svg">
                <?php foreach ( $slots as $s ) :
                    $num  = (int) ( $s['num'] ?? 0 );
                    $x    = (float) ( $s['x'] ?? 50 );
                    $y    = (float) ( $s['y'] ?? 50 );
                    $pid  = (int) ( $lineup_by_slot[ $num ] ?? 0 );
                    $pl   = $pid && isset( $players_by_id[ $pid ] ) ? $players_by_id[ $pid ] : null;
                    $name = $pl ? QueryHelpers::player_display_name( $pl ) : '';
                    $cls  = $pl ? 'tt-mpp-slot' : 'tt-mpp-slot tt-mpp-slot-empty';
                    ?>
                    <span class="<?php echo esc_attr( $cls ); ?>"
                          style="left:<?php echo (float) $x; ?>%; top:<?php echo (float) $y; ?>%;">
                        <?php echo (int) $num; ?>
                    </span>
                    <?php if ( $name !== '' ) : ?>
                        <span class="tt-mpp-slot-label"
                              style="left:<?php echo (float) $x; ?>%; top:<?php echo (float) $y; ?>%;">
                            <?php echo esc_html( $name ); ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int,object> $availability
     * @param array<int,int> $pitch_ids
     * @param array<int,object> $players_by_id
     * @return array<int,string>
     */
    private static function benchNames( array $availability, array $pitch_ids, array $players_by_id ): array {
        $out = [];
        foreach ( $availability as $a ) {
            if ( strcasecmp( (string) $a->status, 'Present' ) !== 0 ) continue;
            $pid = (int) $a->player_id;
            if ( in_array( $pid, $pitch_ids, true ) ) continue;
            if ( ! isset( $players_by_id[ $pid ] ) ) continue;
            $out[] = QueryHelpers::player_display_name( $players_by_id[ $pid ] );
        }
        return $out;
    }
}
