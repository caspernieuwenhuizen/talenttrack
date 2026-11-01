<?php
namespace TT\Modules\ReportStudio\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ReportsRepository (#4569) — the four Report Studio tables: reports, the
 * per-builder drafts, the published versions and who they are published to.
 *
 * Every read and write is scoped to the current club. Access is not decided
 * here (`ReportAccess`); this class only stores and reads.
 *
 * @phpstan-type Cohort array{team_ids:list<int>, age_group:string, position:string}
 * @phpstan-type Report array{id:int, uuid:string, kind:string, scope:string, binding:string, audience:string, period_mode:string, period_value:string, layout:string, sharing:string, name:string, player_id:?int, team_id:?int, cohort:?Cohort, owner_user_id:int, published_version_id:?int, created_at:string, updated_at:string, archived_at:?string}
 * @phpstan-type Draft array{definition:array<mixed>, revision:int, updated_at:string}
 * @phpstan-type Version array{id:int, uuid:string, version_no:int, definition:array<mixed>, note:string, published_by:int, published_at:string}
 */
final class ReportsRepository {

    /**
     * Store a new report and its owner's empty draft.
     *
     * @param array{kind:string, scope:string, binding:string, audience:string, period_mode:string, period_value:string, layout:string, sharing:string, name:string, player_id:?int, team_id:?int, cohort:?Cohort} $choices
     * @return int the report id, 0 on failure
     */
    public function create( array $choices, int $owner_user_id ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $ok  = $wpdb->insert( $wpdb->prefix . 'tt_reports', [
            'uuid'          => wp_generate_uuid4(),
            'club_id'       => CurrentClub::id(),
            'kind'          => $choices['kind'],
            'scope'         => $choices['scope'],
            'binding'       => $choices['binding'],
            'audience'      => $choices['audience'],
            'period_mode'   => $choices['period_mode'],
            'period_value'  => $choices['period_value'],
            'layout'        => $choices['layout'],
            'sharing'       => $choices['sharing'],
            'name'          => $choices['name'],
            'player_id'     => $choices['player_id'],
            'team_id'       => $choices['team_id'],
            'cohort_json'   => $choices['cohort'] !== null ? (string) wp_json_encode( $choices['cohort'] ) : null,
            'owner_user_id' => $owner_user_id,
            'created_at'    => $now,
            'updated_at'    => $now,
        ] );
        if ( $ok === false ) return 0;
        $id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_report_drafts', [
            'club_id'         => CurrentClub::id(),
            'report_id'       => $id,
            'user_id'         => $owner_user_id,
            'definition_json' => (string) wp_json_encode( self::emptyDefinition() ),
            'revision'        => 0,
            'updated_at'      => $now,
        ] );
        return $id;
    }

    /**
     * One report of this club, archived ones included, trashed ones not.
     *
     * @return Report|null
     */
    public function find( int $id ): ?array {
        if ( $id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_reports WHERE id = %d AND club_id = %d AND trashed_at IS NULL",
            $id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? self::hydrate( $row ) : null;
    }

    /**
     * A page of the reports this user owns and the ones published to a
     * persona they hold, newest first by id (the primary key).
     *
     * @param array{kind:string, scope:string, owner:string, archived:string} $filters
     *        empty strings mean "any"; `owner` is `me` or `published`;
     *        `archived` is `active` or `archived`.
     * @param list<string> $persona_keys the personas the user holds
     * @return array{rows:list<Report>, total:int}
     */
    public function listFor( int $user_id, array $persona_keys, array $filters, int $page, int $per_page, bool $ascending ): array {
        global $wpdb;
        $p      = $wpdb->prefix;
        $club   = CurrentClub::id();
        $set    = implode( ',', $persona_keys );
        $offset = max( 0, ( $page - 1 ) * $per_page );

        $where = "r.club_id = %d
              AND r.trashed_at IS NULL
              AND ( ( %s = 'active' AND r.archived_at IS NULL ) OR ( %s = 'archived' AND r.archived_at IS NOT NULL ) )
              AND ( %s = '' OR r.kind = %s )
              AND ( %s = '' OR r.scope = %s )
              AND (
                    ( %s IN ( '', 'me' ) AND r.owner_user_id = %d )
                 OR ( %s IN ( '', 'published' ) AND r.published_version_id IS NOT NULL
                      AND EXISTS ( SELECT 1 FROM {$p}tt_report_publications pub
                                    WHERE pub.report_id = r.id AND pub.club_id = r.club_id
                                      AND FIND_IN_SET( pub.persona_key, %s ) ) )
              )";
        $args = [
            $club,
            $filters['archived'], $filters['archived'],
            $filters['kind'], $filters['kind'],
            $filters['scope'], $filters['scope'],
            $filters['owner'], $user_id,
            $filters['owner'], $set,
        ];

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_reports r WHERE {$where}", ...$args ) );

        $sql = $ascending
            ? "SELECT r.* FROM {$p}tt_reports r WHERE {$where} ORDER BY r.id ASC LIMIT %d OFFSET %d"
            : "SELECT r.* FROM {$p}tt_reports r WHERE {$where} ORDER BY r.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( is_array( $row ) ) $out[] = self::hydrate( $row );
        }
        return [ 'rows' => $out, 'total' => $total ];
    }

    /**
     * This user's draft of the report, or null when they have none.
     *
     * @return Draft|null
     */
    public function draft( int $report_id, int $user_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT definition_json, revision, updated_at FROM {$wpdb->prefix}tt_report_drafts
              WHERE report_id = %d AND user_id = %d AND club_id = %d",
            $report_id, $user_id, CurrentClub::id()
        ), ARRAY_A );
        if ( ! is_array( $row ) ) return null;
        return [
            'definition' => self::decodeDefinition( $row['definition_json'] ?? null ),
            'revision'   => (int) ( $row['revision'] ?? 0 ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    /**
     * The user's draft, made on first use from the published version or, when
     * there is none, the owner's draft.
     *
     * @param array{id:int, owner_user_id:int, published_version_id:?int} $report
     * @return Draft
     */
    public function ensureDraft( array $report, int $user_id ): array {
        $draft = $this->draft( $report['id'], $user_id );
        if ( $draft !== null ) return $draft;

        $definition = self::emptyDefinition();
        $published  = $report['published_version_id'] !== null ? $this->versionById( $report['id'], $report['published_version_id'] ) : null;
        if ( $published !== null ) {
            $definition = $published['definition'];
        } else {
            $owner = $this->draft( $report['id'], $report['owner_user_id'] );
            if ( $owner !== null ) $definition = $owner['definition'];
        }

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}tt_report_drafts (club_id, report_id, user_id, definition_json, revision, updated_at)
             VALUES (%d, %d, %d, %s, 0, %s)",
            CurrentClub::id(), $report['id'], $user_id, (string) wp_json_encode( $definition ), current_time( 'mysql', true )
        ) );
        return $this->draft( $report['id'], $user_id ) ?? [ 'definition' => $definition, 'revision' => 0, 'updated_at' => '' ];
    }

    /**
     * Replace the user's draft definition when its revision is still the one
     * expected (any revision when null).
     *
     * @param array<mixed> $definition
     * @return int|null the new revision, null when the expected one is stale
     */
    public function saveDraft( int $report_id, int $user_id, array $definition, ?int $expected_revision ): ?int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $json = (string) wp_json_encode( $definition );
        $now  = current_time( 'mysql', true );

        $n = $expected_revision === null
            ? $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}tt_report_drafts SET definition_json = %s, revision = revision + 1, updated_at = %s
                  WHERE report_id = %d AND user_id = %d AND club_id = %d",
                $json, $now, $report_id, $user_id, CurrentClub::id()
            ) )
            : $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}tt_report_drafts SET definition_json = %s, revision = revision + 1, updated_at = %s
                  WHERE report_id = %d AND user_id = %d AND club_id = %d AND revision = %d",
                $json, $now, $report_id, $user_id, CurrentClub::id(), $expected_revision
            ) );
        if ( (int) $n !== 1 ) return null;

        $this->touch( $report_id );
        $draft = $this->draft( $report_id, $user_id );
        return $draft !== null ? $draft['revision'] : null;
    }

    public function rename( int $report_id, string $name ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_reports',
            [ 'name' => $name, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $report_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Publish a definition as the next version, and set who it reaches.
     *
     * The next number is MAX + 1; `UNIQUE (report_id, version_no)` refuses a
     * second publish that read the same MAX, and that publish is tried once
     * more before it gives up.
     *
     * @param array<mixed> $definition
     * @param list<string>        $persona_keys
     * @return array{id:int, version_no:int}|null null when the version number stayed taken
     */
    public function publish( int $report_id, array $definition, string $note, array $persona_keys, int $user_id ): ?array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();
        $now  = current_time( 'mysql', true );

        $version = null;
        for ( $attempt = 0; $attempt < 2 && $version === null; $attempt++ ) {
            $next = 1 + (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(version_no) FROM {$p}tt_report_versions WHERE report_id = %d AND club_id = %d",
                $report_id, $club
            ) );
            $suppress = $wpdb->suppress_errors( true );
            $ok       = $wpdb->insert( $p . 'tt_report_versions', [
                'uuid'            => wp_generate_uuid4(),
                'club_id'         => $club,
                'report_id'       => $report_id,
                'version_no'      => $next,
                'definition_json' => (string) wp_json_encode( $definition ),
                'note'            => $note,
                'published_by'    => $user_id,
                'published_at'    => $now,
            ] );
            $wpdb->suppress_errors( $suppress );
            if ( $ok !== false ) $version = [ 'id' => (int) $wpdb->insert_id, 'version_no' => $next ];
        }
        if ( $version === null ) return null;

        $wpdb->update( $p . 'tt_reports', [ 'published_version_id' => $version['id'], 'updated_at' => $now ], [ 'id' => $report_id, 'club_id' => $club ] );
        $wpdb->delete( $p . 'tt_report_publications', [ 'report_id' => $report_id, 'club_id' => $club ] );
        foreach ( $persona_keys as $key ) {
            $wpdb->insert( $p . 'tt_report_publications', [
                'club_id'      => $club,
                'report_id'    => $report_id,
                'persona_key'  => $key,
                'published_by' => $user_id,
                'published_at' => $now,
            ] );
        }
        return $version;
    }

    /**
     * The report's versions, newest first, without their definitions.
     *
     * @return list<array{id:int, uuid:string, version_no:int, note:string, published_by:int, published_at:string}>
     */
    public function versions( int $report_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uuid, version_no, note, published_by, published_at FROM {$wpdb->prefix}tt_report_versions
              WHERE report_id = %d AND club_id = %d ORDER BY version_no DESC",
            $report_id, CurrentClub::id()
        ), ARRAY_A );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( ! is_array( $row ) ) continue;
            $out[] = [
                'id'           => (int) ( $row['id'] ?? 0 ),
                'uuid'         => (string) ( $row['uuid'] ?? '' ),
                'version_no'   => (int) ( $row['version_no'] ?? 0 ),
                'note'         => (string) ( $row['note'] ?? '' ),
                'published_by' => (int) ( $row['published_by'] ?? 0 ),
                'published_at' => (string) ( $row['published_at'] ?? '' ),
            ];
        }
        return $out;
    }

    /** @return Version|null */
    public function version( int $report_id, int $version_no ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_report_versions WHERE report_id = %d AND version_no = %d AND club_id = %d",
            $report_id, $version_no, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? self::hydrateVersion( $row ) : null;
    }

    /** @return Version|null */
    public function versionById( int $report_id, int $version_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_report_versions WHERE report_id = %d AND id = %d AND club_id = %d",
            $report_id, $version_id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? self::hydrateVersion( $row ) : null;
    }

    /** @return list<string> */
    public function personaKeys( int $report_id ): array {
        global $wpdb;
        $keys = $wpdb->get_col( $wpdb->prepare(
            "SELECT persona_key FROM {$wpdb->prefix}tt_report_publications WHERE report_id = %d AND club_id = %d ORDER BY persona_key ASC",
            $report_id, CurrentClub::id()
        ) );
        return array_values( array_map( 'strval', is_array( $keys ) ? $keys : [] ) );
    }

    /**
     * The players of a group's cohort rule: live players on those teams,
     * narrowed to the age group (the team's) and the position when given.
     *
     * @param Cohort $cohort
     * @return list<int>
     */
    public function cohortPlayers( array $cohort ): array {
        $ids = [];
        foreach ( $cohort['team_ids'] as $team_id ) {
            global $wpdb;
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT pl.id FROM {$wpdb->prefix}tt_players pl
                   JOIN {$wpdb->prefix}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
                  WHERE pl.team_id = %d AND pl.club_id = %d
                    AND pl.status = 'active' AND pl.archived_at IS NULL AND pl.trashed_at IS NULL
                    AND ( %s = '' OR t.age_group = %s )
                    AND ( %s = '' OR UPPER( pl.preferred_positions ) LIKE %s )
                  ORDER BY pl.id ASC",
                $team_id, CurrentClub::id(),
                $cohort['age_group'], $cohort['age_group'],
                $cohort['position'], '%' . $wpdb->esc_like( $cohort['position'] ) . '%'
            ) );
            foreach ( is_array( $rows ) ? $rows : [] as $id ) $ids[ (int) $id ] = (int) $id;
        }
        return array_values( $ids );
    }

    /** @return array{blocks:list<array<string,mixed>>} */
    public static function emptyDefinition(): array {
        return [ 'blocks' => [] ];
    }

    private function touch( int $report_id ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_reports', [ 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $report_id, 'club_id' => CurrentClub::id() ] );
    }

    /**
     * @param array<array-key,mixed> $row
     * @return Report
     */
    private static function hydrate( array $row ): array {
        $cohort  = null;
        $decoded = is_string( $row['cohort_json'] ?? null ) ? json_decode( (string) $row['cohort_json'], true ) : null;
        if ( is_array( $decoded ) ) {
            $cohort = [
                'team_ids'  => array_values( array_map( 'intval', (array) ( $decoded['team_ids'] ?? [] ) ) ),
                'age_group' => (string) ( $decoded['age_group'] ?? '' ),
                'position'  => (string) ( $decoded['position'] ?? '' ),
            ];
        }
        $nullable = static fn( string $key ): ?int => isset( $row[ $key ] ) && (int) $row[ $key ] > 0 ? (int) $row[ $key ] : null;

        return [
            'id'                   => (int) ( $row['id'] ?? 0 ),
            'uuid'                 => (string) ( $row['uuid'] ?? '' ),
            'kind'                 => (string) ( $row['kind'] ?? '' ),
            'scope'                => (string) ( $row['scope'] ?? '' ),
            'binding'              => (string) ( $row['binding'] ?? '' ),
            'audience'             => (string) ( $row['audience'] ?? '' ),
            'period_mode'          => (string) ( $row['period_mode'] ?? '' ),
            'period_value'         => (string) ( $row['period_value'] ?? '' ),
            'layout'               => (string) ( $row['layout'] ?? '' ),
            'sharing'              => (string) ( $row['sharing'] ?? '' ),
            'name'                 => (string) ( $row['name'] ?? '' ),
            'player_id'            => $nullable( 'player_id' ),
            'team_id'              => $nullable( 'team_id' ),
            'cohort'               => $cohort,
            'owner_user_id'        => (int) ( $row['owner_user_id'] ?? 0 ),
            'published_version_id' => $nullable( 'published_version_id' ),
            'created_at'           => (string) ( $row['created_at'] ?? '' ),
            'updated_at'           => (string) ( $row['updated_at'] ?? '' ),
            'archived_at'          => isset( $row['archived_at'] ) ? (string) $row['archived_at'] : null,
        ];
    }

    /**
     * @param array<array-key,mixed> $row
     * @return Version
     */
    private static function hydrateVersion( array $row ): array {
        return [
            'id'           => (int) ( $row['id'] ?? 0 ),
            'uuid'         => (string) ( $row['uuid'] ?? '' ),
            'version_no'   => (int) ( $row['version_no'] ?? 0 ),
            'definition'   => self::decodeDefinition( $row['definition_json'] ?? null ),
            'note'         => (string) ( $row['note'] ?? '' ),
            'published_by' => (int) ( $row['published_by'] ?? 0 ),
            'published_at' => (string) ( $row['published_at'] ?? '' ),
        ];
    }

    /** @return array<mixed> */
    private static function decodeDefinition( mixed $json ): array {
        $decoded = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $decoded ) ? $decoded : self::emptyDefinition();
    }
}
