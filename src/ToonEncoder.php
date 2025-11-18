<?php

namespace Tedon\Tooner;

use DateTimeInterface;
use Tedon\Tooner\Exceptions\ToonEncodingException;

/**
 * TOON (Token-Oriented Object Notation) Encoder
 *
 * Encodes PHP objects, arrays, and primitives into TOON format with support for:
 * - YAML-like indentation-based syntax
 * - Nested objects and arrays
 * - Tabular arrays (uniform object arrays)
 * - Key folding (flattening nested keys with dots)
 * - Explicit array lengths
 * - Type normalization
 */
class ToonEncoder
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * Static convenience method for encoding
     * @throws ToonEncodingException
     */
    public function encode(mixed $value, array $options = []): string
    {
        return $this->mergeConfig($options)->encodeValue($value);
    }

    /**
     * Main encoding method
     * @throws ToonEncodingException
     */
    public function encodeValue(mixed $value, int $depth = 0): string
    {
        if ($depth > $this->options['max_depth']) {
            throw new ToonEncodingException('Maximum encoding depth exceeded');
        }

        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) || is_float($value) => (string) $value,
            is_string($value) => $this->encodeString($value),
            $value instanceof DateTimeInterface => $this->encodeDateTime($value),
            is_array($value) => $this->encodeArray($value, $depth),
            is_object($value) => $this->encodeObject($value, $depth),
            default => throw new ToonEncodingException('Unsupported type: ' . gettype($value)),
        };
    }

    /**
     * Encode string with proper escaping
     */
    private function encodeString(string $value): string
    {
        // Check if string needs quoting
        $needsQuotes = $this->stringNeedsQuotes($value);

        if (!$needsQuotes) {
            return $value;
        }

        // Escape special characters
        $escaped = str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value
        );

        return '"' . $escaped . '"';
    }

    /**
     * Check if a string needs quotes
     */
    private function stringNeedsQuotes(string $value): bool
    {
        if (empty($value)) {
            return true;
        }

        // Needs quotes if contains special characters, spaces, or looks like a number/boolean
        if (preg_match('/[\s,{}\[\]":]/', $value) === 1) {
            return true;
        }

        if (preg_match('/^(true|false|null|\d+(\.\d+)?)$/i', $value) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Encode DateTime objects
     */
    private function encodeDateTime(DateTimeInterface $value): string
    {
        return $this->encodeString($value->format(DateTimeInterface::ATOM));
    }

    /**
     * Encode arrays (indexed or tabular)
     * @throws ToonEncodingException
     */
    private function encodeArray(array $value, int $depth): string
    {
        // Check if array is associative (object-like)
        if (!$this->isIndexedArray($value)) {
            return $this->encodeObject((object) $value, $depth);
        }

        // Check if we can use tabular array format
        if ($this->options['tabular_arrays'] && $this->isTabularArray($value)) {
            return $this->encodeTabularArray($value, $depth);
        }

        // Standard array encoding
        return $this->encodeIndexedArray($value, $depth);
    }

    /**
     * Check if array is indexed (not associative)
     */
    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Check if array is tabular (array of uniform objects)
     */
    private function isTabularArray(array $arr): bool
    {
        if (count($arr) < 2) {
            return false;
        }

        $firstKeys = null;

        foreach ($arr as $item) {
            if (!is_object($item) && !is_array($item)) {
                return false;
            }

            $itemData = is_object($item) ? get_object_vars($item) : $item;

            if (!$this->isIndexedArray($itemData)) {
                $keys = array_keys($itemData);
                sort($keys);

                if ($firstKeys === null) {
                    $firstKeys = $keys;
                } elseif ($keys !== $firstKeys) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $firstKeys !== null;
    }

    /**
     * Encode indexed array (simple array of primitives or complex array)
     * @throws ToonEncodingException
     */
    private function encodeIndexedArray(array $value, int $depth): string
    {
        // If array contains objects/arrays, use complex array format
        if (!$this->allPrimitives($value)) {
            return $this->encodeComplexArray($value, $depth);
        }

        // Simple primitive array - inline format
        $length = count($value);
        $lengthPrefix = $this->options['explicit_lengths'] ? "[$length]" : '';

        $items = [];
        foreach ($value as $item) {
            $items[] = $this->encodeValue($item, $depth + 1);
        }

        // Inline format: array[n]: item1,item2,item3
        return $lengthPrefix . ': ' . implode(',', $items);
    }

    /**
     * Encode tabular array (uniform objects) in YAML-like format
     * Format: arrayName[n]{field1,field2,field3}:
     *  value1,value2,value3
     *  value4,value5,value6
     * @throws ToonEncodingException
     */
    private function encodeTabularArray(array $value, int $depth): string
    {
        $length = count($value);
        $firstItem = is_object($value[0]) ? get_object_vars($value[0]) : $value[0];
        $keys = array_keys($firstItem);

        // Header with field names
        $lengthPrefix = $this->options['explicit_lengths'] ? "[$length]" : '';
        $header = $lengthPrefix . '{' . implode(',', $keys) . '}:';
        $rows = [];

        foreach ($value as $item) {
            $itemData = is_object($item) ? get_object_vars($item) : $item;
            $rowValues = [];

            foreach ($keys as $key) {
                $rowValues[] = $this->encodeValue($itemData[$key] ?? null, $depth + 1);
            }

            $rows[] = implode(',', $rowValues);
        }

        $indent = $this->getIndent($depth + 1);

        return $header . "\n" . $indent . implode("\n" . $indent, $rows);
    }

    /**
     * Encode object
     * Format:
     * key1: value1
     * key2: value2
     * nested:
     *  subkey1: subvalue1
     *  subkey2: subvalue2
     * @throws ToonEncodingException
     */
    private function encodeObject(object $value, int $depth): string
    {
        $properties = $this->getObjectProperties($value);
        if (empty($properties)) {
            return '';
        }

        // Apply key folding if enabled
        if ($this->options['key_folding']) {
            $properties = $this->applyKeyFolding($properties, $depth);
        }

        $pairs = [];
        $indent = $this->getIndent($depth);

        foreach ($properties as $key => $val) {
            $encodedKey = $this->encodeObjectKey($key);

            // Handle arrays
            if (is_array($val) && !empty($val)) {
                if ($this->isIndexedArray($val)) {
                    if ($this->allPrimitives($val)) {
                        // Simple primitive array: key[n]: val1,val2,val3
                        $length = count($val);
                        $lengthPrefix = $this->options['explicit_lengths'] ? "[$length]" : '';
                        $items = array_map(fn($item) => $this->encodeValue($item, $depth + 1), $val);
                        $pairs[] = "$encodedKey$lengthPrefix: " . implode(',', $items);
                    } elseif ($this->options['tabular_arrays'] && $this->isTabularArray($val)) {
                        // Tabular array: key[n]{fields}:
                        $encodedValue = $this->encodeTabularArray($val, $depth);
                        $pairs[] = "$encodedKey$encodedValue";
                    } else {
                        // Non-tabular complex array (array of objects/arrays)
                        $encodedValue = $this->encodeComplexArray($val, $depth);
                        $pairs[] = "$encodedKey$encodedValue";
                    }
                } else {
                    // Associative array (treat as nested object)
                    $nestedPairs = $this->encodeNestedObject($val, $depth + 1);
                    $pairs[] = "$encodedKey:\n$nestedPairs";
                }
            } elseif (is_object($val) && !($val instanceof DateTimeInterface)) {
                // Nested object
                $nestedPairs = $this->encodeObject($val, $depth + 1);
                if ($nestedPairs === '' || $nestedPairs === '{}') {
                    // Empty object
                    $pairs[] = "$encodedKey:";
                } else {
                    $pairs[] = "$encodedKey:\n$nestedPairs";
                }
            } else {
                // Simple value (primitive, empty array, datetime)
                if (is_array($val)) {
                    // Empty array as property
                    $pairs[] = "{$encodedKey}[0]:";
                } else {
                    $encodedValue = $this->encodeValue($val, $depth + 1);
                    $pairs[] = "$encodedKey: $encodedValue";
                }
            }
        }

        return implode("\n" . $indent, $pairs);
    }

    /**
     * Encode complex array (non-tabular array of objects/arrays)
     * Format:
     * key[n]:
     *   - prop1: val1
     *     prop2: val2
     *   - prop3: val3
     * @throws ToonEncodingException
     */
    private function encodeComplexArray(array $value, int $depth): string
    {
        $length = count($value);
        $lengthPrefix = $this->options['explicit_lengths'] ? "[$length]" : '';

        $items = [];
        $indent = $this->getIndent($depth + 1);

        foreach ($value as $item) {
            // Check if item is a primitive array (indexed array of primitives)
            if (is_array($item) && $this->isIndexedArray($item) && $this->allPrimitives($item)) {
                // Inline primitive array: - [n]: val1,val2,val3
                $len = count($item);
                $lenPrefix = $this->options['explicit_lengths'] ? "[$len]" : '';
                $vals = array_map(fn($v) => $this->encodeValue($v, $depth + 1), $item);
                $items[] = "- $lenPrefix: " . implode(',', $vals);
            } elseif (is_array($item) || is_object($item)) {
                $itemProps = $this->getObjectProperties($item);

                // Encode each property
                $propLines = [];
                foreach ($itemProps as $key => $val) {
                    $encodedKey = $this->encodeObjectKey($key);

                    // Check if this property is a nested tabular array
                    if (is_array($val) && !empty($val) && $this->options['tabular_arrays'] && $this->isTabularArray($val)) {
                        $encodedValue = $this->encodeTabularArray($val, $depth + 1);
                        $propLines[] = "$encodedKey$encodedValue";
                    } else if (is_array($val) && !empty($val) && $this->isIndexedArray($val) && $this->allPrimitives($val)) {
                        // Simple array
                        $len = count($val);
                        $lenPrefix = $this->options['explicit_lengths'] ? "[$len]" : '';
                        $vals = array_map(fn($v) => $this->encodeValue($v, $depth + 2), $val);
                        $propLines[] = "$encodedKey$lenPrefix: " . implode(',', $vals);
                    } else if (is_object($val) && !($val instanceof DateTimeInterface)) {
                        // Nested object
                        $nestedObj = $this->encodeObject($val, $depth + 2);
                        if ($nestedObj === '{}') {
                            $propLines[] = "$encodedKey: {}";
                        } else {
                            $propLines[] = "$encodedKey:\n" . $this->getIndent($depth + 2) . str_replace("\n", "\n" . $this->getIndent($depth + 2), $nestedObj);
                        }
                    } else {
                        $encodedValue = $this->encodeValue($val, $depth + 2);
                        $propLines[] = "$encodedKey: $encodedValue";
                    }
                }

                // Join properties with proper indentation, first line gets bullet
                $firstProp = array_shift($propLines);
                $itemStr = "- $firstProp";
                if (!empty($propLines)) {
                    $itemStr .= "\n" . $indent . "  " . implode("\n" . $indent . "  ", $propLines);
                }

                $items[] = $itemStr;
            } else {
                // Primitive value in array
                $items[] = "- " . $this->encodeValue($item, $depth + 1);
            }
        }

        return $lengthPrefix . ":\n" . $indent . implode("\n" . $indent, $items);
    }

    /**
     * Encode nested object properties
     * @throws ToonEncodingException
     */
    private function encodeNestedObject(array $properties, int $depth): string
    {
        // Apply key folding if enabled
        if ($this->options['key_folding']) {
            $properties = $this->applyKeyFolding($properties, $depth);
        }

        $pairs = [];
        $indent = $this->getIndent($depth);

        foreach ($properties as $key => $val) {
            $encodedKey = $this->encodeObjectKey($key);

            // Check if value is an associative array (nested object)
            if (is_array($val) && !$this->isIndexedArray($val) && !empty($val)) {
                // Nested associative array - recurse
                $nestedPairs = $this->encodeNestedObject($val, $depth + 1);
                $pairs[] = "$encodedKey:\n$nestedPairs";
            } else {
                $encodedValue = $this->encodeValue($val, $depth + 1);
                // Check if the encoded value is multi-line
                if (str_contains($encodedValue, "\n")) {
                    $pairs[] = "$encodedKey:\n" . $indent . "  " . str_replace("\n", "\n" . $indent . "  ", $encodedValue);
                } else {
                    $pairs[] = "$encodedKey: $encodedValue";
                }
            }
        }

        return $indent . implode("\n" . $indent, $pairs);
    }

    /**
     * Get object properties (handles both arrays and objects)
     */
    private function getObjectProperties(object|array $value): array
    {
        return is_array($value) ? $value : get_object_vars($value);
    }

    /**
     * Apply key folding to flatten nested structures
     */
    private function applyKeyFolding(array $properties, int $depth): array
    {
        $folded = [];

        foreach ($properties as $key => $value) {
            // Check both objects and associative arrays for folding
            if (is_object($value) && !($value instanceof DateTimeInterface)) {
                $nested = $this->getObjectProperties($value);

                if (!empty($nested) && $this->shouldFoldKey($nested)) {
                    foreach ($nested as $nestedKey => $nestedValue) {
                        $folded["$key.$nestedKey"] = $nestedValue;
                    }
                } else {
                    $folded[$key] = $value;
                }
            } elseif (is_array($value) && !$this->isIndexedArray($value)) {
                // Associative array - treat like object for folding
                if (!empty($value) && $this->shouldFoldKey($value)) {
                    foreach ($value as $nestedKey => $nestedValue) {
                        $folded["$key.$nestedKey"] = $nestedValue;
                    }
                } else {
                    $folded[$key] = $value;
                }
            } else {
                $folded[$key] = $value;
            }
        }

        return $folded;
    }

    /**
     * Determine if a nested object should be folded
     */
    private function shouldFoldKey(array $properties): bool
    {
        // Only fold if all nested values are primitives
        return $this->allPrimitiveValues($properties) && count($properties) <= 5;
    }

    /**
     * Encode object key
     */
    private function encodeObjectKey(string $key): string
    {
        // Keys don't need quotes unless they contain special characters
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $key)) {
            return $key;
        }

        return $this->encodeString($key);
    }

    /**
     * Get indentation string
     */
    private function getIndent(int $level): string
    {
        $spaces = $this->options['indentation'] * $level;
        return str_repeat($this->options['indent_char'], $spaces);
    }

    /**
     * Check if all array values are primitives
     */
    private function allPrimitives(array $arr): bool
    {
        foreach ($arr as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if all values are primitives or strings
     */
    private function allPrimitiveValues(array $arr): bool
    {
        foreach ($arr as $value) {
            if (is_array($value) || (is_object($value) && !($value instanceof DateTimeInterface))) {
                return false;
            }
        }
        return true;
    }

    public function setConfig(array $options = []): static
    {
        $this->options = $options;
        return $this;
    }

    private function mergeConfig(array $options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
}
