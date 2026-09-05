<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Polidog\Tehilim\Driver\Driver;
use RuntimeException;

/**
 * Column type tags accepted by `BaseClient::queryRaw(..., shape: [...])`.
 *
 * One tag drives both sides of a raw query: at runtime the value is cast
 * through {@see Driver::cast()} with the same tag the generated clients use
 * for schema columns, and at analysis time the bundled PHPStan extension
 * turns the literal shape into an `array{...}` return type. Prefix a tag
 * with `?` to mark the column nullable (`'?int'`); nullability is a static
 * concern only — NULL always passes through the cast untouched.
 */
final class RawShape
{
    /** tag => PHPDoc type the PHPStan extension resolves the column to */
    public const array TYPES = [
        'int' => 'int',
        'BigInt' => 'int',
        'float' => 'float',
        'bool' => 'bool',
        'string' => 'string',
        'bytes' => 'string',
        'DateTime' => DateTimeImmutable::class,
        'json' => 'mixed',
        'mixed' => 'mixed',
    ];

    /**
     * Split a tag into its base tag and nullability. Returns null when the
     * base tag is not one of {@see TYPES}.
     *
     * @return null|array{0: string, 1: bool}
     */
    public static function parseTag(string $tag): ?array
    {
        $nullable = str_starts_with($tag, '?');
        $base = $nullable ? substr($tag, 1) : $tag;
        if (!array_key_exists($base, self::TYPES)) {
            return null;
        }

        return [$base, $nullable];
    }

    public static function isKnown(string $tag): bool
    {
        return self::parseTag($tag) !== null;
    }

    /**
     * PHPDoc type string for a tag, e.g. `'?int'` → `'int|null'`.
     *
     * @throws InvalidArgumentException for unknown tags
     */
    public static function phpDocType(string $tag): string
    {
        $parsed = self::parseTag($tag)
            ?? throw new InvalidArgumentException(self::unknownTagMessage($tag));
        [$base, $nullable] = $parsed;
        $type = self::TYPES[$base];
        if ($type === 'mixed' || !$nullable) {
            return $type;
        }

        return $type . '|null';
    }

    /** Comma-separated list of every base tag, for error messages and tips. */
    public static function knownTags(): string
    {
        return implode(', ', array_keys(self::TYPES));
    }

    public static function unknownTagMessage(string $tag, ?string $column = null): string
    {
        $for = $column === null ? '' : " for column '{$column}'";

        return sprintf(
            "Unknown tehilim raw type tag '%s'%s. Known tags: %s (prefix with ? for nullable).",
            $tag,
            $for,
            self::knownTags(),
        );
    }

    /**
     * @param array<string,string> $shape
     *
     * @throws InvalidArgumentException on the first unknown tag
     */
    public static function validate(array $shape): void
    {
        foreach ($shape as $column => $tag) {
            if (!self::isKnown($tag)) {
                throw new InvalidArgumentException(self::unknownTagMessage($tag, $column));
            }
        }
    }

    /**
     * Reduce a fetched row to exactly the shape's columns, casting each one.
     * A shape column missing from the result set is a programming error
     * (typo in the shape, or the SELECT list drifted) and throws.
     *
     * @param array<string,string> $shape
     * @param array<string,mixed>  $row
     *
     * @return array<string,mixed>
     */
    public static function castRow(Driver $driver, array $shape, array $row): array
    {
        $out = [];
        foreach ($shape as $column => $tag) {
            if (!array_key_exists($column, $row)) {
                throw new RuntimeException(sprintf(
                    "queryRaw shape lists column '%s' but the result set has no such column (got: %s).",
                    $column,
                    implode(', ', array_keys($row)),
                ));
            }
            [$base] = self::parseTag($tag)
                ?? throw new InvalidArgumentException(self::unknownTagMessage($tag, $column));
            $out[$column] = $driver->cast($base, $row[$column]);
        }

        return $out;
    }
}
