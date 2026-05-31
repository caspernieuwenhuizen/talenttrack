<?php
namespace TT\Modules\Methodology\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MethodologyReferencePrintRouter (#1064) — printable methodology
 * reference card. Coach prints once per season, laminates, brings to
 * every goal-setting 1:1.
 *
 * URL: ?tt_methodology_ref_print=1[&sections=principles,actions,leerdoelen]
 *
 * Up to 3 A4 portrait pages, each section optional (selectable from
 * the operator-facing print dialog). Default = all three. Idempotent
 * URL: passing `sections=principles` produces just the Spelprincipes
 * page; passing `sections=actions,leerdoelen` produces only those two.
 *
 * Content lifted verbatim from the seeded methodology tables
 * (`tt_principles`, `tt_football_actions`, `tt_methodology_learning_goals`)
 * so any academy edit to the game model is reflected on the next print.
 */
class MethodologyReferencePrintRouter {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_methodology_ref_print'] ) ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print the methodology reference.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_view_methodology' ) ) {
            wp_die( esc_html__( 'You do not have access to print the methodology reference.', 'talenttrack' ) );
        }

        $sections_raw = isset( $_GET['sections'] )
            ? sanitize_text_field( wp_unslash( (string) $_GET['sections'] ) )
            : 'principles,actions,leerdoelen';
        $sections = array_filter( array_map( 'trim', explode( ',', $sections_raw ) ) );
        $sections = array_values( array_intersect( $sections, [ 'principles', 'actions', 'leerdoelen' ] ) );
        if ( empty( $sections ) ) {
            wp_die( esc_html__( 'Pick at least one section to print.', 'talenttrack' ) );
        }

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo self::renderHtml( $sections );
        exit;
    }

    /** @param list<string> $sections */
    public static function renderHtml( array $sections ): string {
        ob_start();
        self::emit( $sections );
        return (string) ob_get_clean();
    }

    /** @param list<string> $sections */
    private static function emit( array $sections ): void {
        $season = self::upcomingSeason();
        $academy = self::academyName();

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e( 'Methodiek-referentie', 'talenttrack' ); ?></title>
    <style><?php echo self::stylesCss(); ?></style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
    <a href="<?php echo esc_url( wp_get_referer() ?: home_url( '/' ) ); ?>"><?php esc_html_e( 'Close', 'talenttrack' ); ?></a>
</div>

<?php
        foreach ( $sections as $section ) {
            if ( $section === 'principles' ) self::renderPrinciplesPage( $academy, $season );
            if ( $section === 'actions'    ) self::renderActionsPage( $academy, $season );
            if ( $section === 'leerdoelen' ) self::renderLeerdoelenPage( $academy, $season );
        }
        ?>

</body>
</html>
        <?php
    }

    private static function renderPrinciplesPage( string $academy, string $season ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT code, title_json, team_function_key, team_task_key
               FROM {$p}tt_principles
              WHERE archived_at IS NULL AND is_shipped = 1
              ORDER BY code ASC"
        );

        // Group by team_function + team_task. The natural ordering of
        // the codes (AO, AS, OV, VS, VV, OA) already buckets them.
        $bucket_labels = [
            'AO' => __( 'Aanvallen · opbouwen',              'talenttrack' ),
            'AS' => __( 'Aanvallen · scoren',                'talenttrack' ),
            'OV' => __( 'Omschakelen · na balverlies',       'talenttrack' ),
            'VS' => __( 'Verdedigen · storen',               'talenttrack' ),
            'VV' => __( 'Verdedigen · doelpunten voorkomen', 'talenttrack' ),
            'OA' => __( 'Omschakelen · na balwinst',         'talenttrack' ),
        ];
        $by_bucket = [];
        foreach ( (array) $rows as $r ) {
            $prefix = substr( (string) $r->code, 0, 2 );
            $by_bucket[ $prefix ][] = $r;
        }
        ?>
        <article class="paper">
            <header class="ref-head">
                <div>
                    <p class="brand"><?php esc_html_e( 'TalentTrack · methodiek-referentie', 'talenttrack' ); ?></p>
                    <h1 class="title" style="margin-bottom:0;"><?php esc_html_e( 'Spelprincipes', 'talenttrack' ); ?>
                        <small><?php echo esc_html( sprintf(
                            /* translators: 1: academy name, 2: number of principles */
                            __( '%1$s — speelmodel · %2$d principes · gebruik de codes bij het opstellen van doelen.', 'talenttrack' ),
                            $academy, count( $rows )
                        ) ); ?></small>
                    </h1>
                </div>
                <p class="ref-head__sub"><?php esc_html_e( 'Eenmaal printen · lamineren · bewaren in de doelenmap', 'talenttrack' ); ?></p>
            </header>

            <div class="ref-howto">
                <strong><?php esc_html_e( 'Hoe je deze kaart gebruikt tijdens een doelenintake (1:1):', 'talenttrack' ); ?></strong>
                <ol>
                    <li><?php esc_html_e( 'Bespreek het ontwikkelpunt van de speler in gewone taal.', 'talenttrack' ); ?></li>
                    <li><?php esc_html_e( 'Kies samen 1–2 principes die hieraan raken (de code komt op het intakeformulier).', 'talenttrack' ); ?></li>
                    <li><?php esc_html_e( 'Ga daarna naar de voetbalhandelingen en kies 1–2 handelingen die — als ze beter worden — het principe versterken.', 'talenttrack' ); ?></li>
                </ol>
            </div>

            <?php foreach ( $bucket_labels as $prefix => $label ) :
                if ( empty( $by_bucket[ $prefix ] ) ) continue; ?>
                <section class="phase">
                    <h2 class="phase__title"><?php echo esc_html( $label ); ?></h2>
                    <?php foreach ( $by_bucket[ $prefix ] as $r ) :
                        $title = self::jsonField( (string) $r->title_json, 'nl' );
                        ?>
                        <div class="principle">
                            <span class="principle__code"><?php echo esc_html( (string) $r->code ); ?></span>
                            <span class="principle__text"><?php echo esc_html( $title ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <div class="paper-footer">
                <span><?php echo esc_html( sprintf( __( '%1$s · Spelprincipes (%2$d) · v %3$s', 'talenttrack' ), $academy, count( $rows ), $season ) ); ?></span>
                <span><?php esc_html_e( 'Spelprincipes', 'talenttrack' ); ?></span>
            </div>
        </article>
        <?php
    }

    private static function renderActionsPage( string $academy, string $season ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT slug, category_key, name_json, description_json
               FROM {$p}tt_football_actions
              WHERE archived_at IS NULL AND is_shipped = 1
              ORDER BY sort_order ASC"
        );
        $by_cat = [ 'with_ball' => [], 'without_ball' => [], 'support' => [] ];
        foreach ( (array) $rows as $r ) {
            $cat = (string) $r->category_key;
            if ( isset( $by_cat[ $cat ] ) ) $by_cat[ $cat ][] = $r;
        }
        $cat_labels = [
            'with_ball'    => __( 'Met balcontact',    'talenttrack' ),
            'without_ball' => __( 'Zonder balcontact', 'talenttrack' ),
            'support'      => __( 'Ondersteunend',     'talenttrack' ),
        ];
        ?>
        <article class="paper">
            <header class="ref-head">
                <div>
                    <p class="brand"><?php esc_html_e( 'TalentTrack · methodiek-referentie', 'talenttrack' ); ?></p>
                    <h1 class="title" style="margin-bottom:0;"><?php esc_html_e( 'Voetbalhandelingen', 'talenttrack' ); ?>
                        <small><?php echo esc_html( sprintf(
                            /* translators: %d = number of football actions */
                            __( 'De %d voetbalhandelingen die elke speler oefent — kies er 1–2 per doel.', 'talenttrack' ),
                            count( $rows )
                        ) ); ?></small>
                    </h1>
                </div>
                <p class="ref-head__sub"><?php esc_html_e( 'Eenmaal printen · lamineren · bewaren in de doelenmap', 'talenttrack' ); ?></p>
            </header>

            <div class="actions-grid">
                <?php foreach ( $cat_labels as $key => $label ) : ?>
                    <section>
                        <h2 class="actions-col__title"><?php echo esc_html( $label ); ?></h2>
                        <?php foreach ( $by_cat[ $key ] as $r ) :
                            $name = self::jsonField( (string) $r->name_json, 'nl' );
                            $desc = self::jsonField( (string) ( $r->description_json ?? '' ), 'nl' );
                            ?>
                            <div class="action">
                                <span class="action__name"><?php echo esc_html( $name ); ?></span>
                                <?php if ( $desc !== '' ) : ?>
                                    <span class="action__hint"><?php echo esc_html( $desc ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="paper-footer">
                <span><?php echo esc_html( sprintf( __( '%1$s · Voetbalhandelingen (%2$d) · v %3$s', 'talenttrack' ), $academy, count( $rows ), $season ) ); ?></span>
                <span><?php esc_html_e( 'Voetbalhandelingen', 'talenttrack' ); ?></span>
            </div>
        </article>
        <?php
    }

    private static function renderLeerdoelenPage( string $academy, string $season ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT slug, side, team_task_key, title_json, bullets_json
               FROM {$p}tt_methodology_learning_goals
              WHERE archived_at IS NULL AND is_shipped = 1
              ORDER BY side ASC, sort_order ASC"
        );
        $by_side = [ 'attacking' => [], 'defending' => [], 'transition' => [] ];
        foreach ( (array) $rows as $r ) {
            $side = (string) $r->side;
            if ( isset( $by_side[ $side ] ) ) $by_side[ $side ][] = $r;
        }
        $side_labels = [
            'attacking'  => __( 'Aanvallen',  'talenttrack' ),
            'defending'  => __( 'Verdedigen', 'talenttrack' ),
            'transition' => __( 'Omschakelen','talenttrack' ),
        ];
        // Group within side by team_task.
        $task_labels = [
            'opbouwen'             => __( 'opbouwen',              'talenttrack' ),
            'scoren'               => __( 'scoren',                'talenttrack' ),
            'storen'               => __( 'storen',                'talenttrack' ),
            'doelpunten_voorkomen' => __( 'doelpunten voorkomen',  'talenttrack' ),
            'overgang_balwinst'    => __( 'na balwinst',           'talenttrack' ),
            'overgang_balverlies'  => __( 'na balverlies',         'talenttrack' ),
        ];
        ?>
        <article class="paper">
            <header class="ref-head">
                <div>
                    <p class="brand"><?php esc_html_e( 'TalentTrack · methodiek-referentie', 'talenttrack' ); ?></p>
                    <h1 class="title" style="margin-bottom:0;"><?php esc_html_e( 'Leerdoelen', 'talenttrack' ); ?>
                        <small><?php echo esc_html( sprintf(
                            /* translators: 1: academy name, 2: number of learning goals */
                            __( '%1$s — %2$d leerdoelen · onderbouwen de spelprincipes met concrete deelvaardigheden.', 'talenttrack' ),
                            $academy, count( $rows )
                        ) ); ?></small>
                    </h1>
                </div>
                <p class="ref-head__sub"><?php esc_html_e( 'Eenmaal printen · lamineren · bewaren in de doelenmap', 'talenttrack' ); ?></p>
            </header>

            <div class="ref-howto">
                <strong><?php esc_html_e( 'Hoe je deze kaart gebruikt:', 'talenttrack' ); ?></strong>
                <?php esc_html_e( ' elk leerdoel bestaat uit een titel + 4–5 deelvaardigheden. Tijdens de doelenintake kies je samen met de speler één deelvaardigheid om aan te werken — die wordt het "waarom dit belangrijk is" of "hoe we dit meten" op het intakeformulier.', 'talenttrack' ); ?>
            </div>

            <div class="leerdoelen-grid">
                <?php foreach ( $side_labels as $side_key => $side_label ) :
                    if ( empty( $by_side[ $side_key ] ) ) continue; ?>
                    <section>
                        <h2 class="leerdoelen-col__title"><?php echo esc_html( $side_label ); ?></h2>
                        <?php
                        // Group by team_task within side.
                        $by_task = [];
                        foreach ( $by_side[ $side_key ] as $r ) {
                            $by_task[ (string) ( $r->team_task_key ?? '' ) ][] = $r;
                        }
                        foreach ( $by_task as $task_key => $goals ) :
                            $task_label = $task_labels[ $task_key ] ?? $task_key;
                            ?>
                            <h3 class="leerdoelen-subhead">· <?php echo esc_html( $task_label ); ?></h3>
                            <?php foreach ( $goals as $g ) :
                                $title   = self::jsonField( (string) $g->title_json, 'nl' );
                                $bullets = self::jsonFieldList( (string) ( $g->bullets_json ?? '' ), 'nl' );
                                ?>
                                <article class="leerdoel">
                                    <h4 class="leerdoel__title"><?php echo esc_html( $title ); ?></h4>
                                    <?php if ( ! empty( $bullets ) ) : ?>
                                        <ul>
                                            <?php foreach ( $bullets as $b ) : ?>
                                                <li><?php echo esc_html( (string) $b ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="paper-footer">
                <span><?php echo esc_html( sprintf( __( '%1$s · Leerdoelen (%2$d) · v %3$s', 'talenttrack' ), $academy, count( $rows ), $season ) ); ?></span>
                <span><?php esc_html_e( 'Leerdoelen', 'talenttrack' ); ?></span>
            </div>
        </article>
        <?php
    }

    /**
     * Pull a localised string from a *_json column.
     */
    private static function jsonField( string $json, string $locale_key = 'nl' ): string {
        if ( $json === '' ) return '';
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) return $json;
        if ( isset( $decoded[ $locale_key ] ) && is_string( $decoded[ $locale_key ] ) ) {
            return (string) $decoded[ $locale_key ];
        }
        if ( isset( $decoded['en'] ) && is_string( $decoded['en'] ) ) {
            return (string) $decoded['en'];
        }
        return '';
    }

    /**
     * Pull a localised list from a *_json column whose value is
     * { nl: [...], en: [...] }.
     * @return string[]
     */
    private static function jsonFieldList( string $json, string $locale_key = 'nl' ): array {
        if ( $json === '' ) return [];
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) return [];
        $list = $decoded[ $locale_key ] ?? $decoded['en'] ?? [];
        if ( ! is_array( $list ) ) return [];
        return array_values( array_map( 'strval', $list ) );
    }

    private static function upcomingSeason(): string {
        $now   = current_time( 'timestamp' );
        $year  = (int) date( 'Y', $now );
        $month = (int) date( 'n', $now );
        if ( $month >= 5 ) {
            return sprintf( '%d/%02d', $year, ( $year + 1 ) % 100 );
        }
        return sprintf( '%d/%02d', $year - 1, $year % 100 );
    }

    private static function academyName(): string {
        global $wpdb;
        $p = $wpdb->prefix;
        // Best-effort lookup via tt_config; falls back to site name.
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$p}tt_config WHERE config_key = %s LIMIT 1",
            'academy_name'
        ) );
        if ( $row ) return (string) $row;
        return (string) get_bloginfo( 'name' );
    }

    /**
     * Print-tuned styles for the methodology reference. Mirrors the
     * design-of-record in `.local-mockups/player-goal-intake/index.html`
     * (reference surface).
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
    .paper { margin: 0; box-shadow: none; width: 210mm; min-height: 297mm; page-break-after: always; }
    .paper:last-child { page-break-after: auto; }
}

.brand { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); font-weight: 700; margin: 0 0 4px; }
.title { margin: 0 0 12px; font-size: 18pt; font-weight: 800; color: var(--ink); line-height: 1.15; }
.title small { display: block; font-size: 10pt; font-weight: 500; color: var(--ink-soft); margin-top: 2px; }

.ref-head { display: flex; align-items: baseline; justify-content: space-between; border-bottom: 2px solid var(--ink); padding-bottom: 3mm; margin-bottom: 5mm; }
.ref-head__sub { font-size: 9pt; color: var(--ink-soft); }
.ref-howto { background: var(--mute); border-left: 3px solid var(--accent); padding: 3mm 4mm; margin: 4mm 0 5mm; font-size: 9.5pt; color: var(--ink); line-height: 1.4; }
.ref-howto strong { color: var(--accent); }
.ref-howto ol { margin: 2mm 0 0 4mm; padding: 0; }
.ref-howto li { margin-bottom: 1mm; }

.phase { margin-bottom: 4mm; page-break-inside: avoid; }
.phase__title { font-size: 10pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--paper); background: var(--accent); padding: 1.5mm 3mm; border-radius: 1mm; margin: 0 0 2mm; display: inline-block; }
.principle { display: grid; grid-template-columns: 12mm 1fr; gap: 3mm; padding: 1.5mm 0; border-bottom: 1px dotted var(--line-soft); font-size: 10pt; line-height: 1.3; page-break-inside: avoid; }
.principle:last-child { border-bottom: none; }
.principle__code { font-weight: 800; color: var(--accent); font-variant-numeric: tabular-nums; }
.principle__text { color: var(--ink); }

.actions-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 5mm; margin-top: 3mm; }
.actions-col__title { font-size: 10pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--paper); background: var(--ink); padding: 1.5mm 3mm; border-radius: 1mm; margin: 0 0 2mm; }
.action { padding: 2mm 0; border-bottom: 1px dotted var(--line-soft); font-size: 10pt; page-break-inside: avoid; }
.action:last-child { border-bottom: none; }
.action__name { font-weight: 700; color: var(--ink); }
.action__hint { font-size: 8.5pt; color: var(--ink-soft); margin-left: 2mm; font-style: italic; }

.leerdoelen-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-top: 4mm; }
.leerdoelen-col__title { font-size: 10pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--paper); background: var(--accent); padding: 1.5mm 3mm; border-radius: 1mm; margin: 0 0 2mm; display: block; }
.leerdoelen-subhead { font-size: 9pt; color: var(--ink-soft); margin: 3mm 0 1.5mm; font-style: italic; font-weight: 600; border-bottom: 1px dotted var(--line); padding-bottom: 1mm; }
.leerdoel { padding: 1.5mm 0 2mm; page-break-inside: avoid; }
.leerdoel__title { font-size: 10pt; font-weight: 700; color: var(--ink); margin: 0 0 1mm; }
.leerdoel ul { margin: 0; padding-left: 4mm; font-size: 8.5pt; line-height: 1.35; }
.leerdoel li { margin-bottom: 0.5mm; color: var(--ink-soft); }
CSS;
    }
}
