# LBWP Framework Coding Guidelines

You are working on a WordPress project that uses the LBWP framework plugin located at `wp-content/plugins/lbwp`. This
framework contains many reusable classes and utilities.

## Core Principles

1. **Small Classes and Functions**: Keep classes and functions as small and focused as possible
2. **Separation of Concerns**: Better to create a new class for a specific purpose than to mix responsibilities
3. **Complete Function Headers**: Every function MUST have complete PHPDoc documentation
4. **English Code and Docs**: Variable names, functions name, comments etc. MUST be in english

## Code Style Standards

### File Structure

```php
<?php

namespace LBWP\Theme\Component\[Category];

use LBWP\Core as LbwpCore;
use LBWP\Helper\[HelperClass];
// ... other imports

/**
 * Brief description of what this class does
 * @package LBWP\Theme\Component\[Category]
 * @author [Name] <email@example.com>
 */
class ClassName extends ParentClass
{
    // Class implementation
}
```

### Class Structure Order

1. Constants (with PHPDoc)
2. Static properties (with @var type documentation)
3. Protected/private properties (with @var type documentation)
4. Public properties (with @var type documentation)
5. Constructor and setup methods
6. Public methods
7. Protected methods
8. Private methods

### Constants

```php
/**
 * @var string description of what this constant represents
 */
const CONSTANT_NAME = 'value';
```

### Properties

```php
/**
 * Description of the property
 * @var string|array|ClassName
 */
protected $propertyName = array();
```

### Function Documentation

**EVERY function MUST have a complete PHPDoc header** including:

```php
/**
 * Clear description of what this function does
 *
 * @param string $paramName Description of parameter
 * @param int $anotherParam Description of another parameter
 * @return void|string|array Return type and description
 */
public function functionName($paramName, $anotherParam)
{
    // Implementation
}
```

For functions with no return value, use `@return void`.

### Naming Conventions

- **Classes**: PascalCase (e.g., `Core`, `CustomFieldHandler`)
- **Methods/Functions**: camelCase (e.g., `getUserData`, `addCustomFields`)
- **Properties**: camelCase (e.g., `$userData`, `$hasWooCommerce`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `TYPE_PROFILE_CAT`, `LIST_HISTORY_META`)
- **Private members**: Prefix with underscore is optional but can be used for clarity

### Code Organization

#### Hook Registration

Register WordPress hooks in dedicated initialization methods:

```php
public function init()
{
    // Register actions
    add_action('admin_init', [$this, 'methodName']);
    add_action('save_post', [$this, 'anotherMethod'], 10, 2);
    // Register filters
    add_filter('the_content', [$this, 'filterContent']);
}
```

#### Conditional Logic

Use early returns to reduce nesting:

```php
// Good
public function processData($data)
{
    if (!$data) {
        return;
    }

    // Process data
}

// Avoid deep nesting
public function processData($data)
{
    if ($data) {
        if ($this->isValid($data)) {
            if ($this->canProcess()) {
                // Process data - too deeply nested
            }
        }
    }
}
```

### Database Access

Use WordPress database abstraction:

```php
use LBWP\Util\WordPress;

$db = WordPress::getDb();
$results = $db->get_results($db->prepare("SELECT * FROM {$db->prefix}table WHERE id = %d", $id));
```

### Array Manipulation

```php
// Use short array syntax
$array = [];            // Standard in this codebase
$items = array();       // Old, rewrite if you see this

// Array operations
$result = array_map(function($item) {
    return $item->id;
}, $items);
```

### String Handling

```php
// Use single quotes for simple strings
$simple = 'text';
// Use multi-line strings for longer texts
$multiline = '
  <html>
    <body>
      <h1>Hello World</h1>
    </body>
  </html> 
';

// Use double quotes when interpolating
$interpolated = "User {$userId} has {$count} items";

// Concatenation
$result = $prefix . '-' . $suffix;
```

## Common Patterns

### Component Classes

Components should extend `LBWP\Theme\Base\Component`:

```php
class MyComponent extends Component
{
    /**
     * @var array the configuration for the component
     */
    protected $configuration = array();

    /**
     * Early setup before WordPress init
     */
    public function setup()
    {
        parent::setup();
        // Early initialization
    }

    /**
     * Initialize the component
     */
    public function init()
    {
        // Register hooks and filters
    }
}
```

### Utility Classes

Static utility classes for reusable functionality:

```php
namespace LBWP\Util;

/**
 * Utility class for [specific purpose]
 * @package LBWP\Util
 * @author [Name] <email@example.com>
 */
class UtilityName
{
    /**
     * Description of what this utility method does
     *
     * @param string $input Input description
     * @return string Output description
     */
    public static function processData($input)
    {
        // Implementation
    }
}
```

### Separation of Concerns

When adding functionality, consider:

1. **Does this belong in a new class?** If the functionality is distinct, create a new class
2. **Can this be a utility function?** Reusable logic should go in utility classes
3. **Is this component-specific?** Keep component logic within component classes
4. **Does this need a helper?** Complex operations should be extracted to helper classes

## Framework Structure

```
wp-content/plugins/lbwp/
├── LBWP/
│   ├── Core.php                    # Main framework core
│   ├── Helper/                     # Helper classes
│   ├── Module/                     # Feature modules
│   ├── Theme/
│   │   ├── Base/                   # Base classes (Component, etc.)
│   │   ├── Component/              # Theme components
│   │   ├── Feature/                # Theme features
│   ├── Util/                       # Utility classes
│   │   ├── WordPress.php           # WordPress utilities
│   │   ├── Strings.php             # String utilities
│   │   ├── Date.php                # Date utilities
│   │   └── ArrayManipulation.php   # Array utilities
```

## Common Utilities Available

- `LBWP\Util\WordPress` - WordPress-specific utilities
- `LBWP\Util\Strings` - String manipulation
- `LBWP\Util\Date` - Date/time handling
- `LBWP\Util\ArrayManipulation` - Array operations
- `LBWP\Util\File` - File operations
- `LBWP\Helper\Metabox` - Metabox creation
- `LBWP\Core` - Framework core functionality

## Security Considerations

- Always sanitize user input
- Escape output appropriately (`esc_html`, `esc_attr`, `esc_url`)
- Use prepared statements for database queries
- Check user capabilities before allowing actions
- Validate and verify nonces for form submissions

## Error Handling

```php
// Return early on errors
if (!$this->isValid($data)) {
    return false;
}

// Log errors when appropriate
error_log('Error message: ' . $errorDetails);

// Throw exceptions for exceptional cases
if (!$criticalResource) {
    throw new \Exception('Critical resource not available');
}
```

## Testing Considerations

- Write testable code by keeping functions small and focused
- Avoid tight coupling to WordPress globals when possible
- Use dependency injection where practical
- Keep business logic separate from WordPress hooks

## When Making Changes

1. **Read existing code first** - Understand the current implementation
2. **Follow existing patterns** - Maintain consistency with the codebase
3. **Create new classes** - Don't bloat existing classes with unrelated functionality
4. **Document thoroughly** - Complete PHPDoc headers are mandatory
5. **Test your changes** - Verify functionality works as expected

## Questions to Ask Before Coding

1. Does this functionality already exist in the framework?
2. Should this be a new class or can it extend existing functionality?
3. Is this the right place for this code?
4. Have I documented everything properly?
5. Is this function doing one thing well, or multiple things poorly?

## Example: Well-Structured Function

```php
/**
 * Retrieves user data for CRM display
 *
 * This method fetches user metadata and custom fields,
 * formats them for display in the CRM interface.
 *
 * @param int $userId The WordPress user ID
 * @param bool $includeHistory Whether to include field history
 * @return array Array of user data including custom fields
 */
protected function getUserDataForCrm($userId, $includeHistory = false)
{
    // Validate input
    if (!$userId || !is_numeric($userId)) {
        return array();
    }

    // Get user object
    $user = get_user_by('id', $userId);
    if (!$user) {
        return array();
    }

    // Build data array
    $data = array(
        'id' => $userId,
        'email' => $user->user_email,
        'name' => $user->display_name,
    );

    // Add custom fields
    $data['custom_fields'] = $this->getCustomFields($userId);

    // Optionally add history
    if ($includeHistory) {
        $data['history'] = $this->getFieldHistory($userId);
    }

    return $data;
}
```
