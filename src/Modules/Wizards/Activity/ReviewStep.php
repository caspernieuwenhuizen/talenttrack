<?php
namespace TT\Modules\Wizards\Activity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Review + create.
 *
 * Mirrors the REST `create_session` payload so the wizard ships an
 * activity row identical in shape to one created from the flat form.
 * Source is hardcoded to `manual`; demo-tag bookkeeping is delegated
 * to the helper used everywhere else (see ActivitiesRestController).
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $type   = (string) ( $state['activity_type_key'] ?? ActivityTypeKey::TRAINING );
        $status = (string) ( $state['activity_status_key'] ?? ActivityStatusKey::PLANNED );
        $continue_to_guests = ! empty( $state['continue_to_guests'] );

        echo '<p>' . esc_html__( 'Looks good? Create the activity to start logging attendance.', 'talenttrack' ) . '</p>';

        // v3.110.142 — was a `<dl class="tt-wizard-review">` with
        // `<dt>` / `<dd>` pairs, which the dashboard's default CSS
        // rendered as an indented bullet-style list. Pilot: "the final
        // step before saving does not show a nice table but a
        // bulleted list, that needs to change." Switched to the same
        // `<table class="tt-table tt-wizard-review-table">` markup
        // the Prospect wizard's ReviewStep already uses — keeps the
        // visual language consistent across wizards.
        $team_name = '—';
        $tid = (int) ( $state['team_id'] ?? 0 );
        if ( $tid > 0 ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT name, age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
                $tid, CurrentClub::id()
            ) );
            if ( $row ) {
                $team_name = (string) $row->name;
                if ( ! empty( $row->age_group ) ) $team_name .= ' (' . $row->age_group . ')';
            }
        }
        $loc   = (string) ( $state['location'] ?? '' );
        $notes = (string) ( $state['notes'] ?? '' );

        $rows = [
            [ __( 'Team',   'talenttrack' ), $team_name, false ],
            [ __( 'Type',   'talenttrack' ), self::translateLookup( 'activity_type',   $type   ), false ],
            [ __( 'Status', 'talenttrack' ), self::translateLookup( 'activity_status', $status ), false ],
        ];
        if ( $type === ActivityTypeKey::GAME && ! empty( $state['game_subtype_key'] ) ) {
            $rows[] = [ __( 'Game subtype', 'talenttrack' ), (string) $state['game_subtype_key'], false ];
        }
        if ( $type === ActivityTypeKey::OTHER && ! empty( $state['other_label'] ) ) {
            $rows[] = [ __( 'Other label', 'talenttrack' ), (string) $state['other_label'], false ];
        }
        $rows[] = [ __( 'Title',    'talenttrack' ), (string) ( $state['title'] ?? '' ),       false ];
        $rows[] = [ __( 'Date',     'talenttrack' ), (string) ( $state['session_date'] ?? '' ), false ];
        // #1126 — show the optional time window on the review when set.
        $st = (string) ( $state['start_time'] ?? '' );
        $et = (string) ( $state['end_time']   ?? '' );
        if ( $st !== '' ) {
            $window = substr( $st, 0, 5 ) . ( $et !== '' ? ' – ' . substr( $et, 0, 5 ) : '' );
            $rows[] = [ __( 'Time', 'talenttrack' ), $window, false ];
        }
        $rows[] = [ __( 'Location', 'talenttrack' ), $loc !== '' ? $loc : '—', false ];
        if ( $notes !== '' ) {
            $rows[] = [ __( 'Notes', 'talenttrack' ), $notes, true ];
        }

        // v3.85.3 — show the principles the operator picked in the
        // new PrinciplesStep so the recap is complete before submit.
        $principle_ids = (array) ( $state['activity_principle_ids'] ?? [] );
        $principle_ids = array_values( array_unique( array_filter( array_map( 'intval', $principle_ids ) ) ) );
        if ( ! empty( $principle_ids ) && class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' ) ) {
            $repo = new \TT\Modules\Methodology\Repositories\PrinciplesRepository();
            $names = [];
            foreach ( $principle_ids as $pid ) {
                $pr = $repo->find( $pid );
                if ( ! $pr ) continue;
                $title = '';
                if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                    $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                }
                $names[] = trim( (string) $pr->code . ( $title !== '' ? ' · ' . $title : '' ) );
            }
            if ( ! empty( $names ) ) {
                $rows[] = [ __( 'Connected principles', 'talenttrack' ), implode( ', ', $names ), false ];
            }
        }

        // #1297 — summarise the expected-attendance roster captured by
        // AttendanceRosterStep. Skip-mode shows "Set attendance later";
        // otherwise show counts so the operator can sanity-check before
        // submit.
        $attendance_skip  = ! empty( $state['attendance_skip'] );
        $attendance_picks = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $state['attendance_picks'] ?? [] ) ) ) ) );
        $attendance_guests = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $state['attendance_guest_picks'] ?? [] ) ) ) ) );
        if ( $attendance_skip ) {
            $rows[] = [ __( 'Roster', 'talenttrack' ), __( 'Set attendance later', 'talenttrack' ), false ];
        } else {
            $pieces = [];
            $pieces[] = sprintf(
                /* translators: %d = number of expected players */
                _n( '%d player expected', '%d players expected', count( $attendance_picks ), 'talenttrack' ),
                count( $attendance_picks )
            );
            if ( ! empty( $attendance_guests ) ) {
                $pieces[] = sprintf(
                    /* translators: %d = number of guest players */
                    _n( '%d guest', '%d guests', count( $attendance_guests ), 'talenttrack' ),
                    count( $attendance_guests )
                );
            }
            $rows[] = [ __( 'Roster', 'talenttrack' ), implode( ', ', $pieces ), false ];
        }

        echo '<div class="tt-table-wrap"><table class="tt-table tt-wizard-review-table"><tbody>';
        foreach ( $rows as [ $label, $value, $multiline ] ) {
            if ( $value === '' ) continue;
            echo '<tr>';
            echo '<th scope="row" style="width:35%;">' . esc_html( $label ) . '</th>';
            echo '<td>' . ( $multiline ? nl2br( esc_html( $value ) ) : esc_html( $value ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // v3.110.x — opt-in path to add guests right after creation.
        // The flat-form (FrontendActivitiesManageView::renderForm) has
        // shipped a guest section on create AND edit since #0037; the
        // wizard previously redirected to the activities list, so coaches
        // had to re-open the activity to add guests. Checking this box
        // routes the post-submit redirect at the activity edit page with
        // `&open_guest=1` so the existing guest-add modal pops open in
        // one motion. Default off — most activities don't have guests.
        echo '<div class="tt-field" style="margin-top:12px;">';
        echo '<label style="display:flex; gap:8px; align-items:flex-start;">';
        echo '<input type="checkbox" name="continue_to_guests" value="1"' . ( $continue_to_guests ? ' checked' : '' ) . ' />';
        echo '<span>' . esc_html__( 'Add a guest player after creating (e.g. trial, friendly drop-in).', 'talenttrack' ) . '</span>';
        echo '</label>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        return [
            'continue_to_guests' => ! empty( $post['continue_to_guests'] ),
        ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        global $wpdb;

        $title = (string) ( $state['title'] ?? '' );
        $date  = (string) ( $state['session_date'] ?? '' );
        $tid   = (int) ( $state['team_id'] ?? 0 );
        if ( $title === '' || $date === '' || $tid <= 0 ) {
            return new \WP_Error( 'incomplete', __( 'The activity is missing required fields.', 'talenttrack' ) );
        }

        $type           = (string) ( $state['activity_type_key'] ?? ActivityTypeKey::TRAINING );
        $status         = (string) ( $state['activity_status_key'] ?? ActivityStatusKey::PLANNED );
        $valid_types    = QueryHelpers::get_lookup_names( 'activity_type' );
        $valid_statuses = QueryHelpers::get_lookup_names( 'activity_status' );
        if ( ! in_array( $type,   $valid_types,    true ) ) $type   = ActivityTypeKey::TRAINING;
        if ( ! in_array( $status, $valid_statuses, true ) ) $status = ActivityStatusKey::PLANNED;

        $row = [
            'club_id'             => CurrentClub::id(),
            'team_id'             => $tid,
            'coach_id'            => get_current_user_id(),
            'title'               => $title,
            'session_date'        => $date,
            'start_time'          => ! empty( $state['start_time'] ) ? (string) $state['start_time'] : null,
            'end_time'            => ! empty( $state['end_time'] )   ? (string) $state['end_time']   : null,
            'location'            => (string) ( $state['location'] ?? '' ),
            'notes'               => (string) ( $state['notes'] ?? '' ),
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'activity_source_key' => 'manual',
            'game_subtype_key'    => $type === ActivityTypeKey::GAME  && ! empty( $state['game_subtype_key'] ) ? (string) $state['game_subtype_key'] : null,
            'other_label'         => $type === ActivityTypeKey::OTHER && ! empty( $state['other_label'] )       ? (string) $state['other_label']    : null,
        ];

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_activities', $row );
        if ( $ok === false ) {
            Logger::error( 'wizard.activity.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'payload' => $row ] );
            return new \WP_Error( 'db_error', __( 'The activity could not be saved.', 'talenttrack' ) );
        }
        $activity_id = (int) $wpdb->insert_id;

        // v3.85.2 — was an inline demo-tag write that duplicated the
        // central helper's logic. Operator reported activities created
        // via the wizard sometimes disappearing post-save; switching to
        // DemoMode::tagIfActive uses the same idempotent + table-safe
        // path every other create site shares (REST controllers, admin
        // pages, CSV importer). The helper short-circuits when demo
        // mode is off and when tt_demo_tags doesn't exist.
        if ( class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            \TT\Modules\DemoData\DemoMode::tagIfActive( 'activity', $activity_id );
        }

        if ( class_exists( '\\TT\\Modules\\Translations\\TranslationLayer' ) ) {
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'activity', $activity_id, 'title',    (string) $row['title'] );
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'activity', $activity_id, 'notes',    (string) $row['notes'] );
            \TT\Modules\Translations\TranslationLayer::detectAndCache( 'activity', $activity_id, 'location', (string) $row['location'] );
        }

        // v3.85.3 — persist Principles practiced from the new
        // PrinciplesStep. Mirrors the wp-admin handle_save and the
        // REST controller's persistPrincipleLinks helper so all three
        // create paths (admin form / REST / wizard) tag activities
        // identically. Empty array = operator skipped the step.
        $principle_ids = (array) ( $state['activity_principle_ids'] ?? [] );
        $principle_ids = array_values( array_unique( array_filter( array_map( 'intval', $principle_ids ) ) ) );
        if ( ! empty( $principle_ids )
             && class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrincipleLinksRepository' )
        ) {
            ( new \TT\Modules\Methodology\Repositories\PrincipleLinksRepository() )
                ->setActivityPrinciples( $activity_id, $principle_ids );
        }

        // #1297 — persist the expected-attendance roster captured by
        // AttendanceRosterStep. `record_type='expected'` so the rows
        // are visible to the existing edit form as a pre-seeded roster
        // (vocabulary from #788 ship 2; once the activity happens the
        // edit form converts to `record_type='actual'`). When the
        // operator ticked "Set attendance later", no rows are written
        // and we preserve the pre-#1297 behaviour exactly. Roster picks
        // get is_guest=0; sibling-team picks from the guest disclosure
        // get is_guest=1, matching the post-save guest UX.
        $attendance_skip = ! empty( $state['attendance_skip'] );
        if ( ! $attendance_skip ) {
            $picks  = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $state['attendance_picks'] ?? [] ) ) ) ) );
            $guests = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $state['attendance_guest_picks'] ?? [] ) ) ) ) );

            foreach ( $picks as $pid ) {
                self::insertExpectedAttendance( $activity_id, $pid, false );
            }
            foreach ( $guests as $gpid ) {
                self::insertExpectedAttendance( $activity_id, $gpid, true );
            }
        } elseif ( $status === ActivityStatusKey::COMPLETED ) {
            // #1636 — created already completed but attendance skipped: seed
            // the active roster as present so the activity is immediately
            // rateable (the rate step needs present/late rows).
            self::seedRosterPresent( $activity_id, $tid );
        }

        // v3.85.3 — was redirect to ?tt_view=activities&id=N (the
        // activity DETAIL page, which renders two back-button
        // affordances and no clear "go to list" path). Operator wants
        // to land on the activity LIST after creating, where the new
        // row is highlighted and the next-create button is one click
        // away. Dropped the `id` query arg.
        //
        // v3.110.x — opt-in alternative: when the operator ticked
        // "Add a guest after creating" on the Review step, redirect
        // to the activity edit page with `&open_guest=1` so the
        // existing #0037 guest-add modal pops open in one motion
        // (mirrors the flat-form's "+ Add guest" auto-save flow).
        if ( ! empty( $state['continue_to_guests'] ) ) {
            return [ 'redirect_url' => add_query_arg( [
                'tt_view'    => 'activities',
                'id'         => $activity_id,
                'action'     => 'edit',
                'open_guest' => '1',
            ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
        }
        return [ 'redirect_url' => add_query_arg( [
            'tt_view' => 'activities',
        ], \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl() ) ];
    }

    private static function translateLookup( string $type, string $name ): string {
        foreach ( QueryHelpers::get_lookups( $type ) as $row ) {
            if ( (string) $row->name === $name ) return LookupTranslator::name( $row );
        }
        return $name;
    }

    /**
     * #1297 — write one expected-attendance row. Status is left at the
     * schema default ('present' on older installs; downstream reads
     * scope on `record_type='expected'` to distinguish pre-seeded vs
     * actual rows). Guest rows mirror the post-save guest endpoint:
     * `is_guest=1`, `guest_player_id` pinned to the picked player so
     * promotion / reporting joins match.
     */
    private static function insertExpectedAttendance( int $activity_id, int $player_id, bool $is_guest ): void {
        if ( $activity_id <= 0 || $player_id <= 0 ) return;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = [
            'club_id'     => CurrentClub::id(),
            'activity_id' => $activity_id,
            'player_id'   => $is_guest ? 0 : $player_id,
            'is_guest'    => $is_guest ? 1 : 0,
            'record_type' => 'expected',
        ];
        if ( $is_guest ) {
            $row['guest_player_id'] = $player_id;
        }

        $ok = $wpdb->insert( "{$p}tt_attendance", $row );
        if ( $ok === false ) {
            Logger::error( 'wizard.activity.attendance.expected.failed', [
                'db_error'   => (string) $wpdb->last_error,
                'activity_id' => $activity_id,
                'player_id'  => $player_id,
                'is_guest'   => $is_guest,
            ] );
        }
    }

    /**
     * #1636 — seed every active roster player as present for an activity
     * created already completed when the operator skipped attendance, so
     * the activity is immediately rateable (record_type 'actual' since it
     * already happened).
     */
    private static function seedRosterPresent( int $activity_id, int $team_id ): void {
        if ( $activity_id <= 0 || $team_id <= 0 ) return;
        $players = QueryHelpers::get_players( $team_id );
        if ( ! $players ) return;

        $statuses = QueryHelpers::get_lookup_names( 'attendance_status' );
        $present  = $statuses[0] ?? 'Present';

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            if ( $pid <= 0 ) continue;
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id'     => $club,
                'activity_id' => $activity_id,
                'player_id'   => $pid,
                'is_guest'    => 0,
                'status'      => $present,
                'record_type' => 'actual',
            ] );
        }
    }
}
