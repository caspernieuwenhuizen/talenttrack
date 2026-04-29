<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Letters\DefaultLetterTemplates;

/**
 * Encapsulates the lookup-with-fallback chain:
 *
 *   custom row for current locale
 *     → custom row for fallback locale ('en_US')
 *       → plugin-shipped default for current locale
 *         → plugin-shipped default for 'en_US'
 *
 * The site-side renderer always asks `getForKey()`; clubs that haven't
 * customized never hit the table at all.
 */
class TrialLetterTemplatesRepository {

    public const KEY_ADMITTANCE  = 'admittance';
    public const KEY_DENY_FINAL  = 'deny_final';
    public const KEY_DENY_ENC    = 'deny_encouragement';

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_letter_templates';
    }

    public function findCustom( string $template_key, string $locale ): ?object {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE template_key = %s AND locale = %s AND club_id = %d LIMIT 1",
            $template_key, $locale, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function getForKey( string $template_key, ?string $locale = null ): string {
        $locale = $locale ?: ( get_user_locale() ?: 'en_US' );

        $row = $this->findCustom( $template_key, $locale );
        if ( $row && $row->html_content ) return (string) $row->html_content;

        if ( $locale !== 'en_US' ) {
            $row = $this->findCustom( $template_key, 'en_US' );
            if ( $row && $row->html_content ) return (string) $row->html_content;
        }

        return DefaultLetterTemplates::get( $template_key, $locale );
    }

    public function save( string $template_key, string $locale, string $html_content, int $user_id ): bool {
        $existing = $this->findCustom( $template_key, $locale );
        $payload = [
            'club_id'       => CurrentClub::id(),
            'template_key'  => $template_key,
            'locale'        => $locale,
            'html_content'  => $html_content,
            'is_customized' => 1,
            'updated_at'    => current_time( 'mysql', true ),
            'updated_by'    => $user_id,
        ];
        if ( $existing ) {
            return (bool) $this->wpdb->update( $this->table, $payload, [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ] );
        }
        return (bool) $this->wpdb->insert( $this->table, $payload );
    }

    public function resetToDefault( string $template_key, string $locale ): bool {
        return (bool) $this->wpdb->delete( $this->table, [
            'template_key' => $template_key,
            'locale'       => $locale,
            'club_id'      => CurrentClub::id(),
        ] );
    }

    /**
     * @return object[]
     */
    public function listAll(): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE club_id = %d ORDER BY template_key, locale",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
