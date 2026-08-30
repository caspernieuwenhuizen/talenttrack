<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ProfileDiff (#3037) — renders what applying an install profile would
 * change, grouped into what goes on, what goes off, and what cannot be
 * applied at all.
 *
 * A component rather than a private method on the preview view, because
 * the preview is not the only thing that shows a diff: the release-time
 * drift notice opens the same screen with a pre-filtered row set, and a
 * second copy of this markup would be a second set of touch targets and
 * a second keyboard order to keep in step.
 *
 * Pure presentation. It is handed rows by `ProfileService::diff()` and
 * decides nothing about them — including whether a row can be applied,
 * which arrives as `skipped_reason`.
 */
final class ProfileDiff {

    /**
     * @param list<array{kind:string, id:string, key:string, label:string, from:bool, to:bool, skipped_reason:?string}> $rows
     * @param array{selectable?:bool, heading_level?:string} $opts
     *        `selectable` false renders every group as read-only text —
     *        for a surface that shows what a choice would do without
     *        offering to exclude rows from it (#3259's Setup step, where
     *        the whole profile is the unit and picking rows out of it is
     *        the preview screen's job). `heading_level` lets a caller that
     *        nests this under its own heading keep the document outline
     *        honest.
     */
    public static function render( array $rows, array $opts = [] ): void {
        $pickable = ! isset( $opts['selectable'] ) || (bool) $opts['selectable'];
        $tag      = isset( $opts['heading_level'] ) ? (string) $opts['heading_level'] : 'h2';
        if ( ! in_array( $tag, [ 'h2', 'h3', 'h4' ], true ) ) $tag = 'h2';

        $on      = [];
        $off     = [];
        $skipped = [];

        foreach ( $rows as $row ) {
            if ( $row['skipped_reason'] !== null ) {
                $skipped[] = $row;
                continue;
            }
            if ( $row['to'] ) {
                $on[] = $row;
            } else {
                $off[] = $row;
            }
        }

        echo '<div class="tt-profile-diff">';

        self::group(
            'on',
            __( 'Will be switched on', 'talenttrack' ),
            $on,
            $pickable,
            $tag
        );
        self::group(
            'off',
            __( 'Will be switched off', 'talenttrack' ),
            $off,
            $pickable,
            $tag
        );
        // Rendered as read-only text rather than an unticked checkbox:
        // these are not a choice the operator has, and a control that
        // cannot be operated is worse than a sentence.
        self::group(
            'skipped',
            __( 'Cannot be applied', 'talenttrack' ),
            $skipped,
            false,
            $tag
        );

        echo '</div>';
    }

    /**
     * @param list<array{kind:string, id:string, key:string, label:string, from:bool, to:bool, skipped_reason:?string}> $rows
     */
    private static function group( string $key, string $title, array $rows, bool $selectable, string $tag = 'h2' ): void {
        if ( $rows === [] ) return;

        // Unique per group AND per call site: a page that renders the diff
        // for two profiles at once (#3259's Setup step) would otherwise
        // repeat an id and point every `aria-labelledby` at the first one.
        static $seq = 0;
        $heading_id = 'tt-profile-diff-' . $key . '-' . ( ++$seq );
        ?>
        <section class="tt-profile-diff__group tt-profile-diff__group--<?php echo esc_attr( $key ); ?>"
                 aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
            <<?php echo esc_html( $tag ); ?> class="tt-profile-diff__title" id="<?php echo esc_attr( $heading_id ); ?>">
                <?php echo esc_html( $title ); ?>
                <span class="tt-profile-diff__count"><?php echo esc_html( (string) count( $rows ) ); ?></span>
            </<?php echo esc_html( $tag ); ?>>
            <ul class="tt-profile-diff__list">
                <?php foreach ( $rows as $row ) : ?>
                    <li class="tt-profile-diff__row">
                        <?php if ( $selectable ) : ?>
                            <label class="tt-profile-diff__pick">
                                <input type="checkbox"
                                       class="tt-profile-diff__check"
                                       name="tt_apply[]"
                                       value="<?php echo esc_attr( $row['id'] ); ?>"
                                       checked />
                                <span class="tt-profile-diff__name"><?php echo esc_html( $row['label'] ); ?></span>
                                <span class="tt-profile-diff__kind"><?php echo esc_html( self::kindLabel( $row['kind'] ) ); ?></span>
                            </label>
                        <?php else : ?>
                            <div class="tt-profile-diff__pick tt-profile-diff__pick--static">
                                <span class="tt-profile-diff__name"><?php echo esc_html( $row['label'] ); ?></span>
                                <span class="tt-profile-diff__kind"><?php echo esc_html( self::kindLabel( $row['kind'] ) ); ?></span>
                                <span class="tt-profile-diff__reason">
                                    <?php echo esc_html( self::reasonLabel( (string) $row['skipped_reason'] ) ); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private static function kindLabel( string $kind ): string {
        return $kind === 'module'
            ? __( 'Module', 'talenttrack' )
            : __( 'Feature', 'talenttrack' );
    }

    private static function reasonLabel( string $reason ): string {
        if ( $reason === 'tier' ) {
            return __( 'Not part of your plan', 'talenttrack' );
        }
        return __( 'Unavailable on this install', 'talenttrack' );
    }
}
