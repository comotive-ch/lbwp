# LBWP Framework Documentation (specifically for claude code)

This documentation covers all public static functions from the LBWP framework's Helper and Util classes. It's optimized for AI assistant usage with concise function signatures and brief descriptions.

## Files

- **helper-functions.md** - All public static functions from `LBWP\Helper\*` classes
- **util-functions.md** - All public static functions from `LBWP\Util\*` classes

## Usage

These functions can be called directly using their full class names, but to "use" statements on top of a class, if possible, are preferred.

```php
use LBWP\Helper\ZipDistance;
use LBWP\Util\WordPress;
use LBWP\Util\ArrayManipulation;
// Example usage
$distance = ZipDistance::getDistance('8000', '8001');
$excerpt = WordPress::getConfigurableExcerpt($postId, 150, '...');
$sortedArray = ArrayManipulation::sortByNumericField($data, 'priority');
```

## Organization

- **Helper classes** - Specialized functionality (converters, metaboxes, settings, etc.)
- **Util classes** - General utilities (WordPress helpers, array manipulation, file operations)

Always check for functions/wrappers in the LBWP\Util\WordPress class, as it contains all WordPress related functions, it's preffered to use these instead of the WP core functions.
All functions are documented with their class namespace, parameters, return types, and brief descriptions for quick reference.