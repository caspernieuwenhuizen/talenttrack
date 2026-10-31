<?php
namespace TT\Modules\Journey\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Workflow\Contracts\FormInterface;
use TT\Shared\Dates\TTDate;

/**
 * InjuryRecoveryConfirmForm — the answer to an injury recovery check. The
 * person answering picks one of:
 *
 *   on_track        — the player returns as expected; nothing is written
 *   extend_recovery — the chosen record gets a new expected return
 *   unsure          — flag for follow-up; nothing is written
 *
 * #4289 — the task is one per player, so the record is named here, not on
 * the task: the form lists the player's records whose check has arrived
 * (preselected when there is one) and asks for the new date. Only someone
 * who may change the player's injuries is offered "extend". The template's
 * `onComplete()` writes the date through `InjuryRepository::update()`.
 */
class InjuryRecoveryConfirmForm implements FormInterface {

    public const OPTION_ON_TRACK = 'on_track';
    public const OPTION_EXTEND   = 'extend_recovery';
    public const OPTION_UNSURE   = 'unsure';

    public function render( array $task ): string {
        $existing  = self::decodeResponse( $task );
        $disabled  = self::completedAttr( $task );
        $player_id = (int) ( $task['player_id'] ?? 0 );
        $viewer    = get_current_user_id();

        wp_enqueue_style(
            'tt-workflow-injury-recovery-form',
            TT_PLUGIN_URL . 'assets/css/components/workflow-injury-recovery-form.css',
            [],
            TT_VERSION
        );

        $can_read   = AuthorizationService::canRecordInjury( $viewer, $player_id, 'read' );
        $can_change = AuthorizationService::canRecordInjury( $viewer, $player_id, 'change' );
        $records    = $can_read ? self::recordsFor( $player_id, (int) ( $existing['injury_id'] ?? 0 ) ) : [];
        $offer_extend = $can_change && $records !== [];

        $chosen_id = (int) ( $existing['injury_id'] ?? 0 );
        if ( $chosen_id <= 0 && count( $records ) === 1 ) $chosen_id = (int) ( $records[0]->id ?? 0 );

        $status = (string) ( $existing['recovery_status'] ?? '' );
        $tomorrow = gmdate( 'Y-m-d', (int) strtotime( AttendanceDateRule::today() . ' 00:00:00 UTC +1 day' ) );

        ob_start();
        ?>
        <div class="tt-irc-form">
            <p class="tt-irc-intro">
                <?php esc_html_e( 'Confirm whether this player is on track to return from injury.', 'talenttrack' ); ?>
            </p>

            <?php if ( ! $can_read ) : ?>
                <p class="tt-irc-note"><?php esc_html_e( 'You cannot see this player\'s injury records.', 'talenttrack' ); ?></p>
            <?php elseif ( $records === [] ) : ?>
                <p class="tt-irc-note"><?php esc_html_e( 'None of this player\'s injury records needs a decision any more.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <fieldset class="tt-irc-group">
                    <legend><?php esc_html_e( 'Injury record', 'talenttrack' ); ?></legend>
                    <?php foreach ( $records as $row ) : $row_id = (int) ( $row->id ?? 0 ); ?>
                        <label class="tt-irc-choice">
                            <input type="radio" name="injury_id" value="<?php echo esc_attr( (string) $row_id ); ?>"
                                   <?php checked( $row_id === $chosen_id ); ?>
                                   <?php echo $disabled; ?> />
                            <span><?php echo esc_html( self::recordLabel( $row ) ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <fieldset class="tt-irc-group">
                <legend><?php esc_html_e( 'Status', 'talenttrack' ); ?></legend>
                <label class="tt-irc-choice">
                    <input type="radio" name="recovery_status" value="<?php echo esc_attr( self::OPTION_ON_TRACK ); ?>"
                           <?php checked( $status === self::OPTION_ON_TRACK ); ?>
                           <?php echo $disabled; ?> />
                    <span><?php esc_html_e( 'On track — back as expected', 'talenttrack' ); ?></span>
                </label>
                <?php if ( $offer_extend || $status === self::OPTION_EXTEND ) : ?>
                    <label class="tt-irc-choice">
                        <input type="radio" name="recovery_status" value="<?php echo esc_attr( self::OPTION_EXTEND ); ?>"
                               <?php checked( $status === self::OPTION_EXTEND ); ?>
                               <?php echo $disabled; ?> />
                        <span><?php esc_html_e( 'Extend — needs more time', 'talenttrack' ); ?></span>
                    </label>
                <?php endif; ?>
                <label class="tt-irc-choice">
                    <input type="radio" name="recovery_status" value="<?php echo esc_attr( self::OPTION_UNSURE ); ?>"
                           <?php checked( $status === self::OPTION_UNSURE ); ?>
                           <?php echo $disabled; ?> />
                    <span><?php esc_html_e( 'Unsure — flag for medical review', 'talenttrack' ); ?></span>
                </label>
                <?php if ( $can_read && $records !== [] && ! $can_change ) : ?>
                    <p class="tt-irc-note"><?php esc_html_e( 'Only staff who may edit this player\'s injuries can change the expected return date.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </fieldset>

            <?php if ( $offer_extend || $status === self::OPTION_EXTEND ) : ?>
                <p class="tt-irc-field">
                    <label for="tt-irc-return"><?php esc_html_e( 'New expected return', 'talenttrack' ); ?></label>
                    <input type="date" id="tt-irc-return" name="new_expected_return"
                           min="<?php echo esc_attr( $tomorrow ); ?>"
                           value="<?php echo esc_attr( (string) ( $existing['new_expected_return'] ?? '' ) ); ?>"
                           aria-describedby="tt-irc-return-hint"
                           <?php echo $disabled; ?> />
                    <span id="tt-irc-return-hint" class="tt-irc-hint"><?php esc_html_e( 'Needed when you choose Extend.', 'talenttrack' ); ?></span>
                </p>
            <?php endif; ?>

            <p class="tt-irc-field">
                <label for="tt-recovery-notes"><?php esc_html_e( 'Notes (optional)', 'talenttrack' ); ?></label>
                <textarea id="tt-recovery-notes" name="notes" rows="3"
                          <?php echo $disabled; ?>><?php
                    echo esc_textarea( (string) ( $existing['notes'] ?? '' ) );
                ?></textarea>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $allowed = [ self::OPTION_ON_TRACK, self::OPTION_EXTEND, self::OPTION_UNSURE ];
        $status  = (string) ( $raw['recovery_status'] ?? '' );
        if ( ! in_array( $status, $allowed, true ) ) {
            $errors['recovery_status'] = __( 'Pick one of: on track / extend / unsure.', 'talenttrack' );
        }

        if ( $status === self::OPTION_EXTEND ) {
            $player_id = (int) ( $task['player_id'] ?? 0 );
            if ( ! AuthorizationService::canRecordInjury( get_current_user_id(), $player_id, 'change' ) ) {
                $errors['recovery_status'] = __( 'You cannot change this player\'s expected return date.', 'talenttrack' );
            }

            $row = InjuryRecoveryTasks::extendableRecord( (int) ( $raw['injury_id'] ?? 0 ), $player_id );
            if ( $row === null ) {
                $errors['injury_id'] = __( 'Pick one of this player\'s open injury records.', 'talenttrack' );
            } else {
                $date    = trim( (string) ( $raw['new_expected_return'] ?? '' ) );
                $problem = InjuryRecoveryTasks::newReturnProblem( $row, $date );
                if ( $problem !== null ) {
                    $errors['new_expected_return'] = self::dateMessage( $problem );
                }
            }
        }

        // The task view prints only the form-level message, so a field error
        // must also reach it or the form refuses without saying why.
        if ( $errors !== [] ) {
            $errors['__form'] = implode( ' ', $errors );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $status   = sanitize_key( (string) ( $raw['recovery_status'] ?? '' ) );
        $response = [
            'recovery_status' => $status,
            'notes'           => sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ),
        ];
        if ( $status === self::OPTION_EXTEND ) {
            $response['injury_id']           = (int) ( $raw['injury_id'] ?? 0 );
            $response['new_expected_return'] = sanitize_text_field( (string) ( $raw['new_expected_return'] ?? '' ) );
        }
        return $response;
    }

    private static function dateMessage( string $problem ): string {
        switch ( $problem ) {
            case InjuryRecoveryTasks::NEW_RETURN_NOT_AFTER_TODAY:
                return __( 'The new expected return must be after today.', 'talenttrack' );
            case InjuryRecoveryTasks::NEW_RETURN_BEFORE_START:
                return __( 'The expected return cannot be before the date of injury.', 'talenttrack' );
            case InjuryRecoveryTasks::NEW_RETURN_BEFORE_CURRENT:
                return __( 'The new expected return cannot be before the current one.', 'talenttrack' );
            default:
                return __( 'Enter the new expected return date.', 'talenttrack' );
        }
    }

    /**
     * The records to choose from: those whose check has arrived, plus the
     * one an earlier answer named, so a completed task still shows its pick.
     *
     * @return list<object>
     */
    private static function recordsFor( int $player_id, int $answered_id ): array {
        $records = InjuryRecoveryTasks::dueRecordsForPlayer( $player_id );
        if ( $answered_id <= 0 ) return $records;
        foreach ( $records as $row ) {
            if ( (int) ( $row->id ?? 0 ) === $answered_id ) return $records;
        }
        $row = ( new InjuryRepository() )->find( $answered_id );
        if ( $row && (int) ( $row->player_id ?? 0 ) === $player_id ) {
            array_unshift( $records, $row );
        }
        return $records;
    }

    private static function recordLabel( object $row ): string {
        $started = (string) ( $row->started_on ?? '' );
        $date    = TTDate::date( $started );
        if ( $date === '' ) $date = $started;

        $type = '';
        $type_id = (int) ( $row->injury_type_lookup_id ?? 0 );
        if ( $type_id > 0 ) {
            foreach ( QueryHelpers::get_lookups( 'injury_type' ) as $lookup ) {
                if ( (int) ( $lookup->id ?? 0 ) === $type_id ) {
                    $type = LookupTranslator::name( $lookup );
                    break;
                }
            }
        }

        return $type !== ''
            /* translators: 1: date the injury started, 2: kind of injury */
            ? sprintf( __( 'Injured since %1$s: %2$s', 'talenttrack' ), $date, $type )
            /* translators: %s: date the injury started */
            : sprintf( __( 'Injured since %s', 'talenttrack' ), $date );
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }
}
