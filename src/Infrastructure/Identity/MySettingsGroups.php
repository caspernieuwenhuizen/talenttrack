<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Domain\MessageAudience;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\ShellPreference;
use TT\Shared\Frontend\ThemePreference;

/**
 * MySettingsGroups (#4969) — the four groups My settings opens on, which
 * of them a user has, and the one-line summary each shows on the overview.
 *
 * The page renders a group from the same lists this class answers from
 * (the message types a user may refuse, the sections a parent may be shown),
 * so a summary cannot count something the group page does not offer.
 */
final class MySettingsGroups {

    public const ACCOUNT    = 'account';
    public const MESSAGES   = 'messages';
    public const APPEARANCE = 'appearance';
    public const PRIVACY    = 'privacy';

    public const VIEW_SLUG = 'my-settings';

    /** Display order. */
    private const ORDER = [ self::ACCOUNT, self::MESSAGES, self::APPEARANCE, self::PRIVACY ];

    /** Which group a posted form belongs to, so a save lands back on its own group. */
    private const ACTION_GROUP = [
        'update_profile'           => self::ACCOUNT,
        'change_password'          => self::ACCOUNT,
        'update_comms_optout'      => self::MESSAGES,
        'update_shell'             => self::APPEARANCE,
        'update_match_layout'      => self::APPEARANCE,
        'update_theme'             => self::APPEARANCE,
        'update_parent_visibility' => self::PRIVACY,
    ];

    public static function isGroup( string $key ): bool {
        return in_array( $key, self::ORDER, true );
    }

    public static function label( string $key ): string {
        switch ( $key ) {
            case self::ACCOUNT:    return _x( 'Account', 'my settings group', 'talenttrack' );
            case self::MESSAGES:   return _x( 'Messages', 'my settings group', 'talenttrack' );
            case self::APPEARANCE: return _x( 'Appearance', 'my settings group', 'talenttrack' );
            case self::PRIVACY:    return _x( 'Privacy', 'my settings group', 'talenttrack' );
        }
        return '';
    }

    /** The group a My settings form action saves into, or '' for an unknown action. */
    public static function groupForAction( string $action ): string {
        return self::ACTION_GROUP[ $action ] ?? '';
    }

    /**
     * The groups this user has, in display order. A group with nothing in it
     * for this user is left out.
     *
     * @return list<array{key:string, label:string, summary:string, url:string}>
     */
    public static function forUser( int $user_id ): array {
        if ( $user_id <= 0 ) return [];

        $out = [];
        foreach ( self::ORDER as $key ) {
            if ( ! self::has( $key, $user_id ) ) continue;
            $out[] = [
                'key'     => $key,
                'label'   => self::label( $key ),
                'summary' => self::summary( $key, $user_id ),
                'url'     => self::url( $key ),
            ];
        }
        return $out;
    }

    /** Whether this user has anything to set in the group. */
    public static function has( string $key, int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        switch ( $key ) {
            case self::ACCOUNT:
            case self::APPEARANCE:
            // The held-contact overview always lists the account itself.
            case self::PRIVACY:
                return true;
            case self::MESSAGES:
                return self::visibleMessageTypes( $user_id ) !== [];
        }
        return false;
    }

    /** The overview's URL, or one group's when `$key` is a group. */
    public static function url( string $key = '' ): string {
        $args = [ 'tt_view' => self::VIEW_SLUG ];
        if ( self::isGroup( $key ) ) $args['group'] = $key;
        return add_query_arg( $args, RecordLink::dashboardUrl() );
    }

    public static function summary( string $key, int $user_id ): string {
        switch ( $key ) {
            case self::ACCOUNT:    return self::accountSummary( $user_id );
            case self::MESSAGES:   return self::messagesSummary( $user_id );
            case self::APPEARANCE: return self::appearanceSummary( $user_id );
            case self::PRIVACY:    return self::privacySummary( $user_id );
        }
        return '';
    }

    /**
     * Message types a user may refuse, in display order. The full
     * non-operational vocabulary; {@see visibleMessageTypes()} is one
     * reader's view of it. A missing row for a message you do receive is
     * exactly the gap the Messages group exists to close.
     *
     * @return array<string,string> message_type => user-facing label
     */
    public static function messageTypeLabels(): array {
        return [
            // #3382 — "A training is cancelled" used to be here. It is now
            // operational and appears in the always-sent block.
            MessageType::SCHEDULE_CHANGE_FROM_SPOND => __( 'An activity changes time or place', 'talenttrack' ),
            MessageType::SELECTION_LETTER           => __( 'Selection decisions', 'talenttrack' ),
            MessageType::PDP_READY                  => __( 'A development plan is ready to read', 'talenttrack' ),
            MessageType::PARENT_MEETING_INVITE      => __( 'Invitations to parent meetings', 'talenttrack' ),
            MessageType::TRIAL_PLAYER_WELCOME       => __( 'Welcome messages for trial players', 'talenttrack' ),
            MessageType::GUEST_PLAYER_INVITE        => __( 'Invitations to join an activity as a guest', 'talenttrack' ),
            MessageType::GOAL_NUDGE                 => __( 'Reminders to update a goal', 'talenttrack' ),
            MessageType::ATTENDANCE_FLAG            => __( 'Alerts about repeated absence', 'talenttrack' ),
            MessageType::METHODOLOGY_DELIVERED      => __( 'New training plans are published', 'talenttrack' ),
            MessageType::ONBOARDING_NUDGE_INACTIVE  => __( 'Reminders when you have not logged in for a while', 'talenttrack' ),
            MessageType::STAFF_DEVELOPMENT_REMINDER => __( 'Reminders about your own development review', 'talenttrack' ),
            MessageType::LETTER_DELIVERY            => __( 'Formal letters', 'talenttrack' ),
            MessageType::MASS_ANNOUNCEMENT          => __( 'Academy-wide announcements', 'talenttrack' ),
            // #2604 — notifications raised in-product (a task assigned to
            // you, a reply on a conversation) route through Comms.
            MessageType::NOTIFICATION               => __( 'Notifications about your tasks and conversations', 'talenttrack' ),
            // #3389 — opt-outable types that had no toggle anywhere.
            MessageType::ALERT_DIGEST              => __( 'A summary of your open alerts', 'talenttrack' ),
            MessageType::DIRECT_MESSAGE            => __( 'Messages written to you by academy staff', 'talenttrack' ),
            MessageType::SCHEDULED_REPORT          => __( 'Reports delivered on a schedule', 'talenttrack' ),
            MessageType::SCHEDULED_REPORT_NOT_SENT => __( 'When a report you scheduled could not be sent', 'talenttrack' ),
            MessageType::TRIAL_INPUT_REMINDER      => __( 'Reminders that a trial still needs your input', 'talenttrack' ),
            MessageType::SCOUT_REPORT_DELIVERY     => __( 'Scout reports sent to you', 'talenttrack' ),
        ];
    }

    /**
     * The message types this user is offered: those whose audience overlaps
     * the user's (#3389). Render, save and the summary all read this, and the
     * save writing only these is load-bearing: a type the card never offered
     * has no checkbox and would otherwise be muted by an unrelated Save.
     *
     * @return array<string,string> message_type => user-facing label
     */
    public static function visibleMessageTypes( int $user_id ): array {
        $mine = MessageAudience::forUser( $user_id );

        return array_filter(
            self::messageTypeLabels(),
            static function ( string $type ) use ( $mine ): bool {
                return array_intersect( MessageType::audiences( $type ), $mine ) !== [];
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /** @return array<string,string> section_key => user-facing label, in display order. */
    public static function parentVisibilitySectionLabels(): array {
        return [
            'evaluations'  => __( 'Evaluations', 'talenttrack' ),
            'goals'        => __( 'Goals', 'talenttrack' ),
            'journey'      => __( 'Journey', 'talenttrack' ),
            'measurements' => __( 'Measurements', 'talenttrack' ),
            // #3666 — how many minutes you played, and in which matches.
            'minutes'      => __( 'Playing time', 'talenttrack' ),
            'pdp'          => __( 'Development plan (PDP)', 'talenttrack' ),
            // #3560 — the tournament days you played and your minutes in them.
            'tournaments'  => __( 'Tournaments', 'talenttrack' ),
            // #2500 — minutes trained per principle.
            'training'     => __( 'Training history', 'talenttrack' ),
        ];
    }

    /**
     * The player id whose parent visibility this user controls: their own
     * player record, when a parent is linked to it. 0 otherwise — a player
     * with no linked parent has nothing to control.
     */
    public static function parentVisibilityPlayerId( int $user_id ): int {
        $player = QueryHelpers::get_player_for_user( $user_id );
        if ( ! $player ) return 0;
        // Through an array cast: the helper answers a bare object.
        $player_id = (int) ( ( (array) $player )['id'] ?? 0 );
        if ( $player_id <= 0 ) return 0;

        $parents = ( new \TT\Modules\Invitations\PlayerParentsRepository() )->parentsForPlayer( $player_id );
        return empty( $parents ) ? 0 : $player_id;
    }

    /**
     * Whether the current user can reach their own two-factor screen: the
     * one answer the Account summary and the Account card both read. The
     * cross-view gate covers a switched-off surface as well as the reader.
     */
    public static function twoFactorAvailable(): bool {
        if ( ! class_exists( '\\TT\\Modules\\Mfa\\MfaSecretsRepository' ) ) return false;
        return \TT\Shared\Frontend\Components\CrossViewLink::allows( \TT\Modules\Mfa\Frontend\FrontendTwoFactorView::SLUG );
    }

    public static function twoFactorOn( int $user_id ): bool {
        if ( $user_id <= 0 || ! self::twoFactorAvailable() ) return false;
        return ( new \TT\Modules\Mfa\MfaSecretsRepository() )->isEnrolled( $user_id );
    }

    private static function accountSummary( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user ) return '';

        $parts = array_values( array_filter( [
            trim( (string) $user->display_name ),
            trim( (string) $user->user_email ),
        ], static fn( string $p ): bool => $p !== '' ) );

        if ( self::twoFactorAvailable() ) {
            $parts[] = self::twoFactorOn( $user_id )
                ? _x( 'two-factor on', 'my settings summary', 'talenttrack' )
                : _x( 'two-factor off', 'my settings summary', 'talenttrack' );
        }
        return implode( ' · ', $parts );
    }

    private static function messagesSummary( int $user_id ): string {
        $types  = array_keys( self::visibleMessageTypes( $user_id ) );
        $policy = new OptOutPolicy();
        $on     = 0;
        foreach ( $types as $type ) {
            if ( ! $policy->isOptedOut( $user_id, $type ) ) $on++;
        }
        return sprintf(
            /* translators: 1: message types switched on, 2: message types offered */
            _x( '%1$d of %2$d on', 'my settings summary', 'talenttrack' ),
            $on,
            count( $types )
        );
    }

    private static function appearanceSummary( int $user_id ): string {
        $shells = ShellPreference::shortLabels();
        $themes = ThemePreference::shortLabels();
        $shell  = ShellPreference::resolve( $user_id );
        $theme  = ThemePreference::resolve( $user_id );

        $parts = [];
        if ( isset( $shells[ $shell ] ) ) $parts[] = $shells[ $shell ];
        if ( isset( $themes[ $theme ] ) ) {
            $parts[] = sprintf(
                /* translators: %s: the short name of a visual theme, e.g. "Default" */
                _x( 'theme %s', 'my settings summary', 'talenttrack' ),
                $themes[ $theme ]
            );
        }
        return implode( ' · ', $parts );
    }

    private static function privacySummary( int $user_id ): string {
        $player_id = self::parentVisibilityPlayerId( $user_id );
        if ( $player_id > 0 ) {
            $prefs  = ( new PlayerParentVisibilityRepository() )->preferencesForPlayer( $player_id );
            $hidden = 0;
            foreach ( array_keys( self::parentVisibilitySectionLabels() ) as $section ) {
                if ( empty( $prefs[ $section ] ) ) $hidden++;
            }
            if ( $hidden === 0 ) {
                return _x( 'Your parent sees everything', 'my settings summary', 'talenttrack' );
            }
            return sprintf(
                /* translators: %d: number of record sections hidden from the player's parent */
                _n( '%d part hidden', '%d parts hidden', $hidden, 'talenttrack' ),
                $hidden
            );
        }

        $held   = OwnContactDetails::forUser( $user_id );
        $places = 1 + ( $held['person'] !== null ? 1 : 0 ) + count( $held['children'] );
        return sprintf(
            /* translators: %d: number of places the academy keeps the user's contact details */
            _n( 'Your details are kept in %d place', 'Your details are kept in %d places', $places, 'talenttrack' ),
            $places
        );
    }
}
