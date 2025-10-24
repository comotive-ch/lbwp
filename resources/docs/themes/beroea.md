# Beroea Shop Theme Documentation

**Theme:** beroea-shop  
**Purpose:** Multi-currency e-commerce platform with geographic pricing and ERP integration  
**Reference:** Complex international e-commerce patterns for S03 child themes

---

## src/ Directory - PHP Architecture

### `/src/BeroeaShop/Theme/Core.php`
**Extends:** `Standard03\Theme\Core`  
**Purpose:** Main theme controller with extensive e-commerce and multilingual features

**Component Registration:**
```php
$this->registerComponents(array(
    '\BeroeaShop\Component\ACF',
    '\BeroeaShop\Component\Backend',
    '\BeroeaShop\Component\Shop',
    '\BeroeaShop\Component\Filter',
    '\BeroeaShop\Component\Search',
    '\BeroeaShop\Component\LoungeApi',
    '\BeroeaShop\Component\OpenCartLegacy',
    '\BeroeaShop\Component\Erp\Product',
    '\BeroeaShop\Component\Erp\Inventory',
    '\BeroeaShop\Component\Erp\Customer',
));
```

**Icon System Management:**
```php
// Comprehensive FontAwesome icon mapping (100+ icons)
public function setupIconSystem() {
    $icon_mapping = array(
        'book' => 'fas fa-book',
        'audio' => 'fas fa-headphones',
        'video' => 'fas fa-video',
        'digital' => 'fas fa-download',
        // ... extensive icon library
    );
    
    add_filter('beroea_product_icons', function($icons) use ($icon_mapping) {
        return array_merge($icons, $icon_mapping);
    });
}
```

**Framework Integration:**
```php
// SliderGallery (Slick) configuration
SliderGallery::setConfigAttr('homepage-products', array(
    'dots' => true,
    'arrows' => true,
    'slidesToShow' => 4,
    'responsive' => array(
        array('breakpoint' => 768, 'settings' => array('slidesToShow' => 2)),
        array('breakpoint' => 480, 'settings' => array('slidesToShow' => 1))
    )
));
```

### `/src/BeroeaShop/Component/Shop.php`
**Purpose:** Core e-commerce engine with multi-currency and geographic features

**Multi-Currency System:**
```php
public function initCurrencySystem() {
    // Currency detection based on IP location
    add_action('init', array($this, 'detectUserCurrency'));
    
    // Dynamic price conversion
    add_filter('woocommerce_get_price_html', array($this, 'convertPriceDisplay'), 10, 2);
    
    // Cart currency handling
    add_action('woocommerce_add_to_cart', array($this, 'validateCartCurrency'));
}

public function detectUserCurrency() {
    $user_country = $this->getUserCountryByIP();
    $currency = ($user_country === 'DE') ? 'EUR' : 'CHF';
    
    if (!isset($_COOKIE['beroea_currency'])) {
        setcookie('beroea_currency', $currency, time() + (86400 * 30), '/');
        WC()->session->set('currency', $currency);
    }
}
```

**Customer Type Pricing:**
```php
public function getCustomerTypePrice($product_id, $customer_type = 'private') {
    $price_fields = array(
        'private_chf' => get_field('price_private_chf', $product_id),
        'private_eur' => get_field('price_private_eur', $product_id),
        'reseller_chf' => get_field('price_reseller_chf', $product_id),
        'reseller_eur' => get_field('price_reseller_eur', $product_id)
    );
    
    $currency = WC()->session->get('currency', 'CHF');
    $price_key = strtolower($customer_type . '_' . $currency);
    
    return isset($price_fields[$price_key]) ? $price_fields[$price_key] : 0;
}
```

**Geographic Stock Management:**
```php
public function getLocationBasedStock($product_id) {
    $user_country = $this->getUserCountry();
    $stock_field = ($user_country === 'DE') ? 'stock_de' : 'stock_ch';
    
    $stock = get_field($stock_field, $product_id);
    return max(0, intval($stock));
}
```

**Advanced Shipping Calculation:**
```php
public function calculateShipping($package) {
    $total_weight = WC()->cart->get_cart_contents_weight();
    $destination_country = $package['destination']['country'];
    $customer_type = $this->getCustomerType();
    
    $base_rates = array(
        'CH' => array('private' => 8.50, 'reseller' => 12.00),
        'DE' => array('private' => 12.00, 'reseller' => 15.00),
        'AT' => array('private' => 15.00, 'reseller' => 18.00)
    );
    
    $base_rate = $base_rates[$destination_country][$customer_type] ?? 25.00;
    
    // Weight-based additional charges
    if ($total_weight > 2) {
        $additional_weight = $total_weight - 2;
        $base_rate += ceil($additional_weight) * 2.50;
    }
    
    return $base_rate;
}
```

### `/src/BeroeaShop/Component/Filter.php`
**Purpose:** Advanced product filtering with geographic restrictions

**Category-Based Product Sliders:**
```php
public function getCategoryProducts($category_slug, $limit = 12) {
    $user_country = Shop::getUserCountry();
    
    $query_args = array(
        'post_type' => 'product',
        'posts_per_page' => $limit,
        'meta_query' => array(
            array(
                'key' => 'available_countries',
                'value' => $user_country,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_stock_status',
                'value' => 'instock'
            )
        ),
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => $category_slug
            )
        )
    );
    
    return new WP_Query($query_args);
}
```

**SEO-Friendly URL Rewriting:**
```php
public function setupCustomRewriteRules() {
    // Author pages with filter integration
    add_rewrite_rule(
        '^authors/([^/]+)/?',
        'index.php?post_type=product&filter_author=$matches[1]',
        'top'
    );
    
    // Category pages with automatic filter redirect
    add_rewrite_rule(
        '^category/([^/]+)/?',
        'index.php?post_type=product&filter_category=$matches[1]',
        'top'
    );
}
```

### `/src/BeroeaShop/Component/Erp/Product.php`
**Purpose:** FTP-based ERP integration for product imports

**Multi-line CSV Processing:**
```php
public function processProductCSV($csv_data) {
    $products = array();
    $current_product = null;
    
    foreach ($csv_data as $row) {
        if ($this->isMainProductRow($row)) {
            // Save previous product if exists
            if ($current_product) {
                $products[] = $this->finalizeProduct($current_product);
            }
            
            // Start new product
            $current_product = array(
                'id' => $row['product_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'variants' => array()
            );
        } else {
            // Add variant to current product
            $current_product['variants'][] = array(
                'sku' => $row['sku'],
                'price_private_chf' => $row['price_private_chf'],
                'price_private_eur' => $row['price_private_eur'],
                'price_reseller_chf' => $row['price_reseller_chf'],
                'price_reseller_eur' => $row['price_reseller_eur'],
                'stock_ch' => $row['stock_ch'],
                'stock_de' => $row['stock_de']
            );
        }
    }
    
    // Save final product
    if ($current_product) {
        $products[] = $this->finalizeProduct($current_product);
    }
    
    return $products;
}
```

**Secure File Delivery System:**
```php
public function handleSecureDownload($download_key) {
    // Validate download key
    $download_data = $this->validateDownloadKey($download_key);
    if (!$download_data) {
        wp_die(__('Invalid download key', 'beroea-shop'));
    }
    
    // Check user permissions
    if (!$this->userCanDownload($download_data['product_id'])) {
        wp_die(__('Access denied', 'beroea-shop'));
    }
    
    // Deliver file with proper headers
    $file_path = $this->getSecureFilePath($download_data['file_id']);
    $file_type = $this->getFileType($file_path);
    
    header('Content-Type: ' . $file_type);
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Content-Length: ' . filesize($file_path));
    
    readfile($file_path);
    exit;
}
```

### `/src/BeroeaShop/Component/LoungeApi.php`
**Purpose:** Cross-platform integration with reading application

**Cross-Site Authentication:**
```php
public function setupCrossSiteAuth() {
    add_action('wp_login', array($this, 'syncLoginToLounge'), 10, 2);
    add_action('wp_logout', array($this, 'syncLogoutToLounge'));
}

public function syncLoginToLounge($user_login, $user) {
    $auth_token = $this->generateSecureToken($user->ID);
    
    // Set secure cookie for Leselounge domain
    setcookie(
        'lounge_auth_token',
        $auth_token,
        time() + (24 * 60 * 60),
        '/',
        '.beroea.ch',
        true,  // Secure
        true   // HttpOnly
    );
    
    // API call to sync user data
    $this->syncUserDataToLounge($user->ID, $auth_token);
}
```

**REST API Endpoints:**
```php
public function registerRestEndpoints() {
    register_rest_route('beroea/v1', '/user/bookmarks', array(
        'methods' => array('GET', 'POST', 'DELETE'),
        'callback' => array($this, 'handleBookmarks'),
        'permission_callback' => array($this, 'validateApiAccess')
    ));
    
    register_rest_route('beroea/v1', '/user/prayers', array(
        'methods' => array('GET', 'POST'),
        'callback' => array($this, 'handlePrayers'),
        'permission_callback' => array($this, 'validateApiAccess')
    ));
}
```

### `/src/BeroeaShop/Component/Backend.php`
**Purpose:** Admin interface customizations

**Dynamic Field Groups:**
```php
public function registerProductFieldGroups() {
    $field_groups = array(
        // Pricing fields
        array(
            'key' => 'product_pricing',
            'title' => 'Multi-Currency Pricing',
            'fields' => array(
                array(
                    'key' => 'price_private_chf',
                    'label' => 'Private Price (CHF)',
                    'type' => 'number',
                    'step' => 0.01
                ),
                array(
                    'key' => 'price_private_eur',
                    'label' => 'Private Price (EUR)',
                    'type' => 'number',
                    'step' => 0.01
                ),
                array(
                    'key' => 'price_reseller_chf',
                    'label' => 'Reseller Price (CHF)',
                    'type' => 'number',
                    'step' => 0.01
                ),
                array(
                    'key' => 'price_reseller_eur',
                    'label' => 'Reseller Price (EUR)',
                    'type' => 'number',
                    'step' => 0.01
                )
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'product'
                    )
                )
            )
        ),
        
        // Geographic availability
        array(
            'key' => 'product_availability',
            'title' => 'Geographic Availability',
            'fields' => array(
                array(
                    'key' => 'available_countries',
                    'label' => 'Available Countries',
                    'type' => 'checkbox',
                    'choices' => array(
                        'CH' => 'Switzerland',
                        'DE' => 'Germany',
                        'AT' => 'Austria'
                    )
                ),
                array(
                    'key' => 'stock_ch',
                    'label' => 'Stock CH',
                    'type' => 'number'
                ),
                array(
                    'key' => 'stock_de',
                    'label' => 'Stock DE',
                    'type' => 'number'
                )
            )
        )
    );
    
    foreach ($field_groups as $field_group) {
        acf_add_local_field_group($field_group);
    }
}
```

---

## assets/ Directory - Styles and Scripts

### `/assets/scripts/theme.js`
**Purpose:** Comprehensive frontend functionality orchestration

**Currency Management:**
```javascript
var BeroeaShop = {
    init: function() {
        this.initCurrencySwitch();
        this.initResellerPrices();
        this.initNavigationSystems();
        this.initSliders();
    },
    
    initCurrencySwitch: function() {
        $('.currency-switcher').on('change', function() {
            var selectedCurrency = $(this).val();
            
            // Set cookie
            document.cookie = 'beroea_currency=' + selectedCurrency + '; path=/; max-age=' + (30*24*60*60);
            
            // Update prices via AJAX
            BeroeaShop.updatePricesForCurrency(selectedCurrency);
        });
    },
    
    updatePricesForCurrency: function(currency) {
        $('.product-price').each(function() {
            var $priceElement = $(this);
            var productId = $priceElement.data('product-id');
            var customerType = $priceElement.data('customer-type') || 'private';
            
            $.ajax({
                url: beroea_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_converted_price',
                    product_id: productId,
                    currency: currency,
                    customer_type: customerType,
                    nonce: beroea_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $priceElement.html(response.data.formatted_price);
                    }
                }
            });
        });
    }
};
```

**Reseller Price Display:**
```javascript
initResellerPrices: function() {
    // Show/hide reseller prices based on user type
    if (beroea_user.is_reseller) {
        $('.reseller-price').show();
        $('.private-price').hide();
        
        // Update quantity-based pricing
        $('.quantity-input').on('change', function() {
            var quantity = parseInt($(this).val());
            var productId = $(this).data('product-id');
            
            BeroeaShop.updateBulkPricing(productId, quantity);
        });
    }
},

updateBulkPricing: function(productId, quantity) {
    var bulkTiers = beroea_products[productId].bulk_tiers;
    var basePrice = beroea_products[productId].reseller_price;
    var finalPrice = basePrice;
    
    // Find applicable bulk tier
    for (var tier in bulkTiers) {
        if (quantity >= parseInt(tier)) {
            var discount = bulkTiers[tier];
            finalPrice = basePrice * (1 - discount/100);
        }
    }
    
    // Update display
    $('.product-' + productId + ' .final-price').text(
        BeroeaShop.formatPrice(finalPrice * quantity)
    );
}
```

**Navigation Systems:**
```javascript
initNavigationSystems: function() {
    // Mobile navigation
    $('.mobile-nav-toggle').on('click', function() {
        $('body').toggleClass('mobile-nav-open');
        $(this).attr('aria-expanded', $('body').hasClass('mobile-nav-open'));
    });
    
    // Horizontal scroll navigation
    $('.nav-scroll-container').each(function() {
        var $container = $(this);
        var $nav = $container.find('.nav-horizontal');
        
        // Add scroll buttons if content overflows
        if ($nav[0].scrollWidth > $nav.outerWidth()) {
            BeroeaShop.addScrollButtons($container);
        }
    });
},

addScrollButtons: function($container) {
    var $nav = $container.find('.nav-horizontal');
    var scrollAmount = 200;
    
    // Add left scroll button
    $('<button class="nav-scroll-btn nav-scroll-left">')
        .html('<i class="fas fa-chevron-left"></i>')
        .on('click', function() {
            $nav.animate({scrollLeft: '-=' + scrollAmount}, 300);
        })
        .prependTo($container);
    
    // Add right scroll button
    $('<button class="nav-scroll-btn nav-scroll-right">')
        .html('<i class="fas fa-chevron-right"></i>')
        .on('click', function() {
            $nav.animate({scrollLeft: '+=' + scrollAmount}, 300);
        })
        .appendTo($container);
}
```

### `/assets/styles/scss/child-theme.scss`
**Purpose:** Main stylesheet with e-commerce foundation

**Color System:**
```scss
:root {
    // Primary brand colors
    --beroea-primary-green: #2E7D32;
    --beroea-secondary-orange: #FF6F00;
    --beroea-accent-blue: #1976D2;
    
    // Semantic colors
    --beroea-success: #4CAF50;
    --beroea-warning: #FF9800;
    --beroea-error: #F44336;
    --beroea-info: #2196F3;
    
    // Typography
    --beroea-font-family: 'Open Sans', sans-serif;
    --beroea-font-weight-normal: 400;
    --beroea-font-weight-semibold: 600;
    --beroea-font-weight-bold: 700;
}
```

**Typography Foundation:**
```scss
body {
    font-family: var(--beroea-font-family);
    font-weight: var(--beroea-font-weight-normal);
    line-height: 1.6;
    color: #333;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

// Custom typography mixins
@mixin themeTypoHeading1() {
    font-size: 2.5rem;
    font-weight: var(--beroea-font-weight-bold);
    line-height: 1.2;
    
    @include media-breakpoint-down(md) {
        font-size: 2rem;
    }
}

@mixin themeTypoHeading2() {
    font-size: 2rem;
    font-weight: var(--beroea-font-weight-semibold);
    line-height: 1.3;
    
    @include media-breakpoint-down(md) {
        font-size: 1.5rem;
    }
}
```

### `/assets/styles/scss/parts/_header.scss`
**Purpose:** Complex header with e-commerce features

**Fixed Header System:**
```scss
.beroea-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    
    &.scrolled {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        
        .header-logo {
            transform: scale(0.9);
        }
    }
    
    .header-top {
        background: var(--beroea-primary-green);
        color: white;
        padding: 0.5rem 0;
        
        .currency-switcher {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            
            option {
                background: var(--beroea-primary-green);
                color: white;
            }
        }
    }
}
```

**Navigation Systems:**
```scss
.main-navigation {
    .nav-menu {
        display: flex;
        list-style: none;
        margin: 0;
        padding: 0;
        
        > li {
            position: relative;
            
            > a {
                display: block;
                padding: 1rem 1.5rem;
                text-decoration: none;
                color: #333;
                font-weight: var(--beroea-font-weight-semibold);
                transition: all 0.3s ease;
                
                &:hover {
                    color: var(--beroea-primary-green);
                    background: rgba(var(--beroea-primary-green-rgb), 0.1);
                }
            }
            
            // Mega menu dropdown
            .mega-menu {
                position: absolute;
                top: 100%;
                left: 0;
                width: 600px;
                background: white;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                border-radius: 8px;
                padding: 2rem;
                display: none;
                
                &.show {
                    display: block;
                }
            }
        }
    }
}
```

**Search Integration:**
```scss
.header-search {
    position: relative;
    flex: 1;
    max-width: 400px;
    
    .search-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e0e0e0;
        border-radius: 25px;
        font-size: 1rem;
        transition: all 0.3s ease;
        
        &:focus {
            outline: none;
            border-color: var(--beroea-primary-green);
            box-shadow: 0 0 0 3px rgba(var(--beroea-primary-green-rgb), 0.1);
        }
    }
    
    .search-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        max-height: 300px;
        overflow-y: auto;
        z-index: 1001;
        
        .suggestion-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            
            &:hover {
                background: #f5f5f5;
            }
            
            .suggestion-title {
                font-weight: var(--beroea-font-weight-semibold);
                margin-bottom: 0.25rem;
            }
            
            .suggestion-meta {
                font-size: 0.875rem;
                color: #666;
            }
        }
    }
}
```

### `/assets/styles/scss/shop/_single-product.scss`
**Purpose:** Product detail page styling

**Product Layout:**
```scss
.single-product {
    .product-gallery {
        .product-images {
            .main-image {
                width: 100%;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }
            
            .thumbnail-nav {
                display: flex;
                gap: 0.5rem;
                margin-top: 1rem;
                
                .thumbnail {
                    width: 80px;
                    height: 80px;
                    border-radius: 4px;
                    cursor: pointer;
                    opacity: 0.7;
                    transition: opacity 0.3s ease;
                    
                    &:hover,
                    &.active {
                        opacity: 1;
                    }
                }
            }
        }
    }
    
    .product-summary {
        .product-title {
            @include themeTypoHeading1();
            margin-bottom: 1rem;
        }
        
        .product-price {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            
            .current-price {
                font-size: 1.5rem;
                font-weight: var(--beroea-font-weight-bold);
                color: var(--beroea-primary-green);
            }
            
            .original-price {
                font-size: 1.25rem;
                color: #999;
                text-decoration: line-through;
            }
            
            .price-badge {
                background: var(--beroea-secondary-orange);
                color: white;
                padding: 0.25rem 0.5rem;
                border-radius: 4px;
                font-size: 0.875rem;
                font-weight: var(--beroea-font-weight-semibold);
            }
        }
        
        .product-variants {
            margin-bottom: 1.5rem;
            
            .variant-group {
                margin-bottom: 1rem;
                
                .variant-label {
                    display: block;
                    margin-bottom: 0.5rem;
                    font-weight: var(--beroea-font-weight-semibold);
                }
                
                .variant-options {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.5rem;
                    
                    .variant-option {
                        padding: 0.5rem 1rem;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        cursor: pointer;
                        transition: all 0.3s ease;
                        
                        &:hover {
                            border-color: var(--beroea-primary-green);
                        }
                        
                        &.selected {
                            border-color: var(--beroea-primary-green);
                            background: rgba(var(--beroea-primary-green-rgb), 0.1);
                        }
                        
                        &.out-of-stock {
                            opacity: 0.5;
                            cursor: not-allowed;
                            
                            &::after {
                                content: ' (Ausverkauft)';
                                color: #999;
                                font-size: 0.875rem;
                            }
                        }
                    }
                }
            }
        }
    }
}
```

### `/assets/styles/scss/components/_buttons.scss`
**Purpose:** Button system with e-commerce variants

**Button Architecture:**
```scss
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    border: 2px solid transparent;
    border-radius: 6px;
    font-weight: var(--beroea-font-weight-semibold);
    text-decoration: none;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    
    &:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(var(--beroea-primary-green-rgb), 0.3);
    }
    
    // Primary button
    &--primary {
        background: var(--beroea-primary-green);
        color: white;
        border-color: var(--beroea-primary-green);
        
        &:hover {
            background: darken(var(--beroea-primary-green), 10%);
            border-color: darken(var(--beroea-primary-green), 10%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(var(--beroea-primary-green-rgb), 0.3);
        }
        
        &:active {
            transform: translateY(0);
        }
    }
    
    // Secondary button
    &--secondary {
        background: transparent;
        color: var(--beroea-primary-green);
        border-color: var(--beroea-primary-green);
        
        &:hover {
            background: var(--beroea-primary-green);
            color: white;
        }
    }
    
    // Add to cart button
    &--add-to-cart {
        background: var(--beroea-secondary-orange);
        color: white;
        border-color: var(--beroea-secondary-orange);
        min-width: 150px;
        
        .cart-icon {
            margin-right: 0.5rem;
        }
        
        &:hover {
            background: darken(var(--beroea-secondary-orange), 10%);
            border-color: darken(var(--beroea-secondary-orange), 10%);
        }
        
        &.loading {
            position: relative;
            color: transparent;
            
            &::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 20px;
                height: 20px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-top: 2px solid white;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
        }
    }
}

@keyframes spin {
    from { transform: translate(-50%, -50%) rotate(0deg); }
    to { transform: translate(-50%, -50%) rotate(360deg); }
}
```

---

## views/ Directory - Templates

### `/views/layout/header.php`
**Purpose:** Complex e-commerce header with multi-currency support

**Currency Switcher:**
```php
<div class="header-top">
    <div class="container">
        <div class="header-top-content">
            <div class="currency-selector">
                <label for="currency-switch"><?php _e('Currency:', 'beroea-shop'); ?></label>
                <select id="currency-switch" class="currency-switcher">
                    <?php
                    $current_currency = WC()->session->get('currency', 'CHF');
                    $available_currencies = array(
                        'CHF' => __('Swiss Franc (CHF)', 'beroea-shop'),
                        'EUR' => __('Euro (EUR)', 'beroea-shop')
                    );
                    
                    foreach ($available_currencies as $code => $label) {
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr($code),
                            selected($current_currency, $code, false),
                            esc_html($label)
                        );
                    }
                    ?>
                </select>
            </div>
            
            <div class="user-actions">
                <?php if (is_user_logged_in()) : ?>
                    <span class="welcome-message">
                        <?php printf(__('Welcome, %s', 'beroea-shop'), wp_get_current_user()->display_name); ?>
                    </span>
                    <a href="<?php echo wp_logout_url(); ?>" class="logout-link">
                        <?php _e('Logout', 'beroea-shop'); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo wc_get_page_permalink('myaccount'); ?>" class="login-link">
                        <?php _e('Login', 'beroea-shop'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
```

**Advanced Search with Suggestions:**
```php
<div class="header-search">
    <form role="search" method="get" class="search-form" action="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">
        <input type="search" 
               class="search-input" 
               placeholder="<?php _e('Search products...', 'beroea-shop'); ?>" 
               value="<?php echo get_search_query(); ?>" 
               name="s" 
               autocomplete="off">
        <button type="submit" class="search-submit">
            <i class="fas fa-search" aria-hidden="true"></i>
            <span class="screen-reader-text"><?php _e('Search', 'beroea-shop'); ?></span>
        </button>
    </form>
    
    <div class="search-suggestions" style="display: none;">
        <!-- Populated via JavaScript -->
    </div>
</div>
```

**Cart Integration:**
```php
<div class="header-cart">
    <?php if (class_exists('WooCommerce')) : ?>
        <a href="<?php echo wc_get_cart_url(); ?>" class="cart-link">
            <i class="fas fa-shopping-cart" aria-hidden="true"></i>
            <span class="cart-count"><?php echo WC()->cart->get_cart_contents_count(); ?></span>
            <span class="cart-total"><?php echo WC()->cart->get_cart_subtotal(); ?></span>
        </a>
        
        <?php if (!WC()->cart->is_empty()) : ?>
            <div class="cart-dropdown">
                <div class="cart-items">
                    <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
                        $product = $cart_item['data'];
                        ?>
                        <div class="cart-item">
                            <div class="item-image">
                                <?php echo wp_get_attachment_image($product->get_image_id(), 'thumbnail'); ?>
                            </div>
                            <div class="item-details">
                                <h6 class="item-title"><?php echo $product->get_name(); ?></h6>
                                <span class="item-quantity"><?php echo $cart_item['quantity']; ?>x</span>
                                <span class="item-price"><?php echo wc_price($product->get_price()); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="cart-actions">
                    <a href="<?php echo wc_get_cart_url(); ?>" class="btn btn--secondary">
                        <?php _e('View Cart', 'beroea-shop'); ?>
                    </a>
                    <a href="<?php echo wc_get_checkout_url(); ?>" class="btn btn--primary">
                        <?php _e('Checkout', 'beroea-shop'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
```

### `/views/blocks/new-products.php`
**Purpose:** Featured product slider

**Product Selection and Display:**
```php
<?php
$featured_products = get_field('featured_products');
$slider_settings = get_field('slider_settings');

if ($featured_products) :
    $slider_id = 'new-products-' . uniqid();
?>
    <div class="wp-block-acf-new-products">
        <div class="section-header">
            <h2 class="section-title"><?php echo get_field('section_title') ?: __('New Products', 'beroea-shop'); ?></h2>
            <a href="<?php echo wc_get_page_permalink('shop'); ?>?filter_new=1" class="view-all-link">
                <?php _e('View All', 'beroea-shop'); ?>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        
        <div id="<?php echo $slider_id; ?>" class="products-slider">
            <?php foreach ($featured_products as $product_id) :
                $product = wc_get_product($product_id);
                if (!$product) continue;
                
                $current_currency = WC()->session->get('currency', 'CHF');
                $customer_type = BeroeaShop\Component\Shop::getCustomerType();
                $price = BeroeaShop\Component\Shop::getCustomerTypePrice($product_id, $customer_type);
            ?>
                <div class="product-slide">
                    <div class="product-card">
                        <div class="product-image">
                            <a href="<?php echo get_permalink($product_id); ?>">
                                <?php echo wp_get_attachment_image($product->get_image_id(), 'medium'); ?>
                            </a>
                            
                            <?php if ($product->is_on_sale()) : ?>
                                <span class="sale-badge"><?php _e('Sale', 'beroea-shop'); ?></span>
                            <?php endif; ?>
                            
                            <?php if (get_field('is_new', $product_id)) : ?>
                                <span class="new-badge"><?php _e('New', 'beroea-shop'); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-content">
                            <h3 class="product-title">
                                <a href="<?php echo get_permalink($product_id); ?>"><?php echo $product->get_name(); ?></a>
                            </h3>
                            
                            <div class="product-meta">
                                <?php $author = get_field('author', $product_id); ?>
                                <?php if ($author) : ?>
                                    <span class="product-author"><?php echo esc_html($author); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-price">
                                <?php if ($price > 0) : ?>
                                    <span class="current-price">
                                        <?php echo wc_price($price); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="no-price"><?php _e('Price on request', 'beroea-shop'); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-actions">
                                <?php if ($product->is_in_stock() && $price > 0) : ?>
                                    <button class="btn btn--add-to-cart add-to-cart-btn" 
                                            data-product-id="<?php echo $product_id; ?>">
                                        <i class="fas fa-shopping-cart cart-icon" aria-hidden="true"></i>
                                        <?php _e('Add to Cart', 'beroea-shop'); ?>
                                    </button>
                                <?php else : ?>
                                    <span class="out-of-stock"><?php _e('Out of Stock', 'beroea-shop'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#<?php echo $slider_id; ?>').slick({
                slidesToShow: 4,
                slidesToScroll: 1,
                autoplay: true,
                autoplaySpeed: 5000,
                dots: true,
                arrows: true,
                responsive: [
                    {
                        breakpoint: 1024,
                        settings: {
                            slidesToShow: 3
                        }
                    },
                    {
                        breakpoint: 768,
                        settings: {
                            slidesToShow: 2
                        }
                    },
                    {
                        breakpoint: 480,
                        settings: {
                            slidesToShow: 1
                        }
                    }
                ]
            });
        });
        </script>
    </div>
<?php endif; ?>
```

---

## Key Development Patterns for Reference

### 1. **Multi-Currency E-commerce Architecture**
- Cookie-based currency persistence with server-side validation
- Geographic currency auto-detection using IP-based location
- Customer-type pricing matrix (Private/Reseller × CHF/EUR)
- Real-time price conversion via AJAX

### 2. **Geographic Market Segmentation**
- Country-based product availability restrictions
- Separate inventory management for multiple markets (CH/DE)
- Location-aware shipping calculation with weight-based rates
- Regional stock management with automatic fallbacks

### 3. **ERP Integration Patterns**
- FTP-based product import with multi-line CSV processing
- Secure file delivery system with access validation
- Daily inventory synchronization with error handling
- Legacy system migration with compatibility layers

### 4. **Advanced Customer Management**
- Cross-platform authentication with secure token exchange
- Customer type differentiation with pricing implications
- Bulk pricing tiers with quantity-based discounts
- B2B features with specialized UI elements

### 5. **Performance and UX Optimization**
- Comprehensive caching strategy with selective invalidation
- Progressive enhancement for interactive features
- Mobile-first responsive design with touch optimization
- Lazy loading and image optimization

### 6. **Security Implementation**
- Secure download system with key validation
- Cross-site authentication with proper token handling
- Input validation and sanitization throughout
- WordPress nonce verification for AJAX operations

This theme demonstrates enterprise-level e-commerce development with sophisticated multi-currency support, geographic market segmentation, and complex ERP integration. The architecture provides excellent examples of international e-commerce requirements and cross-platform integration patterns.