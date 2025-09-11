# Alte Schmitte Theme Documentation

**Theme:** alteschmitte-03  
**Purpose:** Custom theme for Genossenschaft Alte Schmitte  
**Reference:** Development patterns and solutions for future S03 child themes

---

## src/ Directory - PHP Architecture

### `/src/Alteschmitte/Theme/Core.php`
**Extends:** `Standard03\Theme\Core`  
**Purpose:** Main theme controller and configuration

**Key Implementations:**
```php
// Custom navigation menu registration
register_nav_menu('legal-navigation', 'Legal Navigation');

// Auto-hide thumbnails filter
public function defaultHideThumbnail($field) {
    if ($field['name'] == 'hide_thumbnail') {
        $field['default_value'] = 1;
    }
    return $field;
}
```

**Asset Loading Patterns:**
- Child JavaScript: `child-theme.js` with jQuery dependency
- Child CSS: `child-theme.css` with version control
- Block editor extensions: `child-extend.js` and `child-editor.css`

**Component Registration:**
```php
$this->registerComponents(array(
    '\Alteschmitte\Component\Frontend',
));
```

### `/src/Alteschmitte/Component/Frontend.php`
**Extends:** `LBWP\Theme\Base\Component`  
**Purpose:** Frontend functionality container  
**Current State:** Empty placeholder for future features
**Pattern:** Standard component structure ready for extension

---

## assets/ Directory - Styles and Scripts

### `/assets/styles/scss/child-theme.scss`
**Purpose:** Main stylesheet entry point

**Font Optimization:**
```scss
body {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}
```

**Page-Specific Styling:**
```scss
body.page, body.single-post {
    // Page-specific styles
}
```

**Card Component Customization:**
```scss
.s03-default-grid .card {
    // Custom card styling with geometric elements
}
```

### `/assets/styles/scss/__settings.scss`
**Purpose:** CSS Custom Properties and brand configuration

**Color System:**
```scss
:root {
    --as-color-red: #D84F17;
    --as-color-gold: #D8AF1F;
    --as-color-dark-blue: #2A5583;
    --as-color-light-blue: #4A95C6;
}
```

**Typography System:**
```scss
// Responsive heading system with breakpoint variations
--as-h1-base-font-size: 35px;
--as-h1-md-font-size: 45px;
--as-h1-lg-font-size: 60px;
```

**Pattern:** Comprehensive responsive typography using CSS custom properties

### `/assets/styles/scss/_fonts.scss`
**Purpose:** Font face declarations for self-hosted Roboto

**Implementation Pattern:**
```scss
@font-face {
    font-display: auto;
    font-family: 'Roboto';
    font-style: normal;
    font-weight: 300;
    src: url('../fonts/roboto/roboto-v30-latin-300.woff2') format('woff2');
}
```

**Covers:** 300, 400, 500 weights + italic variants with fallback formats

### `/assets/styles/scss/_typo.scss`
**Purpose:** Typography rules and body text styling

**Body Typography:**
```scss
body {
    font-size: 18px;
    font-weight: 300;
    line-height: 1.5;
    
    @include media-breakpoint-up(md) {
        font-size: 20px;
    }
}
```

### `/assets/styles/scss/blocks/_s03-person-override.scss`
**Purpose:** Person component customization

**Card Styling with Rotation:**
```scss
.s03-person-card {
    transform: rotate(-1deg);
    transition: transform 0.3s ease;
    
    &:hover {
        transform: rotate(0deg);
    }
    
    // Randomized rotations with nth-child
    &:nth-child(3n+1) { transform: rotate(1deg); }
    &:nth-child(3n+2) { transform: rotate(-0.5deg); }
}
```

**Image Aspect Ratio:**
```scss
.s03-person-card__image {
    aspect-ratio: 4/3;
    object-fit: cover;
}
```

### `/assets/styles/scss/blocks/_s03-image-text-overrides.scss`
**Purpose:** Custom image-text block variations

**Geometric Clip-Path Styling:**
```scss
.wp-block-lbwp-image-text.is-style-content-clip-path {
    .s03-image-text__content {
        clip-path: polygon(0 15px, calc(100% - 15px) 0, 100% calc(100% - 15px), 15px 100%);
        background: var(--as-color-red);
        color: white;
    }
}
```

**Border Effects:**
```scss
.is-style-image-border {
    .s03-image-text__image {
        border: 10px solid white;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        transform: rotate(-1deg);
    }
}
```

### `/assets/styles/scss/parts/_header.scss`
**Purpose:** Complex header with hero slider and geometric design

**Grid Overlay System:**
```scss
.s03-header {
    position: relative;
    
    &__grid-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 2;
    }
}
```

**Logo Container with Clip-Path:**
```scss
.as-logo-container {
    clip-path: polygon(0 0, calc(100% - 40px) 0, 100% 100%, 40px 100%);
    background: var(--as-color-red);
}
```

**Slick Slider Customization:**
```scss
.slick-arrow {
    &:before {
        color: var(--as-color-red);
        font-size: 30px;
    }
}
```

### `/assets/styles/scss/parts/_footer.scss`
**Purpose:** Rich footer with decorative elements and grid layout

**CSS Grid Layout:**
```scss
.s03-footer__inner {
    display: grid;
    grid-template-areas: 
        "logo address"
        "quote widgets";
    grid-template-columns: 1fr 2fr;
    gap: 2rem;
}
```

**Decorative Positioned Elements:**
```scss
.as-footer-photo {
    position: absolute;
    z-index: 1;
    transform: rotate(-5deg);
    
    &--1 { top: 20px; right: 100px; }
    &--2 { bottom: 40px; left: 50px; }
}
```

### `/assets/scripts/child-theme.js`
**Purpose:** Frontend JavaScript functionality

**Global Namespace Pattern:**
```javascript
var Alteschmitte = {
    initialize: function() {
        this.initHeaderScroll();
        this.initHeroSlider();
    },
    
    initHeaderScroll: function() {
        $(window).scroll(function() {
            if ($(this).scrollTop() > 50) {
                $('.s03-header').addClass('scrolled');
            } else {
                $('.s03-header').removeClass('scrolled');
            }
        });
    }
};
```

**Slick Slider Configuration:**
```javascript
initHeroSlider: function() {
    $('.as-hero-slider').slick({
        autoplay: true,
        autoplaySpeed: 5000,
        fade: true,
        arrows: true,
        dots: false
    });
}
```

**Focus Point Handling:**
```javascript
// Handle focus points in slider clones
$('.as-hero-slider').on('afterChange', function() {
    // Focus point logic for slider clones
});
```

### `/assets/blocks/child-extend.js`
**Purpose:** Gutenberg block style registration

**Block Style Registration Pattern:**
```javascript
wp.domReady(function() {
    wp.blocks.registerBlockStyle('lbwp/person', {
        name: 'randomized',
        label: 'Randomized Display'
    });
    
    wp.blocks.registerBlockStyle('lbwp/image-text', {
        name: 'image-border',
        label: 'Image with Border'
    });
    
    wp.blocks.registerBlockStyle('lbwp/image-text', {
        name: 'content-clip-path',
        label: 'Geometric Content Background'
    });
});
```

---

## views/ Directory - Templates

### `/views/layout/header.php`
**Purpose:** Main header template with hero functionality

**Meta Navigation Integration:**
```php
if (has_nav_menu('meta-navigation')) {
    wp_nav_menu(array(
        'theme_location' => 'meta-navigation',
        'container' => false,
        'menu_class' => 's03-meta-nav__menu'
    ));
}
```

**Conditional Hero Content:**
```php
$heroType = get_field('hero_type');
if ($heroType === 'slider') {
    // Hero slider implementation
} elseif (has_post_thumbnail()) {
    // Featured image fallback
}
```

**Geometric Logo Positioning:**
```php
<div class="as-logo-container">
    <div class="as-logo-content">
        <?php echo file_get_contents(get_template_directory_uri() . '/assets/svg/logo-alte-schmitte.svg'); ?>
    </div>
</div>
```

### `/views/layout/footer.php`
**Purpose:** Footer with grid layout and decorative elements

**Grid Content Areas:**
```php
<div class="s03-footer__inner">
    <div class="as-footer-logo">
        <!-- Logo content -->
    </div>
    <div class="as-footer-address">
        <!-- Address content -->
    </div>
    <div class="as-footer-quote">
        <!-- Quote content -->
    </div>
    <div class="as-footer-widgets">
        <!-- Widget content -->
    </div>
</div>
```

**Decorative Background System:**
```php
// Hardcoded image IDs for decorative photos
$decorativeImages = array(123, 456, 789);
foreach ($decorativeImages as $index => $imageId) {
    echo '<div class="as-footer-photo as-footer-photo--' . ($index + 1) . '">';
    echo wp_get_attachment_image($imageId, 'medium');
    echo '</div>';
}
```

**Legal Navigation:**
```php
if (has_nav_menu('legal-navigation')) {
    wp_nav_menu(array(
        'theme_location' => 'legal-navigation',
        'container' => 'nav',
        'menu_class' => 'as-legal-nav'
    ));
}
```

### `/views/parts/post-tags.php`
**Purpose:** Post metadata display

**Date and Category Display:**
```php
<div class="s03-post-meta">
    <span class="post-date">
        <i class="fas fa-calendar"></i>
        <?php echo get_the_date(); ?>
    </span>
    
    <?php $categories = get_the_category(); ?>
    <?php if (!empty($categories)) : ?>
        <span class="post-categories">
            <i class="fas fa-folder"></i>
            <?php echo esc_html($categories[0]->name); ?>
        </span>
    <?php endif; ?>
</div>
```

---

## Key Development Patterns for Reference

### 1. **Geometric Design Implementation**
- Use CSS `clip-path` for angular geometric shapes
- Implement rotation transforms for playful card effects
- Combine geometric backgrounds with functional content

### 2. **CSS Custom Properties Strategy**
- Define brand colors as CSS custom properties
- Create responsive typography system with breakpoint variations
- Use custom properties for maintainable theming

### 3. **Component Override Patterns**
- Extend parent theme components with `.is-style-*` variations
- Use SCSS for component-specific customizations
- Maintain parent functionality while adding custom features

### 4. **Asset Loading Best Practices**
- Self-host fonts with `font-display: auto`
- Use module pattern for JavaScript organization
- Implement proper asset versioning and dependency management

### 5. **Template Customization Approach**
- Override specific layout files (header.php, footer.php)
- Maintain parent theme template structure
- Use conditional logic for flexible content display

### 6. **Block Editor Enhancement**
- Register custom block styles for enhanced editor experience
- Provide multiple styling options for flexibility
- Use descriptive labels for editor usability

This documentation serves as a reference for implementing similar features and patterns in future S03 child themes. The Alte Schmitte theme demonstrates sophisticated geometric design, robust asset management, and flexible content presentation patterns.