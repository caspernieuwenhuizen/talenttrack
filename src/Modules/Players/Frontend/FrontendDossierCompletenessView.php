<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Players\Services\DossierCompletenessService;
use TT\Shared\Dates\TTDate;
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

        self::renderHeader( $title );

        $see_all = $is_admin || MatrixGate::can( $user_id, 'players', 'read', 'global' );
        $teams   = $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams are available to you yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $allowed_ids = array_map( static fn ( $t ) => (int) $t->id, (array) $teams );
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

        foreach ( $report['checks'] as $check ) {
            self::renderCheckCard( $check );
        }
    }

    /**
     * @param array<int, object> $teams
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
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>"<?php selected( $team_id, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Show', 'talenttrack' ); ?></button></noscript>
        </form>
        <?php
    }

    /** @param array<string,mixed> $check */
    private static function renderCheckCard( array $check ): void {
        $total    = (int) $check['total'];
        $complete = (int) $check['complete'];
        $needs    = is_array( $check['needs'] ?? null ) ? $check['needs'] : [];
        $recorded = is_array( $check['recorded'] ?? null ) ? $check['recorded'] : [];
        $pct      = $total > 0 ? (int) round( $complete / $total * 100 ) : 0;

        echo '<section class="tt-dc-card">';
        echo '<div class="tt-dc-card__head">';
        echo '<h2 class="tt-dc-card__title">' . esc_html( (string) $check['name'] ) . '</h2>';
        if ( isset( $check['item_count'] ) && (int) $check['item_count'] > 0 ) {
            echo '<span class="tt-dc-chip tt-dc-chip--missing">' . esc_html( sprintf(
                /* translators: %d: number of photos or videos held with no consent recorded. */
                _n( '%d item', '%d items', (int) $check['item_count'], 'talenttrack' ),
                (int) $check['item_count']
            ) ) . '</span>';
        }
        echo '</div>';

        echo '<p class="tt-dc-card__summary">' . esc_html( sprintf(
            /* translators: 1: number of players whose file is complete on this check, 2: squad size. */
            __( '%1$d of %2$d complete', 'talenttrack' ),
            $complete,
            $total
        ) ) . '</p>';

        echo '<div class="tt-dc-bar"><i style="width:' . (int) $pct . '%"></i></div>'; /* tt-inline-ok */

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

        echo '</section>';
    }

    /**
     * One player line: their name, linked to their record, and the state
     * spelled out in words beside it.
     */
    private static function renderRow( int $player_id, string $name, string $state, string $chip ): void {
        $label = $name !== '' ? $name : '#' . $player_id;

        echo '<li class="tt-dc-row">';
        echo '<span class="tt-dc-row__name">';
        if ( $player_id > 0 ) {
            echo RecordLink::inline( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes its own output.
                $label,
                RecordLink::detailUrlForWithBack( 'players', $player_id )
            );
        } else {
            echo esc_html( $label );
        }
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
}
