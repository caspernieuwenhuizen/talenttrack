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
 */
final class TrialLetterService {

    private const RETENTION_YEARS = 2;

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
     * @return object[]
     */
    public function listForCase( int $case_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_reports';
        $sql = "SELECT id, audience, created_at, revoked_at, generated_by
                  FROM {$table}
                 WHERE JSON_EXTRACT(config_json, '$.case_id') = %d
                   AND club_id = %d
                 ORDER BY id DESC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $case_id, CurrentClub::id() ) );
        return is_array( $rows ) ? $rows : [];
    }
}
