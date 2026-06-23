<?php
namespace TT\Modules\Planning\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Query\LookupTranslator;

/**
 * TeamPlannerWeeklyPrintable (#1631) — composes the branded weekly
 * planner sheet as a browser-rendered, print-to-PDF document.
 *
 * This is the pixel-perfect path: a standalone HTML page the coach
 * opens, then Saves-as-PDF (or Prints) from the browser. Unlike the
 * DomPDF `team_planning` exporter (kept as the programmatic / REST
 * fallback), the browser renders real flexbox + gradients + radii, so
 * the output matches the approved `final.html` mockup exactly.
 *
 * All data composition (query, branding, principle lookup, date walk)
 * lives here, not in the print router or the view — the router only
 * wraps this body in a standalone document with a toolbar.
 *
 * Branding (academy colours / name / logo) comes from `tt_config`; the
 * green gradient + gold rail + soft-gold pills are derived from the
 * configured primary / secondary so the sheet re-skins per club.
 */
final class TeamPlannerWeeklyPrintable {

    /** Default content toggles — mirror the exporter's DEFAULT_FIELDS. */
    public const DEFAULT_FIELDS = [
        'time'       => true,
        'location'   => true,
        'duration'   => true,
        'match'      => true,
        'theme'      => true,
        'principles' => true,
        'notes'      => false,
        'restdays'   => true,
    ];

    /** @var array<string,bool> */
    public const DEFAULT_HEADER = [
        'academy_name'   => true,
        'generated_date' => true,
    ];

    /** Type-tag fill colour per activity-type key (matches final.html). */
    private const TYPE_TAG_COLORS = [
        'training'   => '__primary__',
        'game'       => '#b3611f',
        'match'      => '#b3611f',
        'friendly'   => '#c47f17',
        'tournament' => '#7a4ea3',
        'keeper'     => '#1e88e5',
        'goalkeeper' => '#1e88e5',
    ];

    /** Activity-type keys treated as a fixture (match cell layout). */
    private const MATCH_TYPES = [ 'game', 'match', 'friendly', 'tournament' ];

    /**
     * Build the full printable: title, style block, and body markup.
     *
     * @param array<string,bool> $fields
     * @param array<string,bool> $header
     * @return array{title:string,filename:string,style:string,body:string,empty:bool}
     */
    public static function render(
        int $team_id, string $date_from, string $date_to,
        array $fields, array $header, int $club_id
    ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $fields = self::normalize( $fields, self::DEFAULT_FIELDS );
        $header = self::normalize( $header, self::DEFAULT_HEADER );

        $team      = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
            $team_id, $club_id
        ) );
        $team_name = $team ? (string) $team->name : '';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.session_date, a.title, a.location, a.activity_type_key,
                    a.start_time, a.end_time, a.opponent, a.home_away, a.kickoff_time, a.notes
                FROM {$p}tt_activities a
                WHERE a.club_id = %d AND a.team_id = %d
                  AND a.session_date BETWEEN %s AND %s
                  AND a.activity_status_key <> 'cancelled'
                  AND ( a.archived_at IS NULL OR a.archived_at = '' )
                ORDER BY a.session_date ASC, a.start_time ASC, a.kickoff_time ASC, a.id ASC",
            $club_id, $team_id, $date_from, $date_to
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $principles = ! empty( $fields['principles'] )
            ? self::principlesByActivity( array_map( static fn ( $r ): int => (int) $r->id, $rows ) )
            : [];

        $branding = self::branding();

        return self::compose(
            $team_name, $date_from, $date_to, $rows, $fields, $header, $branding, $principles
        );
    }

    /**
     * @param object[]            $rows
     * @param array<string,bool>  $fields
     * @param array<string,bool>  $header
     * @param array{primary:string,secondary:string,name:string,logo:string} $branding
     * @param array<int,string[]> $principles
     * @return array{title:string,filename:string,style:string,body:string,empty:bool}
     */
    private static function compose(
        string $team_name, string $date_from, string $date_to, array $rows,
        array $fields, array $header, array $branding, array $principles
    ): array {
        $primary   = self::safeColor( (string) ( $branding['primary'] ?? '' ), '#0b3d2e' );
        $secondary = self::safeColor( (string) ( $branding['secondary'] ?? '' ), '#e8b624' );
        $academy   = (string) ( $branding['name'] ?? '' );
        $logo      = (string) ( $branding['logo'] ?? '' );

        $by_date = [];
        foreach ( $rows as $r ) {
            $d = (string) ( $r->session_date ?? '' );
            if ( $d !== '' ) $by_date[ $d ][] = $r;
        }

        $tz     = wp_timezone();
        $cursor = new \DateTime( $date_from . ' 00:00:00', $tz );
        $end    = new \DateTime( $date_to . ' 00:00:00', $tz );

        $rows_html = '';
        $any       = false;
        $guard     = 0;
        while ( $cursor <= $end && $guard < 400 ) {
            $guard++;
            $dkey = $cursor->format( 'Y-m-d' );
            $ts   = $cursor->getTimestamp();
            $acts = $by_date[ $dkey ] ?? [];

            if ( ! $acts && empty( $fields['restdays'] ) ) {
                $cursor->modify( '+1 day' );
                continue;
            }

            $day_cell = '<div class="tt-wp-day"><span class="tt-wp-dn">'
                . esc_html( ucfirst( (string) wp_date( 'l', $ts ) ) ) . '</span>'
                . '<span class="tt-wp-dd">' . esc_html( (string) wp_date( 'j M', $ts ) ) . '</span></div>';

            if ( ! $acts ) {
                $rows_html .= '<div class="tt-wp-row"><div class="tt-wp-day tt-wp-day--rest">'
                    . '<span class="tt-wp-dn">' . esc_html( ucfirst( (string) wp_date( 'l', $ts ) ) ) . '</span>'
                    . '<span class="tt-wp-dd">' . esc_html( (string) wp_date( 'j M', $ts ) ) . '</span></div>'
                    . '<div class="tt-wp-cell"><div class="tt-wp-rest"><span class="tt-wp-dot"></span>'
                    . esc_html__( 'Rest day — no scheduled activity.', 'talenttrack' )
                    . '</div></div></div>';
            } else {
                $cells = '';
                foreach ( $acts as $a ) {
                    $cells .= self::activityCell( $a, $team_name, $fields, $principles, $primary, $secondary );
                }
                $rows_html .= '<div class="tt-wp-row">' . $day_cell
                    . '<div class="tt-wp-cell">' . $cells . '</div></div>';
            }
            $any = true;
            $cursor->modify( '+1 day' );
        }

        // Header.
        $crest = $logo !== ''
            ? '<img class="tt-wp-crest tt-wp-crest--img" src="' . esc_url( $logo ) . '" alt="" />'
            : '<div class="tt-wp-crest">' . esc_html( self::initials( $academy !== '' ? $academy : $team_name ) ) . '</div>';

        $week_no   = (string) wp_date( 'W', strtotime( $date_from ) );
        $year      = (string) wp_date( 'Y', strtotime( $date_from ) );
        $range_str = self::formatDay( $date_from ) . ' – ' . self::formatDate( $date_to );

        // Title (#1631 — user spec): "Week plan · {team} · Week {n} · {year}".
        $bits = [ __( 'Week plan', 'talenttrack' ) ];
        if ( $team_name !== '' ) $bits[] = $team_name;
        $bits[] = sprintf(
            /* translators: %s: ISO week number */
            __( 'Week %s', 'talenttrack' ),
            $week_no
        );
        $bits[]    = $year;
        $title_big = implode( ' · ', $bits );

        // Academy name (when shown) becomes the subline under the title.
        $sub_line = ( ! empty( $header['academy_name'] ) && $academy !== '' ) ? $academy : '';
        $sub_html = $sub_line !== ''
            ? '<div class="tt-wp-sub">' . esc_html( $sub_line ) . '</div>'
            : '';

        $doc_title = $academy !== '' ? $academy : ( $team_name !== '' ? $team_name : __( 'Team planning', 'talenttrack' ) );

        // Proposed Save-as-PDF filename: browsers default to document.title,
        // so the page <title> IS the filename. Mirror the visible header with
        // hyphen separators and strip characters illegal in file names.
        $filename = str_replace( ' · ', ' - ', $title_big );
        $filename = (string) preg_replace( '#[\\\\/:*?"<>|]+#', '-', $filename );
        $filename = trim( (string) preg_replace( '/\s+/', ' ', $filename ) );

        $header_html = '<div class="tt-wp-hd">' . $crest
            . '<div class="tt-wp-id"><div class="tt-wp-ac">'
            . esc_html( $title_big ) . '</div>'
            . $sub_html . '</div>'
            . '<div class="tt-wp-wk"><span>' . esc_html( $range_str ) . '</span></div></div>';

        // Footer.
        $footer_html = '';
        if ( ! empty( $header['generated_date'] ) ) {
            $left  = trim( ( $academy !== '' ? $academy . ' · ' : '' ) . $team_name );
            $right = sprintf(
                /* translators: %s: date the sheet was generated */
                __( 'Generated %s', 'talenttrack' ),
                self::formatDate( ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d' ) )
            );
            $footer_html = '<div class="tt-wp-ftr"><span>' . esc_html( $left ) . '</span>'
                . '<span>' . esc_html( $right ) . '</span></div>';
        }

        if ( ! $any ) {
            $rows_html = '<div class="tt-wp-empty"><em>'
                . esc_html__( 'No activities scheduled in this range.', 'talenttrack' )
                . '</em></div>';
        }

        $body = '<div class="tt-wp-page">' . $header_html . '<div class="tt-wp-week">'
            . $rows_html . '</div>' . $footer_html . '</div>';

        return [
            'title'    => $doc_title,
            'filename' => $filename !== '' ? $filename : $doc_title,
            'style'    => self::styleBlock( $primary, $secondary ),
            'body'     => $body,
            'empty'    => ! $any,
        ];
    }

    /**
     * One activity card inside a day cell. Match-type activities render
     * the fixture title (`Team — Opponent`) and kickoff meta; everything
     * else renders the session title + meta + principle pills.
     *
     * @param array<string,bool>  $fields
     * @param array<int,string[]> $principles
     */
    private static function activityCell(
        object $a, string $team_name, array $fields, array $principles,
        string $primary, string $secondary
    ): string {
        $type_key = (string) ( $a->activity_type_key ?? '' );
        $is_match = in_array( $type_key, self::MATCH_TYPES, true );

        $tag_color = self::TYPE_TAG_COLORS[ $type_key ] ?? '#6b7780';
        if ( $tag_color === '__primary__' ) $tag_color = $primary;
        $tag_color = self::safeColor( $tag_color, '#6b7780' );

        $type_label = $type_key !== ''
            ? LookupTranslator::byTypeAndName( 'activity_type', $type_key )
            : '';
        if ( $type_label === '' ) {
            $type_label = $type_key !== '' ? ucfirst( str_replace( '_', ' ', $type_key ) ) : __( 'Activity', 'talenttrack' );
        }

        // Title line.
        $title = '';
        if ( $is_match ) {
            $opp = trim( (string) ( $a->opponent ?? '' ) );
            $title = $opp !== '' ? trim( $team_name . ' — ' . $opp ) : $team_name;
        } elseif ( ! empty( $fields['theme'] ) ) {
            $title = (string) ( $a->title ?? '' );
        }

        $out  = '<div class="tt-wp-act">';
        $out .= '<div class="tt-wp-ttl"><span class="tt-wp-tp" style="background:' . esc_attr( $tag_color ) . ';">'
            . esc_html( $type_label ) . '</span>';
        if ( $title !== '' ) $out .= '<span class="tt-wp-name">' . esc_html( $title ) . '</span>';
        $out .= '</div>';

        // Meta line.
        $meta = [];
        if ( $is_match ) {
            if ( ! empty( $fields['match'] ) ) {
                $ko = self::clockTime( (string) ( $a->kickoff_time ?? '' ) );
                if ( $ko !== '' ) {
                    /* translators: %s: kickoff time, e.g. "11:00" */
                    $meta[] = sprintf( __( 'Kickoff %s', 'talenttrack' ), $ko );
                }
                $ha = (string) ( $a->home_away ?? '' );
                if ( $ha === 'home' ) $meta[] = __( 'Home', 'talenttrack' );
                elseif ( $ha === 'away' ) $meta[] = __( 'Away', 'talenttrack' );
            }
            if ( ! empty( $fields['location'] ) ) {
                $loc = (string) ( $a->location ?? '' );
                if ( $loc !== '' ) $meta[] = $loc;
            }
        } else {
            if ( ! empty( $fields['time'] ) ) {
                $t = self::timeRange( (string) ( $a->start_time ?? '' ), (string) ( $a->end_time ?? '' ), (string) ( $a->kickoff_time ?? '' ) );
                if ( $t !== '' ) $meta[] = $t;
            }
            if ( ! empty( $fields['location'] ) ) {
                $loc = (string) ( $a->location ?? '' );
                if ( $loc !== '' ) $meta[] = $loc;
            }
            if ( ! empty( $fields['duration'] ) ) {
                $dur = self::durationLabel( (string) ( $a->start_time ?? '' ), (string) ( $a->end_time ?? '' ) );
                if ( $dur !== '' ) $meta[] = $dur;
            }
        }
        if ( $meta ) $out .= '<div class="tt-wp-meta">' . esc_html( implode( ' · ', $meta ) ) . '</div>';

        // Principle pills.
        if ( ! empty( $fields['principles'] ) ) {
            $codes = $principles[ (int) ( $a->id ?? 0 ) ] ?? [];
            if ( $codes ) {
                $chips = '';
                foreach ( $codes as $c ) {
                    $chips .= '<span class="tt-wp-pill">' . esc_html( (string) $c ) . '</span>';
                }
                $out .= '<div class="tt-wp-pills">' . $chips . '</div>';
            }
        }

        // Notes.
        if ( ! empty( $fields['notes'] ) ) {
            $notes = trim( (string) ( $a->notes ?? '' ) );
            if ( $notes !== '' ) $out .= '<div class="tt-wp-notes">' . esc_html( $notes ) . '</div>';
        }

        return $out . '</div>';
    }

    /**
     * The print stylesheet — browser-grade (flexbox, gradients, radii),
     * branding-injected. `print-color-adjust: exact` forces the green
     * day cards + gold rail + tags to survive Save-as-PDF.
     */
    private static function styleBlock( string $primary, string $secondary ): string {
        $green_2   = self::lighten( $primary, 0.18 );
        $pill_bg   = self::lighten( $secondary, 0.82 );
        $pill_bd   = self::lighten( $secondary, 0.55 );
        $pill_ink  = self::darken( $secondary, 0.45 );

        return <<<CSS
        :root {
            --tt-green: {$primary}; --tt-green-2: {$green_2}; --tt-gold: {$secondary};
            --tt-pill-bg: {$pill_bg}; --tt-pill-bd: {$pill_bd}; --tt-pill-ink: {$pill_ink};
            --tt-ink: #1a1d21; --tt-muted: #6b7780; --tt-line: #e3e6ea; --tt-rest: #9aa3aa;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; background: #eceef0; color: var(--tt-ink);
            font: 14px/1.45 "Helvetica Neue", Arial, system-ui, sans-serif;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .tt-wp-page {
            width: 210mm; min-height: 297mm; background: #fff; margin: 0 auto;
            padding: 15mm 14mm; position: relative;
            box-shadow: 0 2px 14px rgba(0,0,0,.12);
        }
        .tt-wp-hd {
            display: flex; align-items: center; gap: 14px;
            border-bottom: 3px solid var(--tt-green); padding-bottom: 12px;
        }
        .tt-wp-crest {
            width: 48px; height: 48px; border-radius: 12px; flex: none;
            background: var(--tt-green); color: #fff; display: grid; place-items: center;
            font-weight: 800; font-size: 16px; box-shadow: inset 0 0 0 2px rgba(232,182,36,.55);
        }
        .tt-wp-crest--img { object-fit: contain; background: #fff; box-shadow: none; }
        .tt-wp-id { min-width: 0; }
        .tt-wp-ac { font-weight: 800; font-size: 17px; color: var(--tt-green); }
        .tt-wp-sub { color: var(--tt-muted); font-size: 12.5px; margin-top: 1px; }
        .tt-wp-wk { margin-left: auto; text-align: right; flex: none; }
        .tt-wp-wk b { display: block; font-size: 19px; }
        .tt-wp-wk span { color: var(--tt-muted); font-size: 12px; }
        .tt-wp-week { margin-top: 2px; }
        .tt-wp-row {
            display: grid; grid-template-columns: 34mm 1fr;
            border-bottom: 1px solid var(--tt-line);
        }
        .tt-wp-row:last-child { border-bottom: 0; }
        .tt-wp-day {
            background: linear-gradient(180deg, var(--tt-green), var(--tt-green-2));
            color: #fff; padding: 12px 13px; position: relative;
            display: flex; flex-direction: column; justify-content: center;
        }
        .tt-wp-day::after {
            content: ""; position: absolute; right: 0; top: 8px; bottom: 8px;
            width: 3px; background: var(--tt-gold); border-radius: 2px;
        }
        .tt-wp-dn { font-weight: 800; font-size: 14px; letter-spacing: .03em; }
        .tt-wp-dd { font-size: 11.5px; opacity: .85; margin-top: 2px; }
        .tt-wp-cell { padding: 12px 15px; min-height: 20mm; }
        .tt-wp-act + .tt-wp-act {
            margin-top: 9px; padding-top: 9px; border-top: 1px dashed var(--tt-line);
        }
        .tt-wp-ttl {
            font-weight: 700; font-size: 14.5px; display: flex; align-items: center;
            gap: 9px; flex-wrap: wrap;
        }
        .tt-wp-tp {
            font-size: 10px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase;
            color: #fff; border-radius: 5px; padding: 3px 9px;
        }
        .tt-wp-meta { color: var(--tt-muted); font-size: 12px; margin: 3px 0 0; }
        .tt-wp-pills { margin-top: 2px; }
        .tt-wp-pill {
            display: inline-block; background: var(--tt-pill-bg); color: var(--tt-pill-ink);
            border: 1px solid var(--tt-pill-bd); border-radius: 999px; font-size: 10.5px;
            font-weight: 600; padding: 2px 10px; margin: 6px 5px 0 0;
        }
        .tt-wp-notes { color: var(--tt-muted); font-size: 12px; font-style: italic; margin-top: 5px; }
        .tt-wp-rest {
            color: var(--tt-rest); font-style: italic; font-size: 12.5px;
            display: flex; align-items: center; gap: 8px;
        }
        .tt-wp-rest .tt-wp-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--tt-line); }
        .tt-wp-empty { padding: 18px 4px; color: var(--tt-muted); }
        .tt-wp-ftr {
            margin-top: 14px; border-top: 1px solid var(--tt-line); padding-top: 6px;
            display: flex; justify-content: space-between; color: var(--tt-muted); font-size: 10.5px;
        }
        @media print {
            html, body { background: #fff; }
            .tt-wp-page { box-shadow: none; margin: 0; width: auto; min-height: 0; padding: 0; }
            @page { size: A4 portrait; margin: 14mm; }
        }
        CSS;
    }

    /**
     * @return array{primary:string,secondary:string,name:string,logo:string}
     */
    private static function branding(): array {
        return [
            'primary'   => (string) QueryHelpers::get_config( 'primary_color', '#0b3d2e' ),
            'secondary' => (string) QueryHelpers::get_config( 'secondary_color', '#e8b624' ),
            'name'      => (string) QueryHelpers::get_config( 'academy_name', '' ),
            'logo'      => (string) QueryHelpers::get_config( 'logo_url', '' ),
        ];
    }

    /**
     * @param int[] $activity_ids
     * @return array<int,string[]>
     */
    private static function principlesByActivity( array $activity_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $activity_ids ), static fn ( $v ): bool => $v > 0 ) ) );
        if ( ! $ids ) return [];
        global $wpdb;
        $p    = $wpdb->prefix;
        $link = $p . 'tt_activity_principles';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $link ) ) !== $link ) {
            return [];
        }
        $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ap.activity_id, pr.code
               FROM {$link} ap
               JOIN {$p}tt_principles pr ON pr.id = ap.principle_id
              WHERE ap.activity_id IN ($ph)
              ORDER BY ap.sort_order ASC, ap.id ASC",
            ...$ids
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $aid = (int) $r->activity_id;
            if ( ! isset( $out[ $aid ] ) ) $out[ $aid ] = [];
            $code = (string) $r->code;
            if ( $code !== '' && count( $out[ $aid ] ) < 6 ) $out[ $aid ][] = $code;
        }
        return $out;
    }

    /**
     * @param array<string,bool> $given
     * @param array<string,bool> $defaults
     * @return array<string,bool>
     */
    private static function normalize( array $given, array $defaults ): array {
        $out = $defaults;
        foreach ( $out as $k => $_ ) {
            if ( array_key_exists( $k, $given ) ) $out[ $k ] = (bool) $given[ $k ];
        }
        return $out;
    }

    private static function safeColor( string $c, string $fallback ): string {
        return preg_match( '/^#[0-9a-fA-F]{6}$/', $c ) ? $c : $fallback;
    }

    /** Blend a hex colour toward white by $amount (0–1). */
    private static function lighten( string $hex, float $amount ): string {
        return self::mix( $hex, 255, $amount );
    }

    /** Blend a hex colour toward black by $amount (0–1). */
    private static function darken( string $hex, float $amount ): string {
        return self::mix( $hex, 0, $amount );
    }

    private static function mix( string $hex, int $target, float $amount ): string {
        $hex = ltrim( self::safeColor( $hex, '#0b3d2e' ), '#' );
        $r   = (int) hexdec( substr( $hex, 0, 2 ) );
        $g   = (int) hexdec( substr( $hex, 2, 2 ) );
        $b   = (int) hexdec( substr( $hex, 4, 2 ) );
        $amount = max( 0.0, min( 1.0, $amount ) );
        $f   = static fn ( int $v ): int => (int) round( $v + ( $target - $v ) * $amount );
        return sprintf( '#%02x%02x%02x', $f( $r ), $f( $g ), $f( $b ) );
    }

    private static function initials( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/', $name ) ?: [];
        $ini   = '';
        foreach ( $parts as $w ) {
            if ( $w === '' ) continue;
            $ini .= strtoupper( mb_substr( $w, 0, 1 ) );
            if ( strlen( $ini ) >= 2 ) break;
        }
        return $ini !== '' ? $ini : strtoupper( mb_substr( $name, 0, 2 ) );
    }

    private static function formatDate( string $ymd ): string {
        $ts = strtotime( $ymd );
        return $ts ? (string) wp_date( 'j M Y', $ts ) : $ymd;
    }

    /** Day + month without year — for the left side of a same-year range. */
    private static function formatDay( string $ymd ): string {
        $ts = strtotime( $ymd );
        return $ts ? (string) wp_date( 'j M', $ts ) : $ymd;
    }

    private static function clockTime( string $time ): string {
        return ( $time !== '' && $time !== '00:00:00' ) ? substr( $time, 0, 5 ) : '';
    }

    private static function timeRange( string $start, string $end, string $kickoff ): string {
        $s = self::clockTime( $start );
        if ( $s === '' ) $s = self::clockTime( $kickoff );
        if ( $s === '' ) return '';
        $e = self::clockTime( $end );
        return $e !== '' ? $s . '–' . $e : $s;
    }

    private static function durationLabel( string $start, string $end ): string {
        if ( $start === '' || $end === '' || $start === '00:00:00' || $end === '00:00:00' ) return '';
        $s = strtotime( '1970-01-01 ' . $start );
        $e = strtotime( '1970-01-01 ' . $end );
        if ( ! $s || ! $e || $e <= $s ) return '';
        $mins = (int) round( ( $e - $s ) / 60 );
        if ( $mins >= 60 ) {
            $h = intdiv( $mins, 60 );
            $m = $mins % 60;
            return $m > 0 ? sprintf( '%dh%02d', $h, $m ) : sprintf( '%dh', $h );
        }
        /* translators: %d: duration in minutes */
        return sprintf( __( '%d min', 'talenttrack' ), $mins );
    }
}
