<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TemplateGuide (#3112) — what each message is, who gets it, and when.
 *
 * A settings screen that lists internal template keys with a checkbox
 * beside each one is not a settings screen; it is a database dump. This
 * is the copy that turns it into a decision an academy can actually
 * make, and it is deliberately NOT in the view: #3113's setup-wizard
 * step shows the same messages in the same families, and two surfaces
 * describing the same eighteen things in two sets of words is how they
 * drift apart.
 *
 * ## Families
 *
 * The four families come from #3049's shaping and survived its
 * "everything off on a fresh install" decision, because they are about
 * what the message *is* rather than what its default should be. They
 * also carry the wizard's recommendation: an academy will almost
 * certainly want the operationally urgent family and can take the rest
 * at leisure.
 *
 * ## `triggered`
 *
 * Whether anything in the product actually fires this template today.
 * Eleven of the switchable templates have shipped copy and a registry
 * entry but no call site (#2600's finding); #2605 is wiring five of
 * them. A checkbox for a message the product cannot send is a lie on a
 * settings screen, and an operator who ticks it, hears nothing, and
 * works out why will not trust the other rows either. So the screen
 * says "not yet sent automatically" rather than hiding the row —
 * hiding would lose the audit of what exists.
 *
 * **Keep this honest.** When a trigger is wired, flip the flag in the
 * same PR. The value is verifiable: a template with a trigger has a
 * call site that names its key outside `src/Modules/Comms/Templates/`.
 */
final class TemplateGuide {

    public const FAMILY_TRANSACTIONAL = 'transactional';
    public const FAMILY_URGENT        = 'urgent';
    public const FAMILY_NUDGE         = 'nudge';
    public const FAMILY_MILESTONE     = 'milestone';

    /**
     * Families in display order, each with a heading and a sentence
     * saying what belongs in it and why an academy might want it.
     *
     * @return array<string, array{label: string, blurb: string, recommended: bool}>
     */
    public static function families(): array {
        return [
            self::FAMILY_URGENT => [
                'label' => __( 'People need to know now', 'talenttrack' ),
                'blurb' => __( 'Something changed today and the family finds out too late if nobody tells them. Most academies want all of these on.', 'talenttrack' ),
                'recommended' => true,
            ],
            self::FAMILY_TRANSACTIONAL => [
                'label' => __( 'Somebody asked for it', 'talenttrack' ),
                'blurb' => __( 'A person clicked something and the message is what completes it. Switching one off makes the feature that sends it look broken.', 'talenttrack' ),
                'recommended' => false,
            ],
            self::FAMILY_MILESTONE => [
                'label' => __( 'Moments in a player\'s season', 'talenttrack' ),
                'blurb' => __( 'A decision or a document a family has been waiting for. Whether these are emailed or handed over in a conversation is your academy\'s call.', 'talenttrack' ),
                'recommended' => false,
            ],
            self::FAMILY_NUDGE => [
                'label' => __( 'Reminders and summaries', 'talenttrack' ),
                'blurb' => __( 'Useful once your academy is running, noisy while you are still entering data. These are the ones that teach people to ignore TalentTrack mail.', 'talenttrack' ),
                'recommended' => false,
            ],
        ];
    }

    /**
     * What / who / when for one template key.
     *
     * @return array{family: string, what: string, who: string, when: string, triggered: bool}|null
     */
    public static function forKey( string $key ): ?array {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    /**
     * Group templates into families, in family display order, dropping
     * empty families. Templates with no guide entry land in the family
     * they are least likely to surprise anybody in — reminders — and
     * say so, rather than vanishing off the screen.
     *
     * @param array<string, TemplateInterface> $templates
     * @return array<string, array<string, TemplateInterface>>
     */
    public static function grouped( array $templates ): array {
        $out = [];
        foreach ( array_keys( self::families() ) as $family ) {
            $out[ $family ] = [];
        }

        foreach ( $templates as $key => $template ) {
            $entry  = self::forKey( (string) $key );
            $family = $entry['family'] ?? self::FAMILY_NUDGE;
            $out[ $family ][ (string) $key ] = $template;
        }

        return array_filter( $out, static fn ( array $group ): bool => $group !== [] );
    }

    /**
     * Channel names an operator recognises. TalentTrack picks ONE of a
     * message's channels per person — the first it can reach them on —
     * so these read as a fallback order, not as "sends four times".
     */
    public static function channelLabel( string $channelKey ): string {
        $labels = [
            'email'         => __( 'Email', 'talenttrack' ),
            'push'          => __( 'Push notification', 'talenttrack' ),
            'sms'           => __( 'Text message', 'talenttrack' ),
            'whatsapp_link' => __( 'WhatsApp link', 'talenttrack' ),
            'inapp'         => __( 'Inside TalentTrack', 'talenttrack' ),
        ];
        return $labels[ $channelKey ] ?? $channelKey;
    }

    /**
     * @return array<string, array{family: string, what: string, who: string, when: string, triggered: bool}>
     */
    private static function all(): array {
        return [

            // -- People need to know now --------------------------------

            'training_cancelled' => [
                'family'    => self::FAMILY_URGENT,
                'what'      => __( 'Tells everyone expected at a training that it is off.', 'talenttrack' ),
                'who'       => __( 'The players in the squad and their parents, plus the staff assigned to it.', 'talenttrack' ),
                'when'      => __( 'When a training is cancelled.', 'talenttrack' ),
                'triggered' => false,
            ],
            'schedule_change_from_spond' => [
                'family'    => self::FAMILY_URGENT,
                'what'      => __( 'Passes on a time, date or location change that came in from Spond.', 'talenttrack' ),
                'who'       => __( 'The players affected and their parents.', 'talenttrack' ),
                'when'      => __( 'When a Spond sync brings in a changed training or match.', 'talenttrack' ),
                'triggered' => false,
            ],
            'safeguarding_broadcast' => [
                'family'    => self::FAMILY_URGENT,
                'what'      => __( 'A safeguarding message the academy needs everyone to see.', 'talenttrack' ),
                'who'       => __( 'The group the sender chooses — often every parent and every member of staff.', 'talenttrack' ),
                'when'      => __( 'When somebody with safeguarding responsibility sends one.', 'talenttrack' ),
                'triggered' => false,
            ],

            // -- Somebody asked for it ----------------------------------

            'guest_player_invite' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'Invites a player from another team to train with this one.', 'talenttrack' ),
                'who'       => __( 'The guest player and their parents.', 'talenttrack' ),
                'when'      => __( 'When a coach invites a guest to a training.', 'talenttrack' ),
                'triggered' => false,
            ],
            'trial_player_welcome' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                // #2605 — was "tells them what to bring and where to be",
                // which the message never could: a trial case has no
                // location and no kit list. It now says a coach will follow
                // up with those, and this description says the same.
                'what'      => __( 'Welcomes a trialist, names the start date, and says a coach will follow up with the details.', 'talenttrack' ),
                'who'       => __( 'The trial player and their parents.', 'talenttrack' ),
                'when'      => __( 'When a trial case is opened for a player.', 'talenttrack' ),
                'triggered' => true,
            ],
            'letter_delivery' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'Delivers a letter somebody wrote in TalentTrack.', 'talenttrack' ),
                'who'       => __( 'The player and parents the letter is addressed to.', 'talenttrack' ),
                'when'      => __( 'When a letter is sent.', 'talenttrack' ),
                'triggered' => false,
            ],
            'mass_announcement' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'An announcement written by the academy and sent to a group at once.', 'talenttrack' ),
                'who'       => __( 'The teams, age groups or roles the sender picks.', 'talenttrack' ),
                'when'      => __( 'When somebody sends an announcement.', 'talenttrack' ),
                'triggered' => false,
            ],
            'direct_message' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'An email a member of staff typed and sent from inside TalentTrack.', 'talenttrack' ),
                'who'       => __( 'Whoever the sender addressed it to.', 'talenttrack' ),
                'when'      => __( 'When a member of staff sends one.', 'talenttrack' ),
                'triggered' => true,
            ],
            'scout_report_delivery' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'A confidential one-time link to a player report, for somebody outside the academy.', 'talenttrack' ),
                'who'       => __( 'The scout the report was shared with.', 'talenttrack' ),
                'when'      => __( 'When a player report is shared with a scout.', 'talenttrack' ),
                'triggered' => true,
            ],
            'scheduled_report' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'Delivers a report on the schedule somebody set up, with the file attached.', 'talenttrack' ),
                'who'       => __( 'The recipients on that scheduled report.', 'talenttrack' ),
                'when'      => __( 'On the report\'s own schedule.', 'talenttrack' ),
                'triggered' => true,
            ],
            'desktop_link' => [
                'family'    => self::FAMILY_TRANSACTIONAL,
                'what'      => __( 'Emails somebody a link to a screen that needs a bigger display.', 'talenttrack' ),
                'who'       => __( 'The person who pressed the button, at their own address.', 'talenttrack' ),
                'when'      => __( 'When somebody asks for the link on a phone.', 'talenttrack' ),
                'triggered' => true,
            ],

            // -- Moments in a player's season ---------------------------

            'selection_letter' => [
                'family'    => self::FAMILY_MILESTONE,
                'what'      => __( 'Tells a family the selection decision for next season.', 'talenttrack' ),
                'who'       => __( 'The player and their parents.', 'talenttrack' ),
                'when'      => __( 'When a selection letter is sent.', 'talenttrack' ),
                'triggered' => false,
            ],
            'pdp_ready' => [
                'family'    => self::FAMILY_MILESTONE,
                'what'      => __( 'Says a development plan or evaluation is finished and ready to read.', 'talenttrack' ),
                'who'       => __( 'The player and their parents.', 'talenttrack' ),
                'when'      => __( 'When a plan or evaluation is published.', 'talenttrack' ),
                'triggered' => false,
            ],
            'parent_meeting_invite' => [
                'family'    => self::FAMILY_MILESTONE,
                'what'      => __( 'Invites parents to a meeting about their child.', 'talenttrack' ),
                'who'       => __( 'The player\'s parents.', 'talenttrack' ),
                'when'      => __( 'When a parent meeting is arranged.', 'talenttrack' ),
                'triggered' => false,
            ],
            'methodology_delivered' => [
                'family'    => self::FAMILY_MILESTONE,
                'what'      => __( 'Shares an activity plan or a piece of the academy methodology with a coach.', 'talenttrack' ),
                'who'       => __( 'The coaching staff it was shared with.', 'talenttrack' ),
                'when'      => __( 'When a plan is delivered to a coach.', 'talenttrack' ),
                'triggered' => false,
            ],

            // -- Reminders and summaries --------------------------------

            'goal_nudge' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'Reminds a player about a goal nobody has updated in a while.', 'talenttrack' ),
                'who'       => __( 'The player, and their parents when the player has no account.', 'talenttrack' ),
                'when'      => __( 'Daily check; sends for goals untouched for four weeks or more.', 'talenttrack' ),
                'triggered' => true,
            ],
            'attendance_flag' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'Flags a run of missed trainings so somebody asks why.', 'talenttrack' ),
                'who'       => __( 'The player\'s coach.', 'talenttrack' ),
                'when'      => __( 'Daily check; sends after three absences in a row.', 'talenttrack' ),
                'triggered' => true,
            ],
            'onboarding_nudge_inactive' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'Nudges a parent who was given an account and has not used it.', 'talenttrack' ),
                'who'       => __( 'The parent.', 'talenttrack' ),
                'when'      => __( 'Daily check; sends after 30 days without a sign-in.', 'talenttrack' ),
                'triggered' => true,
            ],
            'staff_development_reminder' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'Reminds a member of staff that their own development review is due.', 'talenttrack' ),
                'who'       => __( 'The member of staff.', 'talenttrack' ),
                'when'      => __( 'Daily check; sends in the week before the review date.', 'talenttrack' ),
                'triggered' => true,
            ],
            'trial_input_reminder' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'Chases staff whose verdict on a trial is still missing.', 'talenttrack' ),
                'who'       => __( 'The staff assigned to that trial.', 'talenttrack' ),
                'when'      => __( 'While a trial is open and their input has not been given.', 'talenttrack' ),
                'triggered' => true,
            ],
            'alert_digest' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'One email collecting the alerts raised since the last one, instead of an email each.', 'talenttrack' ),
                'who'       => __( 'The staff those alerts are for.', 'talenttrack' ),
                'when'      => __( 'On the digest schedule set under Alerts.', 'talenttrack' ),
                'triggered' => true,
            ],
            'notification' => [
                'family'    => self::FAMILY_NUDGE,
                'what'      => __( 'Tells somebody about activity aimed at them — a new message in a conversation, a task, a reply to an idea.', 'talenttrack' ),
                'who'       => __( 'The person the activity concerns.', 'talenttrack' ),
                'when'      => __( 'When the activity happens.', 'talenttrack' ),
                'triggered' => true,
            ],
        ];
    }
}
