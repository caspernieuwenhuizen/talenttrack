<?php
namespace TT\Modules\Comms\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\CommsOutcomeSummary;
use TT\Modules\Comms\Rest\CommsRestController;
use TT\Modules\Comms\Send\SafeguardingBroadcastSender;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendSafeguardingBroadcastView (#3423, epic #3384) —
 * `?tt_view=safeguarding-broadcast`.
 *
 * The one message an academy sends that no family can refuse. *My
 * settings* has told every parent so since the module shipped; until now
 * nothing could send one, which made that a promise the product could not
 * keep.
 *
 * ## Two stages, because it cannot be unsent
 *
 * Compose, then confirm. The confirm step is not a generic *Are you
 * sure?* — it is sized to the blast radius: it counts the people the
 * message will reach, says in as many words that they cannot switch it
 * off and that quiet hours will not hold it, and asks for an explicit
 * acknowledgement of both before the send button does anything.
 *
 * The audience is locked at the confirm step and the words are not. A
 * sender re-reading their own sentences should be able to fix them
 * without starting again; a sender changing *who this reaches* after
 * seeing the count has invalidated the number they just agreed to.
 *
 * ## Save model (CLAUDE.md §6)
 *
 * **B — explicit Save with a real Cancel.** Not autosave: there is no
 * record to write to, and a half-finished broadcast is the definition of
 * a commit worse than a lost one. Not a wizard either: this is a send,
 * not record creation, and a Previous/Next chrome would dilute the single
 * confirm step that is the whole control.
 *
 * ## Navigation (§5)
 *
 * Two affordances: the breadcrumb chain ending at Dashboard, emitted on
 * every path including the permission-denied return, and the `tt_back`
 * pill it renders when the entry URL carried one. Nothing else.
 */
final class FrontendSafeguardingBroadcastView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_safeguarding_broadcast';
    public const NONCE_FIELD  = '_tt_safeguarding_nonce';

    private const STAGE_COMPOSE = 'compose';
    private const STAGE_REVIEW  = 'review';
    private const STAGE_SEND    = 'send';

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-safeguarding-broadcast',
            TT_PLUGIN_URL . 'assets/css/frontend-safeguarding-broadcast.css',
            [ 'tt-public' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id, bool $is_admin = false ): void {
        $title = __( 'Safeguarding broadcast', 'talenttrack' );

        if ( ! current_user_can( SafeguardingBroadcastSender::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to send a safeguarding broadcast.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $sender = new SafeguardingBroadcastSender();
        $teams  = self::teams();

        $stage    = self::postedStage();
        $subject  = self::postedText( 'subject' );
        $body     = self::postedBody();
        $audience = self::postedAudience();
        $scope    = self::scopeOf( $audience );
        $team_id  = self::teamIdOf( $audience );

        $error = '';
        if ( $stage !== self::STAGE_COMPOSE ) {
            $audience_ok = $audience === SafeguardingBroadcastSender::SCOPE_ACADEMY
                || ( $scope === SafeguardingBroadcastSender::SCOPE_TEAM && self::teamExists( $team_id, $teams ) );

            if ( ! self::nonceOk() ) {
                $error = __( 'Security check failed. Please reload the page and try again.', 'talenttrack' );
                $stage = self::STAGE_COMPOSE;
            } elseif ( $subject === '' || trim( wp_strip_all_tags( $body ) ) === '' ) {
                $error = __( 'A subject and a message are both required.', 'talenttrack' );
                $stage = self::STAGE_COMPOSE;
            } elseif ( ! $audience_ok ) {
                $error = __( 'Choose who this reaches before continuing.', 'talenttrack' );
                $stage = self::STAGE_COMPOSE;
            }
        }

        if ( $error !== '' ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html( $error ) . '</div>';
        }

        if ( $stage === self::STAGE_SEND ) {
            // The acknowledgement is checked here and not only in the
            // browser: `required` on a checkbox is a convenience, and this
            // is the last gate before a message nobody can refuse.
            if ( empty( $_POST['acknowledged'] ) ) {
                echo '<div class="tt-notice tt-notice-error">'
                    . esc_html__( 'Confirm you understand what this message does before sending it.', 'talenttrack' )
                    . '</div>';
                self::renderReview( $sender, $teams, $subject, $body, $scope, $team_id );
                return;
            }

            $results = $sender->send( $subject, $body, $scope, $team_id );
            self::renderOutcome( $results );
            self::renderComposeForm( $teams, '', '', '' );
            return;
        }

        if ( $stage === self::STAGE_REVIEW ) {
            self::renderReview( $sender, $teams, $subject, $body, $scope, $team_id );
            return;
        }

        self::renderIntro();
        self::renderComposeForm( $teams, $subject, $body, $audience );
    }

    /* ---- stages ---------------------------------------------------------- */

    private static function renderIntro(): void {
        echo '<div class="tt-safeguarding-intro">';
        echo '<p>' . esc_html__(
            'A safeguarding broadcast reaches every family in the audience you choose. Nobody can switch it off, and it is delivered whatever the time of day. Use it for a concern the academy needs every family to read.',
            'talenttrack'
        ) . '</p>';
        echo '<p>' . esc_html__(
            'You will see exactly how many people it reaches before anything is sent.',
            'talenttrack'
        ) . '</p>';
        echo '</div>';
    }

    /**
     * @param list<array{id:int,name:string}> $teams
     */
    private static function renderComposeForm( array $teams, string $subject, string $body, string $audience ): void {
        ?>
        <form method="post" class="tt-safeguarding-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="stage" value="<?php echo esc_attr( self::STAGE_REVIEW ); ?>" />

            <p class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-sg-scope"><?php esc_html_e( 'Who this reaches', 'talenttrack' ); ?></label>
                <select id="tt-sg-scope" class="tt-input" name="audience" required>
                    <option value=""><?php esc_html_e( '— Choose —', 'talenttrack' ); ?></option>
                    <option value="<?php echo esc_attr( SafeguardingBroadcastSender::SCOPE_ACADEMY ); ?>"
                        <?php selected( $audience, SafeguardingBroadcastSender::SCOPE_ACADEMY ); ?>>
                        <?php esc_html_e( 'Every family in the academy', 'talenttrack' ); ?>
                    </option>
                    <?php foreach ( $teams as $team ) : ?>
                        <option value="team:<?php echo esc_attr( (string) $team['id'] ); ?>"
                            <?php selected( $audience, 'team:' . $team['id'] ); ?>>
                            <?php
                            printf(
                                /* translators: %s: the team's name */
                                esc_html__( 'The families of %s', 'talenttrack' ),
                                esc_html( $team['name'] )
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="tt-field-hint"><?php esc_html_e( 'A concern about one squad is better sent to that squad. Send a second one if it turns out to be wider.', 'talenttrack' ); ?></small>
            </p>

            <p class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-sg-subject"><?php esc_html_e( 'Subject', 'talenttrack' ); ?></label>
                <input type="text" id="tt-sg-subject" class="tt-input" name="subject" required
                       autocomplete="off" value="<?php echo esc_attr( $subject ); ?>" />
            </p>

            <p class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-sg-body"><?php esc_html_e( 'Message', 'talenttrack' ); ?></label>
                <textarea id="tt-sg-body" class="tt-input" name="body" rows="10" required><?php echo esc_textarea( $body ); ?></textarea>
            </p>

            <?php echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'label'      => __( 'Review before sending', 'talenttrack' ),
                'cancel_url' => self::cancelUrl(),
            ] ); ?>
        </form>
        <?php
    }

    /**
     * The confirm step, sized to what it commits.
     *
     * @param list<array{id:int,name:string}> $teams
     */
    private static function renderReview(
        SafeguardingBroadcastSender $sender,
        array $teams,
        string $subject,
        string $body,
        string $scope,
        int $team_id
    ): void {
        $count    = $sender->recipientCount( $scope, $team_id );
        $audience = self::audienceLabel( $scope, $team_id, $teams );

        echo '<div class="tt-safeguarding-confirm">';
        echo '<h2 class="tt-safeguarding-confirm-title">' . esc_html__( 'Check this before it goes', 'talenttrack' ) . '</h2>';

        echo '<p class="tt-safeguarding-count">' . esc_html( sprintf(
            /* translators: %d: number of people the message will be sent to */
            _n( 'This message will be sent to %d person.', 'This message will be sent to %d people.', $count, 'talenttrack' ),
            $count
        ) ) . '</p>';
        echo '<p class="tt-safeguarding-audience">' . esc_html( $audience ) . '</p>';

        echo '<ul class="tt-safeguarding-consequences">';
        echo '<li>' . esc_html__( 'Recipients cannot refuse it. A safeguarding message ignores every messaging preference they have set.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'It ignores quiet hours. If you send it at 23:00 it arrives at 23:00.', 'talenttrack' ) . '</li>';
        echo '<li>' . esc_html__( 'It cannot be recalled. Every send is recorded in the message log.', 'talenttrack' ) . '</li>';
        echo '</ul>';

        if ( $count === 0 ) {
            echo '<p class="tt-notice tt-notice-warning">' . esc_html__( 'Nobody in this audience can be reached, so there is nothing to send. Check the contact details on the player records first.', 'talenttrack' ) . '</p>';
        }

        foreach ( CommsOutcomeSummary::warnings( $sender->preflight( $scope, $team_id ) ) as $warning ) {
            echo '<div class="tt-notice tt-notice-warning">' . esc_html( $warning ) . '</div>';
        }
        echo '</div>';
        ?>
        <form method="post" class="tt-safeguarding-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="stage" value="<?php echo esc_attr( self::STAGE_SEND ); ?>" />
            <input type="hidden" name="audience" value="<?php echo esc_attr( self::audienceValue( $scope, $team_id ) ); ?>" />

            <p class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-sg-subject-review"><?php esc_html_e( 'Subject', 'talenttrack' ); ?></label>
                <input type="text" id="tt-sg-subject-review" class="tt-input" name="subject" required
                       autocomplete="off" value="<?php echo esc_attr( $subject ); ?>" />
            </p>

            <p class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-sg-body-review"><?php esc_html_e( 'Message', 'talenttrack' ); ?></label>
                <textarea id="tt-sg-body-review" class="tt-input" name="body" rows="10" required><?php echo esc_textarea( $body ); ?></textarea>
                <small class="tt-field-hint"><?php esc_html_e( 'You can still change the wording. Changing who it reaches means going back and choosing again.', 'talenttrack' ); ?></small>
            </p>

            <p class="tt-field tt-safeguarding-ack">
                <label class="tt-check">
                    <input type="checkbox" name="acknowledged" value="1" required />
                    <span><?php echo esc_html( sprintf(
                        /* translators: %d: number of people the message will be sent to */
                        _n(
                            'I understand this reaches %d person who cannot refuse it.',
                            'I understand this reaches %d people who cannot refuse it.',
                            $count,
                            'talenttrack'
                        ),
                        $count
                    ) ); ?></span>
                </label>
            </p>

            <?php echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'label'        => __( 'Send now', 'talenttrack' ),
                // The ellipsis character, not three dots: this msgid already
                // exists and is already translated.
                'label_saving' => __( 'Sending…', 'talenttrack' ),
                'label_saved'  => __( 'Sent', 'talenttrack' ),
                'cancel_url'   => self::cancelUrl(),
            ] ); ?>
        </form>
        <?php
    }

    /**
     * @param \TT\Modules\Comms\Domain\CommsResult[] $results
     */
    private static function renderOutcome( array $results ): void {
        if ( $results === [] ) {
            echo '<div class="tt-notice tt-notice-error">'
                . esc_html__( 'Nothing was sent — the audience resolved to nobody.', 'talenttrack' )
                . '</div>';
            return;
        }

        $sent = CommsOutcomeSummary::sentCount( $results );
        echo '<div class="tt-notice tt-notice-success">' . esc_html( sprintf(
            /* translators: %d: number of people the message reached */
            _n( 'Sent to %d person.', 'Sent to %d people.', $sent, 'talenttrack' ),
            $sent
        ) ) . '</div>';

        foreach ( CommsOutcomeSummary::lines( $results ) as $line ) {
            echo '<div class="tt-notice">' . esc_html( $line ) . '</div>';
        }
    }

    /* ---- input ----------------------------------------------------------- */

    private static function postedStage(): string {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'POST' ) return self::STAGE_COMPOSE;
        $stage = isset( $_POST['stage'] ) ? sanitize_key( (string) wp_unslash( $_POST['stage'] ) ) : '';
        return in_array( $stage, [ self::STAGE_REVIEW, self::STAGE_SEND ], true ) ? $stage : self::STAGE_COMPOSE;
    }

    private static function nonceOk(): bool {
        return isset( $_POST[ self::NONCE_FIELD ] )
            && (bool) wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ),
                self::NONCE_ACTION
            );
    }

    private static function postedText( string $key ): string {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
    }

    /**
     * `audience` carries both halves of the choice in one control —
     * `academy`, or `team:12`. One select, one required answer, and no way
     * to submit a team scope with no team attached. Empty until the sender
     * has chosen, so the form cannot pre-select "everyone" for them.
     */
    private static function postedAudience(): string {
        $raw = self::postedText( 'audience' );
        if ( $raw === SafeguardingBroadcastSender::SCOPE_ACADEMY ) return $raw;
        if ( preg_match( '/^team:(\d+)$/', $raw, $m ) === 1 ) return 'team:' . (int) $m[1];
        return '';
    }

    private static function scopeOf( string $audience ): string {
        return strpos( $audience, 'team:' ) === 0
            ? SafeguardingBroadcastSender::SCOPE_TEAM
            : SafeguardingBroadcastSender::SCOPE_ACADEMY;
    }

    private static function teamIdOf( string $audience ): int {
        return strpos( $audience, 'team:' ) === 0 ? (int) substr( $audience, 5 ) : 0;
    }

    private static function postedBody(): string {
        return isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['body'] ) ) : '';
    }

    /* ---- helpers --------------------------------------------------------- */

    private static function audienceValue( string $scope, int $team_id ): string {
        return $scope === SafeguardingBroadcastSender::SCOPE_TEAM
            ? 'team:' . $team_id
            : SafeguardingBroadcastSender::SCOPE_ACADEMY;
    }

    /**
     * @param list<array{id:int,name:string}> $teams
     */
    private static function audienceLabel( string $scope, int $team_id, array $teams ): string {
        if ( $scope !== SafeguardingBroadcastSender::SCOPE_TEAM ) {
            return __( 'Every family in the academy.', 'talenttrack' );
        }
        foreach ( $teams as $team ) {
            if ( $team['id'] === $team_id ) {
                return sprintf(
                    /* translators: %s: the team's name */
                    __( 'The families of %s.', 'talenttrack' ),
                    $team['name']
                );
            }
        }
        return __( 'One team.', 'talenttrack' );
    }

    /**
     * §6 rule 4 — a send is a create, so Cancel lands on the list the
     * record joins: the message log, where the broadcast would appear.
     * `tt_back` overrides it inside `FormSaveButton`.
     *
     * Only for somebody who can open it, though. Sending a broadcast and
     * reading the message log are separate capabilities, and an academy
     * can grant the first without the second — so this mirrors the target
     * view's own guard rather than offering a way out that would refuse
     * the person who clicked it. Cancel must always land somewhere.
     */
    private static function cancelUrl(): string {
        $dashboard = RecordLink::dashboardUrl();
        if ( ! current_user_can( CommsRestController::CAP_READ_LOG ) ) {
            return $dashboard;
        }
        return add_query_arg( [ 'tt_view' => 'messages' ], $dashboard ); /* tt-xview-ok */
    }

    /** @return list<array{id:int,name:string}> */
    private static function teams(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, name FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
              ORDER BY name ASC",
            CurrentClub::id()
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) continue;
            $out[] = [ 'id' => $id, 'name' => (string) ( $row['name'] ?? '' ) ];
        }
        return $out;
    }

    /**
     * @param list<array{id:int,name:string}> $teams
     */
    private static function teamExists( int $team_id, array $teams ): bool {
        if ( $team_id <= 0 ) return false;
        foreach ( $teams as $team ) {
            if ( $team['id'] === $team_id ) return true;
        }
        return false;
    }
}
