<?php
return [
    'validate_lengths' => env('TOON_VALIDATE_LENGTHS', true),
    'restore_dates' => env('TOON_RESTORE_DATES', true),
    'max_depth' => env('TOON_MAX_DEPTH', 100),
    'object_as_array' => env('TOON_OBJECT_AS_ARRAY', false),
    'key_folding' => env('TOON_KEY_FOLDING', true),
    'tabular_arrays' => env('TOON_TABULAR_ARRAYS', true),
    'indentation' => env('TOON_INDENTATION', 2),
    'indent_char' => env('TOON_INDENT_CHAR', ' '),
    'explicit_lengths' => env('TOON_EXPLICIT_LENGTHS', true),
];
