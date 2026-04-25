<?php
namespace TT\Modules\Backup\Destinations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StoreResult — return value from a destination's store() call.
 *
 * Intentionally minimal: success boolean, an opaque destination-scoped
 * id used to look the file up later, and an optional error string for
 * failure cases. Each destination decides what `id` means (LocalDestination
 * uses a filename; EmailDestination uses the WP mail message id when
 * available, falling back to a deterministic hash).
 */
final class StoreResult {

    public bool   $ok;
    public string $id;
    public string $error;
    /** @var array<string,mixed> */
    public array  $meta;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct( bool $ok, string $id = '', string $error = '', array $meta = [] ) {
        $this->ok    = $ok;
        $this->id    = $id;
        $this->error = $error;
        $this->meta  = $meta;
    }

    /** @param array<string,mixed> $meta */
    public static function success( string $id, array $meta = [] ): self {
        return new self( true, $id, '', $meta );
    }

    public static function failure( string $error ): self {
        return new self( false, '', $error );
    }
}
