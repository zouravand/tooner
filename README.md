# Tooner - TOON (Token-Oriented Object Notation) Encoder/Decoder

A PHP library for encoding and decoding data in TOON format - a human-readable, YAML-like serialization format optimized for readability and efficiency.

## Features

- **YAML-like syntax** - Indentation-based structure for nested objects and arrays
- **Tabular arrays** - Compact representation for arrays of uniform objects
- **Key folding** - Flatten and unfold nested keys using dot notation
- **Type support** - Handles primitives, objects, arrays, and DateTime objects
- **Configurable** - Fine-tune encoding/decoding behavior via configuration
- **Laravel integration** - Optional Laravel service provider and facade
- **PHP 8.2+** - Built for modern PHP with strict types and match expressions

## What is TOON?

TOON (Token-Oriented Object Notation) is a serialization format that combines the readability of YAML with efficient encoding strategies:

```
user:
  name: John Doe
  email: john@example.com
  age: 30

tags[3]: php,laravel,api

posts[2]{id,title,views}:
  1,"Hello World",150
  2,"PHP Tips",320
```

### Key Benefits

- **Human-readable** - Easy to read and edit manually
- **Compact tabular format** - Efficient for arrays of uniform objects
- **Type-aware** - Preserves data types (strings, numbers, booleans, dates)
- **Configurable depth** - Control nesting levels and output format

## Installation

Install via Composer:

```bash
composer require tedon/tooner
```

## Basic Usage

### Standalone Usage

```php
use Tedon\Tooner\Tooner;

// Create instance
$tooner = new Tooner();

// Encode data to TOON format
$data = [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30,
    'active' => true
];

$encoded = $tooner->encode($data);
echo $encoded;
// Output:
// name: John Doe
// email: john@example.com
// age: 30
// active: true

// Decode TOON string back to PHP
$decoded = $tooner->decode($encoded);
print_r($decoded);
```

### Laravel Usage

For Laravel applications, the package automatically registers a service provider and facade.

#### Using the Facade

```php
use Tedon\Tooner\Facades\Tooner;

// Encode
$toon = Tooner::encode(['key' => 'value']);

// Decode
$data = Tooner::decode($toon);
```

#### Using Dependency Injection

```php
use Tedon\Tooner\Tooner;

class MyController extends Controller
{
    public function __construct(private Tooner $tooner)
    {
    }

    public function store(Request $request)
    {
        $encoded = $this->tooner->encode($request->all());
        // Store or process $encoded
    }
}
```

## Configuration

### Laravel Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Tedon\Tooner\ToonerServiceProvider" --tag="config"
```

This creates `config/tooner.php` with the following options:

```php
return [
    // Validate array lengths during decoding
    'validate_lengths' => true,

    // Attempt to restore DateTime objects from date strings
    'restore_dates' => true,

    // Maximum nesting depth for encoding/decoding
    'max_depth' => 100,

    // Return associative arrays instead of objects when decoding
    'object_as_array' => false,

    // Enable key folding (flatten nested keys with dots)
    'key_folding' => true,

    // Use tabular format for arrays of uniform objects
    'tabular_arrays' => true,

    // Number of spaces per indentation level
    'indentation' => 2,

    // Character to use for indentation (space or tab)
    'indent_char' => ' ',

    // Include explicit array lengths in output
    'explicit_lengths' => true,

    // Skip null values when encoding objects
    'skip_nulls' => false,

    // Normalize numeric string keys ("0", "1") to integers (0, 1)
    'normalize_numeric_keys' => true,
];
```

### Standalone Configuration

Pass configuration as constructor parameter or method options:

```php
$tooner = new Tooner([
    'indentation' => 4,
    'key_folding' => false,
]);

// Or per-operation
$encoded = $tooner->encode($data, [
    'tabular_arrays' => false,
    'explicit_lengths' => false,
]);
```

## Advanced Examples

### Simple Arrays

```php
$data = ['apple', 'banana', 'cherry'];
$encoded = $tooner->encode($data);
// Output: [3]: apple,banana,cherry
```

### Nested Objects

```php
$data = [
    'user' => [
        'profile' => [
            'name' => 'Jane',
            'location' => 'NYC'
        ]
    ]
];

$encoded = $tooner->encode($data);
// Output:
// user:
//   profile:
//     name: Jane
//     location: NYC
```

### Key Folding

```php
$tooner = new Tooner(['key_folding' => true]);

$data = [
    'database' => [
        'host' => 'localhost',
        'port' => 3306
    ]
];

$encoded = $tooner->encode($data);
// Output:
// database.host: localhost
// database.port: 3306
```

### Tabular Arrays

Perfect for arrays of uniform objects like database records:

```php
$users = [
    ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
    ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
    ['id' => 3, 'name' => 'Charlie', 'role' => 'user'],
];

$encoded = $tooner->encode($users);
// Output:
// [3]{id,name,role}:
//   1,Alice,admin
//   2,Bob,user
//   3,Charlie,user
```

### Complex Nested Structures

```php
$data = [
    'project' => 'My App',
    'version' => '1.0.0',
    'features' => [
        ['name' => 'Auth', 'status' => 'complete'],
        ['name' => 'API', 'status' => 'in-progress'],
    ],
    'config' => [
        'debug' => true,
        'cache' => [
            'driver' => 'redis',
            'ttl' => 3600
        ]
    ]
];

$encoded = $tooner->encode($data);
// Output:
// project: My App
// version: 1.0.0
// features[2]{name,status}:
//   Auth,complete
//   API,in-progress
// config:
//   debug: true
//   cache:
//     driver: redis
//     ttl: 3600
```

### DateTime Support

```php
use DateTime;

$data = [
    'created_at' => new DateTime('2025-01-15 10:30:00'),
    'updated_at' => new DateTime('2025-01-16 15:45:00'),
];

$encoded = $tooner->encode($data);
// Dates are encoded as ISO 8601 strings
// Output:
// created_at: "2025-01-15T10:30:00+00:00"
// updated_at: "2025-01-16T15:45:00+00:00"

// Decode with date restoration
$tooner = new Tooner(['restore_dates' => true]);
$decoded = $tooner->decode($encoded);
// DateTime objects are automatically restored
```

### Skipping Null Values

When working with database records or APIs, you often have null values that you want to omit from the output:

```php
$users = [
    [
        'id' => 7,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@yahoo.com',
        'status' => 0,
    ],
    [
        'id' => 13,
        'first_name' => 'Jolie',
        'last_name' => 'Diss',
        'email' => 'example@gmail.com',
        'status' => 1,
    ],
];

// Enable skip_nulls to omit null values
$tooner = new Tooner(['skip_nulls' => true]);
$encoded = $tooner->encode($users);

// Output - only non-null fields are shown.
```

## Direct Encoder/Decoder Access

For more control, use the encoder and decoder directly:

```php
use Tedon\Tooner\ToonEncoder;
use Tedon\Tooner\ToonDecoder;

$encoder = new ToonEncoder(['indentation' => 4]);
$encoded = $encoder->encode($data);

$decoder = new ToonDecoder(['validate_lengths' => false]);
$decoded = $decoder->decode($encoded);
```

## Error Handling

The library throws specific exceptions for encoding and decoding errors:

```php
use Tedon\Tooner\Exceptions\ToonEncodingException;
use Tedon\Tooner\Exceptions\ToonDecodingException;

try {
    $encoded = $tooner->encode($complexData);
} catch (ToonEncodingException $e) {
    // Handle encoding errors (e.g., max depth exceeded, unsupported types)
    echo "Encoding failed: " . $e->getMessage();
}

try {
    $decoded = $tooner->decode($toonString);
} catch (ToonDecodingException $e) {
    // Handle decoding errors (e.g., invalid format, length mismatch)
    echo "Decoding failed: " . $e->getMessage();
}
```

## Configuration Options Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `validate_lengths` | bool | `true` | Validate array lengths match declared lengths during decoding |
| `restore_dates` | bool | `true` | Attempt to convert ISO 8601 strings back to DateTime objects |
| `max_depth` | int | `100` | Maximum nesting depth allowed for encoding/decoding |
| `object_as_array` | bool | `false` | Return associative arrays instead of stdClass objects when decoding |
| `key_folding` | bool | `true` | Flatten nested single-value objects using dot notation |
| `tabular_arrays` | bool | `true` | Use compact tabular format for arrays of uniform objects |
| `indentation` | int | `2` | Number of indent characters per level |
| `indent_char` | string | `' '` | Character used for indentation (space or tab) |
| `explicit_lengths` | bool | `true` | Include `[n]` length indicators in array declarations |
| `skip_nulls` | bool | `false` | Skip properties with null values when encoding objects |
| `normalize_numeric_keys` | bool | `true` | Convert numeric string keys ("0", "1") to integer keys for proper array formatting |

## Use Cases

### Configuration Files

TOON's readable format makes it ideal for configuration files:

```php
$config = $tooner->decode(file_get_contents('config.toon'));
```

### Data Export

Export database records in a human-readable format:

```php
$users = User::all()->toArray();
file_put_contents('users.toon', $tooner->encode($users));
```

### API Responses

Alternative to JSON for APIs where readability matters:

```php
return response($tooner->encode($data))
    ->header('Content-Type', 'application/toon');
```

### Data Transfer

Compact format for transferring structured data between systems:

```php
$serialized = $tooner->encode($largeDataset);
// Transfer $serialized
$restored = $tooner->decode($serialized);
```

## Requirements

- PHP 8.2 or higher
- Optional: Laravel 10.x, 11.x, or 12.x for framework integration

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT License - see the [LICENSE](LICENSE) file for details.

## Author

**Pouya Zouravand**
- Email: pouya.zuravand@gmail.com

## Credits

Inspired by YAML and JSON, TOON aims to provide a balanced approach between human readability and efficient data representation.