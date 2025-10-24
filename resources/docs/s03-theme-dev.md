# S03 Theme Development Documentation

## Overview

The S03 theme system is our third-generation WordPress theme framework. All customer themes are built as child themes of `lbwp-standard-03`, utilizing a component-based architecture and modern WordPress development practices.

## Theme Organization

### Directory Structure
```
wp-content/themes/
├── lbwp-standard-03/              # Parent theme
├── lbwp-standard-03_starter/      # Template for new themes  
├── [customer]-s03/                # Child themes (suffix with -s03)
└── [customer]/                    # Legacy themes (non-s03)
```

**Child Theme Identification**: All themes suffixed with `-s03` are children of `lbwp-standard-03`.

## Parent Theme Structure (lbwp-standard-03)

### Core Architecture
```
lbwp-standard-03/
├── functions.php                  # Theme initialization
├── src/                          # PHP namespace classes
│   └── Standard03/
│       ├── Component/            # Feature components
│       ├── Newsletter/           # Newsletter functionality
│       └── Theme/               # Core theme classes
├── assets/                       # JS and SCSS files
│   ├── scripts/                 # JavaScript files
│   ├── styles/                  # SCSS source and compiled CSS
│   ├── blocks/                  # Block editor scripts
│   ├── fonts/                   # Web fonts
│   └── img/                     # Images and SVG icons
├── views/                        # HTML templates
│   ├── layout/                  # Base layout files
│   ├── blocks/                  # Block templates
│   ├── parts/                   # Reusable template parts
│   ├── components/              # Component templates
│   └── wordpress/               # WordPress core templates
├── lib/                         # External libraries
│   └── FontAwesome/             # FontAwesome versions
└── woocommerce/                 # WooCommerce templates
```

### Functions.php Structure
Every theme has a `functions.php` that loads the theme stack:

```php
<?php
// Parent theme (lbwp-standard-03)
if (!is_child_theme()) {
  $classLoader = new SplClassLoader('Standard03', __DIR__ . '/src');
  $classLoader->register();
  
  $wpWrapper = new \LBWP\Theme\Base\WpWrapper();
  $standardTheme = new Standard03\Theme\Core($wpWrapper);
}
```

```php
<?php
// Child theme pattern
$chChild = new SplClassLoader('Starter', __DIR__ . '/src');
$chChild->register();
$clParent = new SplClassLoader('Standard03', dirname(__DIR__) . '/lbwp-standard-03/src');
$clParent->register();

$wpWrapper = new \LBWP\Theme\Base\WpWrapper();
$starterTheme = new Starter\Theme\Core($wpWrapper);
```

## Theme Features

### Block Editor Integration
- Custom Gutenberg blocks with ACF integration
- Block whitelist system for content control
- Custom block styles and variations
- FontAwesome icon integration

### Component System
**Available Components:**
- **ACF**: Advanced Custom Fields integration
- **Frontend**: Frontend functionality
- **Newsletter**: Email newsletter system
- **Search**: Google Search Engine integration
- **Settings**: Theme settings panel
- **Partner**: Partner listing functionality
- **WebApp**: Progressive Web App features
- **Svg**: SVG icon management system

**Optional E-commerce Components** (when `LBWP_S03_ENABLE_ABOON_COMPONENTS` is defined):
- Shop functionality
- Product filtering
- Checkout enhancements
- Payment integrations
- Bulk pricing
- Product variations

### Navigation System
- **Primary Navigation**: Main desktop navigation
- **Primary Navigation Mobile**: Mobile-specific navigation
- **Meta Navigation**: Header meta links
- Mega menu support
- Mobile hamburger menu

### Asset Management
- **CSS**: Compiled SCSS with responsive design
- **JavaScript**: ES6+ with module pattern
- **Fonts**: Self-hosted web fonts (Inter, Open Sans, Domine)
- **Icons**: FontAwesome with configurable icon packs
- **Images**: Optimized asset delivery

### Multilingual Support
- Polylang integration
- Multilingual sidebars
- Language switcher components
- Translation-ready templates

## Creating New Customer Themes

### Using lbwp-standard-03-starter

The `lbwp-standard-03-starter` theme serves as a template for new customer themes.

#### Required Changes After Copying:

1. **Directory Naming**
   ```
   Copy: lbwp-standard-03_starter
   To: [customer-name]-s03
   ```

2. **style.css Header**
   ```css
   /*
   Theme Name: __THEME_NAME__         → Customer Theme Name
   Theme URI: https://www.comotive.ch
   Author: Michael Sebel / Martin Ott
   Author URI: https://www.comotive.ch
   Template: lbwp-standard-03
   Version: 1.0.0
   Description: __THEME_DESC__        → Theme description
   Text Domain: __THEME_TEXT_DOMAIN__ → customer-theme-slug
   */
   ```

3. **Namespace Updates**
   - Change `Starter` namespace to `CustomerName`
   - Update class names and file references
   - Update autoloader registration in functions.php

4. **Component Registration**
   ```php
   // In src/CustomerName/Theme/Core.php
   namespace CustomerName\Theme;
   
   use Standard03\Theme\Core as BaseTheme;
   
   class Core extends BaseTheme
   {
     // Custom setup and component registration
   }
   ```

#### Starter Theme Structure:
```
customer-name-s03/
├── functions.php                  # Theme initialization
├── style.css                     # Theme header information
├── src/
│   └── CustomerName/
│       ├── Component/
│       │   └── Frontend.php       # Custom frontend functionality
│       └── Theme/
│           └── Core.php           # Main theme class
├── assets/
│   ├── blocks/
│   │   └── child-extend.js        # Block editor extensions
│   ├── scripts/
│   │   └── Main.js                # Custom JavaScript
│   └── styles/
│       ├── scss/                  # SCSS source files
│       ├── child-theme.css        # Compiled styles
│       └── child-editor.css       # Editor styles
└── views/
    └── layout/
        ├── header_TODO.php        # Custom header template
        └── footer_TODO.php        # Custom footer template
```

## Development Standards

### PHP Standards
- **PSR-4 Autoloading**: Namespace structure follows directory structure
- **Naming Convention**: PascalCase for classes, camelCase for methods
- **Inheritance**: Extend parent theme classes, don't override unnecessarily
- **Documentation**: PHPDoc comments for all classes and methods

### CSS/SCSS Standards
- **Prefix**: All theme classes use `s03-` prefix
- **Naming**: BEM-like methodology (`.s03-component__element--modifier`)
- **Organization**: Modular SCSS files by component/feature
- **Responsive**: Mobile-first approach with CSS custom properties

**SCSS File Structure:**
```scss
// Settings and variables
@import "__settings-s03";
@import "_mixins-s03";

// Components
@import "s03-components/buttons";
@import "s03-components/forms";

// Blocks
@import "blocks/s03-hero-teaser";
@import "blocks/s03-accordion";

// Template parts
@import "parts/header";
@import "parts/footer";
```

### JavaScript Standards
- **Module Pattern**: Global namespace object (`Standard03`, `CustomerName`)
- **Initialization**: Single `initialize()` method for setup
- **Event Handling**: Consistent naming (`handle*`, `setup*`, `init*`)
- **jQuery**: Consistent usage with proper event delegation

### HTML Template Standards
- **File Naming**: Kebab-case (`nav-mobile.php`, `hero-teaser.php`)
- **Block Templates**: Prefix with `s03-` for custom blocks
- **Template Registration**: Use `registerViews()` method in Core class
- **Accessibility**: Semantic HTML with ARIA attributes

### File Naming Conventions
- **Directories**: Lowercase with hyphens (`lbwp-standard-03`)
- **PHP Classes**: PascalCase (`Core.php`, `Frontend.php`)
- **Templates**: Kebab-case (`single-header.php`)
- **SCSS Partials**: Underscore prefix (`_mixins.scss`, `_buttons.scss`)
- **Assets**: Descriptive kebab-case (`s03-theme.js`, `child-theme.css`)

### Block Development
- **Naming**: `s03-block-name` pattern
- **Registration**: Via ACF and theme components
- **Templates**: Store in `views/blocks/`
- **Styles**: Component-specific SCSS files
- **JavaScript**: Block-specific scripts in `assets/blocks/`

### Asset Organization
- **Fonts**: Organized by family and version (`fonts/inter/v13/`)
- **Icons**: SVG system through Svg component
- **Images**: Optimized formats, descriptive naming
- **Scripts**: Modular organization with clear dependencies
- **Styles**: Source SCSS separate from compiled CSS

## Workflow Best Practices

### Development Process
1. **Start with Starter Theme**: Copy `lbwp-standard-03_starter`
2. **Update Identifiers**: Change names, namespaces, text domains
3. **Customize Gradually**: Override only what needs customization
4. **Test Responsively**: Ensure mobile-first design compliance
5. **Validate Performance**: Check asset loading and optimization

### Asset Compilation
- **SCSS**: Use provided watch scripts for live compilation
- **JavaScript**: ES6+ with proper module organization  
- **FontAwesome**: Configure appropriate icon pack for project needs
- **Fonts**: Use self-hosted fonts for performance and privacy

### Component Development
- **Extend, Don't Replace**: Inherit from parent components when possible
- **Feature Flags**: Use constants for optional functionality
- **Clean Dependencies**: Only register components that are needed
- **Documentation**: Comment complex functionality thoroughly

### Performance Considerations
- **Conditional Loading**: Load assets only when needed
- **Font Display**: Use `font-display: swap` for web fonts
- **Image Optimization**: Implement lazy loading and responsive images
- **JavaScript**: Use deferred loading for non-critical scripts
- **CSS**: Minimize and combine stylesheets efficiently

This documentation provides the foundation for developing consistent, maintainable WordPress themes within the S03 framework. Always refer to the parent theme codebase for implementation examples and follow the established patterns for best results.