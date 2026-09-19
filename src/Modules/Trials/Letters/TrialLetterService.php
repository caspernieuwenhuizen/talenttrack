<?php
namespace TT\Modules\Trials\Letters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\ScoutReportsRepository;

/**
 * Generates a trial letter and persists it.
 *
 * Reuses the `tt_player_reports` table from #0014 Sprint 5: trial
 * letters are letters about a player, retained for two years per the
 * shaping decision, and can be retrieved + reprinted on demand. They
 * deliberately do NOT use the scout-token access path — letters are
 * printed/emailed by the HoD, not externally shared via a link.
 *
 * The service writes a row, returns its id, and can revoke earlier
 * versions if the HoD regenerates.
 *
 * #3683 — it also records the delivery that follows. Sending stays a
 * human step; what the club gets back is an answer to "does this family
 * have the letter", which the case screen had no way to give.
 */
final class TrialLetterService {

    private const RETENTION_YEARS = 2;

    /**
     * How a letter reached the family (#3683).
     *
     * TalentTrack sends nothing itself — generating a letter has never
     * delivered it, and that stays true. What the club records here is
     * the human step that followed: the letter came off the printer and
     * went in the post, it was attached to an email, or it was put in a
     * parent's hand after the meeting.
     *
     * @var list<string>
     */
    public const DELIVERY_METHODS = [ 'printed', 'emailed', 'handed_over' ];

    public static function isDeliveryMethod( string $method ): bool {
        return in_array( $method, self::DELIVERY_METHODS, true );
    }

    /**
     * Reader-facing name for a delivery method; the raw key when unknown.
     *
     * `_x()` rather than `__()`: "Emailed" alone reads as a send status
     * elsewhere in the product and picks up the wrong Dutch sense, so the
     * three carry the context that says what kind of thing they name.
     */
    public static function methodLabel( string $method ): string {
        switch ( $method ) {
            case 'printed':     return _x( 'Printed and posted', 'letter delivery method', 'talenttrack' );
            case 'emailed':     return _x( 'Emailed', 'letter delivery method', 'talenttrack' );
            case 'handed_over': return _x( 'Handed over in person', 'letter delivery method', 'talenttrack' );
        }
        return $method;
    }

    /**
     * @return int Inserted row id, or 0 on failure.
     */
    public function generate( object $case, string $audience, int $generated_by, ?string $strengths = null, ?string $growth = null ): int {
        if ( ! AudienceType::isTrialLetter( $audience ) ) return 0;

        $extra = [];
        if ( $strengths !== null ) $extra['strengths_summary'] = $strengths;
        if ( $growth   !== null ) $extra['growth_areas']      = $growth;

        $engine = new LetterTemplateEngine();
        $html   = $engine->render( $audience, $case, $extra );

        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';

        // Read once. `$case` is a plain object, so each property access is
        // an error PHPStan's baseline counts individually — and the count
        // is the gate. Reading it twice would need a baseline edit to say
        // nothing new.
        $case_id = (int) $case->id;

        $config_json = wp_json_encode( [
            'case_id'  => $case_id,
            'audience' => $audience,
            'locale'   => get_locale(),
        ] );

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::RETENTION_YEARS * 365 * 86400 );

        $ok = $wpdb->insert( $table, [
            'club_id'         => CurrentClub::id(),
            'player_id'       => (int) $case->player_id,
            'generated_by'    => $generated_by,
            'audience'        => $audience,
            'config_json'     => $config_json ?: '{}',
            'rendered_html'   => $html,
            'expires_at'      => $expires_at,
            'recipient_email' => null,
            'cover_message'   => null,
        ] );

        if ( ! $ok ) return 0;

        $id = (int) $wpdb->insert_id;

        // #3223 — superseding belongs here, not in the caller.
        //
        // Both call sites in `FrontendTrialCaseView` generated and then
        // revoked the priors on the very next line, which made "a case has
        // one live letter" a rule the views happened to follow rather than
        // one the service guaranteed. Adding the REST route would have made
        // a third place to remember it, and the first place to forget it —
        // two live letters saying different things to the same family is
        // exactly the failure this prevents.
        $this->revokePriorLetters( $case_id, $id );

        return $id;
    }

    /**
     * Revoke every other live letter on a case.
     *
     * Called by {@see self::generate()}, which is where the rule now lives.
     * Still public: regenerating from an existing letter is a legitimate
     * caller, and so is a future admin correction.
     */
    public function revokePriorLetters( int $case_id, int $current_letter_id = 0 ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';

        $sql = "UPDATE {$table}
                   SET revoked_at = %s
                 WHERE JSON_EXTRACT(config_json, '$.case_id') = %d
                   AND revoked_at IS NULL
                   AND id <> %d
                   AND club_id = %d";

        return (int) $wpdb->query( $wpdb->prepare(
            $sql,
            current_time( 'mysql', true ),
            $case_id,
            $current_letter_id,
            CurrentClub::id()
        ) );
    }

    public function findActiveForCase( int $case_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';
        $sql = "SELECT * FROM {$table}
                 WHERE JSON_EXTRACT(config_json, '$.case_id') = %d
                   AND revoked_at IS NULL
                   AND club_id = %d
                 ORDER BY id DESC LIMIT 1";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $case_id, CurrentClub::id() ) );
        return $row ?: null;
    }

    /**
     * One letter, but only if it belongs to this case and this club.
     *
     * The ownership test lives here rather than in the caller: a letter
     * id is a guessable integer, and "letter 4711" says nothing about
     * whose child it is about. Every delivery write goes through it.
     */
    public function findInCase( int $letter_id, int $case_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';
        $sql = "SELECT * FROM {$table}
                 WHERE id = %d
                   AND JSON_EXTRACT(config_json, '$.case_id') = %d
                   AND club_id = %d
                 LIMIT 1";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $letter_id, $case_id, CurrentClub::id() ) );
        return $row ?: null;
    }

    /**
     * Record that the family has the letter (#3683).
     *
     * Refuses a method outside {@see self::DELIVERY_METHODS} and a letter
     * that is not on this case, so a caller cannot stamp a delivery onto
     * another family's letter by id.
     */
    public function recordDelivery( int $letter_id, int $case_id, string $method, int $user_id ): bool {
        if ( ! self::isDeliveryMethod( $method ) ) return false;
        if ( ! $this->findInCase( $letter_id, $case_id ) ) return false;

        global $wpdb;
        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_player_reports',
            [
                'delivered_at'    => current_time( 'mysql', true ),
                'delivered_by'    => $user_id,
                'delivery_method' => $method,
            ],
            [ 'id' => $letter_id, 'club_id' => CurrentClub::id() ]
        );

        return $ok !== false;
    }

    /** Undo a delivery record — the HoD ticked the wrong letter. */
    public function clearDelivery( int $letter_id, int $case_id ): bool {
        if ( ! $this->findInCase( $letter_id, $case_id ) ) return false;

        global $wpdb;
        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_player_reports',
            [
                'delivered_at'    => null,
                'delivered_by'    => null,
                'delivery_method' => null,
            ],
            [ 'id' => $letter_id, 'club_id' => CurrentClub::id() ]
        );

        return $ok !== false;
    }

    /**
     * @return object[]
     */
    public function listForCase( int $case_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';
        $sql = "SELECT id, audience, created_at, revoked_at, generated_by,
                       delivered_at, delivered_by, delivery_method
                  FROM {$table}
                 WHERE JSON_EXTRACT(config_json, '$.case_id') = %d
                   AND club_id = %d
                 ORDER BY id DESC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $case_id, CurrentClub::id() ) );
        return is_array( $rows ) ? $rows : [];
    }
}
