<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * PdpFormRegistry (#4976, epic #4975) — the PDP conversation forms an
 * academy can use, and which ones it does.
 *
 * A form is data, not a screen: `config/pdp_forms/<form_key>/v<N>.php`, one
 * file per form and version. A shipped version is immutable, so a
 * conversation held on v1 keeps reading v1 after v2 ships. There is no
 * editor in the app (owner decision 1 on #4975).
 *
 * The text in a definition is the academy's own, shown as it stands in every
 * locale, and not a msgid. The screens around it translate as usual.
 *
 * Which forms an academy uses is tenant configuration: the `pdp_forms` key in
 * `tt_config`, by club, holding `{"outfield":<form_key>,"goalkeeper":<form_key>}`.
 * Nothing in the catalogue is active until an academy selects it.
 *
 * A definition that does not validate is never served: `definition()` returns
 * null and the file is reported once to the error log. The validation rules
 * live in `validate()`, which the tests run on every shipped file.
 */
final class PdpFormRegistry {

    public const CONFIG_KEY = 'pdp_forms';

    public const GROUP_OUTFIELD   = 'outfield';
    public const GROUP_GOALKEEPER = 'goalkeeper';
    public const GROUPS = [ self::GROUP_OUTFIELD, self::GROUP_GOALKEEPER ];

    /** The four main evaluation categories every form's sections map onto. */
    public const MAINS = [ 'technical', 'tactical', 'physical', 'mental' ];

    public const AUTHORS = [ 'player', 'coach', 'both' ];
    public const KINDS   = [ 'text', 'smart_goal' ];

    private const TOP_KEYS     = [ 'form_key', 'version', 'set', 'position_group', 'category_prefix', 'header', 'scale', 'score_columns', 'sections', 'prompts', 'sign_off' ];
    private const SECTION_KEYS = [ 'key', 'main', 'title', 'items' ];
    private const ITEM_KEYS    = [ 'key', 'label', 'definition' ];
    private const PROMPT_KEYS  = [ 'key', 'author', 'kind', 'label', 'question', 'example' ];

    /** @var array<string, array<string,mixed>|null> path => definition, per request */
    private static array $loaded = [];

    /** The directory the definitions live in. */
    public static function directory(): string {
        return rtrim( TT_PLUGIN_DIR, '/\\' ) . '/config/pdp_forms';
    }

    /**
     * Every form key in the catalogue, sorted.
     *
     * @return list<string>
     */
    public static function formKeys(): array {
        $out = [];
        foreach ( (array) glob( self::directory() . '/*', GLOB_ONLYDIR ) as $dir ) {
            $key = basename( (string) $dir );
            if ( preg_match( '/^[a-z0-9_]+$/', $key ) ) $out[] = $key;
        }
        sort( $out );
        return $out;
    }

    /**
     * The shipped versions of a form, ascending.
     *
     * @return list<int>
     */
    public static function versions( string $form_key ): array {
        if ( ! preg_match( '/^[a-z0-9_]+$/', $form_key ) ) return [];
        $out = [];
        foreach ( (array) glob( self::directory() . '/' . $form_key . '/v*.php' ) as $file ) {
            if ( preg_match( '/\/v(\d+)\.php$/', str_replace( '\\', '/', (string) $file ), $m ) ) $out[] = (int) $m[1];
        }
        sort( $out );
        return $out;
    }

    /** The newest shipped version of a form, or 0 when it has none. */
    public static function latestVersion( string $form_key ): int {
        $versions = self::versions( $form_key );
        return $versions === [] ? 0 : (int) end( $versions );
    }

    /**
     * One version of a form, validated, or null when it is missing or does
     * not validate. `$version` 0 means the newest.
     *
     * @return array<string,mixed>|null
     */
    public static function definition( string $form_key, int $version = 0 ): ?array {
        if ( $version <= 0 ) $version = self::latestVersion( $form_key );
        if ( $version <= 0 || ! preg_match( '/^[a-z0-9_]+$/', $form_key ) ) return null;

        $path = self::directory() . '/' . $form_key . '/v' . $version . '.php';
        if ( array_key_exists( $path, self::$loaded ) ) return self::$loaded[ $path ];

        $definition = null;
        if ( is_readable( $path ) ) {
            $raw = require $path;
            $errors = is_array( $raw ) ? self::validate( $raw, $form_key, $version ) : [ 'the file does not return an array' ];
            if ( $errors === [] ) {
                $definition = $raw;
            } else {
                error_log( '[TalentTrack] PDP form ' . $form_key . ' v' . $version . ' is not used: ' . implode( '; ', $errors ) );
            }
        }
        self::$loaded[ $path ] = $definition;
        return $definition;
    }

    /**
     * The form sets on offer, each with its form per position group, from the
     * newest valid version of every form. The PDP settings select reads this.
     *
     * @return array<string, array{key:string, label:string, forms:array<string,string>}> set key => set
     */
    public static function sets(): array {
        $out = [];
        foreach ( self::formKeys() as $form_key ) {
            $definition = self::definition( $form_key );
            if ( $definition === null ) continue;
            $set = (array) $definition['set'];
            $key = (string) $set['key'];
            if ( ! isset( $out[ $key ] ) ) $out[ $key ] = [ 'key' => $key, 'label' => (string) $set['label'], 'forms' => [] ];
            $out[ $key ]['forms'][ (string) $definition['position_group'] ] = $form_key;
        }
        ksort( $out );
        // Each set's forms in position-group order, outfield first.
        foreach ( $out as $key => $set ) {
            $ordered = [];
            foreach ( self::GROUPS as $group ) {
                if ( isset( $set['forms'][ $group ] ) ) $ordered[ $group ] = $set['forms'][ $group ];
            }
            $out[ $key ]['forms'] = $ordered;
        }
        return $out;
    }

    /**
     * The forms the current academy uses, per position group. A group with no
     * form, an unknown form key, or no setting at all reads as null, so the
     * PDP stays as it is without forms.
     *
     * @return array{outfield:?string, goalkeeper:?string}
     */
    public static function formsForClub(): array {
        $out     = [ self::GROUP_OUTFIELD => null, self::GROUP_GOALKEEPER => null ];
        $decoded = json_decode( (string) QueryHelpers::get_config( self::CONFIG_KEY, '' ), true );
        if ( ! is_array( $decoded ) ) return $out;

        foreach ( self::GROUPS as $group ) {
            $form_key = isset( $decoded[ $group ] ) && is_string( $decoded[ $group ] ) ? $decoded[ $group ] : '';
            if ( $form_key === '' ) continue;
            $definition = self::definition( $form_key );
            if ( $definition !== null && $definition['position_group'] === $group ) $out[ $group ] = $form_key;
        }
        return $out;
    }

    /** The set the current academy uses, or '' when it uses none. */
    public static function setForClub(): string {
        $forms = array_filter( self::formsForClub() );
        if ( $forms === [] ) return '';
        foreach ( self::sets() as $key => $set ) {
            if ( $set['forms'] == $forms ) return $key;
        }
        return '';
    }

    /** Forget what this request loaded; tests that write definitions call it. */
    public static function reset(): void {
        self::$loaded = [];
    }

    /**
     * Everything wrong with a definition, as sentences. Empty when it is
     * valid. `$form_key` and `$version` are what the file's place says it is.
     *
     * @param array<mixed> $d
     * @return list<string>
     */
    public static function validate( array $d, string $form_key, int $version ): array {
        $errors = [];

        foreach ( array_diff( array_keys( $d ), self::TOP_KEYS ) as $unknown ) $errors[] = "unknown key `{$unknown}`";
        foreach ( array_diff( self::TOP_KEYS, array_keys( $d ) ) as $missing ) $errors[] = "missing key `{$missing}`";
        if ( $errors !== [] ) return $errors;

        if ( $d['form_key'] !== $form_key ) $errors[] = 'form_key does not match its directory';
        if ( $d['version'] !== $version ) $errors[] = 'version does not match its file name';
        if ( ! is_array( $d['set'] ) || ! self::isKey( $d['set']['key'] ?? null ) || ! self::isText( $d['set']['label'] ?? null ) ) {
            $errors[] = 'set needs a key and a label';
        }
        if ( ! in_array( $d['position_group'], self::GROUPS, true ) ) $errors[] = 'position_group must be outfield or goalkeeper';
        if ( ! self::isKey( $d['category_prefix'] ) ) $errors[] = 'category_prefix must be a key';

        $header = $d['header'];
        if ( ! is_array( $header ) || ! self::isText( $header['title'] ?? null ) || ! is_array( $header['fields'] ?? null ) ) {
            $errors[] = 'header needs a title and fields';
        }

        $scale = $d['scale'];
        if ( ! is_array( $scale ) || array_keys( $scale ) !== [ 1, 2, 3, 4, 5 ] ) {
            $errors[] = 'scale must have exactly the steps 1 to 5';
        } else {
            foreach ( $scale as $step => $row ) {
                if ( ! is_array( $row ) || ! self::isText( $row['label'] ?? null ) ) $errors[] = "scale step {$step} needs a label";
            }
        }

        $columns = $d['score_columns'];
        if ( ! is_array( $columns ) || ! self::isText( $columns['player'] ?? null ) || ! self::isText( $columns['coach'] ?? null ) ) {
            $errors[] = 'score_columns needs a player and a coach column';
        }

        $section_keys = [];
        $item_keys    = [];
        $mains        = [];
        if ( ! is_array( $d['sections'] ) || $d['sections'] === [] ) {
            $errors[] = 'sections must not be empty';
        } else {
            foreach ( $d['sections'] as $i => $section ) {
                if ( ! is_array( $section ) ) { $errors[] = "section {$i} is not an array"; continue; }
                foreach ( array_diff( array_keys( $section ), self::SECTION_KEYS ) as $unknown ) $errors[] = "section {$i}: unknown key `{$unknown}`";
                $key = $section['key'] ?? null;
                if ( ! self::isKey( $key ) ) { $errors[] = "section {$i} needs a key"; continue; }
                if ( isset( $section_keys[ $key ] ) ) $errors[] = "duplicate section key `{$key}`";
                $section_keys[ $key ] = true;

                $main = $section['main'] ?? null;
                if ( ! in_array( $main, self::MAINS, true ) ) {
                    $errors[] = "section `{$key}`: main must be one of " . implode( ', ', self::MAINS );
                } else {
                    $mains[ $main ] = true;
                }
                if ( ! self::isText( $section['title'] ?? null ) ) $errors[] = "section `{$key}` needs a title";

                if ( ! is_array( $section['items'] ?? null ) || $section['items'] === [] ) {
                    $errors[] = "section `{$key}` has no items";
                    continue;
                }
                foreach ( $section['items'] as $j => $item ) {
                    if ( ! is_array( $item ) ) { $errors[] = "section `{$key}` item {$j} is not an array"; continue; }
                    foreach ( array_diff( array_keys( $item ), self::ITEM_KEYS ) as $unknown ) $errors[] = "item {$j} in `{$key}`: unknown key `{$unknown}`";
                    $item_key = $item['key'] ?? null;
                    if ( ! self::isKey( $item_key ) ) { $errors[] = "item {$j} in `{$key}` needs a key"; continue; }
                    if ( isset( $item_keys[ $item_key ] ) ) $errors[] = "duplicate item_key `{$item_key}`";
                    $item_keys[ $item_key ] = true;
                    if ( ! self::isText( $item['label'] ?? null ) ) $errors[] = "item `{$item_key}` needs a label";
                    if ( ! self::isText( $item['definition'] ?? null ) ) $errors[] = "item `{$item_key}` needs a definition";
                    if ( self::isKey( $d['category_prefix'] ) && strlen( $d['category_prefix'] . '_' . $item_key ) > 64 ) {
                        $errors[] = "item `{$item_key}`: its category key is longer than 64 characters";
                    }
                }
            }
            foreach ( array_diff( self::MAINS, array_keys( $mains ) ) as $missing ) $errors[] = "no section for the main `{$missing}`";
        }

        $prompt_keys = [];
        if ( ! is_array( $d['prompts'] ) ) {
            $errors[] = 'prompts must be a list';
        } else {
            foreach ( $d['prompts'] as $i => $prompt ) {
                if ( ! is_array( $prompt ) ) { $errors[] = "prompt {$i} is not an array"; continue; }
                foreach ( array_diff( array_keys( $prompt ), self::PROMPT_KEYS ) as $unknown ) $errors[] = "prompt {$i}: unknown key `{$unknown}`";
                $key = $prompt['key'] ?? null;
                if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', $key ) ) { $errors[] = "prompt {$i} needs a key"; continue; }
                if ( isset( $prompt_keys[ $key ] ) ) $errors[] = "duplicate prompt key `{$key}`";
                $prompt_keys[ $key ] = true;
                if ( ! in_array( $prompt['author'] ?? null, self::AUTHORS, true ) ) $errors[] = "prompt `{$key}` needs an author (player, coach or both)";
                if ( ! in_array( $prompt['kind'] ?? null, self::KINDS, true ) ) $errors[] = "prompt `{$key}` needs a kind (text or smart_goal)";
                if ( ! self::isText( $prompt['label'] ?? null ) ) $errors[] = "prompt `{$key}` needs a label";
                if ( ! is_string( $prompt['question'] ?? null ) ) $errors[] = "prompt `{$key}` needs a question (it may be empty)";
                if ( strpos( $key, 'notes.' ) === 0 ) {
                    $section = substr( $key, 6 );
                    if ( ! isset( $section_keys[ $section ] ) ) $errors[] = "prompt `{$key}` names no section";
                    if ( ( $prompt['author'] ?? '' ) !== 'coach' ) $errors[] = "prompt `{$key}`: section notes are the coach's";
                }
            }
            if ( ! isset( $prompt_keys['strongest_quality'] ) ) $errors[] = 'the strongest-quality prompt is missing';
        }

        $sign_off = $d['sign_off'];
        if ( ! is_array( $sign_off ) || array_keys( $sign_off ) !== [ 'player', 'coach', 'parent' ] ) {
            $errors[] = 'sign_off must name the player, coach and parent, in that order';
        }

        return $errors;
    }

    /**
     * Every item of a definition with its section and main, in form order.
     *
     * @param array<string,mixed> $definition
     * @return list<array{key:string, label:string, definition:string, section:string, main:string}>
     */
    public static function items( array $definition ): array {
        $out = [];
        foreach ( (array) $definition['sections'] as $section ) {
            foreach ( (array) $section['items'] as $item ) {
                $out[] = [
                    'key'        => (string) $item['key'],
                    'label'      => (string) $item['label'],
                    'definition' => (string) $item['definition'],
                    'section'    => (string) $section['key'],
                    'main'       => (string) $section['main'],
                ];
            }
        }
        return $out;
    }

    /**
     * The evaluation category key of one item of a definition.
     *
     * @param array<string,mixed> $definition
     */
    public static function categoryKey( array $definition, string $item_key ): string {
        return (string) $definition['category_prefix'] . '_' . $item_key;
    }

    /** @param mixed $v */
    private static function isKey( $v ): bool {
        return is_string( $v ) && (bool) preg_match( '/^[a-z0-9_]+$/', $v );
    }

    /** @param mixed $v */
    private static function isText( $v ): bool {
        return is_string( $v ) && trim( $v ) !== '';
    }
}
