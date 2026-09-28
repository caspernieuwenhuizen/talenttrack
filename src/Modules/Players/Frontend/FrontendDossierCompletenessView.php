<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Players\Services\DossierCompletenessService;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendDossierCompletenessView (#3805) — one screen per squad saying
 * whose file is still incomplete. Slug: `dossier-completeness`.
 *
 * Which player question does this answer? *What does this player need
 * next?* for the part of a player's file that is not football: an adult the
 * club can reach, a parent account that can read their record, and a
 * recorded answer to whether the club may photograph them.
 *
 * Composition only. Every count, every list and every judgement of complete
 * versus missing comes from `DossierCompletenessService`, which the REST
 * route calls too, so `GET /teams/{id}/dossier-completeness` and this page
 * cannot disagree (CLAUDE.md §4).
 *
 * **It shows no contact details.** The report says a guardian e-mail
 * address is missing, never what the address is when it is there. A
 * per-team checklist that printed families' contact details would be a
 * bulk export with a friendlier heading.
 *
 * **It marks, it never hides.** #3804 locked that media consent is a record
 * and not a gate: naming the players whose file holds pictures nobody
 * consented to is a prompt to go and ask, and nothing on this page blurs,
 * blocks or removes a single image.
 */
final class FrontendDossierCompletenessView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Dossier completeness', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title );

        if ( ! $is_admin && ! MatrixGate::canAnyScope( $user_id, 'players', 'read' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to review player files.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-dossier-completeness',
            TT_PLUGIN_URL . 'assets/css/frontend-dossier-completeness.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-dossier-completeness',
            TT_PLUGIN_URL . 'assets/js/frontend-dossier-completeness.js',
            [],
            TT_VERSION,
            true
        );

        self::renderHeader( $title );

        $see_all = $is_admin || MatrixGate::can( $user_id, 'players', 'read', 'global' );
        $teams   = $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams are available to you yet.', 'talenttrack' ) . '</p>';
            return;
        }

        // Through an array cast: `get_teams()` answers bare `stdClass` rows,
        // whose properties static analysis cannot check.
        $allowed_ids = array_map(
            static function ( $team ): int {
                $row = (array) $team;
                return (int) ( $row['id'] ?? 0 );
            },
            (array) $teams
        );
        $team_id     = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id > 0 && ! in_array( $team_id, $allowed_ids, true ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderPicker( $teams, $team_id );

        echo '<p class="tt-dc-source">'
            . esc_html__( 'This page says which fields are still empty, never what is in them. Open a player to read or change their guardian details.', 'talenttrack' )
            . '</p>';

        if ( $team_id <= 0 ) {
            echo '<p class="tt-dc-hint">' . esc_html__( 'Choose a team to see whose file is still incomplete.', 'talenttrack' ) . '</p>';
            return;
        }

        $report = ( new DossierCompletenessService() )->forTeam( $team_id );
        if ( empty( $report['checks'] ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players on this team yet.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderReachability( $report['family_reachable'] );
        self::renderOverview( $report['players'], (int) $report['players_complete'] );

        echo '<section class="tt-dc-checks" aria-labelledby="tt-dc-checks-title">';
        echo '<div class="tt-dc-checks__head">';
        echo '<h2 class="tt-dc-section-title" id="tt-dc-checks-title">' . esc_html_x( 'Checks', 'dossier completeness section heading', 'talenttrack' ) . '</h2>';
        // The cards work one by one without this; the script only reveals
        // it, so a page without JavaScript never shows a dead control.
        echo '<div class="tt-dc-toggle-all" data-tt-dc-toggle-all hidden>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-dc-open-all>' . esc_html__( 'Open all', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-dc-close-all>' . esc_html__( 'Close all', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</div>';
        foreach ( $report['checks'] as $check ) {
            self::renderCheckCard( $check );
        }
        echo '</section>';
    }

    /**
     * #4145 — the overview: one line per player naming what their file is
     * missing, most gaps first, as the service ordered them.
     *
     * Rendered twice over the same rows — a grid for a screen wide enough
     * to hold seven columns, a list of chips for a phone — and the
     * stylesheet shows one. A table squeezed to 360px scrolls sideways,
     * and a chip list on a desktop wastes the one view that compares
     * players at a glance.
     *
     * @param list<array<string,mixed>> $players
     */
    private static function renderOverview( array $players, int $players_complete ): void {
        $incomplete = [];
        $complete   = [];
        foreach ( $players as $row ) {
            if ( empty( $row['missing'] ) ) {
                $complete[] = $row;
            } else {
                $incomplete[] = $row;
            }
        }

        echo '<section class="tt-dc-card tt-dc-overview" aria-labelledby="tt-dc-overview-title">';
        echo '<h2 class="tt-dc-card__title" id="tt-dc-overview-title">' . esc_html__( 'Who is missing what', 'talenttrack' ) . '</h2>';

        if ( $incomplete === [] ) {
            echo '<p class="tt-dc-card__clear">' . esc_html__( 'Every file on this team is complete.', 'talenttrack' ) . '</p>';
        } else {
            self::renderGrid( $incomplete );
            self::renderChipList( $incomplete );
        }

        if ( $complete !== [] ) {
            echo '<details class="tt-dc-fold">';
            echo '<summary class="tt-dc-fold__summary"><span class="tt-dc-chevron" aria-hidden="true"></span>' . esc_html( sprintf(
                /* translators: %d: number of players whose file has nothing missing. */
                _n( '%d player complete', '%d players complete', $players_complete, 'talenttrack' ),
                $players_complete
            ) ) . '</summary>';
            echo '<ul class="tt-dc-fold__list">';
            foreach ( $complete as $row ) {
                echo '<li>';
                self::renderPlayerName( $row );
                echo '</li>';
            }
            echo '</ul>';
            echo '</details>';
        }

        echo '</section>';
    }

    /**
     * The wide-screen grid: players down, the six checks across, a gap
     * count at the end. Every cell carries its state as words for a
     * screen reader; the glyph is for the eye and never the only signal.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function renderGrid( array $rows ): void {
        echo '<div class="tt-dc-grid">';
        echo '<table class="tt-dc-grid__table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        foreach ( DossierCompletenessService::checkKeys() as $key ) {
            echo '<th scope="col"><abbr title="' . esc_attr( DossierCompletenessService::checkLabel( $key ) ) . '">'
                . esc_html( DossierCompletenessService::checkShortLabel( $key ) ) . '</abbr></th>';
        }
        echo '<th scope="col">' . esc_html_x( 'Gaps', 'dossier grid column', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $missing = is_array( $row['missing'] ?? null ) ? $row['missing'] : [];
            echo '<tr>';
            echo '<th scope="row">';
            self::renderPlayerName( $row );
            echo '</th>';
            foreach ( DossierCompletenessService::checkKeys() as $key ) {
                $label = DossierCompletenessService::checkLabel( $key );
                if ( ! in_array( $key, $missing, true ) ) {
                    echo '<td class="tt-dc-cell tt-dc-cell--complete"><span aria-hidden="true">&#10003;</span>'
                        . '<span class="tt-dc-sr">' . esc_html( sprintf(
                            /* translators: %s: the name of a dossier check, e.g. "Guardian phone number". */
                            __( '%s: complete', 'talenttrack' ),
                            $label
                        ) ) . '</span></td>';
                    continue;
                }
                if ( $key === DossierCompletenessService::MEDIA_WITHOUT_CONSENT ) {
                    $items = (int) ( $row['media_items'] ?? 0 );
                    echo '<td class="tt-dc-cell tt-dc-cell--missing"><span aria-hidden="true">' . (int) $items . '</span>'
                        . '<span class="tt-dc-sr">' . esc_html( self::overviewChipLabel( $key, $items ) ) . '</span></td>';
                    continue;
                }
                echo '<td class="tt-dc-cell tt-dc-cell--missing"><span aria-hidden="true">&#10007;</span>'
                    . '<span class="tt-dc-sr">' . esc_html( sprintf(
                        /* translators: %s: the name of a dossier check, e.g. "Guardian phone number". */
                        __( '%s: missing', 'talenttrack' ),
                        $label
                    ) ) . '</span></td>';
            }
            echo '<td class="tt-dc-cell tt-dc-cell--gaps">' . (int) ( $row['gap_count'] ?? 0 ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * The phone layout of the same rows: a name, then only what is
     * missing, one chip per check.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function renderChipList( array $rows ): void {
        echo '<ul class="tt-dc-list tt-dc-overview__list">';
        foreach ( $rows as $row ) {
            $missing = is_array( $row['missing'] ?? null ) ? $row['missing'] : [];
            echo '<li class="tt-dc-row tt-dc-overview__row">';
            echo '<span class="tt-dc-row__name">';
            self::renderPlayerName( $row );
            echo '</span>';
            echo '<span class="tt-dc-overview__chips">';
            foreach ( $missing as $key ) {
                echo '<span class="tt-dc-chip tt-dc-chip--missing">' . esc_html( self::overviewChipLabel( (string) $key, (int) ( $row['media_items'] ?? 0 ) ) ) . '</span>';
            }
            echo '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * A player's name, linked to their record when the viewer can open it.
     * The link is gated like every other cross-view link: a viewer who
     * could not reach the player view reads the name as plain text.
     *
     * @param array<string,mixed> $row A `players` entry from the service.
     */
    private static function renderPlayerName( array $row ): void {
        $player_id = (int) ( $row['player_id'] ?? 0 );
        $name      = (string) ( $row['name'] ?? '' );
        $label     = $name !== '' ? $name : '#' . $player_id;
        if ( $player_id > 0 && CrossViewLink::allows( 'players' ) ) {
            echo RecordLink::inline( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes its own output.
                $label,
                RecordLink::detailUrlForWithBack( 'players', $player_id ) /* tt-xview-ok — gated by CrossViewLink::allows() above */
            );
            return;
        }
        echo esc_html( $label );
    }

    /**
     * #4014 — what the guardian columns and the parent-account link add up
     * to.
     *
     * An administrator read "0 of 21 guardian e-mail addresses" beside "3 of
     * 21 parent accounts" as two sources that disagreed, and could not tell
     * which one the office uses to contact a family. Both are true, and
     * neither answers "can we reach this family at all". This line does, and
     * it is deliberately placed above the checks rather than among them:
     * it is a reading of them, not a seventh one.
     *
     * @param array{total:int,reachable:int,unreachable:int} $reach
     */
    private static function renderReachability( array $reach ): void {
        if ( $reach['total'] < 1 ) return;

        echo '<section class="tt-dc-card">';
        echo '<h2 class="tt-dc-card__title">' . esc_html__( 'Families we can reach', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-dc-card__summary">' . esc_html( sprintf(
            /* translators: 1: number of families the club can contact, 2: squad size. */
            __( '%1$d of %2$d families reachable', 'talenttrack' ),
            (int) $reach['reachable'],
            (int) $reach['total']
        ) ) . '</p>';
        echo '<p class="tt-dc-source">'
            . esc_html__( 'A family counts as reachable when this player has a guardian e-mail address, a guardian phone number or a linked parent account. The checks below stay separate on purpose: an account is how a parent reads their child\'s record, and the guardian fields are how the club phones somebody on a Saturday morning.', 'talenttrack' )
            . '</p>';
        echo '</section>';
    }

    /**
     * @param array<int, mixed> $teams Bare rows from `QueryHelpers::get_teams()`.
     */
    private static function renderPicker( array $teams, int $team_id ): void {
        ?>
        <form method="get" class="tt-dc-picker">
            <?php foreach ( $_GET as $k => $v ) :
                if ( $k === 'team_id' ) continue;
                if ( ! is_scalar( $v ) ) continue;
                ?>
                <input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>" />
            <?php endforeach; ?>
            <label class="tt-dc-picker__field">
                <span class="tt-dc-picker__label"><?php esc_html_e( 'Team', 'talenttrack' ); ?></span>
                <select name="team_id" class="tt-input" onchange="this.form.submit()">
                    <option value="0"><?php esc_html_e( '— Choose team —', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams as $team ) :
                        $row = (array) $team;
                        $tid = (int) ( $row['id'] ?? 0 );
                        ?>
                        <option value="<?php echo (int) $tid; ?>"<?php selected( $team_id, $tid ); ?>><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Show', 'talenttrack' ); ?></button></noscript>
        </form>
        <?php
    }

    /**
     * One check as a native `<details>`, closed on load (#4145): the
     * summary line carries everything needed to decide whether to open it
     * — the name, "x of y complete", the bar and the item count — and the
     * body holds the names. A browser opens and closes it by tap, Enter
     * or Space with no script at all.
     *
     * @param array<string,mixed> $check
     */
    private static function renderCheckCard( array $check ): void {
        $total    = (int) $check['total'];
        $complete = (int) $check['complete'];
        $needs    = is_array( $check['needs'] ?? null ) ? $check['needs'] : [];
        $recorded = is_array( $check['recorded'] ?? null ) ? $check['recorded'] : [];
        $pct      = (int) ( $check['completion'] ?? 0 );
        $is_clear = $needs === [];

        echo '<details class="tt-dc-card tt-dc-check' . ( $is_clear ? ' tt-dc-check--clear' : '' ) . '" data-tt-dc-check>';
        echo '<summary class="tt-dc-check__summary">';
        echo '<span class="tt-dc-card__head">';
        echo '<span class="tt-dc-chevron" aria-hidden="true"></span>';
        echo '<h3 class="tt-dc-card__title">' . esc_html( (string) $check['name'] ) . '</h3>';
        if ( isset( $check['item_count'] ) && (int) $check['item_count'] > 0 ) {
            echo '<span class="tt-dc-chip tt-dc-chip--missing">' . esc_html( sprintf(
                /* translators: %d: number of photos or videos held with no consent recorded. */
                _n( '%d item', '%d items', (int) $check['item_count'], 'talenttrack' ),
                (int) $check['item_count']
            ) ) . '</span>';
        }
        echo '</span>';

        if ( $is_clear ) {
            echo '<span class="tt-dc-card__summary"><span class="tt-dc-chip tt-dc-chip--complete">' . esc_html_x( 'Complete', 'dossier check state', 'talenttrack' ) . '</span></span>';
        } else {
            echo '<span class="tt-dc-card__summary">' . esc_html( sprintf(
                /* translators: 1: number of players whose file is complete on this check, 2: squad size. */
                __( '%1$d of %2$d complete', 'talenttrack' ),
                $complete,
                $total
            ) ) . '</span>';
        }

        echo '<span class="tt-dc-bar"><i style="width:' . (int) $pct . '%"></i></span>'; /* tt-inline-ok */
        echo '</summary>';
        echo '<div class="tt-dc-check__body">';

        if ( $needs === [] ) {
            echo '<p class="tt-dc-card__clear">' . esc_html__( 'Nothing missing on this one.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-dc-list">';
            foreach ( $needs as $need ) {
                self::renderRow(
                    (int) ( $need['player_id'] ?? 0 ),
                    (string) ( $need['name'] ?? '' ),
                    'missing',
                    self::missingChipLabel( (string) $check['key'], (string) ( $need['detail'] ?? '' ) )
                );
            }
            echo '</ul>';
        }

        // Consent carries its date, because a consent record without one is
        // an assertion rather than evidence (#2744). This is the one check
        // that also names the players it is satisfied for: "when did this
        // family agree" is the question an administrator gets asked.
        if ( $recorded !== [] ) {
            echo '<ul class="tt-dc-list">';
            foreach ( $recorded as $row ) {
                $at = (string) ( $row['recorded_at'] ?? '' );
                self::renderRow(
                    (int) ( $row['player_id'] ?? 0 ),
                    (string) ( $row['name'] ?? '' ),
                    'complete',
                    $at !== ''
                        ? sprintf(
                            /* translators: %s: the date consent was recorded. */
                            __( 'Recorded %s', 'talenttrack' ),
                            TTDate::date( $at )
                        )
                        : __( 'Recorded', 'talenttrack' )
                );
            }
            echo '</ul>';
        }

        echo '</div>';
        echo '</details>';
    }

    /**
     * One player line: their name, linked to their record, and the state
     * spelled out in words beside it.
     */
    private static function renderRow( int $player_id, string $name, string $state, string $chip ): void {
        echo '<li class="tt-dc-row">';
        echo '<span class="tt-dc-row__name">';
        self::renderPlayerName( [ 'player_id' => $player_id, 'name' => $name ] );
        echo '</span>';
        echo '<span class="tt-dc-chip tt-dc-chip--' . esc_attr( $state ) . '">' . esc_html( $chip ) . '</span>';
        echo '</li>';
    }

    /** What the chip says on a player this check is not satisfied for. */
    private static function missingChipLabel( string $key, string $detail ): string {
        if ( $key === DossierCompletenessService::MEDIA_WITHOUT_CONSENT ) {
            $count = (int) $detail;
            return sprintf(
                /* translators: %d: number of photos or videos held for this player with no consent recorded. */
                _n( 'No consent, %d item', 'No consent, %d items', $count, 'talenttrack' ),
                $count
            );
        }
        return __( 'Missing', 'talenttrack' );
    }

    /**
     * What a chip in the overview says: which check is missing, in words,
     * because there it stands without the card title beside it.
     */
    private static function overviewChipLabel( string $key, int $items ): string {
        switch ( $key ) {
            case DossierCompletenessService::GUARDIAN_NAME:  return __( 'No guardian name', 'talenttrack' );
            case DossierCompletenessService::GUARDIAN_EMAIL: return __( 'No e-mail', 'talenttrack' );
            case DossierCompletenessService::GUARDIAN_PHONE: return __( 'No phone number', 'talenttrack' );
            case DossierCompletenessService::PARENT_ACCOUNT: return __( 'No parent account', 'talenttrack' );
            case DossierCompletenessService::MEDIA_CONSENT:  return __( 'No consent', 'talenttrack' );
            case DossierCompletenessService::MEDIA_WITHOUT_CONSENT:
                return sprintf(
                    /* translators: %d: number of photos or videos held for this player with no consent recorded. */
                    _n( '%d picture without consent', '%d pictures without consent', $items, 'talenttrack' ),
                    $items
                );
            default: return __( 'Missing', 'talenttrack' );
        }
    }
}
