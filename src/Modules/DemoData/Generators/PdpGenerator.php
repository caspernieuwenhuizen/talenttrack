<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PdpVerdictDecision;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PdpGenerator — seasons, PDP dossiers, their conversation cycle, the
 * verdicts that close them, and calendar links.
 *
 * One season per year the history window covers (#3402), not one season per
 * run. A two-year window used to produce a single season stretched across
 * both, so a player's dossier covered two age groups at once and the
 * "previous season" every carry-over and comparison surface reads had
 * nothing in it.
 *
 * Goes through the PDP repositories so the conversation cycle is spaced by
 * the same block/planning-window logic the real flow uses, and so a signed-off
 * verdict raises its journey event.
 *
 * Both conversation states are represented on purpose: earlier conversations
 * conducted and signed off, the upcoming one scheduled and unsigned. A demo
 * where every conversation is closed can't show the surface an operator
 * spends most of their time on.
 */
class PdpGenerator implements DependentGeneratorInterface {

    /** @var array<string, array{prep:string, notes:string, actions:string, reflection:string, summary:string}> */
    private const COPY_BY_LANGUAGE = [
        'en_US' => [
            'prep'       => 'Review the block, agree two focus points, check in on wellbeing.',
            'notes'      => 'Good engagement in training. Wants more game time in midfield.',
            'actions'    => 'Extra weak-foot work twice a week; review at the next conversation.',
            'reflection' => 'I want to be braver on the ball when we are under pressure.',
            'summary'    => 'Steady progress across the season; stays in the current age group.',
        ],
        'nl_NL' => [
            'prep'       => 'Blok terugkijken, twee aandachtspunten afspreken, welzijn bespreken.',
            'notes'      => 'Goede inzet op de training. Wil meer speeltijd op het middenveld.',
            'actions'    => 'Twee keer per week extra werken met de zwakke voet; volgende keer evalueren.',
            'reflection' => 'Ik wil durven voetballen als we onder druk staan.',
            'summary'    => 'Gestage ontwikkeling dit seizoen; blijft in de huidige leeftijdsgroep.',
        ],
    ];

    /**
     * Verdict mix for a player who is still at the academy — most stay, a
     * few move up. Release and transfer are deliberately absent: a verdict
     * that released a player who is on next season's roster is the kind of
     * contradiction the multi-season demo exists to remove (#3402). The
     * departed cohort carries those decisions instead.
     */
    private const DECISION_WEIGHTS = [
        [ 22, PdpVerdictDecision::PROMOTE ],
        [ 100, PdpVerdictDecision::RETAIN ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    private int $weeks;

    private string $language;

    private DemoCalendar $calendar;

    private DemoRoster $roster;

    public static function category(): string {
        return 'pdp';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->historicPlayers(),
            $ctx->teams,
            $ctx->users,
            $ctx->weeks(),
            $ctx->contentLanguage,
            $ctx->calendar(),
            $ctx->roster()
        );
    }

    /**
     * @param object[] $players
     * @param object[] $teams
     * @param array<string,int> $users
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        array $users,
        int $weeks,
        string $language = '',
        ?DemoCalendar $calendar = null,
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->users    = $users;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
        $this->roster   = $roster ?? new DemoRoster( $this->calendar, $teams, $players );
    }

    public function generate(): int {
        $seasons = $this->ensureSeasons();
        if ( $seasons === [] ) return 0;

        $copy  = self::COPY_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $files = new PdpFilesRepository();
        $convs = new PdpConversationsRepository();
        $hoa   = (int) ( $this->users['hjo'] ?? $this->users['admin'] ?? 0 );

        $coach_by_team = [];
        foreach ( $this->teams as $t ) {
            $coach_by_team[ (int) $t->id ] = (int) ( $t->head_coach_user_id ?? 0 );
        }

        $last_index = (int) array_key_last( $seasons );

        $total = count( $seasons ); // the seasons themselves
        foreach ( $seasons as $index => $season ) {
            $start = (string) $season['start'];
            $end   = (string) $season['end'];

            // The dossier's conversations sit where the evaluation rounds
            // put them, so `EvidencePacket::forConversation()` has the round
            // behind each talk rather than an empty packet (#3401).
            $dates        = $this->calendar->conversationDates( [ 'start_date' => $start, 'end_date' => $end ] );
            $season_files = [];
            $is_current   = $index === $last_index;

            foreach ( $this->players as $p ) {
                $player_id = (int) ( $p->id ?? 0 );
                if ( $player_id <= 0 ) continue;

                $team_id = $this->roster->teamForPlayerInSeason( $player_id, $index );
                if ( $team_id <= 0 ) continue; // not at the academy that season

                $coach_id = (int) ( $coach_by_team[ $team_id ] ?? 0 );

                $file_id = $files->create( [
                    'player_id'      => $player_id,
                    'season_id'      => $season['id'],
                    'owner_coach_id' => $coach_id > 0 ? $coach_id : null,
                    'cycle_size'     => DemoCalendar::ROUNDS_PER_SEASON,
                    'notes'          => $copy['prep'],
                ] );
                if ( $file_id <= 0 ) continue;

                $this->registry->tag( 'pdp_file', $file_id, [
                    'player_id'  => $player_id,
                    'cycle_size' => DemoCalendar::ROUNDS_PER_SEASON,
                    'season_id'  => $season['id'],
                ] );
                $total++;
                $season_files[ $file_id ] = $player_id;

                $convs->createCycleOn( $file_id, $dates, $start, $end );
                $total += $this->fillCycle( $file_id, $coach_id, $copy );
            }

            // #3402 — a finished season with an open conversation cycle is
            // not a state a real academy is in. Prior seasons close with a
            // verdict; the current one stays open at whatever stage the
            // window puts it. A window covering only one season still reaches
            // `closeFiles()`, but since #3650 only a dossier whose whole
            // cycle has been held closes there — on a season that has just
            // started that is usually none of them, which is the truth.
            $total += $is_current && count( $seasons ) > 1
                ? 0
                : $this->closeFiles( $files, $hoa, $copy, $season_files, $end, $is_current, (int) $index );
        }

        return $total;
    }

    /**
     * Walk a file's conversations: everything whose scheduled date has passed
     * is conducted and signed off, the next one stays open. Calendar links go
     * on the scheduled ones — that's where a coach needs the reminder.
     *
     * @param array{prep:string, notes:string, actions:string, reflection:string, summary:string} $copy
     */
    private function fillCycle( int $file_id, int $coach_id, array $copy ): int {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, scheduled_at, template_key FROM {$wpdb->prefix}tt_pdp_conversations
              WHERE pdp_file_id = %d AND club_id = %d ORDER BY sequence",
            $file_id, CurrentClub::id()
        ) );

        $total = 0;
        foreach ( (array) $rows as $row ) {
            $conversation_id = (int) $row->id;
            $scheduled_ts    = strtotime( (string) $row->scheduled_at ) ?: time();
            $this->registry->tag( 'pdp_conversation', $conversation_id, [ 'pdp_file_id' => $file_id ] );
            $total++;

            // #3305 — the coach's preparation. Seeded for every conversation
            // in the cycle, conducted or not: a demo where only past talks
            // are prepared hides the surface a coach actually opens.
            $total += $this->seedPrep( $conversation_id, (string) ( $row->template_key ?? '' ), $copy );

            if ( $scheduled_ts < time() ) {
                $conducted = gmdate( 'Y-m-d H:i:s', $scheduled_ts );
                $wpdb->update(
                    "{$wpdb->prefix}tt_pdp_conversations",
                    [
                        // #3306 — `agenda` is retired; the coach's
                        // preparation is the prep answers seeded above.
                        'conducted_at'      => $conducted,
                        'notes'             => $copy['notes'],
                        'agreed_actions'    => $copy['actions'],
                        'player_reflection' => $copy['reflection'],
                        'coach_signoff_at'  => $conducted,
                        'parent_ack_at'     => mt_rand( 1, 100 ) <= 70 ? $conducted : null,
                        'player_ack_at'     => mt_rand( 1, 100 ) <= 60 ? $conducted : null,
                    ],
                    [ 'id' => $conversation_id, 'club_id' => CurrentClub::id() ]
                );
                continue;
            }

            $wpdb->insert( "{$wpdb->prefix}tt_pdp_calendar_links", [
                'club_id'           => CurrentClub::id(),
                'conversation_id'   => $conversation_id,
                'provider'          => 'native',
                'provider_event_id' => 'demo-' . $conversation_id,
                'provider_payload'  => null,
            ] );
            $link_id = (int) $wpdb->insert_id;
            if ( $link_id ) {
                $this->registry->tag( 'pdp_calendar_link', $link_id );
                $total++;
            }
        }
        return $total;
    }

    /**
     * #3305 (epic #3301) — a coach's answers to this conversation's prep
     * questions.
     *
     * Written straight through `$wpdb` rather than through
     * `PdpPrepAnswersRepository`: that repository gates every write on the
     * current user being the coach who owns the file, and a generation run
     * from WP-CLI has no user at all. The gate is right; going round it here
     * is the same choice the calendar-link insert above makes.
     *
     * @param array{prep:string, notes:string, actions:string, reflection:string, summary:string} $copy
     * @return int Rows written.
     */
    private function seedPrep( int $conversation_id, string $template_key, array $copy ): int {
        global $wpdb;

        $questions = ( new \TT\Modules\Pdp\Prep\PdpPrepQuestionsRepository() )
            ->listForTemplate( $template_key );
        if ( $questions === [] ) return 0;

        $written = 0;
        foreach ( $questions as $index => $question ) {
            // The last question is the free-text catch-all; leaving it empty
            // is what a real prep looks like more often than not.
            if ( $index === count( $questions ) - 1 ) continue;

            $text = $index === 0 ? $copy['notes'] : $copy['actions'];

            $ok = $wpdb->insert( "{$wpdb->prefix}tt_pdp_prep_answers", [
                'uuid'             => wp_generate_uuid4(),
                'club_id'          => CurrentClub::id(),
                'conversation_id'  => $conversation_id,
                'question_id'      => (int) $question['id'],
                'question_version' => (int) $question['version'],
                'answer_text'      => $text,
            ] );
            if ( ! $ok ) continue;

            $this->registry->tag( 'pdp_prep_answer', (int) $wpdb->insert_id, [
                'conversation_id' => $conversation_id,
            ] );
            $written++;
        }
        return $written;
    }

    /**
     * Write the verdicts that close a season's dossiers.
     *
     * A season that has finished closes all of them — an academy does not
     * carry an open cycle into the next year (#3402). The current season
     * only reaches here when it is the only season the window covers, and
     * then a minority of the dossiers whose cycle has actually been held
     * close (#3650). Early in a season that is usually none of them: a
     * dossier is closed by its last conversation, not by a dice roll.
     *
     * Signed-off verdicts raise their journey event through the repository.
     *
     * @param array<int,int> $season_files file id => the player it is about
     * @param array{prep:string, notes:string, actions:string, reflection:string, summary:string} $copy
     */
    private function closeFiles(
        PdpFilesRepository $files,
        int $hoa,
        array $copy,
        array $season_files,
        string $season_end,
        bool $is_current,
        int $season_index
    ): int {
        $verdicts = new PdpVerdictsRepository();

        $total = 0;
        foreach ( $season_files as $file_id => $player_id ) {
            if ( $is_current && mt_rand( 1, 100 ) > 30 ) continue;

            // #3650 — never close a dossier over conversations nobody has
            // held. A prior season is entirely in the past, so `fillCycle()`
            // has conducted every talk on it and this passes. The current
            // season's cycle usually runs months into the future, and a
            // signed-off verdict on it told a head of development the
            // player's year was finished in the week it started.
            if ( $is_current && $this->hasOpenConversation( (int) $file_id ) ) continue;

            $file = $files->find( (int) $file_id );
            if ( ! $file ) continue;

            $signed_off = $is_current
                ? gmdate( 'Y-m-d H:i:s', strtotime( '-' . mt_rand( 3, 30 ) . ' days' ) ?: time() )
                : gmdate( 'Y-m-d H:i:s', strtotime( $season_end . ' -' . mt_rand( 1, 21 ) . ' days' ) ?: time() );

            $ok = $verdicts->upsertForFile( (int) $file_id, [
                'decision'           => $this->pickDecision( (int) $player_id, $season_index ),
                'summary'            => $copy['summary'],
                'coach_id'           => (int) ( $file->owner_coach_id ?? 0 ),
                'head_of_academy_id' => $hoa,
                'signed_off_at'      => $signed_off,
            ] );
            if ( ! $ok ) continue;

            $verdict = $verdicts->findForFile( (int) $file_id );
            if ( $verdict ) {
                $this->registry->tag( 'pdp_verdict', (int) $verdict->id, [ 'pdp_file_id' => (int) $file_id ] );
                $total++;
            }
            $files->setStatus( (int) $file_id, 'completed' );
        }
        return $total;
    }

    /**
     * #3650 — does this dossier still have a conversation nobody has held?
     *
     * Club-scoped like every other read in this generator, and asked per
     * file rather than once for the season: `closeFiles()` walks a handful
     * of dossiers, and a count keyed on the file is the cheapest form of
     * the question.
     */
    private function hasOpenConversation( int $file_id ): bool {
        global $wpdb;

        $open = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_pdp_conversations
              WHERE pdp_file_id = %d AND club_id = %d AND conducted_at IS NULL",
            $file_id, CurrentClub::id()
        ) );

        return (int) $open > 0;
    }

    /**
     * One season row per year the window covers, oldest first.
     *
     * A season the club already has is reused rather than duplicated — and
     * kept untagged, so a demo wipe never takes an academy's own season with
     * it. Only the ones this run creates are tagged. The last is made
     * current.
     *
     * @return array<int, array{id:int, start:string, end:string}> keyed by the calendar's season index
     */
    private function ensureSeasons(): array {
        $repo     = new SeasonsRepository();
        $existing = [];
        foreach ( $repo->all() as $object ) {
            $row = (array) $object;
            $existing[ (string) ( $row['name'] ?? '' ) ]       = $row;
            $existing[ (string) ( $row['start_date'] ?? '' ) ] = $row;
        }

        $out     = [];
        $current = 0;
        foreach ( $this->calendar->seasons() as $season ) {
            $row = $existing[ (string) $season['name'] ] ?? $existing[ (string) $season['start_date'] ] ?? null;

            if ( $row === null ) {
                $id = $repo->create( [
                    'name'       => (string) $season['name'],
                    'start_date' => (string) $season['start_date'],
                    'end_date'   => (string) $season['end_date'],
                ] );
                if ( $id <= 0 ) continue;
                $this->registry->tag( 'season', $id );

                $created = $repo->find( $id );
                if ( $created === null ) continue;
                $row = (array) $created;
            }

            $out[ (int) $season['index'] ] = [
                'id'    => (int) ( $row['id'] ?? 0 ),
                'start' => (string) ( $row['start_date'] ?? '' ),
                'end'   => (string) ( $row['end_date'] ?? '' ),
            ];
            $current = (int) ( $row['id'] ?? 0 );
        }

        if ( $current > 0 ) {
            $repo->setCurrent( $current );
        }
        return $out;
    }

    /**
     * What a season's verdict says. The verdict that ends a departed
     * player's last season is the release itself; everyone else stays or
     * moves up.
     */
    private function pickDecision( int $player_id, int $season_index ): string {
        $leaving = $player_id > 0
            && $this->roster->teamForPlayerInSeason( $player_id, $season_index ) > 0
            && $this->roster->teamForPlayerInSeason( $player_id, $season_index + 1 ) === 0
            && $season_index < $this->calendar->seasonCount() - 1;

        if ( $leaving ) {
            return PdpVerdictDecision::RELEASE;
        }

        $roll = mt_rand( 1, 100 );
        foreach ( self::DECISION_WEIGHTS as [ $cut, $decision ] ) {
            if ( $roll <= $cut ) return $decision;
        }
        return PdpVerdictDecision::RETAIN;
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::COPY_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::COPY_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
