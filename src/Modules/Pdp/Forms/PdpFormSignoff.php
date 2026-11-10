<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\GoalOrigin;
use TT\Domain\Vocabularies\Enums\PdpFormAuthor;
use TT\Domain\Vocabularies\Lookups\GoalPriority;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Database\AfterCommit;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\RatingScale;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\GoalLinksRepository;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;

/**
 * PdpFormSignoff (#4977, epic #4975) — the irreversible commit of a PDP
 * conversation form.
 *
 * In one transaction, in this order:
 *  1. lock the conversation row (`PdpFormEntriesRepository::underLock()`,
 *     the lock the trainer's writes take too), check it is not signed off,
 *     and read the trainer's part and check it is complete;
 *  2. write the trainer's scores as an evaluation through
 *     `EvaluationInserter::insert()` (P3 on #4975): the type of the form's
 *     position group, each item's score mapped through `RatingScale` onto
 *     its form-owned category, and the conversation linked. The player's
 *     scores are never written to it (owner decision 9);
 *  3. create one goal per SMART point (`GoalsRepository::create()`) and
 *     record it on its slot;
 *  4. link each goal to the conversation additively, so the goals the
 *     coach marked as discussed stay linked;
 *  5. set `coach_signoff_at`, and `conducted_at` when it is empty.
 *
 * After the commit it announces `tt_pdp_conversation_signed_off`.
 *
 * What must be complete first is owner decision 4: a trainer score on every
 * item, the agreed answer to every shared prompt (the strongest quality),
 * and every SMART point with an action, the trainer's help and a deadline.
 * The player's part is not required (decision 5).
 */
final class PdpFormSignoff {

    /**
     * Sign the conversation off as the given user. The caller has checked
     * `PdpFormAccess::signsOff()`.
     *
     * @return array{coach_signoff_at:string, evaluation_id:int, goal_ids:list<int>}|\WP_Error
     */
    public static function signOff( int $user_id, object $conversation, int $player_id ) {
        $conv_id    = (int) ( ( (array) $conversation )['id'] ?? 0 );
        $definition = PdpFormReader::definitionFor( $conversation );
        if ( $conv_id <= 0 || $definition === null ) {
            return new \WP_Error( 'no_form', __( 'This conversation does not use a conversation form.', 'talenttrack' ), [ 'status' => 409 ] );
        }
        if ( ! empty( ( (array) $conversation )['coach_signoff_at'] ) ) {
            return self::alreadySignedOff();
        }

        $entries  = new PdpFormEntriesRepository();
        $form_key = (string) $definition['form_key'];

        // The form's categories and type, up to date and active, before the
        // first write that needs them (#4976 review: the seeder owns them).
        // Idempotent, so it runs before the lock.
        $group  = (string) $definition['position_group'];
        $seeded = PdpFormCategorySeeder::ensure( [ $group => $form_key ] );
        if ( $seeded instanceof \WP_Error ) {
            return new \WP_Error( (string) $seeded->get_error_code(), $seeded->get_error_message(), [ 'status' => 422 ] );
        }
        $type_id = PdpFormCategorySeeder::typeIdFor( $group );

        $category_ids = [];
        $repo         = new EvalCategoriesRepository();
        foreach ( PdpFormRegistry::items( $definition ) as $item ) {
            $category = $repo->getByKey( PdpFormRegistry::categoryKey( $definition, $item['key'] ) );
            $cat_id   = $category !== null ? (int) ( ( (array) $category )['id'] ?? 0 ) : 0;
            if ( $cat_id <= 0 ) {
                return new \WP_Error( 'seed_failed', __( 'The evaluation categories of the conversation form are missing. Ask your administrator to save the conversation forms setting again.', 'talenttrack' ), [ 'status' => 422 ] );
            }
            $category_ids[ $item['key'] ] = $cat_id;
        }
        try {
            $scale = RatingScale::current();
        } catch ( \DomainException $e ) {
            return new \WP_Error( 'rating_scale', $e->getMessage(), [ 'status' => 422 ] );
        }

        try {
            // The form is read under the lock the trainer's writes take too
            // (#5005 review), so the evaluation is what the signed form says.
            $result = $entries->underLock( $conv_id, static function ( array $locked ) use ( $user_id, $conv_id, $player_id, $type_id, $category_ids, $scale, $definition, $entries, $form_key ): array {
                global $wpdb;

                // #4978 — the form was read from the row loaded before the
                // lock. A switch that landed in between would have the
                // evaluation built from the wrong form's categories.
                if ( (string) ( $locked['form_key'] ?? '' ) !== $form_key
                    || (int) ( $locked['form_version'] ?? 0 ) !== (int) $definition['version'] ) {
                    throw new PdpFormSignoffRefused( new \WP_Error(
                        'form_changed',
                        __( 'The conversation form was changed meanwhile. Reload the form and try again.', 'talenttrack' ),
                        [ 'status' => 409 ]
                    ) );
                }

                $scores  = $entries->scores( $conv_id, $form_key, PdpFormAuthor::COACH );
                $answers = $entries->answers( $conv_id, $form_key, PdpFormAuthor::COACH );
                $goals   = $entries->goals( $conv_id );
                $missing = self::missing( $definition, $scores, $answers, $goals );
                if ( $missing !== [] ) {
                    throw new PdpFormSignoffRefused( new \WP_Error(
                        'missing_fields',
                        __( 'The form is not complete yet. Score every item, fill in the strongest quality, and give both development points an action, the trainer\'s help and a deadline.', 'talenttrack' ),
                        [ 'status' => 400, 'fields' => $missing ]
                    ) );
                }
                $ratings = [];
                foreach ( $category_ids as $item_key => $cat_id ) {
                    $ratings[ $cat_id ] = $scale->toStored( (int) $scores[ $item_key ] );
                }

                $now        = current_time( 'mysql', true );
                $conducted  = (string) ( $locked['conducted_at'] ?? '' );
                $today      = current_time( 'Y-m-d' );
                $eval_date  = $conducted !== '' ? min( substr( $conducted, 0, 10 ), $today ) : $today;

                $eval_id = EvaluationInserter::insert( [
                    'player_id'           => $player_id,
                    'coach_id'            => $user_id,
                    'eval_date'           => $eval_date,
                    'eval_type_id'        => $type_id,
                    'pdp_conversation_id' => $conv_id,
                    'ratings'             => $ratings,
                    'notes'               => '',
                ] );
                if ( $eval_id instanceof \WP_Error ) {
                    throw new PdpFormSignoffRefused( new \WP_Error( (string) $eval_id->get_error_code(), $eval_id->get_error_message(), [ 'status' => 500 ] ) );
                }
                $written = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}tt_eval_ratings WHERE evaluation_id = %d AND club_id = %d",
                    $eval_id, CurrentClub::id()
                ) );
                if ( $written !== count( $ratings ) ) {
                    throw new PdpFormSignoffRefused( self::failed( 'eval_ratings' ) );
                }

                $goal_repo  = new GoalsRepository();
                $links_repo = new GoalLinksRepository();
                $goal_ids   = [];
                foreach ( array_keys( PdpFormRegistry::goalSlots( $definition ) ) as $slot ) {
                    $g       = $goals[ $slot ];
                    $goal_id = $goal_repo->create( [
                        'uuid'        => wp_generate_uuid4(),
                        'player_id'   => $player_id,
                        'title'       => mb_substr( trim( wp_strip_all_tags( $g['action_text'] ) ), 0, 255 ),
                        'description' => $g['support_text'],
                        'status'      => GoalStatus::PENDING,
                        'priority'    => GoalPriority::MEDIUM,
                        'due_date'    => $g['due_date'],
                        'created_by'  => $user_id,
                    ], [ 'origin' => GoalOrigin::SET ] );
                    if ( $goal_id <= 0 ) throw new PdpFormSignoffRefused( self::failed( 'goal' ) );
                    if ( ! $entries->setGoalId( $conv_id, $slot, $goal_id ) ) throw new PdpFormSignoffRefused( self::failed( 'goal_slot' ) );
                    if ( ! $links_repo->linkGoalToConversation( $conv_id, $goal_id ) ) throw new PdpFormSignoffRefused( self::failed( 'goal_link' ) );
                    $goal_ids[] = $goal_id;
                }

                $patch = [ 'coach_signoff_at' => $now ];
                if ( $conducted === '' ) $patch['conducted_at'] = $now;
                if ( ! ( new PdpConversationsRepository() )->update( $conv_id, $patch ) ) {
                    throw new PdpFormSignoffRefused( self::failed( 'conversation' ) );
                }

                return [ 'coach_signoff_at' => $now, 'evaluation_id' => (int) $eval_id, 'goal_ids' => $goal_ids ];
            } );
        } catch ( PdpFormSignoffRefused $refused ) {
            return $refused->error();
        } catch ( \DomainException $e ) {
            return new \WP_Error( 'rating_scale', $e->getMessage(), [ 'status' => 422 ] );
        }

        AfterCommit::run( static function () use ( $conv_id, $result ): void {
            /**
             * A PDP conversation form was signed off: the evaluation and the
             * goals are written. Fires after the commit.
             *
             * @param int       $conversation_id
             * @param int       $evaluation_id
             * @param list<int> $goal_ids
             */
            do_action( 'tt_pdp_conversation_signed_off', $conv_id, $result['evaluation_id'], $result['goal_ids'] );
        } );

        return $result;
    }

    /**
     * What sign-off still needs, as field paths (`scores.<item>`,
     * `answers.<field>`, `goals.<slot>.<field>`). Empty when complete.
     *
     * @param array<string,mixed>      $definition
     * @param array<string,int|null>   $scores
     * @param array<string,string>     $answers
     * @param array<int,array<string,mixed>> $goals
     * @return list<string>
     */
    public static function missing( array $definition, array $scores, array $answers, array $goals ): array {
        $out = [];
        foreach ( PdpFormRegistry::items( $definition ) as $item ) {
            if ( ( $scores[ $item['key'] ] ?? null ) === null ) $out[] = 'scores.' . $item['key'];
        }
        foreach ( PdpFormRegistry::sharedFields( $definition ) as $field ) {
            if ( trim( wp_strip_all_tags( (string) ( $answers[ $field ] ?? '' ) ) ) === '' ) $out[] = 'answers.' . $field;
        }
        foreach ( array_keys( PdpFormRegistry::goalSlots( $definition ) ) as $slot ) {
            $g = $goals[ $slot ] ?? [];
            if ( trim( wp_strip_all_tags( (string) ( $g['action_text'] ?? '' ) ) ) === '' )  $out[] = "goals.{$slot}.action";
            if ( trim( wp_strip_all_tags( (string) ( $g['support_text'] ?? '' ) ) ) === '' ) $out[] = "goals.{$slot}.support";
            if ( (string) ( $g['due_date'] ?? '' ) === '' )                                    $out[] = "goals.{$slot}.due_date";
        }
        return $out;
    }

    private static function alreadySignedOff(): \WP_Error {
        return new \WP_Error( 'already_signed_off', __( 'This conversation has already been signed off.', 'talenttrack' ), [ 'status' => 409 ] );
    }

    private static function failed( string $what ): \WP_Error {
        return new \WP_Error( 'db_error', __( 'The sign-off could not be saved. Nothing was changed; try again.', 'talenttrack' ), [ 'status' => 500, 'step' => $what ] );
    }
}
