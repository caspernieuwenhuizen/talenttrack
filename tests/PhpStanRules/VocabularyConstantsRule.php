<?php
/**
 * VocabularyConstantsRule — flags raw string-literal comparisons against
 * values that are already enumerated under `TT\Domain\Vocabularies\*`.
 *
 * Rationale (per umbrella issue #988):
 *
 *   if ( $row->status === 'present' ) { ... }
 *
 * silently breaks the moment the canonical vocabulary value is renamed,
 * and is invisible to IDE refactor / rename tooling. The same comparison
 * written against the typed constant is statically checked, IDE-renameable
 * and self-documenting:
 *
 *   if ( $row->status === AttendanceStatus::PRESENT ) { ... }
 *
 * What the rule walks:
 *
 *   - Strict / loose equality and inequality: `=== !== == !=`. These are
 *     the BinaryOp node families that PHPStan visits.
 *   - `in_array( $value, [ 'present', 'late' ], true )` — the most common
 *     allowlist shape in the codebase.
 *
 * What the rule does NOT walk (deliberately):
 *
 *   - Switch / match expressions — PHPStan visits the `case` arms as
 *     `Stmt\Case_` nodes; walking them is straightforward but reserved
 *     for a later iteration once the rule has burned in.
 *   - SQL-string literals (`WHERE status='present'`). The DB is the source
 *     of truth for the stored value; the literal there is canonical, not
 *     a comparison-against-canonical.
 *   - Array keys (`[ 'present' => ... ]`). The key IS the canonical value
 *     in many lookup-label maps; rewriting it to `AttendanceStatus::PRESENT
 *     => ...` is correct but is a separate refactor.
 *   - Default-parameter literals (`function ( string $status = 'manual' )`).
 *     Reachable later via `Param` node walk; out of scope for v1.
 *
 * Sub-namespace coverage:
 *
 *   - `TT\Domain\Vocabularies\Lookups\*` — operator-editable vocabularies
 *     backed by `tt_lookups`.
 *   - `TT\Domain\Vocabularies\Enums\*` — code-only, stable enums.
 *
 * Both sub-namespaces are loaded; the rule does not differentiate at the
 * error-message level.
 *
 * Per #988's locked decisions (2026-05-28), this rule is shipped as
 * infrastructure but disabled by default — the backwards-compat allowlist
 * keeps the raw literals legal until the one-release deprecation window
 * closes. Operators wire it on via:
 *
 *   includes:
 *       - tests/PhpStanRules/vocabulary-constants-rule.neon
 *
 * in their own `phpstan.neon` overlay. See PR-set 8 PR body + #988 for the
 * roll-out timeline.
 */

declare(strict_types=1);

namespace TT\Tests\PhpStanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node>
 */
final class VocabularyConstantsRule implements Rule {

    /**
     * Map of canonical string value -> list of `Class::CONST` suggestions.
     *
     * Populated lazily on first node visit by scanning the vocabulary
     * directory via the autoloader + reflection. We don't hard-code the
     * map here because new vocabularies land in their own PR-sets and we
     * want the rule to pick them up automatically.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $index = null;

    /**
     * Absolute path to the project root. Set by the constructor so the
     * vocabulary scanner can walk `src/Domain/Vocabularies/*`.
     */
    private string $projectRoot;

    public function __construct( string $projectRoot ) {
        $this->projectRoot = rtrim( $projectRoot, "/\\" );
    }

    public function getNodeType(): string {
        return Node::class;
    }

    /**
     * @param Node $node
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode( Node $node, Scope $scope ): array {
        $this->ensureIndex();

        if ( $node instanceof BinaryOp\Identical
            || $node instanceof BinaryOp\NotIdentical
            || $node instanceof BinaryOp\Equal
            || $node instanceof BinaryOp\NotEqual
        ) {
            return $this->processEquality( $node );
        }

        if ( $node instanceof FuncCall ) {
            return $this->processInArray( $node );
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processEquality( BinaryOp $node ): array {
        $errors = [];

        foreach ( [ $node->left, $node->right ] as $operand ) {
            if ( $operand instanceof String_ ) {
                $error = $this->errorFor( $operand->value );
                if ( $error !== null ) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function processInArray( FuncCall $node ): array {
        if ( ! ( $node->name instanceof Name ) ) {
            return [];
        }
        if ( strtolower( $node->name->toString() ) !== 'in_array' ) {
            return [];
        }
        // in_array( $needle, $haystack, $strict ) — we want the haystack
        // (arg 1). If it's an inline array literal of strings, walk each
        // entry.
        if ( ! isset( $node->args[1] ) ) {
            return [];
        }
        $haystack = $node->args[1]->value ?? null;
        if ( ! ( $haystack instanceof Node\Expr\Array_ ) ) {
            return [];
        }

        $errors = [];
        foreach ( $haystack->items as $item ) {
            if ( $item === null ) {
                continue;
            }
            $value = $item->value;
            if ( $value instanceof String_ ) {
                $error = $this->errorFor( $value->value );
                if ( $error !== null ) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * Returns a RuleError if `$literal` matches a known vocabulary value;
     * NULL otherwise.
     *
     * @return \PHPStan\Rules\RuleError|null
     */
    private function errorFor( string $literal ) {
        if ( ! isset( $this->index[ $literal ] ) ) {
            return null;
        }

        $suggestions = $this->index[ $literal ];
        $hint = count( $suggestions ) === 1
            ? $suggestions[0]
            : implode( ' or ', $suggestions );

        return RuleErrorBuilder::message(
            sprintf(
                'String literal %s matches a TalentTrack vocabulary value. Use the typed constant %s instead (umbrella issue #988).',
                var_export( $literal, true ),
                $hint
            )
        )
        ->identifier( 'talenttrack.vocabularyConstants' )
        ->tip( 'See src/Domain/Vocabularies/{Lookups,Enums}/ for the canonical class. SQL string literals, array keys, and migration-seed values are out of scope and may be suppressed locally if the rule is too aggressive on a given line.' )
        ->build();
    }

    /**
     * Populate `$this->index` on first node visit by scanning the
     * vocabulary directory. Uses reflection so the lookup is cheap on
     * subsequent calls (`isset()` on a flat associative array).
     */
    private function ensureIndex(): void {
        if ( $this->index !== null ) {
            return;
        }
        $this->index = [];

        foreach ( [ 'Lookups', 'Enums' ] as $subnamespace ) {
            $dir = $this->projectRoot . DIRECTORY_SEPARATOR
                . 'src' . DIRECTORY_SEPARATOR
                . 'Domain' . DIRECTORY_SEPARATOR
                . 'Vocabularies' . DIRECTORY_SEPARATOR
                . $subnamespace;

            if ( ! is_dir( $dir ) ) {
                continue;
            }

            $entries = scandir( $dir );
            if ( $entries === false ) {
                continue;
            }

            foreach ( $entries as $entry ) {
                if ( substr( $entry, -4 ) !== '.php' ) {
                    continue;
                }
                $className = substr( $entry, 0, -4 );
                $fqcn = "TT\\Domain\\Vocabularies\\{$subnamespace}\\{$className}";

                if ( ! class_exists( $fqcn ) ) {
                    // Class may not be autoloaded in some PHPStan setups;
                    // include the file directly as a last-resort fallback.
                    require_once $dir . DIRECTORY_SEPARATOR . $entry;
                    if ( ! class_exists( $fqcn ) ) {
                        continue;
                    }
                }

                try {
                    $reflection = new \ReflectionClass( $fqcn );
                } catch ( \ReflectionException $e ) {
                    continue;
                }

                foreach ( $reflection->getReflectionConstants() as $constant ) {
                    $value = $constant->getValue();
                    if ( ! is_string( $value ) ) {
                        continue;
                    }
                    $suggestion = "{$className}::{$constant->getName()}";
                    if ( ! isset( $this->index[ $value ] ) ) {
                        $this->index[ $value ] = [];
                    }
                    if ( ! in_array( $suggestion, $this->index[ $value ], true ) ) {
                        $this->index[ $value ][] = $suggestion;
                    }
                }
            }
        }
    }
}
