<?php
/**
 * Migration 0019 — Methodology seed asset registration (#0027 follow-up).
 *
 * For every shipped methodology entity created by migration 0018,
 * register the matching PDF page (already in `assets/methodology/seed/`
 * thanks to the previous commit) as a WordPress attachment and create
 * a `tt_methodology_assets` row linking the entity to the attachment.
 *
 * Mechanics:
 *   1. Copy `<plugin>/assets/methodology/seed/page-NN.png` to
 *      `<uploads>/talenttrack-methodology/page-NN.png`.
 *   2. `wp_insert_attachment` the copy with a sentinel postmeta key
 *      `_tt_methodology_seed_page = NN` so the migration is idempotent
 *      and reruns don't duplicate.
 *   3. Insert a row in `tt_methodology_assets` matching the entity
 *      type/id, marked `is_primary = 1` and `is_shipped = 1`.
 *
 * Casper can replace any individual seed image later via the admin
 * picker. Setting a different image as primary just demotes the
 * shipped one — the seed row stays around as an alternative or can be
 * archived through the asset list.
 *
 * The page → entity map below is the authoring lookup driving this
 * migration. Pages without a clean 1:1 entity (e.g. PDF cover, table
 * of contents, intro slides) attach to the framework primer.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const SEED_REL = 'assets/methodology/seed';
    private const UPLOAD_SUBDIR = 'talenttrack-methodology';
    private const META_KEY = '_tt_methodology_seed_page';

    public function getName(): string {
        return '0019_methodology_seed_assets';
    }

    public function up(): void {
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $this->ensureUploadDir();
        foreach ( $this->mapping() as $entry ) {
            $this->seedAsset( $entry );
        }
    }

    /** @return array<int, array{page:int, entity_type:string, entity_key:array<string,mixed>, caption?:array<string,string>}> */
    private function mapping(): array {
        return [
            // Framework primer sections — the pages that carry the
            // overall framework graphics. Every primer asset gets the
            // primer id resolved at runtime; entity_key=primer marks it.
            [ 'page' => 1,  'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'cover' ],
              'caption' => [ 'nl' => 'Het spelen van voetbal — omslag', 'en' => 'Playing football — cover' ] ],
            [ 'page' => 3,  'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'voetbalmodel' ],
              'caption' => [ 'nl' => 'Voetbalmodel — spelbedoeling, teamfuncties, teamtaken, voetbalhandelingen', 'en' => 'Football model — game intent, team functions, team tasks, football actions' ] ],
            [ 'page' => 5,  'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'phases' ],
              'caption' => [ 'nl' => 'Vier fasen van aanvallen en verdedigen', 'en' => 'Four phases of attacking and defending' ] ],
            [ 'page' => 6,  'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'learning_goals_attacking' ],
              'caption' => [ 'nl' => 'Leerdoelen — aanvallen', 'en' => 'Learning goals — attacking' ] ],
            [ 'page' => 7,  'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'learning_goals_defending' ],
              'caption' => [ 'nl' => 'Leerdoelen — verdedigen', 'en' => 'Learning goals — defending' ] ],
            [ 'page' => 8,  'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'spelprincipes' ],
              'caption' => [ 'nl' => 'Spelprincipes per teamfunctie', 'en' => 'Game principles per team function' ] ],
            [ 'page' => 60, 'entity_type' => 'framework', 'entity_key' => [ 'primer' => 'influence_factors' ],
              'caption' => [ 'nl' => 'Factoren van invloed', 'en' => 'Influence factors' ] ],

            // Vision page from the PDF.
            [ 'page' => 4,  'entity_type' => 'vision', 'entity_key' => [ 'club_scope' => null ],
              'caption' => [ 'nl' => 'Visie op hoofdlijnen — formatie 1:4:2:3:1', 'en' => 'Vision — formation 1:4:2:3:1' ] ],

            // Principles — one PDF page per principle (PDF p11–41).
            [ 'page' => 11, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AO-01' ] ],
            [ 'page' => 12, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AO-02' ] ],
            [ 'page' => 14, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AO-03' ] ],
            [ 'page' => 15, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AO-04' ] ],
            [ 'page' => 16, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AO-05' ] ],
            [ 'page' => 18, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AS-01' ] ],
            [ 'page' => 19, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'AS-02' ] ],
            [ 'page' => 22, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'OV-01' ] ],
            [ 'page' => 23, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'OV-02' ] ],
            [ 'page' => 24, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'OV-03' ] ],
            [ 'page' => 27, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VS-01' ] ],
            [ 'page' => 28, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VS-02' ] ],
            [ 'page' => 29, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VS-03' ] ],
            [ 'page' => 30, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VS-04' ] ],
            [ 'page' => 32, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VS-05' ] ],
            [ 'page' => 34, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VV-01' ] ],
            [ 'page' => 35, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VV-02' ] ],
            [ 'page' => 36, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'VV-03' ] ],
            [ 'page' => 39, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'OA-01' ] ],
            [ 'page' => 40, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'OA-02' ] ],
            [ 'page' => 41, 'entity_type' => 'principle', 'entity_key' => [ 'code' => 'OA-03' ] ],

            // Set pieces (PDF p44–51).
            [ 'page' => 44, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'corner-attacking-far-post' ] ],
            [ 'page' => 45, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'free-kick-direct-attacking' ] ],
            [ 'page' => 46, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'penalty-attacking' ] ],
            [ 'page' => 47, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'free-kick-pass-attacking' ] ],
            [ 'page' => 48, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'corner-defending' ] ],
            [ 'page' => 49, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'free-kick-direct-defending' ] ],
            [ 'page' => 50, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'penalty-defending' ] ],
            [ 'page' => 51, 'entity_type' => 'set_piece', 'entity_key' => [ 'slug' => 'free-kick-pass-defending' ] ],

            // Position cards (PDF p53–59). The PDF describes the role
            // type once and applies it to multiple jersey numbers; we
            // reuse the same diagram across paired positions.
            [ 'page' => 53, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 1  ] ],
            [ 'page' => 54, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 2  ] ],
            [ 'page' => 54, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 5  ] ],
            [ 'page' => 55, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 3  ] ],
            [ 'page' => 55, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 4  ] ],
            [ 'page' => 56, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 6  ] ],
            [ 'page' => 56, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 8  ] ],
            [ 'page' => 57, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 7  ] ],
            [ 'page' => 57, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 11 ] ],
            [ 'page' => 58, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 10 ] ],
            [ 'page' => 59, 'entity_type' => 'position', 'entity_key' => [ 'jersey_number' => 9  ] ],

            // Influence factors (PDF p61–66).
            [ 'page' => 61, 'entity_type' => 'influence_factor', 'entity_key' => [ 'slug' => 'eigen-visie' ] ],
            [ 'page' => 62, 'entity_type' => 'influence_factor', 'entity_key' => [ 'slug' => 'spelers' ] ],
            [ 'page' => 63, 'entity_type' => 'influence_factor', 'entity_key' => [ 'slug' => 'staf' ] ],
            [ 'page' => 64, 'entity_type' => 'influence_factor', 'entity_key' => [ 'slug' => 'teamdynamiek' ] ],
            [ 'page' => 65, 'entity_type' => 'influence_factor', 'entity_key' => [ 'slug' => 'speelniveau' ] ],
            [ 'page' => 66, 'entity_type' => 'influence_factor', 'entity_key' => [ 'slug' => 'ondersteuning' ] ],
        ];
    }

    private function seedAsset( array $entry ): void {
        global $wpdb;
        $page = (int) $entry['page'];
        $entity_id = $this->resolveEntityId( $entry['entity_type'], $entry['entity_key'] );
        if ( $entity_id <= 0 ) return;

        $attachment_id = $this->ensureAttachment( $page );
        if ( $attachment_id <= 0 ) return;

        // Idempotency: don't re-link if already linked.
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_methodology_assets
             WHERE entity_type = %s AND entity_id = %d AND attachment_id = %d LIMIT 1",
            $entry['entity_type'], $entity_id, $attachment_id
        ) );
        if ( $existing > 0 ) return;

        $caption = $entry['caption'] ?? null;
        $wpdb->insert( "{$wpdb->prefix}tt_methodology_assets", [
            'entity_type'   => $entry['entity_type'],
            'entity_id'     => $entity_id,
            'attachment_id' => $attachment_id,
            'caption_json'  => $caption ? wp_json_encode( $caption ) : null,
            'sort_order'    => 0,
            'is_primary'    => 1,
            'is_shipped'    => 1,
        ] );
    }

    /** @param array<string,mixed> $key */
    private function resolveEntityId( string $entity_type, array $key ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        switch ( $entity_type ) {
            case 'framework':
                return (int) $wpdb->get_var(
                    "SELECT id FROM {$p}tt_methodology_framework_primers
                     WHERE is_shipped = 1 AND club_scope IS NULL LIMIT 1"
                );
            case 'vision':
                return (int) $wpdb->get_var(
                    "SELECT id FROM {$p}tt_methodology_visions
                     WHERE is_shipped = 1 AND club_scope IS NULL LIMIT 1"
                );
            case 'principle':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_principles WHERE code = %s AND is_shipped = 1 LIMIT 1",
                    (string) $key['code']
                ) );
            case 'set_piece':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_set_pieces WHERE slug = %s AND is_shipped = 1 LIMIT 1",
                    (string) $key['slug']
                ) );
            case 'position':
                $formation_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_formations WHERE slug = %s LIMIT 1", '1-4-2-3-1'
                ) );
                if ( $formation_id <= 0 ) return 0;
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_formation_positions
                     WHERE formation_id = %d AND jersey_number = %d AND is_shipped = 1 LIMIT 1",
                    $formation_id, (int) $key['jersey_number']
                ) );
            case 'influence_factor':
                $primer_id = (int) $wpdb->get_var(
                    "SELECT id FROM {$p}tt_methodology_framework_primers
                     WHERE is_shipped = 1 AND club_scope IS NULL LIMIT 1"
                );
                if ( $primer_id <= 0 ) return 0;
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}tt_methodology_influence_factors
                     WHERE primer_id = %d AND slug = %s LIMIT 1",
                    $primer_id, (string) $key['slug']
                ) );
        }
        return 0;
    }

    private function ensureAttachment( int $page ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_KEY, (string) $page
        ) );
        if ( $existing > 0 ) return $existing;

        $source = TT_PLUGIN_DIR . self::SEED_REL . '/' . sprintf( 'page-%02d.png', $page );
        if ( ! file_exists( $source ) ) return 0;

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) return 0;

        $dest_dir  = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
        $dest_url  = trailingslashit( $upload_dir['baseurl'] ) . self::UPLOAD_SUBDIR;
        if ( ! is_dir( $dest_dir ) ) wp_mkdir_p( $dest_dir );

        $filename = sprintf( 'page-%02d.png', $page );
        $dest_path = $dest_dir . '/' . $filename;
        if ( ! file_exists( $dest_path ) ) {
            if ( ! @copy( $source, $dest_path ) ) return 0;
        }

        $attachment = [
            'guid'           => $dest_url . '/' . $filename,
            'post_mime_type' => 'image/png',
            'post_title'     => sprintf( 'Voetbalmethodiek p%02d', $page ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment( $attachment, $dest_path );
        if ( is_wp_error( $attach_id ) || $attach_id <= 0 ) return 0;

        if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
            $metadata = wp_generate_attachment_metadata( $attach_id, $dest_path );
            wp_update_attachment_metadata( $attach_id, $metadata );
        }
        update_post_meta( $attach_id, self::META_KEY, (string) $page );
        update_post_meta( $attach_id, '_tt_methodology_source', 'voetbalmethode-pdf' );
        return (int) $attach_id;
    }

    private function ensureUploadDir(): void {
        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) return;
        $dir = trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_SUBDIR;
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
    }
};
