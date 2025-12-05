# WordPress Development with LBWP Framework

This skill provides specific instructions for WordPress development using the LBWP framework.

## Framework Core Utilities

The LBWP framework provides pre-built utilities in `wp-content/plugins/lbwp/LBWP/Util/WordPress.php` - **ALWAYS use these instead of direct WordPress functions** for consistency.

---

## Registering Post Types

### Use LBWP Utility Function

**ALWAYS use** `WordPress::registerType()` instead of `register_post_type()`:

```php
use LBWP\Util\WordPress;

// Basic registration
WordPress::registerType(
    'team-member',           // Slug
    'Team Member',           // Singular name
    'Team Members',          // Plural name
    [],                      // Config overrides (optional)
    'n'                      // Letter for German grammar (optional, default 's')
);

// With custom configuration
WordPress::registerType(
    'product',
    'Product',
    'Products',
    [
        'menu_icon' => 'dashicons-products',
        'supports' => ['title', 'editor', 'thumbnail'],
        'public' => true,
        'has_archive' => true,
        'show_in_rest' => true,  // Enable Gutenberg
        'rewrite' => ['slug' => 'produkte']
    ]
);
```

### Common Configuration Options

```php
[
    'menu_icon' => 'dashicons-icon-name',
    'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
    'public' => true,
    'publicly_queryable' => true,
    'show_ui' => true,
    'show_in_menu' => true,
    'show_in_rest' => true,        // Required for Gutenberg
    'has_archive' => true,
    'hierarchical' => false,        // true for page-like behavior
    'rewrite' => ['slug' => 'custom-slug'],
    'capability_type' => 'post',
    'menu_position' => 5
]
```

---

## Registering Taxonomies

### Use LBWP Utility Function

**ALWAYS use** `WordPress::registerTaxonomy()` instead of `register_taxonomy()`:

```php
use LBWP\Util\WordPress;

// Basic registration
WordPress::registerTaxonomy(
    'product-category',      // Slug
    'Product Category',      // Singular name
    'Product Categories',    // Plural name
    '',                      // Letter for German grammar
    [],                      // Config overrides (optional)
    'product'                // Post type(s) to assign to
);

// Assign to multiple post types
WordPress::registerTaxonomy(
    'topic',
    'Topic',
    'Topics',
    '',
    [
        'hierarchical' => true,
        'show_in_rest' => true,
        'show_admin_column' => true
    ],
    ['post', 'page', 'custom-type']  // Multiple types
);

// Non-hierarchical (tag-like)
WordPress::registerTaxonomy(
    'skill',
    'Skill',
    'Skills',
    '',
    [
        'hierarchical' => false,  // Makes it tag-like
        'show_tagcloud' => true
    ],
    'team-member'
);
```

---

## Working with ACF (Advanced Custom Fields)

### Extend ACFBase Component

For ACF-heavy features, extend `LBWP\Theme\Component\ACFBase`:

```php
<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Component\ACFBase;

/**
 * Component for managing team members with ACF fields
 * @package LBWP\Theme\Component
 * @author Your Name <email@example.com>
 */
class TeamMember extends ACFBase
{
    /**
     * Register ACF field groups
     */
    public function fields()
    {
        // Register field groups here using acf_add_local_field_group()
    }

    /**
     * Register ACF blocks if needed
     */
    public function blocks()
    {
        // Register blocks here using $this->registerBlock()
    }
}
```

### ACFBase Helper Methods

The ACFBase component provides these helper methods:

#### Get Post Meta (ACF Fields)

```php
// Get meta for current post
$value = ACFBase::meta('field_name');

// Get meta for specific post
$value = ACFBase::meta('field_name', $postId);

// Get array of values (non-single)
$values = ACFBase::meta('field_name', $postId, false);
```

#### Check if Value is Selected

```php
// Check if value exists in array field (checkbox, multi-select)
if (ACFBase::isSelected('checkbox_field', 'value', $postId)) {
    // Value is selected
}
```

#### Get Options Page Values

```php
// Get option from ACF options page
$value = ACFBase::option('option_name');

// Check if option is active (checkbox on options page)
if (ACFBase::isOptionActive('feature_enabled')) {
    // Feature is enabled
}
```

### Register ACF Blocks

```php
/**
 * Register ACF blocks
 */
public function blocks()
{
    $this->registerBlock([
        'name' => 'team-grid',
        'title' => 'Team Grid',
        'description' => 'Display team members in a grid',
        'preview' => true,  // Enable preview mode
        'post_types' => ['page', 'post'],
        'category' => 'layout',
        'icon' => 'groups',
        'keywords' => ['team', 'members', 'grid']
    ]);
}
```

### Register ACF Options Pages

```php
/**
 * Initialize ACF features
 */
public function acfInit()
{
    $this->addOptionsPage(
        'Theme Settings',           // Title
        'theme-settings',            // Slug
        'options-general.php',       // Parent menu
        [
            'icon_url' => 'dashicons-admin-settings',
            'position' => 60
        ]
    );
}
```

---

## WordPress Utility Methods

### Get Database Object

```php
use LBWP\Util\WordPress;

$db = WordPress::getDb();
$results = $db->get_results($db->prepare(
    "SELECT * FROM {$db->prefix}tablename WHERE id = %d",
    $id
));
```

### React to Post Type Changes

```php
use LBWP\Util\WordPress;

// Run callback when any change occurs to a post type
WordPress::onTypeChange('product', function() {
    // Clear cache, rebuild index, etc.
    delete_transient('product_cache');
});
```

This triggers on:
- Save/update
- Delete
- Trash/untrash
- Status transition
- Sortable type reordering

### Check if Plugin is Active

```php
use LBWP\Util\WordPress;

if (WordPress::isPluginActive('woocommerce/woocommerce.php')) {
    // WooCommerce is active
}
```

### Get Term Field List

```php
use LBWP\Util\WordPress;

// Get array of term slugs from a post
$slugs = WordPress::getTermFieldList($postId, 'category', 'slug');

// Get array of term names
$names = WordPress::getTermFieldList($postId, 'category', 'name');
```

---

## Best Practices

### 1. Component Structure for Custom Post Types

Create a dedicated component for each major post type:

```php
<?php

namespace LBWP\Theme\Component;

use LBWP\Theme\Base\Component;
use LBWP\Util\WordPress;

/**
 * Component for managing products
 * @package LBWP\Theme\Component
 * @author Your Name <email@example.com>
 */
class Products extends Component
{
    /**
     * @var string post type slug
     */
    const POST_TYPE = 'product';

    /**
     * @var string taxonomy slug
     */
    const TAXONOMY_CATEGORY = 'product-category';

    /**
     * Initialize the component
     */
    public function init()
    {
        // Register post type and taxonomies
        $this->registerPostTypes();

        // Add hooks
        add_action('save_post_' . self::POST_TYPE, [$this, 'saveProduct']);
        add_filter('the_content', [$this, 'modifyProductContent']);

        // React to changes
        WordPress::onTypeChange(self::POST_TYPE, function() {
            $this->clearProductCache();
        });
    }

    /**
     * Register custom post types and taxonomies
     */
    protected function registerPostTypes()
    {
        WordPress::registerType(
            self::POST_TYPE,
            'Product',
            'Products',
            [
                'menu_icon' => 'dashicons-products',
                'supports' => ['title', 'editor', 'thumbnail'],
                'show_in_rest' => true
            ]
        );

        WordPress::registerTaxonomy(
            self::TAXONOMY_CATEGORY,
            'Product Category',
            'Product Categories',
            '',
            ['show_in_rest' => true],
            self::POST_TYPE
        );
    }

    /**
     * Save product data
     *
     * @param int $postId The post ID
     * @return void
     */
    public function saveProduct($postId)
    {
        // Save logic
    }

    /**
     * Clear product cache
     *
     * @return void
     */
    protected function clearProductCache()
    {
        delete_transient('product_list_cache');
    }
}
```

### 2. ACF Field Groups - Use Local JSON

Always store ACF field groups in the theme:

```php
// In your theme's functions.php or component
add_filter('acf/settings/save_json', function($path) {
    return get_stylesheet_directory() . '/acf-json';
});

add_filter('acf/settings/load_json', function($paths) {
    $paths[] = get_stylesheet_directory() . '/acf-json';
    return $paths;
});
```

### 3. Use Constants for Post Type/Taxonomy Slugs

```php
class Products extends Component
{
    const POST_TYPE = 'product';
    const TAX_CATEGORY = 'product-category';
    const TAX_TAG = 'product-tag';

    // Use constants throughout
    WordPress::registerType(self::POST_TYPE, ...);
}
```

### 4. Query Custom Post Types Efficiently

```php
// Use WP_Query with proper parameters
$query = new WP_Query([
    'post_type' => 'product',
    'posts_per_page' => 10,
    'tax_query' => [
        [
            'taxonomy' => 'product-category',
            'field' => 'slug',
            'terms' => 'featured'
        ]
    ],
    'meta_query' => [
        [
            'key' => 'price',
            'value' => 100,
            'compare' => '>',
            'type' => 'NUMERIC'
        ]
    ]
]);

// Always reset post data after custom queries
wp_reset_postdata();
```

### 5. Cache Expensive Queries

```php
/**
 * Get all products with caching
 *
 * @return array Array of product posts
 */
protected function getAllProducts()
{
    $cacheKey = 'all_products_v1';
    $products = get_transient($cacheKey);

    if ($products === false) {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'posts_per_page' => -1
        ]);
        $products = $query->posts;
        set_transient($cacheKey, $products, HOUR_IN_SECONDS);
    }

    return $products;
}
```

---

## Common Patterns

### Register Everything in One Method

```php
/**
 * Register all custom post types and taxonomies
 */
protected function registerPostTypes()
{
    // Post types first
    WordPress::registerType('event', 'Event', 'Events', [...]);
    WordPress::registerType('location', 'Location', 'Locations', [...]);

    // Then taxonomies
    WordPress::registerTaxonomy('event-type', 'Event Type', 'Event Types', '', [...], 'event');
    WordPress::registerTaxonomy('region', 'Region', 'Regions', '', [...], 'location');
}
```

### Separate Components for Separation of Concerns

```
LBWP/Theme/Component/
├── Products.php           # Product post type
├── ProductArchive.php     # Product archive display
├── ProductFilter.php      # Product filtering
└── ProductACF.php         # Product ACF fields (extends ACFBase)
```

---

## Quick Reference

```php
// Post Types
WordPress::registerType($slug, $singular, $plural, $config, $letter);

// Taxonomies
WordPress::registerTaxonomy($slug, $singular, $plural, $letter, $config, $types);

// Database
$db = WordPress::getDb();

// React to changes
WordPress::onTypeChange($type, $callback);

// ACF Helpers
ACFBase::meta($name, $postId, $single);
ACFBase::option($name);
ACFBase::isSelected($name, $value, $postId);
ACFBase::isOptionActive($option);

// Plugin check
WordPress::isPluginActive($plugin);

// Term fields
WordPress::getTermFieldList($postId, $taxonomy, $field);
```

---

## Next Steps (To Be Expanded)

- [ ] WooCommerce integration patterns
- [ ] REST API endpoints
- [ ] Custom admin columns
- [ ] Bulk actions
- [ ] Import/export workflows
- [ ] Performance optimization
- [ ] Multilingual setup (WPML/Polylang)
- [ ] Search integration
- [ ] Media handling
- [ ] Email notifications
- [ ] Cron jobs