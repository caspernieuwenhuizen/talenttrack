<?php
namespace TT\Modules\Goals\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * PlayerGoalIntakePrintRouter (#1064) — printable season-start
 * goal-setting intake form, one player per print job.
 *
 * URL: ?tt_goal_intake_print=1&player_id=N[&season=YYYY/YY]
 *
 * Same isolation pattern as MatchPrepPrintRouter: hook before the
 * admin / theme shell renders, emit a standalone document, exit. No
 * chrome leaks onto paper.
 *
 * Three A4 portrait pages: speler-snapshot (identity + stats +
 * lookback + reflection) · doelen 1 + 2 (blank goal boxes) · doel 3
 * + afronding (final goal + closing reflection + signatures + CTA
 * reminder). Design-of-record: .local-mockups/player-goal-intake/.
 */
class PlayerGoalIntakePrintRouter {

    /**
     * #1313 — block-keys the picker exposes. Each maps to one
     * `<article>` / `<section>` in `emit()`. Order is the display
     * order on the picker UI; the renderer always emits in page order.
     */
    private const BLOCK_KEYS = [
        'snapshot',
        'doel1',
        'doel2',
        'doel3',
        'afsluiting',
        'handtekeningen',
        'reminder',
    ];

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_goal_intake_print'] ) ) return;
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $team_id   = isset( $_GET['team_id'] )   ? absint( $_GET['team_id'] )   : 0;
        if ( $player_id <= 0 && $team_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this intake.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_edit_goals' ) ) {
            wp_die( esc_html__( 'You do not have access to print this intake.', 'talenttrack' ) );
        }

        $season = isset( $_GET['season'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['season'] ) ) : self::upcomingSeason();

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        // #1313 — block picker. First click on "Print doelenintake"
        // lands on a picker page (all 7 blocks pre-checked). Submitting
        // the form round-trips back to this URL with `blocks[]=…` query
        // params; we re-enter `maybeRender` and dispatch to the render
        // path. `blocks[]=all` is the escape hatch for "Print alles".
        $blocks = self::selectedBlocks();
        if ( $blocks === null ) {
            if ( $team_id > 0 ) {
                echo self::renderTeamBatchPicker( $team_id, $season );
            } else {
                echo self::renderPickerHtml( $player_id, $season );
            }
            exit;
        }

        if ( $team_id > 0 ) {
            echo self::renderTeamBatch( $team_id, $season, $blocks );
        } else {
            echo self::renderHtml( $player_id, $season, $blocks );
        }
        exit;
    }

    /**
     * #1313 — resolve which blocks to render from the `blocks[]` query
     * param. Returns:
     *   - `null` when `blocks` is absent or contains no valid keys —
     *     the dispatcher renders the picker instead of the document.
     *   - All-true map when `blocks[]=all` (the "Print alles" escape).
     *   - Selective map otherwise (true only for ticked keys).
     *
     * @return array<string,bool>|null
     */
    private static function selectedBlocks(): ?array {
        if ( ! isset( $_GET['blocks'] ) ) return null;
        $raw = array_map( 'sanitize_key', (array) $_GET['blocks'] );

        if ( in_array( 'all', $raw, true ) ) {
            return array_fill_keys( self::BLOCK_KEYS, true );
        }

        $out = array_fill_keys( self::BLOCK_KEYS, false );
        foreach ( $raw as $key ) {
            if ( isset( $out[ $key ] ) ) {
                $out[ $key ] = true;
            }
        }
        // No valid keys ticked → bounce to picker. Handles the edge
        // case where the operator unchecks every box and submits.
        if ( ! in_array( true, $out, true ) ) return null;
        return $out;
    }

    /**
     * @param array<string,bool> $blocks
     */
    public static function renderHtml( int $player_id, string $season, array $blocks ): string {
        ob_start();
        self::emit( $player_id, $season, $blocks );
        return (string) ob_get_clean();
    }

    /**
     * Team-batch print: one 3-page intake per active player on the
     * team's roster, in roster display order. Each player's 3 pages
     * are emitted back-to-back; the browser print-dialog produces a
     * single concatenated PDF when the operator picks "Save as PDF".
     *
     * @param array<string,bool> $blocks #1313 — block selection from the picker.
     */
    public static function renderTeamBatch( int $team_id, string $season, array $blocks ): string {
        global $wpdb;
        $p = $wpdb->prefix;

        // #1149 — drop the club_id filter and use demo-scope, matching
        // the per-player emit() path. Same scope-strictness mismatch
        // family otherwise.
        $team_scope = QueryHelpers::apply_demo_scope( 't', 'team' );
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams t WHERE t.id = %d {$team_scope} LIMIT 1",
            $team_id
        ) );
        if ( ! $team ) {
            wp_die( esc_html__( 'Team not found.', 'talenttrack' ) );
        }

        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $players = $wpdb->get_col( $wpdb->prepare(
            "SELECT pl.id FROM {$p}tt_players pl
              WHERE pl.team_id = %d
                AND ( pl.archived_at IS NULL ) {$player_scope}
              ORDER BY COALESCE(pl.jersey_number, 999) ASC, pl.last_name ASC",
            $team_id
        ) );

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        echo esc_html( sprintf(
            /* translators: 1: team name, 2: season e.g. 2026/27 */
            __( 'Seizoens-intakes — %1$s — %2$s', 'talenttrack' ),
            (string) $team->name, $season
        ) );
    ?></title>
    <style><?php echo self::stylesCss(); ?></style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
    <a href="<?php echo esc_url( wp_get_referer() ?: home_url( '/' ) ); ?>"><?php esc_html_e( 'Close', 'talenttrack' ); ?></a>
    <span style="color:#bbb;font-size:11px;">
        <?php echo esc_html( sprintf(
            /* translators: 1: player count, 2: page count */
            __( '%1$d players · %2$d pages total', 'talenttrack' ),
            count( $players ), count( $players ) * 3
        ) ); ?>
    </span>
</div>

<?php
        if ( empty( $players ) ) {
            echo '<article class="paper"><h1 class="title">' . esc_html( sprintf( __( '%s — geen actieve spelers', 'talenttrack' ), $team->name ) ) . '</h1></article>';
        } else {
            foreach ( $players as $pid ) {
                // Emit the player's 3 pages. emit() prints its own
                // full HTML doc; for batching, we only want the
                // <article> blocks. Capture each player's pages
                // separately and inject them. #1313 — same $blocks
                // selection applies to every player in the batch.
                $pid = (int) $pid;
                ob_start();
                self::emit( $pid, $season, $blocks );
                $full = (string) ob_get_clean();
                if ( preg_match_all( '#<article class="paper">.*?</article>#s', $full, $matches ) ) {
                    foreach ( $matches[0] as $page ) {
                        echo $page;
                    }
                }
            }
        }
        ?>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    public static function upcomingSeason(): string {
        // Pilot seasons run Aug-Jul. After 1 May the upcoming season is
        // the one starting this year; before, it's still the current.
        $now   = current_time( 'timestamp' );
        $year  = (int) date( 'Y', $now );
        $month = (int) date( 'n', $now );
        if ( $month >= 5 ) {
            return sprintf( '%d/%02d', $year, ( $year + 1 ) % 100 );
        }
        return sprintf( '%d/%02d', $year - 1, $year % 100 );
    }

    /**
     * @param array<string,bool> $blocks #1313 — per-block render gates.
     */
    private static function emit( int $player_id, string $season, array $blocks ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // #1149 — was filtered by `pl.club_id = CurrentClub::id()` (always 1),
        // which 404'd on installs where the player row carried a different
        // club_id than the resolved current club. The player profile view
        // ([FrontendPlayersManageView::loadPlayer](src/Shared/Frontend/FrontendPlayersManageView.php#L631-L640))
        // doesn't enforce club_id either — only demo-scope — so the print
        // router was a stricter gate than the page that linked here, and
        // pilot 2026-06-03 hit a hard "Player not found" on a valid link.
        // Pivot to the player's stored club_id (read it back from the row)
        // for the sub-queries below.
        $scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        // #1267 — was `pl.attachment_id_avatar` (nonexistent column);
        // canonical column is `pl.photo_url` per migration 0001 /
        // every other player-photo callsite. The non-existent column
        // made $wpdb->get_row return null → the player-not-found bail
        // below fired on every valid id.
        $player = $wpdb->get_row( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.date_of_birth,
                    pl.jersey_number, pl.preferred_foot, pl.team_id, pl.club_id,
                    pl.photo_url,
                    t.name AS team_name
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
              WHERE pl.id = %d {$scope}
              LIMIT 1",
            $player_id
        ) );
        if ( ! $player ) {
            wp_die( esc_html__( 'Player not found.', 'talenttrack' ) );
        }
        $club_id = (int) $player->club_id;

        $name = trim( (string) $player->first_name . ' ' . (string) $player->last_name );

        // Last-season stats — best-effort. Falls back to dashes when
        // tables / columns aren't populated.
        $stats = self::lastSeasonStats( $player_id, $club_id );

        // Lookback anchors — eval medians + prior goals + coach notes.
        $lookback = self::lookbackForPlayer( $player_id, $club_id );

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        echo esc_html( sprintf(
            /* translators: 1: player display name, 2: season e.g. 2026/27 */
            __( 'Goals intake — %1$s — %2$s', 'talenttrack' ),
            $name, $season
        ) );
    ?></title>
    <style><?php echo self::stylesCss(); ?></style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
    <a href="<?php echo esc_url( wp_get_referer() ?: home_url( '/' ) ); ?>"><?php esc_html_e( 'Close', 'talenttrack' ); ?></a>
</div>

<?php // PAGE 1 — speler-snapshot. #1313 — only emit if snapshot block is selected. ?>
<?php if ( $blocks['snapshot'] ) : ?>
<article class="paper">
    <p class="brand"><?php echo esc_html( sprintf( __( 'TalentTrack · seizoensintake %s', 'talenttrack' ), $season ) ); ?></p>
    <h1 class="title"><?php echo esc_html( sprintf( __( 'Goals intake — %s', 'talenttrack' ), $name ) ); ?>
        <small><?php esc_html_e( 'Pagina 1: speler-snapshot — lees voor het gesprek begint. Doelen volgen op pagina 2 + 3.', 'talenttrack' ); ?></small>
    </h1>

    <section class="identity">
        <div class="identity__photo">
            <?php
            // #1267 — same column fix. Reads photo_url directly per
            // FrontendPlayerDetailView::294 etc., no wp_get_attachment_image_url
            // round-trip needed since photo_url already stores the URL.
            $photo = (string) ( $player->photo_url ?? '' );
            if ( $photo !== '' ) {
                echo '<img src="' . esc_url( $photo ) . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:2mm;">';
            } else {
                esc_html_e( 'Foto', 'talenttrack' );
            }
            ?>
        </div>
        <div class="identity__body">
            <p class="identity__name"><?php echo esc_html( $name ); ?></p>
            <dl class="identity__meta">
                <div><dt><?php esc_html_e( 'Team', 'talenttrack' ); ?></dt><dd><?php echo esc_html( (string) ( $player->team_name ?? '—' ) ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Rugnummer', 'talenttrack' ); ?></dt><dd><?php echo $player->jersey_number ? '#' . (int) $player->jersey_number : '—'; ?></dd></div>
                <div><dt><?php esc_html_e( 'Leeftijd · Geboortedatum', 'talenttrack' ); ?></dt><dd><?php echo esc_html( self::ageDob( (string) $player->date_of_birth ) ); ?></dd></div>
                <div><dt><?php esc_html_e( 'Voorkeursvoet', 'talenttrack' ); ?></dt><dd><?php echo esc_html( self::footLabel( (string) $player->preferred_foot ) ); ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="stats" aria-label="<?php esc_attr_e( 'Vorig seizoen', 'talenttrack' ); ?>">
        <div class="stat"><span class="stat__num"><?php echo (int) $stats['apps']; ?></span><span class="stat__label"><?php esc_html_e( 'Wedstr.', 'talenttrack' ); ?></span></div>
        <div class="stat"><span class="stat__num"><?php echo (int) $stats['minutes']; ?></span><span class="stat__label"><?php esc_html_e( 'Minuten', 'talenttrack' ); ?></span></div>
        <div class="stat"><span class="stat__num"><?php echo (int) $stats['goals']; ?></span><span class="stat__label"><?php esc_html_e( 'Doelpunten', 'talenttrack' ); ?></span></div>
        <div class="stat"><span class="stat__num"><?php echo (int) $stats['assists']; ?></span><span class="stat__label"><?php esc_html_e( 'Assists', 'talenttrack' ); ?></span></div>
        <div class="stat"><span class="stat__num"><?php echo esc_html( $stats['avg_rating'] !== null ? number_format_i18n( (float) $stats['avg_rating'], 1 ) : '—' ); ?></span><span class="stat__label"><?php esc_html_e( 'Gem. score', 'talenttrack' ); ?></span></div>
    </section>

    <div class="section-h"><h2 class="section-h__title"><?php esc_html_e( 'Terugblik', 'talenttrack' ); ?></h2></div>

    <section class="lookback">
        <?php foreach ( $lookback as $card ) : ?>
            <div class="lookback__card">
                <p class="lookback__card-title"><?php echo esc_html( (string) $card['title'] ); ?></p>
                <?php if ( empty( $card['items'] ) ) : ?>
                    <p style="color: var(--ink-soft); font-size: 10pt; font-style: italic;"><?php esc_html_e( 'Geen gegevens vorig seizoen.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <ul>
                        <?php foreach ( $card['items'] as $item ) : ?>
                            <li><?php echo esc_html( (string) $item ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="section-h"><h2 class="section-h__title"><?php esc_html_e( 'Reflectie — in de eigen woorden van de speler', 'talenttrack' ); ?></h2></div>
    <section class="reflection">
        <p class="reflection__label"><?php esc_html_e( 'Speler schrijft / trainer noteert:', 'talenttrack' ); ?></p>
        <div class="write-line"></div>
        <div class="write-line"></div>
        <div class="write-line"></div>
    </section>

    <div class="paper-footer">
        <span><?php echo esc_html( $name ); ?> · <?php echo esc_html( (string) ( $player->team_name ?? '' ) ); ?></span>
        <span><?php esc_html_e( 'Pagina 1 van 3 · speler-snapshot', 'talenttrack' ); ?></span>
    </div>
</article>
<?php endif; // snapshot ?>

<?php // PAGE 2 — doelen 1 + 2. #1313 — only emit if at least one goal block on this page is selected. ?>
<?php if ( $blocks['doel1'] || $blocks['doel2'] ) : ?>
<article class="paper">
    <p class="brand"><?php echo esc_html( sprintf( __( 'TalentTrack · seizoensintake %s', 'talenttrack' ), $season ) ); ?></p>
    <h1 class="title"><?php echo esc_html( sprintf( __( 'Goals intake — %s', 'talenttrack' ), $name ) ); ?>
        <small><?php esc_html_e( 'Goals 1 + 2 · fill in during the conversation.', 'talenttrack' ); ?></small>
    </h1>

    <?php if ( $blocks['doel1'] ) self::renderGoalBox( 1 ); ?>
    <?php if ( $blocks['doel2'] ) self::renderGoalBox( 2 ); ?>

    <div class="paper-footer">
        <span><?php echo esc_html( $name ); ?> · <?php echo esc_html( (string) ( $player->team_name ?? '' ) ); ?></span>
        <span><?php esc_html_e( 'Pagina 2 van 3 · doelen 1 + 2', 'talenttrack' ); ?></span>
    </div>
</article>
<?php endif; // doel1 || doel2 ?>

<?php // PAGE 3 — doel 3 + afronding. #1313 — only emit if at least one block on this page is selected. ?>
<?php if ( $blocks['doel3'] || $blocks['afsluiting'] || $blocks['handtekeningen'] || $blocks['reminder'] ) : ?>
<article class="paper">
    <p class="brand"><?php echo esc_html( sprintf( __( 'TalentTrack · seizoensintake %s', 'talenttrack' ), $season ) ); ?></p>
    <h1 class="title"><?php echo esc_html( sprintf( __( 'Goals intake — %s', 'talenttrack' ), $name ) ); ?>
        <small><?php esc_html_e( 'Doel 3 · afsluitende reflectie · ondertekening.', 'talenttrack' ); ?></small>
    </h1>

    <?php if ( $blocks['doel3'] ) self::renderGoalBox( 3 ); ?>

    <?php if ( $blocks['afsluiting'] ) : ?>
    <div class="section-h"><h2 class="section-h__title"><?php esc_html_e( 'Afsluiting — één ding om mee te nemen het seizoen in', 'talenttrack' ); ?></h2></div>
    <section class="reflection">
        <div class="write-line"></div>
        <div class="write-line"></div>
    </section>
    <?php endif; ?>

    <?php if ( $blocks['handtekeningen'] ) : ?>
    <section class="signatures">
        <div class="sig">
            <div class="sig__line"></div>
            <p class="sig__label"><?php esc_html_e( 'Speler', 'talenttrack' ); ?> · <?php echo esc_html( $name ); ?></p>
        </div>
        <div class="sig">
            <div class="sig__line"></div>
            <p class="sig__label"><?php esc_html_e( 'Trainer', 'talenttrack' ); ?></p>
        </div>
        <div class="sig">
            <div class="sig__line"></div>
            <p class="sig__label"><?php esc_html_e( 'Datum gesprek', 'talenttrack' ); ?></p>
        </div>
    </section>
    <?php endif; ?>

    <?php if ( $blocks['reminder'] ) : ?>
    <div class="cta-note">
        <strong><?php esc_html_e( 'Reminder voor de trainer:', 'talenttrack' ); ?></strong>
        <?php esc_html_e( 'zet de drie doelen hierboven binnen 48 uur in TalentTrack (Speler › Doelen › + Nieuw doel) zodat de digitale versie aan dit gesprek hangt. Gebruik de velden "gekoppeld spelprincipe" en "gekoppelde voetbalhandeling" in de wizard zodat het digitale doel hetzelfde leest als hierboven.', 'talenttrack' ); ?>
    </div>
    <?php endif; ?>

    <div class="paper-footer">
        <span><?php echo esc_html( $name ); ?> · <?php echo esc_html( (string) ( $player->team_name ?? '' ) ); ?></span>
        <span><?php esc_html_e( 'Pagina 3 van 3 · doel 3 + afronding', 'talenttrack' ); ?></span>
    </div>
</article>
<?php endif; // page 3 has any block ?>

</body>
</html>
        <?php
    }

    private static function renderGoalBox( int $num ): void {
        ?>
        <article class="goal">
            <div class="goal__head">
                <span class="goal__num"><?php echo (int) $num; ?></span>
                <div class="goal__categories">
                    <span class="goal__cat"><?php esc_html_e( 'Technisch', 'talenttrack' ); ?></span>
                    <span class="goal__cat"><?php esc_html_e( 'Tactisch',  'talenttrack' ); ?></span>
                    <span class="goal__cat"><?php esc_html_e( 'Fysiek',    'talenttrack' ); ?></span>
                    <span class="goal__cat"><?php esc_html_e( 'Mentaal',   'talenttrack' ); ?></span>
                </div>
            </div>

            <div class="goal__field">
                <p class="goal__field-label"><?php esc_html_e( 'Doelomschrijving', 'talenttrack' ); ?> <small><?php esc_html_e( 'de SMART-zin die jullie samen formuleren', 'talenttrack' ); ?></small></p>
                <div class="write-line"></div>
                <div class="write-line"></div>
            </div>

            <div class="goal__row">
                <div class="goal__field">
                    <p class="goal__field-label"><?php esc_html_e( 'Gekoppeld(e) spelprincipe(s)', 'talenttrack' ); ?></p>
                    <div class="goal__codes">
                        <span class="goal__code-box"></span>
                        <span class="goal__code-box"></span>
                        <small><?php esc_html_e( 'bijv. AO-01, VS-03', 'talenttrack' ); ?></small>
                    </div>
                </div>
                <div class="goal__field">
                    <p class="goal__field-label"><?php esc_html_e( 'Gekoppelde voetbalhandeling(en)', 'talenttrack' ); ?></p>
                    <div class="goal__codes goal__codes--actions">
                        <span class="goal__code-box"></span>
                        <span class="goal__code-box"></span>
                        <small><?php esc_html_e( 'bijv. Passen, Vrijlopen', 'talenttrack' ); ?></small>
                    </div>
                </div>
            </div>

            <div class="goal__field">
                <p class="goal__field-label"><?php esc_html_e( 'Waarom dit belangrijk is', 'talenttrack' ); ?></p>
                <div class="write-line"></div>
                <div class="write-line"></div>
            </div>

            <div class="goal__field">
                <p class="goal__field-label"><?php esc_html_e( 'Hoe we dit meten', 'talenttrack' ); ?></p>
                <div class="write-line"></div>
                <div class="write-line"></div>
            </div>

            <div class="goal__footer">
                <div class="goal__footer-cell">
                    <div class="write-line"></div>
                    <p class="goal__footer-cell-label"><?php esc_html_e( 'Eerste check-in datum', 'talenttrack' ); ?></p>
                </div>
                <div class="goal__footer-cell">
                    <div class="write-line"></div>
                    <p class="goal__footer-cell-label"><?php esc_html_e( 'Eigenaar (speler / trainer / beide)', 'talenttrack' ); ?></p>
                </div>
            </div>
        </article>
        <?php
    }

    private static function ageDob( string $dob ): string {
        if ( $dob === '' || $dob === '0000-00-00' ) return '—';
        $ts = strtotime( $dob );
        if ( ! $ts ) return $dob;
        $age = (int) date_diff( date_create( $dob ), date_create( 'now' ) )->y;
        return $age . ' · ' . date_i18n( 'd-m-Y', $ts );
    }

    private static function footLabel( string $foot ): string {
        $f = strtolower( trim( $foot ) );
        if ( $f === 'right' || $f === 'rechts' ) return __( 'Rechts', 'talenttrack' );
        if ( $f === 'left'  || $f === 'links'  ) return __( 'Links',  'talenttrack' );
        if ( $f === 'both'  || $f === 'beide'  ) return __( 'Beide',  'talenttrack' );
        return $foot !== '' ? $foot : '—';
    }

    /**
     * Best-effort last-season stat aggregation. Returns dashes if the
     * underlying tables are empty for this player.
     *
     * @return array{apps:int, minutes:int, goals:int, assists:int, avg_rating:?float}
     */
    private static function lastSeasonStats( int $player_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // Use a 12-month trailing window as a proxy for "last season".
        // Pilot installs without a formal season anchor still get
        // meaningful numbers.
        $cutoff = gmdate( 'Y-m-d', strtotime( '-365 days' ) );

        $apps = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT a.id)
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.club_id   = %d
                AND att.status    = 'Present'
                AND a.session_date >= %s",
            $player_id, $club_id, $cutoff
        ) );

        $minutes = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM( att.minutes_played ), 0 )
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.club_id   = %d
                AND a.session_date >= %s",
            $player_id, $club_id, $cutoff
        ) );

        // Goals + assists may live on evaluations.notes / dedicated
        // stat tables that aren't universal. Default to 0; pilot can
        // augment.
        $goals   = 0;
        $assists = 0;

        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(r.rating)
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id
              WHERE e.player_id = %d
                AND e.club_id   = %d
                AND e.eval_date >= %s
                AND e.archived_at IS NULL",
            $player_id, $club_id, $cutoff
        ) );

        return [
            'apps'       => $apps,
            'minutes'    => $minutes,
            'goals'      => $goals,
            'assists'    => $assists,
            'avg_rating' => $avg !== null ? (float) $avg : null,
        ];
    }

    /**
     * Lookback cards: pre-printed anchor data from the player's
     * recent evaluations + prior goals.
     *
     * @return list<array{title:string, items:string[]}>
     */
    private static function lookbackForPlayer( int $player_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $cutoff = gmdate( 'Y-m-d', strtotime( '-365 days' ) );

        // Strongest categories (mediaan top 3).
        $strong_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.name AS category, AVG(r.rating) AS avg_rating, COUNT(*) AS n
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id
               JOIN {$p}tt_eval_categories c ON c.id = r.category_id
              WHERE e.player_id = %d AND e.club_id = %d AND e.eval_date >= %s
                AND e.archived_at IS NULL
              GROUP BY c.id
              HAVING n >= 2
              ORDER BY avg_rating DESC LIMIT 3",
            $player_id, $club_id, $cutoff
        ) );
        $weak_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.name AS category, AVG(r.rating) AS avg_rating, COUNT(*) AS n
               FROM {$p}tt_eval_ratings r
               JOIN {$p}tt_evaluations e ON e.id = r.evaluation_id
               JOIN {$p}tt_eval_categories c ON c.id = r.category_id
              WHERE e.player_id = %d AND e.club_id = %d AND e.eval_date >= %s
                AND e.archived_at IS NULL
              GROUP BY c.id
              HAVING n >= 2
              ORDER BY avg_rating ASC LIMIT 3",
            $player_id, $club_id, $cutoff
        ) );

        $strong = array_map( function ( $r ) {
            return sprintf( '%s · %s / 5', (string) $r->category, number_format_i18n( (float) $r->avg_rating, 1 ) );
        }, (array) $strong_rows );
        $weak = array_map( function ( $r ) {
            return sprintf( '%s · %s / 5', (string) $r->category, number_format_i18n( (float) $r->avg_rating, 1 ) );
        }, (array) $weak_rows );

        // Last-season goals + outcome.
        $goal_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, status FROM {$p}tt_goals
              WHERE player_id = %d AND club_id = %d AND created_at >= %s
              ORDER BY created_at DESC LIMIT 4",
            $player_id, $club_id, $cutoff
        ) );
        $goals = array_map( function ( $g ) {
            $status = (string) ( $g->status ?? '' );
            return sprintf( '%s · %s', (string) $g->title, $status !== '' ? $status : __( 'in progress', 'talenttrack' ) );
        }, (array) $goal_rows );

        return [
            [ 'title' => __( 'Sterke punten vorig seizoen (mediaan eval)', 'talenttrack' ), 'items' => $strong ],
            [ 'title' => __( 'Ontwikkelpunten',                            'talenttrack' ), 'items' => $weak ],
            [ 'title' => __( 'Goals from last season',                     'talenttrack' ), 'items' => $goals ],
            [ 'title' => __( 'Trainersnotities',                           'talenttrack' ), 'items' => [] ],
        ];
    }

    /**
     * Print-tuned styles. Mirrors the design-of-record at
     * `.local-mockups/player-goal-intake/index.html` (intake surface
     * only; methodology reference has its own router).
     */
    public static function stylesCss(): string {
        return <<<CSS
:root {
    --ink: #1a1d21; --ink-soft: #5b6e75; --line: #b8bdc2; --line-soft: #d6dadd;
    --paper: #ffffff; --mute: #f3f5f6; --accent: #155a57;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #c9cdd0; }
body { font-family: var(--font); color: var(--ink); font-size: 11pt; line-height: 1.35; -webkit-font-smoothing: antialiased; }

.toolbar { position: sticky; top: 0; z-index: 100; background: #222; color: #eee; font-size: 12px; padding: 8px 12px; display: flex; gap: 12px; align-items: center; border-bottom: 2px solid #000; }
.toolbar button, .toolbar a { background: #6c4; color: #000; border: 1px solid #4a3; padding: 4px 14px; border-radius: 4px; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; }

.paper { width: 210mm; min-height: 297mm; background: var(--paper); margin: 24px auto; padding: 16mm 14mm; box-shadow: 0 2px 8px rgba(0,0,0,0.15); page-break-after: always; position: relative; }
.paper:last-child { page-break-after: auto; }
.paper-footer { position: absolute; bottom: 8mm; left: 14mm; right: 14mm; font-size: 9pt; color: var(--ink-soft); display: flex; justify-content: space-between; border-top: 1px solid var(--line-soft); padding-top: 4px; }

@page { size: A4 portrait; margin: 0; }
@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    /* Pin each sheet to an exact A4 box and clip sub-pixel spill. A
       min-height of 297mm inside a 0-margin A4 page rounds up past the
       printable height on some renderers, bleeding every sheet onto a
       trailing blank page so the batch's content cascades down across
       pages. Fixed height + overflow:hidden keeps one logical page per
       sheet; break-after:page forces the next player to start fresh. */
    .paper { margin: 0; box-shadow: none; width: 210mm; height: 297mm; min-height: 0; overflow: hidden; page-break-after: always; break-after: page; }
    .paper:last-child { page-break-after: auto; break-after: auto; }
}

.brand { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); font-weight: 700; margin: 0 0 4px; }
.title { margin: 0 0 12px; font-size: 18pt; font-weight: 800; color: var(--ink); line-height: 1.15; }
.title small { display: block; font-size: 10pt; font-weight: 500; color: var(--ink-soft); margin-top: 2px; }

.identity { display: grid; grid-template-columns: 28mm 1fr; gap: 8mm; padding-bottom: 4mm; border-bottom: 1px solid var(--line); margin-bottom: 5mm; }
.identity__photo { width: 28mm; height: 32mm; background: var(--mute); border: 1px solid var(--line); border-radius: 2mm; display: flex; align-items: center; justify-content: center; color: var(--ink-soft); font-size: 8pt; text-align: center; padding: 2mm; }
.identity__body { min-width: 0; }
.identity__name { font-size: 16pt; font-weight: 800; margin: 0 0 2mm; line-height: 1.1; }
.identity__meta { display: grid; grid-template-columns: 1fr 1fr; gap: 2mm 6mm; font-size: 10pt; }
.identity__meta dt { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4px; color: var(--ink-soft); font-weight: 600; margin: 0; }
.identity__meta dd { margin: 0 0 1mm; font-weight: 600; }

.stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 2mm; margin-bottom: 5mm; }
.stat { border: 1px solid var(--line-soft); border-radius: 2mm; padding: 2.5mm 2mm; text-align: center; background: var(--mute); }
.stat__num { font-size: 16pt; font-weight: 800; line-height: 1; color: var(--accent); font-variant-numeric: tabular-nums; }
.stat__label { display: block; font-size: 8pt; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.4px; margin-top: 1mm; font-weight: 600; }

.section-h { display: flex; align-items: baseline; gap: 4mm; margin: 5mm 0 2mm; }
.section-h__title { font-size: 11pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; color: var(--accent); margin: 0; }
.section-h::before { content: ''; width: 4mm; height: 4mm; background: var(--accent); border-radius: 1mm; flex-shrink: 0; align-self: center; }

.lookback { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-bottom: 5mm; }
.lookback__card { border: 1px solid var(--line-soft); border-radius: 2mm; padding: 3mm 3.5mm; }
.lookback__card-title { font-size: 9pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; color: var(--ink-soft); margin: 0 0 2mm; }
.lookback__card ul { margin: 0; padding-left: 4mm; font-size: 10pt; }
.lookback__card li { margin-bottom: 1mm; }

.reflection { border: 1px dashed var(--line); padding: 3mm 4mm 2mm; border-radius: 2mm; margin-bottom: 5mm; }
.reflection__label { font-size: 9pt; color: var(--ink-soft); margin: 0 0 2mm; font-style: italic; }
.write-line { border-bottom: 1px solid var(--line); height: 7mm; }
.write-line:last-child { border-bottom: none; }

.goal { border: 1.5px solid var(--ink); border-radius: 2mm; padding: 4mm 4mm 3mm; margin-bottom: 5mm; page-break-inside: avoid; }
.goal__head { display: flex; align-items: center; justify-content: space-between; gap: 4mm; padding-bottom: 2mm; border-bottom: 1px solid var(--line); margin-bottom: 3mm; }
.goal__num { background: var(--ink); color: var(--paper); width: 8mm; height: 8mm; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11pt; font-weight: 800; flex-shrink: 0; }
.goal__categories { display: flex; gap: 3mm; font-size: 9pt; }
.goal__cat { display: inline-flex; align-items: center; gap: 1mm; }
.goal__cat::before { content: ''; width: 3mm; height: 3mm; border: 1.5px solid var(--ink); border-radius: 0.5mm; display: inline-block; }

.goal__field { margin-bottom: 2.5mm; }
.goal__field-label { font-size: 9pt; font-weight: 700; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.3px; margin: 0 0 1mm; }
.goal__field-label small { text-transform: none; letter-spacing: 0; font-weight: 500; color: var(--ink-soft); font-style: italic; margin-left: 3mm; }
.goal__row { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; }
.goal__codes { display: flex; gap: 1.5mm; align-items: center; margin-top: 0.5mm; }
.goal__code-box { border: 1px solid var(--line); width: 12mm; height: 6mm; border-radius: 1mm; display: inline-block; background: var(--mute); }
.goal__codes--actions .goal__code-box { width: 30mm; height: 8mm; }
.goal__codes small { color: var(--ink-soft); font-size: 8pt; margin-left: 2mm; font-style: italic; }
.goal .write-line { border-bottom: 1px solid var(--line); height: 7mm; }
.goal__footer { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; margin-top: 2mm; padding-top: 2mm; border-top: 1px dashed var(--line); }
.goal__footer-cell { font-size: 9pt; }
.goal__footer-cell .write-line { height: 5mm; border-bottom: 1px solid var(--ink); }
.goal__footer-cell-label { font-size: 8pt; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.4px; margin: 1mm 0 0; font-weight: 600; }

.signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6mm; margin-top: 6mm; padding-top: 4mm; border-top: 2px solid var(--ink); }
.sig__line { border-bottom: 1px solid var(--ink); height: 12mm; }
.sig__label { font-size: 9pt; color: var(--ink-soft); margin: 1mm 0 0; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }

.cta-note { background: var(--mute); border-left: 3px solid var(--accent); padding: 3mm 4mm; margin-top: 5mm; font-size: 9pt; color: var(--ink-soft); line-height: 1.4; }
.cta-note strong { color: var(--ink); }
CSS;
    }

    /**
     * #1313 — single-player picker page. Renders the 7 block checkboxes
     * pre-checked, a Print button (submits the form), a Print-all link
     * (escape hatch), and a Cancel link back to where the operator came
     * from. Mobile-first per CLAUDE.md §2 — 48px touch targets, 16px
     * input font to suppress iOS zoom, base layout at 360px.
     */
    private static function renderPickerHtml( int $player_id, string $season ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        $scope  = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $player = $wpdb->get_row( $wpdb->prepare(
            "SELECT pl.first_name, pl.last_name
               FROM {$p}tt_players pl
              WHERE pl.id = %d {$scope}
              LIMIT 1",
            $player_id
        ) );
        if ( ! $player ) {
            wp_die( esc_html__( 'Player not found.', 'talenttrack' ) );
        }
        $name = trim( (string) $player->first_name . ' ' . (string) $player->last_name );

        $print_all_url = add_query_arg( [
            'tt_goal_intake_print' => 1,
            'player_id'            => $player_id,
            'season'               => $season,
            'blocks'               => [ 'all' ],
        ], home_url( '/' ) );

        return self::renderPicker(
            sprintf( __( 'Goals intake — %1$s — choose blocks', 'talenttrack' ), $name ),
            sprintf( __( 'Speler: %1$s · Seizoen %2$s', 'talenttrack' ), $name, $season ),
            [
                'tt_goal_intake_print' => 1,
                'player_id'            => $player_id,
                'season'               => $season,
            ],
            $print_all_url
        );
    }

    /**
     * #1313 — team-batch picker. Identical UX to the per-player picker;
     * the operator picks blocks once and the same selection applies to
     * every player in the batch.
     */
    private static function renderTeamBatchPicker( int $team_id, string $season ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        $team_scope = QueryHelpers::apply_demo_scope( 't', 'team' );
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams t WHERE t.id = %d {$team_scope} LIMIT 1",
            $team_id
        ) );
        if ( ! $team ) {
            wp_die( esc_html__( 'Team not found.', 'talenttrack' ) );
        }

        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $player_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players pl
              WHERE pl.team_id = %d
                AND ( pl.archived_at IS NULL ) {$player_scope}",
            $team_id
        ) );

        $print_all_url = add_query_arg( [
            'tt_goal_intake_print' => 1,
            'team_id'              => $team_id,
            'season'               => $season,
            'blocks'               => [ 'all' ],
        ], home_url( '/' ) );

        return self::renderPicker(
            sprintf( __( 'Seizoens-intakes — %1$s — kies blokken', 'talenttrack' ), (string) $team->name ),
            sprintf(
                /* translators: 1: team name, 2: season, 3: player count */
                __( 'Team: %1$s · Seizoen %2$s · %3$d spelers', 'talenttrack' ),
                (string) $team->name, $season, $player_count
            ),
            [
                'tt_goal_intake_print' => 1,
                'team_id'              => $team_id,
                'season'               => $season,
            ],
            $print_all_url
        );
    }

    /**
     * #1313 — shared picker chrome used by both single-player and
     * team-batch flows. The form submits via GET back to the same URL,
     * adding `blocks[]=…` for each ticked checkbox; `maybeRender()`
     * then re-enters and dispatches to the render path.
     *
     * @param array<string,scalar> $hidden_fields  hidden inputs to round-trip
     */
    private static function renderPicker( string $title, string $subtitle, array $hidden_fields, string $print_all_url ): string {
        $cancel_url = wp_get_referer() ?: home_url( '/' );

        $block_labels = [
            'snapshot'       => __( 'Snapshot (foto, stats, terugblik, reflectie)',     'talenttrack' ),
            'doel1'          => __( 'Doel 1',                                            'talenttrack' ),
            'doel2'          => __( 'Doel 2',                                            'talenttrack' ),
            'doel3'          => __( 'Doel 3',                                            'talenttrack' ),
            'afsluiting'     => __( 'Afsluiting (reflectie 1 ding om mee te nemen)',     'talenttrack' ),
            'handtekeningen' => __( 'Handtekeningen (speler / trainer / datum)',         'talenttrack' ),
            'reminder'       => __( 'Trainer-reminder (CTA-note)',                       'talenttrack' ),
        ];

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( $title ); ?></title>
    <style><?php echo self::pickerStylesCss(); ?></style>
</head>
<body>

<main class="picker">
    <header class="picker__header">
        <h1 class="picker__title"><?php echo esc_html( $title ); ?></h1>
        <p class="picker__subtitle"><?php echo esc_html( $subtitle ); ?></p>
    </header>

    <form class="picker__form" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
        <?php foreach ( $hidden_fields as $name => $value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
        <?php endforeach; ?>

        <fieldset class="picker__fieldset">
            <legend class="picker__legend"><?php esc_html_e( 'Selecteer de blokken om te printen', 'talenttrack' ); ?></legend>
            <ul class="picker__list">
                <?php foreach ( $block_labels as $key => $label ) : ?>
                    <li class="picker__item">
                        <label class="picker__label">
                            <input type="checkbox" name="blocks[]" value="<?php echo esc_attr( $key ); ?>" checked>
                            <span class="picker__text"><?php echo esc_html( $label ); ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </fieldset>

        <div class="picker__actions">
            <button type="submit" class="picker__btn picker__btn--primary"><?php esc_html_e( 'Print geselecteerde blokken', 'talenttrack' ); ?></button>
            <a class="picker__btn picker__btn--secondary" href="<?php echo esc_url( $print_all_url ); ?>"><?php esc_html_e( 'Print alles', 'talenttrack' ); ?></a>
            <a class="picker__btn picker__btn--ghost" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></a>
        </div>
    </form>
</main>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * #1313 — picker styles. Mobile-first per CLAUDE.md §2: base CSS
     * targets ~360px viewports; the only breakpoint is 768px where the
     * action buttons switch from stacked to a row. 48×48 touch targets
     * on every interactive element; 16px input font to suppress iOS
     * focus-zoom. No build step — inlined to match the print router's
     * convention.
     */
    private static function pickerStylesCss(): string {
        return <<<CSS
:root {
    --ink: #1a1d21;
    --ink-soft: #5b6e75;
    --line: #d6dadd;
    --accent: #155a57;
    --accent-hover: #0e4441;
    --bg: #f3f5f6;
    --paper: #ffffff;
    --font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    background: var(--bg);
    color: var(--ink);
    font-family: var(--font);
    font-size: 1rem;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    padding: 1rem;
    padding-top: env(safe-area-inset-top, 1rem);
    padding-bottom: env(safe-area-inset-bottom, 1rem);
}

.picker {
    background: var(--paper);
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    max-width: 36rem;
    margin: 0 auto;
    padding: 1.25rem;
}
.picker__header { margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--line); }
.picker__title { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.25rem; line-height: 1.25; color: var(--ink); }
.picker__subtitle { margin: 0; color: var(--ink-soft); font-size: 0.875rem; }

.picker__form { margin: 0; }
.picker__fieldset { border: 0; padding: 0; margin: 0 0 1.25rem; }
.picker__legend { font-size: 0.875rem; font-weight: 600; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.5rem; padding: 0; }
.picker__list { list-style: none; padding: 0; margin: 0; }
.picker__item { margin: 0; }
.picker__label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-height: 3rem;
    padding: 0.5rem;
    border-radius: 0.375rem;
    cursor: pointer;
    touch-action: manipulation;
}
.picker__label:hover { background: var(--bg); }
.picker__label input[type="checkbox"] {
    width: 1.5rem;
    height: 1.5rem;
    flex-shrink: 0;
    accent-color: var(--accent);
    cursor: pointer;
}
.picker__text { font-size: 1rem; line-height: 1.35; }

.picker__actions {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding-top: 1rem;
    border-top: 1px solid var(--line);
}
.picker__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 3rem;
    padding: 0 1.25rem;
    border-radius: 0.375rem;
    font-size: 1rem;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
    text-decoration: none;
    touch-action: manipulation;
    font-family: inherit;
}
.picker__btn--primary { background: var(--accent); color: var(--paper); }
.picker__btn--primary:hover { background: var(--accent-hover); }
.picker__btn--secondary { background: var(--paper); color: var(--accent); border-color: var(--accent); }
.picker__btn--secondary:hover { background: var(--bg); }
.picker__btn--ghost { background: transparent; color: var(--ink-soft); border-color: var(--line); }
.picker__btn--ghost:hover { background: var(--bg); color: var(--ink); }
.picker__btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

@media (min-width: 768px) {
    body { padding: 2rem 1rem; }
    .picker { padding: 1.75rem; }
    .picker__actions { flex-direction: row; }
    .picker__btn--primary { order: 3; margin-left: auto; }
    .picker__btn--secondary { order: 2; }
    .picker__btn--ghost { order: 1; }
}

@media (prefers-reduced-motion: reduce) {
    * { transition: none !important; animation: none !important; }
}
CSS;
    }
}
