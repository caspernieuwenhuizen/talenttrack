<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — first / last / DOB / current_club.
 *
 * Mirrors the LogProspectForm identity fields. Duplicate detection
 * runs at validate time via `ProspectsRepository::findDuplicateCandidates()`
 * — same logic the legacy form used. If matches exist and the user
 * hasn't ticked the override, validation fails with the candidate
 * names listed.
 */
final class IdentityStep implements WizardStepInterface {

    public function slug(): string { return 'identity'; }
    public function label(): string { return __( 'Identity', 'talenttrack' ); }

    public function render( array $state ): void {
        $first  = (string) ( $state['first_name']    ?? '' );
        $last   = (string) ( $state['last_name']     ?? '' );
        $dob    = (string) ( $state['date_of_birth'] ?? '' );
        $club   = (string) ( $state['current_club']  ?? '' );
        ?>
        <label>
            <span><?php esc_html_e( 'First name', 'talenttrack' ); ?> *</span>
            <input type="text" name="first_name" value="<?php echo esc_attr( $first ); ?>" required autocomplete="given-name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Last name', 'talenttrack' ); ?> *</span>
            <input type="text" name="last_name" value="<?php echo esc_attr( $last ); ?>" required autocomplete="family-name" />
        </label>
        <label>
            <span><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></span>
            <input type="date" name="date_of_birth" value="<?php echo esc_attr( $dob ); ?>" />
        </label>
        <label>
            <span><?php esc_html_e( 'Current club', 'talenttrack' ); ?></span>
            <input type="text" name="current_club" value="<?php echo esc_attr( $club ); ?>" />
        </label>
        <?php self::renderExistingProspectsList(); ?>
        <label style="display:block; margin-top:12px; min-height:48px; padding:12px 0;">
            <input type="checkbox" name="duplicate_override" value="1" <?php checked( ! empty( $state['duplicate_override'] ) ); ?> />
            <?php esc_html_e( 'I have checked the existing prospects list — this is a new entry', 'talenttrack' ); ?>
        </label>
        <?php
    }

    /**
     * Render a collapsible list of existing (non-archived) prospects so
     * the scout can verify they're not creating a duplicate before
     * ticking the override checkbox (v3.110.98).
     *
     * Mobile-first per CLAUDE.md §2: `<summary>` is a 48px-min touch
     * target; the table sits inside `.tt-table-wrap` so it scrolls
     * horizontally at 360px without breaking layout. Sorted by
     * last+first so a manual scan lands on the right name fast.
     * Capped at 200 rows — past that this inline pattern stops being
     * useful and we'd build a dedicated search view.
     */
    private static function renderExistingProspectsList(): void {
        $repo = new ProspectsRepository();
        $rows = $repo->search( [
            'include_archived' => false,
            'limit'            => 200,
        ] );
        if ( empty( $rows ) ) return;

        usort( $rows, static function ( $a, $b ): int {
            $la = strtolower( (string) ( $a->last_name  ?? '' ) );
            $lb = strtolower( (string) ( $b->last_name  ?? '' ) );
            if ( $la !== $lb ) return $la <=> $lb;
            return strtolower( (string) ( $a->first_name ?? '' ) )
               <=> strtolower( (string) ( $b->first_name ?? '' ) );
        } );

        $count = count( $rows );
        ?>
        <details class="tt-prospect-dedupe" style="margin: var(--tt-sp-3, 16px) 0; border:1px solid var(--tt-line, #e5e7ea); border-radius:8px; background:#fafbfc;">
            <summary style="cursor:pointer; padding:14px 16px; min-height:48px; font-weight:600;">
                <?php
                printf(
                    /* translators: %d: number of existing non-archived prospects in the club. */
                    esc_html( _n( 'Show existing prospects (%d)', 'Show existing prospects (%d)', $count, 'talenttrack' ) ),
                    (int) $count
                );
                ?>
            </summary>
            <div class="tt-table-wrap" style="padding:0 12px 12px;">
                <table class="tt-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'First name', 'talenttrack' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Last name',  'talenttrack' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Club',       'talenttrack' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status',     'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $r->first_name   ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $r->last_name    ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $r->current_club ?? '' ) ); ?></td>
                            <td><?php echo esc_html( self::statusLabel( $r ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
    }

    private static function statusLabel( object $r ): string {
        if ( ! empty( $r->promoted_to_trial_case_id ) )  return __( 'In trial', 'talenttrack' );
        if ( ! empty( $r->promoted_to_player_id ) )      return __( 'Joined',   'talenttrack' );
        return __( 'Active', 'talenttrack' );
    }

    public function validate( array $post, array $state ) {
        $first  = isset( $post['first_name'] )    ? sanitize_text_field( (string) $post['first_name'] )    : '';
        $last   = isset( $post['last_name']  )    ? sanitize_text_field( (string) $post['last_name']  )    : '';
        $dob    = isset( $post['date_of_birth'] ) ? trim( (string) $post['date_of_birth'] )                : '';
        $club   = isset( $post['current_club']  ) ? sanitize_text_field( (string) $post['current_club']  ) : '';
        $override = ! empty( $post['duplicate_override'] );

        if ( $first === '' || $last === '' ) {
            return new \WP_Error( 'missing_name', __( 'First and last name are required.', 'talenttrack' ) );
        }
        if ( $dob !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
            return new \WP_Error( 'bad_dob', __( 'Use YYYY-MM-DD for the date of birth.', 'talenttrack' ) );
        }

        if ( ! $override ) {
            $repo = new ProspectsRepository();
            $candidates = $repo->findDuplicateCandidates( $first, $last, null, $club ?: null );
            if ( ! empty( $candidates ) ) {
                $names = array_filter( array_map(
                    static fn ( $c ) => trim( ( $c->first_name ?? '' ) . ' ' . ( $c->last_name ?? '' ) ),
                    array_slice( $candidates, 0, 5 )
                ) );
                return new \WP_Error( 'duplicate_candidates', sprintf(
                    /* translators: %s: comma-separated list of likely-duplicate prospect names. */
                    __( 'A prospect with this name already exists (%s). Tick "this is a new entry" if you have already checked.', 'talenttrack' ),
                    implode( ', ', $names )
                ) );
            }
        }

        return [
            'first_name'         => $first,
            'last_name'          => $last,
            'date_of_birth'      => $dob,
            'current_club'       => $club,
            'duplicate_override' => $override ? 1 : 0,
        ];
    }

    public function nextStep( array $state ): ?string { return 'discovery'; }
    public function submit( array $state ) { return null; }
}
