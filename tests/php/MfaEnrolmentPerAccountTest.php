<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\CredentialEncryption;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;

/**
 * #4798 — a second factor belongs to the account: one enrolment per
 * `wp_user_id`, read the same in every academy; migration 0318 merges an
 * account's several enrolments into the one that last verified a code.
 */
final class MfaEnrolmentPerAccountTest extends WP_UnitTestCase {

    // The migration's ALTER commits, so what the migration tests wrote is
    // swept and committed afterwards. They use account ids no user holds,
    // so no WordPress user is left behind.
    use CommitsAfterCascade;

    private const MIGRATION = '/database/migrations/0318_mfa_enrolment_per_account.php';

    private string $table = '';

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_user_mfa';
    }

    public function tear_down(): void {
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    public function test_setting_up_in_one_academy_satisfies_another(): void {
        $user   = (int) self::factory()->user->create();
        $repo   = new MfaSecretsRepository();
        $secret = TotpService::generateSecret();

        ClubIteration::withClub( 1, static function () use ( $repo, $user, $secret ): void {
            $repo->upsertSecret( $user, $secret, [] );
            $repo->markEnrolled( $user );
        } );

        $in_two = ClubIteration::withClub( 2, static fn () => [ $repo->isEnrolled( $user ), (string) ( $repo->findByUserId( $user )['secret'] ?? '' ) ] );
        $this->assertTrue( $in_two[0], 'enrolled in academy 2 too' );
        $this->assertSame( $secret, $in_two[1], 'the same authenticator' );
        $this->assertTrue( TotpService::verify( $in_two[1], TotpService::generate( $secret ) ), 'a code from that authenticator verifies in academy 2' );

        // Setting up again from academy 2 replaces the one enrolment, never adds a second.
        ClubIteration::withClub( 2, static fn () => $repo->upsertSecret( $user, TotpService::generateSecret(), [] ) );
        global $wpdb;
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE wp_user_id = %d", $user ) ) );
    }

    public function test_the_unique_key_is_the_account(): void {
        global $wpdb;
        $columns = $wpdb->get_col( $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX',
            $this->table, 'uk_user'
        ) );
        $this->assertSame( [ 'wp_user_id' ], array_values( (array) $columns ) );
    }

    public function test_the_migration_keeps_the_most_recently_verified_enrolment_and_its_codes(): void {
        $this->allowDuplicates();
        $user  = 990001;
        $other = 990002;
        // The survivor is inserted first, so it has the lower id: the rule
        // cannot pass by keeping the highest id.
        $newer = $this->row( $user, 2, 'NEWSECRET234567', '2025-06-01 10:00:00', '2026-10-01 10:00:00', [ 'newer-code-hash' ] );
        $older = $this->row( $user, 1, 'OLDSECRET234567', '2026-01-01 10:00:00', '2026-03-01 10:00:00', [ 'older-code-hash' ] );
        $alone = $this->row( $other, 1, 'ALONESECRET2345', '2026-02-01 10:00:00', '2026-02-02 10:00:00', [ 'alone-code-hash' ] );
        $this->assertLessThan( $older, $newer );

        // Before the merge the repository already reads the row the merge keeps.
        $this->assertSame( 'NEWSECRET234567', ( new MfaSecretsRepository() )->findByUserId( $user )['secret'] ?? '' );

        $this->migration()->up();
        // Twice, because a re-run must change nothing.
        $this->migration()->up();

        global $wpdb;
        $this->assertSame( [ (string) $newer ], array_values( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE wp_user_id = %d", $user ) ) ) );
        $kept = ( new MfaSecretsRepository() )->findByUserId( $user );
        $this->assertSame( 'NEWSECRET234567', $kept['secret'] ?? '' );
        $this->assertSame( [ [ 'hash' => 'newer-code-hash', 'used_at' => null ] ], $kept['backup_codes'] ?? [] );
        $this->assertSame( [ (string) $alone ], array_values( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE wp_user_id = %d", $other ) ) ), 'an account with one enrolment is unchanged' );
        $this->assertNotSame( $older, $newer );

        $audit = $wpdb->get_results( $wpdb->prepare(
            "SELECT club_id, payload FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_id = %d ORDER BY club_id ASC",
            'mfa.enrolments_merged', $user
        ), ARRAY_A );
        $this->assertSame( [ '1', '2' ], array_column( (array) $audit, 'club_id' ), 'one audit row per merge in each academy that had an enrolment, though the migration ran twice' );
        foreach ( (array) $audit as $entry ) {
            $payload = (string) $entry['payload'];
            $this->assertSame( $newer, (int) json_decode( $payload, true )['kept']['id'] );
            foreach ( [ 'SECRET', 'code-hash', 'secret_encrypted' ] as $secret ) {
                $this->assertStringNotContainsString( $secret, $payload, 'no secret material in the audit row' );
            }
        }
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_id = %d", 'mfa.enrolments_merged', $other ) ) );

        $this->test_the_unique_key_is_the_account();
    }

    public function test_with_no_verification_the_most_recently_set_up_enrolment_survives(): void {
        $this->allowDuplicates();
        $user = 990003;
        // The survivor first again, with the lower id.
        $later = $this->row( $user, 2, 'LATERSECRET2345', '2026-09-01 10:00:00', null, [] );
        $first = $this->row( $user, 1, 'FIRSTSECRET2345', '2026-05-01 10:00:00', null, [] );
        $this->assertLessThan( $first, $later );
        $this->assertSame( 'LATERSECRET2345', ( new MfaSecretsRepository() )->findByUserId( $user )['secret'] ?? '' );

        $this->migration()->up();

        global $wpdb;
        $this->assertSame( [ (string) $later ], array_values( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE wp_user_id = %d", $user ) ) ) );
    }

    /** The pre-0318 shape: unique per account and academy. */
    private function allowDuplicates(): void {
        global $wpdb;
        $wpdb->query( "ALTER TABLE {$this->table} DROP INDEX uk_user, ADD UNIQUE KEY uk_user (wp_user_id, club_id)" );
    }

    /** @param list<string> $codes */
    private function row( int $user, int $club, string $secret, string $enrolled, ?string $verified, array $codes ): int {
        global $wpdb;
        $wpdb->insert( $this->table, [
            'club_id' => $club, 'uuid' => wp_generate_uuid4(), 'wp_user_id' => $user,
            'secret_encrypted' => CredentialEncryption::encrypt( $secret ),
            'backup_codes_hashed' => (string) wp_json_encode( array_map( static fn( string $h ): array => [ 'hash' => $h, 'used_at' => null ], $codes ) ),
            'enrolled_at' => $enrolled, 'last_verified_at' => $verified, 'created_at' => $enrolled,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: ' . $wpdb->last_error );
        return $id;
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }
}
