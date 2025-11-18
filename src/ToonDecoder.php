<?php

namespace Tedon\Tooner;

use DateTime;
use Exception;
use stdClass;
use Tedon\Tooner\Exceptions\ToonDecodingException;

/**
 * TOON (Token-Oriented Object Notation) Decoder
 *
 * Decodes TOON format strings back into PHP objects, arrays, and primitives with support for:
 * - YAML-like indentation-based syntax
 * - Nested objects and arrays
 * - Tabular arrays
 * - Key folding (unfolding dotted keys)
 * - Explicit array length validation
 * - Type restoration
 */
class ToonDecoder
{
    private array $lines;
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * Static convenience method for decoding
     * @throws ToonDecodingException
     */
    public function decode(string $toonString, array $options = []): mixed
    {
        return $this->mergeConfig($options)->decodeString($toonString);
    }

    /**
     * Main decoding method
     * @throws ToonDecodingException
     */
    public function decodeString(string $toonString): mixed
    {
        $trimmed = trim($toonString);

        // Handle special cases
        if (empty($trimmed)) {
            // Empty string represents empty object
            return new stdClass();
        }

        if ($trimmed === '[]') {
            return [];
        }

        if ($trimmed === '{}') {
            return new stdClass();
        }

        // Handle empty array format: [0]:
        if (preg_match('/^\[0]:\s*$/', $trimmed)) {
            return [];
        }

        // Split into lines and preserve indentation
        $this->lines = explode("\n", $toonString);

        return $this->parseValue(0, 0);
    }

    /**
     * Parse value at current indentation level
     * @throws ToonDecodingException
     */
    private function parseValue(int $startLine, int $expectedIndent, int $depth = 0): mixed
    {
        if ($depth > $this->options['max_depth']) {
            throw new ToonDecodingException('Maximum decoding depth exceeded');
        }

        if ($startLine >= count($this->lines)) {
            return null;
        }

        $line = $this->lines[$startLine];
        $indent = $this->getIndentLevel($line);
        $trimmed = trim($line);

        if (empty($trimmed)) {
            return null;
        }

        // Parse key-value pair
        if (str_contains($trimmed, ':')) {
            return $this->parseObject($startLine, $expectedIndent, $depth);
        }

        // Parse primitive value
        return $this->parsePrimitive($trimmed);
    }

    /**
     * Parse object (multiple key-value pairs with indentation)
     * @throws ToonDecodingException
     */
    private function parseObject(int $startLine, int $baseIndent, int $depth = 0): object|array
    {
        $properties = [];
        $lineIndex = $startLine;
        $firstLine = $this->lines[$startLine] ?? '';

        // Check if this is a top-level tabular array (starts with [n]{fields}: or {fields}:)
        if (preg_match('/^\s*(?:\[(\d+)])?\{([^}]+)}:\s*$/', $firstLine, $matches)) {
            $length = !empty($matches[1]) ? (int)$matches[1] : null;
            $fields = explode(',', $matches[2]);
            $arrayData = $this->parseTabularArray($startLine + 1, $baseIndent + 1, $length, $fields, $depth + 1);
            return $arrayData['data'];
        }

        while ($lineIndex < count($this->lines)) {
            $line = $this->lines[$lineIndex];
            $indent = $this->getIndentLevel($line);
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                $lineIndex++;
                continue;
            }

            // If indentation decreased, we're done with this object
            if ($indent < $baseIndent) {
                break;
            }

            // Parse key-value pair
            if (str_contains($trimmed, ':')) {
                $colonPos = strpos($trimmed, ':');
                $key = trim(substr($trimmed, 0, $colonPos));
                $value = trim(substr($trimmed, $colonPos + 1));

                // Check for array declaration with length [n] or [n]{fields}
                if (preg_match('/^\[(\d+)](?:\{([^}]+)})?$/', $key, $matches)) {
                    // Standalone array without a key
                    $length = (int)$matches[1];
                    $fields = isset($matches[2]) ? explode(',', $matches[2]) : null;

                    if ($fields) {
                        // Tabular array
                        $arrayData = $this->parseTabularArray($lineIndex + 1, $indent + 1, $length, $fields, $depth + 1);
                        return $arrayData['data'];
                    } else {
                        // Simple or complex array
                        if (!empty($value)) {
                            return $this->parseSimpleArray($value, $length);
                        } else {
                            // Complex array with bullets on next lines
                            $arrayData = $this->parseComplexArray($lineIndex + 1, $indent + 1, $length, $depth + 1);
                            return $arrayData['data'];
                        }
                    }
                } elseif (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\[(\d+)])?\{([^}]+)}$/', $key, $matches)) {
                    // Named tabular array: key[n]{fields} or key{fields}
                    $arrayName = $matches[1];
                    $length = !empty($matches[2]) ? (int)$matches[2] : null;
                    $fields = explode(',', $matches[3]);

                    // Tabular array
                    $arrayData = $this->parseTabularArray($lineIndex + 1, $indent + 1, $length, $fields, $depth + 1);
                    $properties[$arrayName] = $arrayData['data'];
                    $lineIndex = $arrayData['nextLine'];
                } elseif (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\[(\d+)]$/', $key, $matches)) {
                    // Named simple/complex array: key[n]
                    $arrayName = $matches[1];
                    $length = (int)$matches[2];

                    // Check if it's an inline simple array or complex array with bullets
                    if (!empty($value)) {
                        // Inline simple array
                        $arrayData = $this->parseSimpleArray($value, $length);
                        $properties[$arrayName] = $arrayData;
                        $lineIndex++;
                    } else {
                        // Complex array with bullets on next lines
                        $arrayData = $this->parseComplexArray($lineIndex + 1, $indent + 1, $length, $depth + 1);
                        $properties[$arrayName] = $arrayData['data'];
                        $lineIndex = $arrayData['nextLine'];
                    }
                } else {
                    // Regular key-value pair
                    if (!empty($value)) {
                        // Check if value is an inline array: [n]: val1,val2
                        if (preg_match('/^\[(\d+)]:\s*(.+)$/', $value, $matches)) {
                            $arrayLength = (int)$matches[1];
                            $arrayValues = $matches[2];
                            $properties[$key] = $this->parseSimpleArray($arrayValues, $arrayLength);
                        } else {
                            // Value on same line
                            $properties[$key] = $this->parsePrimitive($value);
                        }
                        $lineIndex++;
                    } else {
                        // Value on next line(s) - nested object
                        $nestedData = $this->parseNestedValue($lineIndex + 1, $indent + 1, $depth + 1);
                        $properties[$key] = $nestedData['data'];
                        $lineIndex = $nestedData['nextLine'];
                    }
                }
            } else {
                $lineIndex++;
            }
        }

        // Unfold dotted keys if enabled
        if ($this->options['key_folding']) {
            $properties = $this->unfoldKeys($properties);
        }

        // Restore dates if enabled
        if ($this->options['restore_dates']) {
            $properties = $this->restoreDates($properties);
        }

        if ($this->options['object_as_array']) {
            return $properties;
        }

        return (object) $properties;
    }

    /**
     * Parse nested value (could be an object or array)
     * @throws ToonDecodingException
     */
    private function parseNestedValue(int $startLine, int $expectedIndent, int $depth): array
    {
        $properties = [];
        $lineIndex = $startLine;

        while ($lineIndex < count($this->lines)) {
            $line = $this->lines[$lineIndex];
            $indent = $this->getIndentLevel($line);
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                $lineIndex++;
                continue;
            }

            // If indentation decreased, we're done
            if ($indent < $expectedIndent) {
                break;
            }

            // Parse key-value pair
            if (str_contains($trimmed, ':')) {
                $colonPos = strpos($trimmed, ':');
                $key = trim(substr($trimmed, 0, $colonPos));
                $value = trim(substr($trimmed, $colonPos + 1));

                if (!empty($value)) {
                    // Check if value is an inline array: [n]: val1,val2
                    if (preg_match('/^\[(\d+)]:\s*(.+)$/', $value, $matches)) {
                        $arrayLength = (int)$matches[1];
                        $arrayValues = $matches[2];
                        $properties[$key] = $this->parseSimpleArray($arrayValues, $arrayLength);
                    } else {
                        $properties[$key] = $this->parsePrimitive($value);
                    }
                    $lineIndex++;
                } else {
                    // Nested value
                    $nestedData = $this->parseNestedValue($lineIndex + 1, $indent + 1, $depth + 1);
                    $properties[$key] = $nestedData['data'];
                    $lineIndex = $nestedData['nextLine'];
                }
            } else {
                $lineIndex++;
            }
        }

        // Unfold dotted keys if enabled
        if ($this->options['key_folding']) {
            $properties = $this->unfoldKeys($properties);
        }

        // Restore dates if enabled
        if ($this->options['restore_dates']) {
            $properties = $this->restoreDates($properties);
        }

        return [
            'data' => $this->options['object_as_array'] ? $properties : (object) $properties,
            'nextLine' => $lineIndex
        ];
    }

    /**
     * Parse tabular array
     * Format:
     *  value1,value2,value3
     *  value4,value5,value6
     * @throws ToonDecodingException
     */
    private function parseTabularArray(int $startLine, int $expectedIndent, ?int $expectedLength, array $fields, int $depth): array
    {
        $result = [];
        $lineIndex = $startLine;
        $rowCount = 0;

        while ($lineIndex < count($this->lines) && ($expectedLength === null || $rowCount < $expectedLength)) {
            $line = $this->lines[$lineIndex];
            $indent = $this->getIndentLevel($line);
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                $lineIndex++;
                continue;
            }

            // If indentation decreased, we're done
            if ($indent < $expectedIndent) {
                break;
            }

            // Parse row values
            $values = $this->parseCSVLine($trimmed);

            if (count($values) !== count($fields)) {
                throw new ToonDecodingException("Tabular array row has wrong number of values. Expected " . count($fields) . ", got " . count($values));
            }

            $rowObject = [];
            foreach ($fields as $index => $field) {
                $rowObject[$field] = $this->parsePrimitive($values[$index]);
            }

            $result[] = $this->options['object_as_array'] ? $rowObject : (object) $rowObject;
            $rowCount++;
            $lineIndex++;
        }

        if ($this->options['validate_lengths'] && $expectedLength !== null && $rowCount !== $expectedLength) {
            throw new ToonDecodingException("Tabular array length mismatch: expected $expectedLength, got $rowCount");
        }

        return [
            'data' => $result,
            'nextLine' => $lineIndex
        ];
    }

    /**
     * Parse simple array (comma-separated values)
     * @throws ToonDecodingException
     */
    private function parseSimpleArray(string $value, ?int $expectedLength = null): array
    {
        $values = $this->parseCSVLine($value);

        if ($this->options['validate_lengths'] && $expectedLength !== null && count($values) !== $expectedLength) {
            throw new ToonDecodingException("Array length mismatch: expected $expectedLength, got " . count($values));
        }

        return array_map(fn($v) => $this->parsePrimitive($v), $values);
    }

    /**
     * Parse complex array (array with bullet items)
     * Format:
     *   - key: value
     *     key2: value2
     *   - key: value3
     * Or:
     *   - [n]: val1,val2
     *   - [n]: val3,val4
     * @throws ToonDecodingException
     */
    private function parseComplexArray(int $startLine, int $expectedIndent, int $expectedLength, int $depth): array
    {
        $result = [];
        $lineIndex = $startLine;
        $itemCount = 0;

        while ($lineIndex < count($this->lines) && $itemCount < $expectedLength) {
            $line = $this->lines[$lineIndex];
            $indent = $this->getIndentLevel($line);
            $trimmed = trim($line);

            // Skip empty lines
            if (empty($trimmed)) {
                $lineIndex++;
                continue;
            }

            // If indentation decreased, we're done
            if ($indent < $expectedIndent) {
                break;
            }

            // Check if line starts with bullet
            if (str_starts_with($trimmed, '- ')) {
                $itemContent = substr($trimmed, 2); // Remove "- "

                // Check if it's a primitive array: - [n]: val1,val2
                if (preg_match('/^\[(\d+)]:\s*(.+)$/', $itemContent, $matches)) {
                    $arrayLength = (int)$matches[1];
                    $arrayValues = $matches[2];
                    $result[] = $this->parseSimpleArray($arrayValues, $arrayLength);
                    $lineIndex++;
                } elseif (str_contains($itemContent, ':')) {
                    // It's an object with properties
                    $colonPos = strpos($itemContent, ':');
                    $firstKey = trim(substr($itemContent, 0, $colonPos));
                    $firstValue = trim(substr($itemContent, $colonPos + 1));

                    $itemProperties = [];

                    // Check if first key is a tabular array: key[n]{fields}:
                    if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\[(\d+)]\{([^}]+)}$/', $firstKey, $matches)) {
                        $arrayName = $matches[1];
                        $arrayLength = (int)$matches[2];
                        $fields = explode(',', $matches[3]);

                        // Parse tabular array on following lines
                        $lineIndex++;
                        $arrayData = $this->parseTabularArray($lineIndex, $expectedIndent + 2, $arrayLength, $fields, $depth + 1);
                        $itemProperties[$arrayName] = $arrayData['data'];
                        $lineIndex = $arrayData['nextLine'];
                    } elseif (!empty($firstValue)) {
                        // Regular property with value on same line
                        $itemProperties[$firstKey] = $this->parsePrimitive($firstValue);
                        $lineIndex++;
                    } else {
                        // First property has no value, move to next line
                        $lineIndex++;
                    }

                    // Check for additional properties on following lines
                    while ($lineIndex < count($this->lines)) {
                        $nextLine = $this->lines[$lineIndex];
                        $nextIndent = $this->getIndentLevel($nextLine);
                        $nextTrimmed = trim($nextLine);

                        if (empty($nextTrimmed)) {
                            $lineIndex++;
                            continue;
                        }

                        // If this line is indented more than the bullet, it's part of this item
                        if ($nextIndent > $expectedIndent && !str_starts_with($nextTrimmed, '-')) {
                            if (str_contains($nextTrimmed, ':')) {
                                $colonPos = strpos($nextTrimmed, ':');
                                $propKey = trim(substr($nextTrimmed, 0, $colonPos));
                                $propValue = trim(substr($nextTrimmed, $colonPos + 1));

                                // Check if it's a tabular array
                                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\[(\d+)]\{([^}]+)}$/', $propKey, $matches)) {
                                    $arrayName = $matches[1];
                                    $arrayLength = (int)$matches[2];
                                    $fields = explode(',', $matches[3]);

                                    $lineIndex++;
                                    $arrayData = $this->parseTabularArray($lineIndex, $nextIndent + 2, $arrayLength, $fields, $depth + 1);
                                    $itemProperties[$arrayName] = $arrayData['data'];
                                    $lineIndex = $arrayData['nextLine'];
                                } elseif (!empty($propValue)) {
                                    $itemProperties[$propKey] = $this->parsePrimitive($propValue);
                                    $lineIndex++;
                                } else {
                                    $lineIndex++;
                                }
                            } else {
                                $lineIndex++;
                            }
                        } else {
                            // Next bullet or decreased indentation
                            break;
                        }
                    }

                    $result[] = $this->options['object_as_array'] ? $itemProperties : (object) $itemProperties;
                } else {
                    // Primitive value
                    $result[] = $this->parsePrimitive($itemContent);
                    $lineIndex++;
                }

                $itemCount++;
            } else {
                $lineIndex++;
            }
        }

        if ($this->options['validate_lengths'] && $itemCount !== $expectedLength) {
            throw new ToonDecodingException("Complex array length mismatch: expected $expectedLength, got $itemCount");
        }

        return [
            'data' => $result,
            'nextLine' => $lineIndex
        ];
    }

    /**
     * Parse CSV line (handles quoted values)
     */
    private function parseCSVLine(string $line): array
    {
        $values = [];
        $current = '';
        $inQuotes = false;
        $escaped = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($escaped) {
                $current .= match ($char) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '\\', '"' => $char,
                    default => '\\' . $char,
                };
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === ',' && !$inQuotes) {
                $values[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (!empty($current) || str_ends_with($line, ',')) {
            $values[] = $current;
        }

        return $values;
    }

    /**
     * Parse primitive value (string, number, boolean, null)
     * @throws ToonDecodingException
     */
    private function parsePrimitive(string $value): mixed
    {
        $trimmed = trim($value);

        // Check for null
        if ($trimmed === 'null') {
            return null;
        }

        // Check for boolean
        if ($trimmed === 'true') {
            return true;
        }
        if ($trimmed === 'false') {
            return false;
        }

        // Check for number
        if (is_numeric($trimmed)) {
            if (str_contains($trimmed, '.')) {
                return (float) $trimmed;
            }
            return (int) $trimmed;
        }

        // Check for quoted string
        if (str_starts_with($trimmed, '"')) {
            if (!str_ends_with($trimmed, '"')) {
                throw new ToonDecodingException('Unterminated string');
            }
            return $this->decodeQuotedString(substr($trimmed, 1, -1));
        }

        // Unquoted string
        return $trimmed;
    }

    /**
     * Decode quoted string with escape sequences
     */
    private function decodeQuotedString(string $value): string
    {
        return str_replace(
            ['\\n', '\\r', '\\t', '\\"', '\\\\'],
            ["\n", "\r", "\t", '"', '\\'],
            $value
        );
    }

    /**
     * Get indentation level (number of leading spaces)
     */
    private function getIndentLevel(string $line): int
    {
        $count = 0;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === ' ') {
                $count++;
            } elseif ($line[$i] === "\t") {
                $count += 2; // Count tab as 2 spaces
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Unfold dotted keys into nested structures
     */
    private function unfoldKeys(array $properties): array
    {
        $result = [];

        foreach ($properties as $key => $value) {
            if (str_contains($key, '.')) {
                $parts = explode('.', $key);
                $current = &$result;

                foreach ($parts as $i => $part) {
                    if ($i === count($parts) - 1) {
                        $current[$part] = $value;
                    } else {
                        if (!isset($current[$part])) {
                            $current[$part] = [];
                        }
                        $current = &$current[$part];
                    }
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Attempt to restore date strings to DateTime objects
     */
    private function restoreDates(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (is_string($value) && $this->looksLikeDate($value)) {
                try {
                    $properties[$key] = new DateTime($value);
                } catch (Exception $e) {
                    // Keep as string if parsing fails
                }
            } elseif (is_array($value)) {
                $properties[$key] = $this->restoreDates($value);
            }
        }

        return $properties;
    }

    /**
     * Check if string looks like a date
     */
    private function looksLikeDate(string $value): bool
    {
        // Check for ISO 8601 format
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1;
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
