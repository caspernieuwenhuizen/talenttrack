<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RenderContext — passed into every widget render() call.
 *
 *   user_id       — the current viewer.
 *   club_id       — current club resolved via CurrentClub (#0052).
 *   persona_slug  — the persona this render is for.
 *   base_url      — dashboard URL with TT-specific args stripped, used
 *                   to compose tt_view= links inside widgets.
 *   is_preview    — sprint 2 sets true so widgets render with placeholder
 *                   cues + drag handles enabled. Sprint 1 always false.
 */
final class RenderContext {

    public int $user_id;
    public int $club_id;
    public string $persona_slug;
    public string $base_url;
    public bool $is_preview;

    public function __construct(
        int $user_id,
        int $club_id,
        string $persona_slug,
        string $base_url,
        bool $is_preview = false
    ) {
        $this->user_id      = $user_id;
        $this->club_id      = $club_id;
        $this->persona_slug = $persona_slug;
        $this->base_url     = $base_url;
        $this->is_preview   = $is_preview;
    }

    public function viewUrl( string $view_slug ): string {
        return add_query_arg( 'tt_view', $view_slug, $this->base_url );
    }
}
