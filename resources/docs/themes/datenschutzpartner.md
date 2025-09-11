# Datenschutzpartner Theme Documentation

**Theme:** datenschutzpartner-s03  
**Purpose:** Data protection consulting service with document generation  
**Reference:** Complex business logic implementation patterns for S03 child themes

---

## src/ Directory - PHP Architecture

### `/src/Datenschutzpartner/Theme/Core.php`
**Extends:** `Standard03\Theme\Core`  
**Purpose:** Main theme controller and component orchestrator

**Key Implementation Patterns:**
```php
// Singleton pattern for theme management
private static $instance = null;

public static function getInstance() {
    if (self::$instance === null) {
        self::$instance = new self();
    }
    return self::$instance;
}
```

**Component Registration System:**
```php
$this->registerComponents(array(
    '\Datenschutzpartner\Component\Frontend',
    '\Datenschutzpartner\Component\Backend',
    '\Datenschutzpartner\Component\Document',
    '\Datenschutzpartner\Component\Shop',
    '\Datenschutzpartner\Component\Crm',
));
```

### `/src/Datenschutzpartner/Component/Document.php`
**Purpose:** Document generation engine for privacy documents

**Key Features:**
```php
// Custom post type registration
public function registerCustomPostTypes() {
    register_post_type('dsp_document', array(
        'public' => true,
        'supports' => array('title', 'editor', 'custom-fields'),
        'capability_type' => 'dsp_document'
    ));
}

// Multi-language document generation
public function generateDocument($language = 'de') {
    $template = $this->getTemplate($language);
    return $this->processConditionalBlocks($template);
}
```

**Conditional Block Processing:**
```php
// Complex logic for conditional content display
private function processConditionalBlocks($content) {
    // Parse conditional statements
    // Apply business logic
    // Generate final document HTML/PDF
}
```

### `/src/Datenschutzpartner/Component/Frontend.php`
**Purpose:** Frontend functionality and asset management

**Asset Enqueuing Strategy:**
```php
public function assets() {
    // Modern ES6 modules
    wp_enqueue_script('dsp-main', 'Main.js', array(), $version, true);
    wp_script_add_data('dsp-main', 'type', 'module');
    
    // Legacy jQuery fallback
    wp_enqueue_script('dsp-legacy', 'datenschutzpartner.js', 
        array('jquery'), $version, true);
}
```

**Custom Block Registration:**
```php
public function registerBlocks() {
    acf_register_block_type(array(
        'name' => 'dsp-stoerer',
        'title' => 'DSP Störer',
        'render_template' => 'views/blocks/dsp-stoerer.php',
        'category' => 'common',
        'supports' => array('color' => true)
    ));
}
```

### `/src/Datenschutzpartner/Component/Shop.php`
**Purpose:** WooCommerce integration for document sales

**Custom Product Types:**
```php
public function addCustomProductTypes() {
    // Document product type
    // Lead session integration
    // Subscription handling
}

// Order processing with lead data
public function processOrder($order_id) {
    $leadData = LeadSession::getLeadData();
    $this->attachLeadToOrder($order_id, $leadData);
}
```

### `/src/Datenschutzpartner/Helper/Generator.php`
**Purpose:** Core document generation logic

**Document Processing Pipeline:**
```php
public function generateHTML($documentId, $formData) {
    $template = $this->loadTemplate($documentId);
    $processed = $this->replaceVariables($template, $formData);
    $conditional = $this->processConditionals($processed);
    return $this->finalizeDocument($conditional);
}

// Field replacement system
private function replaceVariables($content, $data) {
    foreach ($data as $key => $value) {
        $content = str_replace("{{$key}}", $value, $content);
    }
    return $content;
}
```

**PDF Generation:**
```php
public function generatePDF($htmlContent, $options = array()) {
    // PDF library integration
    // Custom styling for print
    // Page break handling
}
```

### `/src/Datenschutzpartner/Helper/LeadSession.php`
**Purpose:** Customer session and lead data management

**Session Management:**
```php
public function storeLeadData($data) {
    $sessionId = $this->generateSessionId();
    set_transient("lead_session_{$sessionId}", $data, HOUR_IN_SECONDS);
    $this->setSessionCookie($sessionId);
}

public function getLeadData() {
    $sessionId = $this->getSessionId();
    return get_transient("lead_session_{$sessionId}");
}
```

---

## assets/ Directory - Styles and Scripts

### `/assets/styles/scss/child-theme.scss`
**Purpose:** Main stylesheet with modern CSS architecture

**Font Optimization:**
```scss
body {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
    font-feature-settings: "liga", "kern";
}
```

**Flexbox Foundation:**
```scss
.dsp-flex-container {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    
    .dsp-content {
        flex: 1;
    }
}
```

### `/assets/styles/scss/__settings.scss`
**Purpose:** Comprehensive design system with CSS custom properties

**Color System:**
```scss
:root {
    // Primary brand colors
    --dsp-color-primary: #0066CC;
    --dsp-color-secondary: #FF6B35;
    --dsp-color-success: #28A745;
    --dsp-color-warning: #FFC107;
    --dsp-color-danger: #DC3545;
    
    // Gradient variants
    --dsp-gradient-primary: linear-gradient(135deg, var(--dsp-color-primary), #004499);
    
    // Color overrides for components
    --dsp-override-bg: var(--dsp-color-primary);
    --dsp-override-text: white;
}
```

**Responsive Typography:**
```scss
// Fluid typography system
--dsp-h1-font-size: clamp(2rem, 4vw, 3.5rem);
--dsp-h2-font-size: clamp(1.5rem, 3vw, 2.5rem);
--dsp-body-font-size: clamp(1rem, 2vw, 1.125rem);
```

### `/assets/styles/scss/_fonts.scss`
**Purpose:** Modern font loading strategy

**Font Family Definitions:**
```scss
// Poppins for headings
@font-face {
    font-family: 'Poppins';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('../fonts/poppins-v22-latin-600.woff2') format('woff2');
}

// Inter for body text
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('../fonts/inter-v18-latin-regular.woff2') format('woff2');
}
```

### `/assets/styles/scss/components/_buttons.scss`
**Purpose:** Modern button system with custom property integration

**Button Architecture:**
```scss
.btn {
    border-radius: 99px;
    padding: 0.75rem 2rem;
    font-weight: 600;
    transition: all 0.3s ease;
    
    // CSS custom property integration
    background-color: var(--dsp-override-bg, var(--dsp-color-primary));
    color: var(--dsp-override-text, white);
    
    &:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    // Responsive padding
    @include media-breakpoint-up(md) {
        padding: 1rem 2.5rem;
    }
}
```

### `/assets/styles/scss/components/_mega-menu.scss`
**Purpose:** Complex navigation with grid layout and graphics

**Grid-Based Mega Menu:**
```scss
.dsp-mega-menu {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
    padding: 2rem;
    
    &__item {
        position: relative;
        padding: 1.5rem;
        border-radius: 8px;
        background: white;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        
        // Background SVG graphics
        &::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 60px;
            height: 60px;
            background-image: var(--menu-icon);
            background-size: contain;
            opacity: 0.1;
        }
        
        &--academy::before {
            background-image: url('../img/svg/dsp-graphic--academy.svg');
        }
    }
}
```

### `/assets/styles/scss/blocks/_custom-dsp-stoerer.scss`
**Purpose:** Promotional banner component with color system

**Color Override System:**
```scss
.wp-block-acf-dsp-stoerer {
    padding: 2rem;
    border-radius: 12px;
    background: var(--dsp-override-bg, var(--dsp-color-primary));
    color: var(--dsp-override-text, white);
    
    // Dynamic color classes
    &.has-red-background {
        --dsp-override-bg: #E53E3E;
        --dsp-override-text: white;
    }
    
    &.has-blue-background {
        --dsp-override-bg: #3182CE;
        --dsp-override-text: white;
    }
}
```

### `/assets/scripts/Main.js`
**Purpose:** Modern ES6 entry point

**Module Import Pattern:**
```javascript
import Navigation from './modules/Navigation.js?v=1.2.0';
import TestimonialSlider from './modules/TestimonialSlider.js?v=1.2.0';

class DatenschutzpartnerApp {
    constructor() {
        this.navigation = new Navigation();
        this.slider = new TestimonialSlider();
        this.init();
    }
    
    init() {
        this.buttonizeDescriptions();
        this.initScrollIndicator();
    }
    
    buttonizeDescriptions() {
        document.querySelectorAll('.gform_description').forEach(desc => {
            if (desc.textContent.includes('Button:')) {
                desc.classList.add('btn', 'btn-primary');
            }
        });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new DatenschutzpartnerApp();
});
```

### `/assets/scripts/modules/Navigation.js`
**Purpose:** Mobile navigation handler

**Modern DOM API Usage:**
```javascript
export default class Navigation {
    constructor() {
        this.hamburger = document.querySelector('.hamburger');
        this.body = document.body;
        this.bindEvents();
    }
    
    bindEvents() {
        this.hamburger?.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggleMobileNav();
        });
    }
    
    toggleMobileNav() {
        this.body.classList.toggle('mobile-nav-open');
        this.hamburger.setAttribute('aria-expanded', 
            this.body.classList.contains('mobile-nav-open'));
    }
}
```

### `/assets/scripts/datenschutzpartner.js`
**Purpose:** Comprehensive jQuery-based functionality

**Document Management:**
```javascript
var Datenschutzpartner = {
    init: function() {
        this.initDocumentFeatures();
        this.initWooCommerceIntegration();
        this.initCopyToClipboard();
    },
    
    initDocumentFeatures: function() {
        // PDF generation handling
        $('.generate-pdf').on('click', function(e) {
            e.preventDefault();
            Datenschutzpartner.generatePDF($(this).data('document-id'));
        });
    },
    
    generatePDF: function(documentId) {
        $.ajax({
            url: dsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_document_pdf',
                document_id: documentId,
                nonce: dsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    window.open(response.data.pdf_url, '_blank');
                }
            }
        });
    }
};
```

**WooCommerce Integration:**
```javascript
initWooCommerceIntegration: function() {
    // Lead session handling in checkout
    $('form.checkout').on('submit', function() {
        var leadData = Datenschutzpartner.collectLeadData();
        $('<input>').attr({
            type: 'hidden',
            name: 'lead_session_data',
            value: JSON.stringify(leadData)
        }).appendTo(this);
    });
},
```

---

## views/ Directory - Templates

### `/views/layout/header.php`
**Purpose:** Complex header with conditional mega menu

**Conditional Mega Menu:**
```php
<?php if (has_nav_menu('primary-navigation')) : ?>
    <nav class="dsp-main-nav" role="navigation">
        <?php if ($mega_menu_enabled) : ?>
            <div class="dsp-mega-menu-container">
                <?php $this->view('components/mega-menu-inner'); ?>
            </div>
        <?php else : ?>
            <?php wp_nav_menu(array(
                'theme_location' => 'primary-navigation',
                'container' => false,
                'menu_class' => 'dsp-nav-menu'
            )); ?>
        <?php endif; ?>
    </nav>
<?php endif; ?>
```

**User Authentication State:**
```php
<div class="dsp-user-actions">
    <?php if (is_user_logged_in()) : ?>
        <a href="<?php echo wc_get_account_endpoint_url('dashboard'); ?>" class="btn btn-outline">
            <?php _e('Mein Konto', 'datenschutzpartner'); ?>
        </a>
    <?php else : ?>
        <a href="<?php echo wc_get_page_permalink('myaccount'); ?>" class="btn btn-primary">
            <?php _e('Anmelden', 'datenschutzpartner'); ?>
        </a>
    <?php endif; ?>
</div>
```

### `/views/blocks/dsp-stoerer.php`
**Purpose:** Promotional banner with ACF integration

**ACF Field Integration:**
```php
<?php
$background_color = get_field('background_color');
$text_color = get_field('text_color');
$content = get_field('content');
$button = get_field('button');

// Generate CSS custom properties
$style_vars = array();
if ($background_color) {
    $style_vars[] = '--dsp-override-bg: ' . $background_color;
}
if ($text_color) {
    $style_vars[] = '--dsp-override-text: ' . $text_color;
}
?>

<div class="wp-block-acf-dsp-stoerer" <?php if (!empty($style_vars)) echo 'style="' . implode('; ', $style_vars) . '"'; ?>>
    <div class="dsp-stoerer__content">
        <?php echo $content; ?>
    </div>
    
    <?php if ($button) : ?>
        <div class="dsp-stoerer__button">
            <a href="<?php echo $button['url']; ?>" class="btn btn-secondary">
                <?php echo $button['title']; ?>
            </a>
        </div>
    <?php endif; ?>
</div>
```

### `/views/blocks/dsp-scroll-indicator.php`
**Purpose:** Smooth scroll navigation element

**Accessibility Integration:**
```php
<?php
$target_anchor = get_field('target_anchor');
$icon = get_field('icon') ?: 'fa-chevron-down';
$text = get_field('text') ?: __('Mehr erfahren', 'datenschutzpartner');
?>

<div class="wp-block-acf-dsp-scroll-indicator">
    <button 
        type="button" 
        class="dsp-scroll-btn" 
        data-target="<?php echo esc_attr($target_anchor); ?>"
        aria-label="<?php echo esc_attr($text); ?>"
    >
        <span class="dsp-scroll-btn__text"><?php echo $text; ?></span>
        <i class="fas <?php echo esc_attr($icon); ?>" aria-hidden="true"></i>
    </button>
</div>
```

---

## Key Development Patterns for Reference

### 1. **Complex Business Logic Implementation**
- Document generation engine with conditional rendering
- Multi-language content management
- Lead session tracking with secure data handling
- E-commerce integration with custom product types

### 2. **Modern CSS Architecture**
- CSS custom properties for dynamic theming
- Color override system for component flexibility
- Fluid typography with clamp() functions
- Grid-based layouts with fallbacks

### 3. **JavaScript Architecture Patterns**
- ES6 modules with version control in imports
- Legacy jQuery fallback for compatibility
- Event delegation and modern DOM APIs
- AJAX integration with WordPress nonce security

### 4. **Component Development Strategy**
- Modular component registration system
- ACF integration with custom field management
- Block editor enhancements with custom styles
- Template hierarchy with conditional rendering

### 5. **Performance Optimization Techniques**
- Font loading optimization with font-display: swap
- Asset versioning and cache busting
- Conditional script loading based on context
- Optimized font formats (WOFF2 priority)

### 6. **Security and Data Handling**
- Secure session management with transients
- WordPress nonce integration for AJAX
- Capability-based access control
- Sanitized data processing

This theme demonstrates enterprise-level WordPress development with complex business logic, modern development practices, and sophisticated user experience features. The document generation system and lead management functionality provide excellent examples of custom WordPress application development.