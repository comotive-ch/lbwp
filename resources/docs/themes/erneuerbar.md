# Erneuerbar-Go Theme Documentation

**Theme:** erneuerbar-go  
**Purpose:** E-commerce platform for renewable energy/solar panels with Swiss logistics  
**Reference:** Complex e-commerce and document generation patterns for S03 child themes

---

## src/ Directory - PHP Architecture

### `/src/ErneuerbarGo/Theme/Core.php`
**Extends:** `Standard03\Theme\Core`  
**Purpose:** Main theme controller with specialized e-commerce and integration features

**Component Registration:**
```php
$this->registerComponents(array(
    '\ErneuerbarGo\Component\ACF',
    '\ErneuerbarGo\Component\Statistics',
    '\ErneuerbarGo\Component\Shop',
    '\ErneuerbarGo\Component\ReverseDropship',
    '\ErneuerbarGo\Component\DocGenerator',
    '\ErneuerbarGo\Component\Inventory',
    '\ErneuerbarGo\Component\Search',
));
```

**EBICS Banking Integration:**
```php
// Banking payment processing for order status changes
add_action('woocommerce_order_status_changed', array($this, 'handleEbicsOrderStatus'), 10, 4);

public function handleEbicsOrderStatus($order_id, $old_status, $new_status, $order) {
    if ($new_status === 'processing') {
        // Trigger banking payment processing
        do_action('ebics_process_order_payment', $order_id);
    }
}
```

**Zapier Social Media Integration:**
```php
// Multi-platform social media automation
private function setupZapierSocial() {
    $platforms = array('linkedin', 'google-business', 'instagram', 'threads', 'mastodon', 'bluesky');
    foreach ($platforms as $platform) {
        add_action("zapier_publish_{$platform}", array($this, 'handleSocialPublication'), 10, 2);
    }
}
```

### `/src/ErneuerbarGo/Component/DocGenerator.php`
**Purpose:** PDF compliance document generation system

**Custom Post Type Registration:**
```php
public function registerPostTypes() {
    // Declaration of Conformity documents
    register_post_type('dec-of-conformity', array(
        'public' => true,
        'supports' => array('title', 'editor', 'custom-fields'),
        'menu_icon' => 'dashicons-media-document'
    ));
    
    // Document components/elements
    register_post_type('docl-element', array(
        'public' => false,
        'supports' => array('title', 'custom-fields')
    ));
}
```

**PDF Generation with DocRaptor:**
```php
public function generatePDF($document_id, $product_data) {
    $html_content = $this->buildDocumentHTML($document_id, $product_data);
    
    $docraptor_config = array(
        'document_content' => $html_content,
        'document_type' => 'pdf',
        'test' => false,
        'prince_options' => array(
            'media' => 'print',
            'baseurl' => home_url()
        )
    );
    
    $pdf_response = $this->callDocRaptorAPI($docraptor_config);
    return $this->saveToS3($pdf_response, $document_id);
}
```

**Document Assembly with Ghostscript:**
```php
private function assembleDocuments($main_pdf, $attachments) {
    $temp_files = array($main_pdf);
    foreach ($attachments as $attachment) {
        $temp_files[] = $this->downloadTempFile($attachment);
    }
    
    // Combine PDFs using Ghostscript
    $output_file = tempnam(sys_get_temp_dir(), 'combined_doc_');
    $command = sprintf(
        'gs -dNOPAUSE -sDEVICE=pdfwrite -sOUTPUTFILE=%s -dBATCH %s',
        escapeshellarg($output_file),
        implode(' ', array_map('escapeshellarg', $temp_files))
    );
    
    exec($command, $output, $return_code);
    return ($return_code === 0) ? $output_file : false;
}
```

### `/src/ErneuerbarGo/Component/Shop.php`
**Purpose:** Complex Swiss logistics and e-commerce engine

**Dynamic Shipping Calculation:**
```php
public function calculateShippingCosts($postcode, $weight, $volume, $product_categories) {
    $canton = $this->getCantonFromPostcode($postcode);
    $season = $this->getCurrentSeason();
    
    // Base rates by delivery type
    $rates = array(
        'standard' => $this->getStandardRate($canton),
        'e-transport' => $this->getETransportRate($canton, $season),
        'spedition' => $this->getSpeditionRate($canton, $weight, $volume)
    );
    
    // Special handling for product categories
    if (in_array('batteries', $product_categories)) {
        $rates = $this->applyBatteryRules($rates, $canton);
    }
    
    return $this->filterAvailableRates($rates, $postcode);
}
```

**Canton-Based Pricing:**
```php
private function getCantonFromPostcode($postcode) {
    $canton_mapping = array(
        '1000-1999' => 'VD',  // Vaud
        '2000-2999' => 'NE',  // Neuchâtel
        '3000-3999' => 'BE',  // Bern
        '4000-4999' => 'BL',  // Basel-Land
        '5000-5999' => 'AG',  // Aargau
        '6000-6999' => 'LU',  // Luzern
        '7000-7999' => 'GR',  // Graubünden
        '8000-8999' => 'ZH',  // Zürich
        '9000-9999' => 'SG'   // St. Gallen
    );
    
    foreach ($canton_mapping as $range => $canton) {
        list($min, $max) = explode('-', $range);
        if ($postcode >= $min && $postcode <= $max) {
            return $canton;
        }
    }
    return 'unknown';
}
```

**REST API Endpoint for Delivery Calculation:**
```php
public function registerRestEndpoints() {
    register_rest_route('erneuerbar/v1', '/delivery-cost', array(
        'methods' => 'POST',
        'callback' => array($this, 'calculateDeliveryAPI'),
        'permission_callback' => '__return_true',
        'args' => array(
            'postcode' => array('required' => true, 'type' => 'string'),
            'products' => array('required' => true, 'type' => 'array')
        )
    ));
}
```

### `/src/ErneuerbarGo/Component/ReverseDropship.php`
**Purpose:** B2B dropshipping functionality for reseller network

**User Role Detection:**
```php
public function isDropshipUser($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $is_dropship = get_user_meta($user_id, 'is_dropship_user', true);
    $dropship_code = get_user_meta($user_id, 'dropship_partner_code', true);
    
    return !empty($is_dropship) && !empty($dropship_code);
}
```

**Automatic Voucher Application:**
```php
public function autoApplyDropshipVoucher($cart) {
    if (!$this->isDropshipUser()) {
        return;
    }
    
    $partner_code = get_user_meta(get_current_user_id(), 'dropship_partner_code', true);
    $voucher_code = 'DROPSHIP_' . strtoupper($partner_code);
    
    if (!$cart->has_discount($voucher_code)) {
        $cart->apply_coupon($voucher_code);
        wc_add_notice(__('Dropship discount applied automatically.', 'erneuerbar'), 'success');
    }
}
```

**Dual Email System:**
```php
public function setupDualEmailSystem($order_id) {
    $order = wc_get_order($order_id);
    
    // Get dropship partner email
    $partner_email = get_user_meta($order->get_customer_id(), 'partner_billing_email', true);
    $customer_email = $order->get_billing_email();
    
    // Send to partner for billing
    $this->sendPartnerNotification($order, $partner_email);
    
    // Send to end customer for delivery
    $this->sendCustomerNotification($order, $customer_email);
}
```

### `/src/ErneuerbarGo/Component/Inventory.php`
**Purpose:** Advanced inventory management and automation

**Email Automation with Packing Slips:**
```php
public function sendCompletionNotification($order_id) {
    $order = wc_get_order($order_id);
    $packing_slip = $this->generatePackingSlip($order);
    
    $email_data = array(
        'to' => 'warehouse@erneuer.bar',
        'subject' => sprintf(__('Order %s completed - Packing slip attached', 'erneuerbar'), $order->get_order_number()),
        'attachments' => array($packing_slip),
        'template' => 'order-completion'
    );
    
    $this->sendEmail($email_data);
}
```

**Price Comparison System:**
```php
public function checkSupplierPrices() {
    $products = $this->getActiveProducts();
    $supplier_apis = $this->getSupplierAPIConfigs();
    
    foreach ($products as $product) {
        $current_cost = get_post_meta($product->ID, '_supplier_cost', true);
        
        foreach ($supplier_apis as $supplier => $config) {
            $new_price = $this->fetchSupplierPrice($product->get_sku(), $config);
            
            if ($new_price && $new_price != $current_cost) {
                $this->logPriceChange($product->ID, $supplier, $current_cost, $new_price);
                $this->notifyPriceChange($product, $supplier, $current_cost, $new_price);
            }
        }
    }
}
```

### `/src/ErneuerbarGo/Component/ACF.php`
**Purpose:** Custom block registration using ACF

**Block Registration Pattern:**
```php
public function registerBlocks() {
    $blocks = array(
        array(
            'name' => 'erneuerbar-delivery-options',
            'title' => 'Delivery Options Calculator',
            'render_template' => 'views/blocks/delivery-options.php',
            'category' => 'common',
            'supports' => array('mode' => false)
        ),
        array(
            'name' => 'erneuerbar-savings-calculator',
            'title' => 'Solar Savings Calculator',
            'render_template' => 'views/blocks/savings-calculator.php',
            'category' => 'widgets'
        ),
        array(
            'name' => 'erneuerbar-social-wall',
            'title' => 'Social Media Wall',
            'render_template' => 'views/blocks/social-wall.php',
            'category' => 'media'
        )
    );
    
    foreach ($blocks as $block) {
        acf_register_block_type($block);
    }
}
```

---

## assets/ Directory - Styles and Scripts

### `/assets/styles/scss/child-theme.scss`
**Purpose:** Main stylesheet with e-commerce optimizations

**Font Optimization and Base Styles:**
```scss
body {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
}

// WooCommerce form optimizations
.woocommerce-form-row {
    margin-bottom: 1rem;
    
    &.woocommerce-invalid {
        .select2-container--default .select2-selection--single {
            border-color: #e74c3c;
        }
    }
}
```

### `/assets/styles/scss/__settings.scss`
**Purpose:** Theme-specific variables

**Mobile Header Logo Sizing:**
```scss
:root {
    --erneuerbar-logo-mobile-height: 45px;
    --erneuerbar-logo-mobile-width: auto;
    
    @include media-breakpoint-up(md) {
        --erneuerbar-logo-mobile-height: 60px;
    }
}
```

### `/assets/styles/scss/blocks/_delivery-options.scss`
**Purpose:** Delivery calculator component styling

**CSS Grid Layout with Responsive Design:**
```scss
.wp-block-acf-erneuerbar-delivery-options {
    .delivery-options-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
        
        @include media-breakpoint-up(md) {
            grid-template-columns: repeat(2, 1fr);
        }
        
        @include media-breakpoint-up(lg) {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    
    .delivery-option {
        padding: 1.5rem;
        border: 2px solid #e0e6ed;
        border-radius: 8px;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
        
        &:hover, &.selected {
            border-color: var(--s03-primary-color);
            background-color: rgba(var(--s03-primary-color-rgb), 0.05);
        }
        
        .delivery-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            
            svg {
                width: 100%;
                height: 100%;
                fill: var(--s03-primary-color);
            }
        }
    }
}
```

**Dynamic Content States:**
```scss
.delivery-calculator {
    .calculator-result {
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.4s ease;
        
        &.show {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .loading-state {
        display: none;
        text-align: center;
        padding: 2rem;
        
        &.active {
            display: block;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--s03-primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
    }
}
```

### `/assets/styles/scss/blocks/_savings-calculator.scss`
**Purpose:** Solar ROI calculator styling

**Custom Form Elements:**
```scss
.wp-block-acf-erneuerbar-savings-calculator {
    .calculator-form {
        .form-group {
            margin-bottom: 2rem;
            
            label {
                display: block;
                font-weight: 600;
                margin-bottom: 0.5rem;
                color: var(--s03-text-color);
            }
        }
        
        // Custom checkbox styling
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            
            .checkbox-item {
                position: relative;
                
                input[type="checkbox"] {
                    position: absolute;
                    opacity: 0;
                    
                    &:checked + label {
                        background-color: var(--s03-primary-color);
                        color: white;
                        border-color: var(--s03-primary-color);
                    }
                }
                
                label {
                    display: block;
                    padding: 1rem;
                    border: 2px solid #ddd;
                    border-radius: 6px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    text-align: center;
                    
                    &:hover {
                        border-color: var(--s03-primary-color);
                    }
                }
            }
        }
    }
}
```

**Range Slider with SVG Visualization:**
```scss
.self-consumption-slider {
    position: relative;
    margin: 2rem 0;
    
    input[type="range"] {
        width: 100%;
        height: 8px;
        background: #ddd;
        border-radius: 4px;
        outline: none;
        
        &::-webkit-slider-thumb {
            appearance: none;
            width: 24px;
            height: 24px;
            background: var(--s03-primary-color);
            border-radius: 50%;
            cursor: pointer;
        }
    }
    
    .slider-visualization {
        margin-top: 1rem;
        
        svg {
            width: 100%;
            height: 100px;
            
            .consumption-bar {
                fill: var(--s03-primary-color);
                transition: width 0.3s ease;
            }
            
            .grid-bar {
                fill: #ff6b35;
                transition: width 0.3s ease;
            }
        }
    }
}
```

### `/assets/styles/scss/blocks/_social-wall.scss`
**Purpose:** Social media wall grid styling

**Masonry Grid Implementation:**
```scss
.wp-block-acf-erneuerbar-social-wall {
    .social-posts-grid {
        // Masonry-compatible column system
        column-count: 1;
        column-gap: 1.5rem;
        
        @include media-breakpoint-up(md) {
            column-count: 2;
        }
        
        @include media-breakpoint-up(lg) {
            column-count: 3;
        }
        
        @include media-breakpoint-up(xl) {
            column-count: 4;
        }
    }
    
    .social-post {
        break-inside: avoid;
        margin-bottom: 1.5rem;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        
        &:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }
        
        .post-content {
            padding: 1.5rem;
            
            .post-text {
                margin-bottom: 1rem;
                line-height: 1.6;
                
                a {
                    color: var(--s03-primary-color);
                    text-decoration: none;
                    border-bottom: 1px dashed currentColor;
                    
                    &:hover {
                        border-bottom-style: solid;
                    }
                }
            }
        }
        
        .post-meta {
            padding: 1rem 1.5rem;
            background-color: #f8f9fa;
            border-top: 1px solid #e9ecef;
            font-size: 0.9rem;
            color: #6c757d;
            
            .platform-icon {
                width: 20px;
                height: 20px;
                vertical-align: middle;
                margin-right: 0.5rem;
            }
        }
    }
}
```

### `/assets/scripts/child-theme.js`
**Purpose:** Frontend functionality orchestration

**Namespace Organization:**
```javascript
var GoErneuerbar = {
    init: function() {
        this.initMasonryLayout();
        this.initDeliveryCalculator();
        this.initSavingsCalculator();
        this.autoSelectShipping();
    },
    
    initMasonryLayout: function() {
        // Use parent theme's masonry implementation
        if (typeof Standard03 !== 'undefined' && Standard03.initMasonry) {
            Standard03.initMasonry('.social-posts-grid');
        }
    },
    
    autoSelectShipping: function() {
        // Automatically select first available shipping method
        $(document).on('updated_checkout', function() {
            var firstShipping = $('input[name^="shipping_method"]:first');
            if (firstShipping.length && !$('input[name^="shipping_method"]:checked').length) {
                firstShipping.prop('checked', true).trigger('change');
            }
        });
    }
};
```

**AJAX Delivery Calculator:**
```javascript
initDeliveryCalculator: function() {
    $('.delivery-calculator-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            postcode: $('#postcode').val(),
            products: GoErneuerbar.getSelectedProducts(),
            action: 'calculate_delivery_cost',
            nonce: erneuerbar_ajax.nonce
        };
        
        $('.loading-state').addClass('active');
        $('.calculator-result').removeClass('show');
        
        $.ajax({
            url: erneuerbar_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                $('.loading-state').removeClass('active');
                
                if (response.success) {
                    GoErneuerbar.displayDeliveryOptions(response.data);
                } else {
                    GoErneuerbar.showError(response.data.message);
                }
            },
            error: function() {
                $('.loading-state').removeClass('active');
                GoErneuerbar.showError('Calculation failed. Please try again.');
            }
        });
    });
},
```

**Solar Calculator Logic:**
```javascript
initSavingsCalculator: function() {
    var calculator = $('.savings-calculator');
    
    // Product selection handling
    calculator.on('change', 'input[type="checkbox"]', function() {
        GoErneuerbar.updateCalculation();
    });
    
    // Self-consumption slider
    calculator.on('input', '.self-consumption-range', function() {
        var percentage = $(this).val();
        $('.consumption-percentage').text(percentage + '%');
        GoErneuerbar.updateVisualization(percentage);
        GoErneuerbar.updateCalculation();
    });
},

updateCalculation: function() {
    var totalCost = 0;
    var totalKwp = 0;
    var selfConsumption = parseFloat($('.self-consumption-range').val()) / 100;
    
    // Calculate based on selected products
    $('.calculator-form input[type="checkbox"]:checked').each(function() {
        var productData = $(this).data('product');
        totalCost += parseFloat(productData.price);
        totalKwp += parseFloat(productData.kwp);
    });
    
    // Calculate annual savings
    var annualProduction = totalKwp * 1000; // kWh per year
    var selfConsumed = annualProduction * selfConsumption;
    var feedIn = annualProduction - selfConsumed;
    
    var savings = (selfConsumed * 0.25) + (feedIn * 0.08); // CHF per kWh
    var paybackYears = totalCost / savings;
    
    // Update display
    $('.calculation-results').html(
        '<div class="result-item">Annual Savings: CHF ' + savings.toFixed(0) + '</div>' +
        '<div class="result-item">Payback Period: ' + paybackYears.toFixed(1) + ' years</div>' +
        '<div class="result-item">Total Investment: CHF ' + totalCost.toFixed(0) + '</div>'
    ).addClass('show');
}
```

### `/assets/blocks/child-extend.js`
**Purpose:** Gutenberg block style registration

**Block Style Variations:**
```javascript
wp.domReady(function() {
    // Social wall width variations
    wp.blocks.registerBlockStyle('acf/erneuerbar-social-wall', {
        name: 'narrow',
        label: 'Narrow Width'
    });
    
    wp.blocks.registerBlockStyle('acf/erneuerbar-social-wall', {
        name: 'full-width',
        label: 'Full Width'
    });
    
    // Social wall layout variations
    wp.blocks.registerBlockStyle('acf/erneuerbar-social-wall', {
        name: 'compact',
        label: 'Compact Layout'
    });
    
    wp.blocks.registerBlockStyle('acf/erneuerbar-social-wall', {
        name: 'expanded',
        label: 'Expanded Layout'
    });
});
```

---

## views/ Directory - Templates

### `/views/blocks/delivery-options.php`
**Purpose:** Interactive delivery cost calculator

**Real-time Calculation Interface:**
```php
<div class="wp-block-acf-erneuerbar-delivery-options">
    <form class="delivery-calculator-form">
        <div class="form-group">
            <label for="postcode"><?php _e('Postcode', 'erneuerbar'); ?></label>
            <input type="text" id="postcode" name="postcode" 
                   pattern="[0-9]{4}" maxlength="4" required>
        </div>
        
        <div class="delivery-options-grid">
            <?php 
            $delivery_methods = array(
                'standard' => array(
                    'icon' => 'truck',
                    'title' => __('Standard Delivery', 'erneuerbar'),
                    'description' => __('5-7 business days', 'erneuerbar')
                ),
                'express' => array(
                    'icon' => 'shipping-fast',
                    'title' => __('Express Delivery', 'erneuerbar'),
                    'description' => __('1-2 business days', 'erneuerbar')
                ),
                'pickup' => array(
                    'icon' => 'store',
                    'title' => __('Store Pickup', 'erneuerbar'),
                    'description' => __('Ready in 24h', 'erneuerbar')
                )
            );
            
            foreach ($delivery_methods as $method => $data) : ?>
                <div class="delivery-option" data-method="<?php echo $method; ?>">
                    <div class="delivery-icon">
                        <i class="fas fa-<?php echo $data['icon']; ?>"></i>
                    </div>
                    <h4><?php echo $data['title']; ?></h4>
                    <p><?php echo $data['description']; ?></p>
                    <div class="delivery-price" data-method="<?php echo $method; ?>">
                        <span class="loading">...</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <button type="submit" class="btn btn-primary">
            <?php _e('Calculate Delivery Cost', 'erneuerbar'); ?>
        </button>
    </form>
    
    <div class="loading-state">
        <div class="spinner"></div>
        <p><?php _e('Calculating delivery options...', 'erneuerbar'); ?></p>
    </div>
    
    <div class="calculator-result">
        <!-- Dynamic content populated via JavaScript -->
    </div>
</div>
```

### `/views/blocks/savings-calculator.php`
**Purpose:** Solar panel ROI calculator

**Interactive Product Selection:**
```php
<div class="wp-block-acf-erneuerbar-savings-calculator">
    <div class="calculator-form">
        <div class="form-group">
            <label><?php _e('Select Solar Panels', 'erneuerbar'); ?></label>
            <div class="checkbox-group">
                <?php 
                $solar_products = array(
                    array('id' => 'panel-400w', 'name' => '400W Panel', 'price' => 250, 'kwp' => 0.4),
                    array('id' => 'panel-500w', 'name' => '500W Panel', 'price' => 300, 'kwp' => 0.5),
                    array('id' => 'panel-600w', 'name' => '600W Panel', 'price' => 350, 'kwp' => 0.6)
                );
                
                foreach ($solar_products as $product) : ?>
                    <div class="checkbox-item">
                        <input type="checkbox" 
                               id="<?php echo $product['id']; ?>" 
                               data-product='<?php echo json_encode($product); ?>'>
                        <label for="<?php echo $product['id']; ?>">
                            <strong><?php echo $product['name']; ?></strong><br>
                            <span class="price">CHF <?php echo number_format($product['price']); ?></span><br>
                            <span class="kwp"><?php echo $product['kwp']; ?> kWp</span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="form-group">
            <label><?php _e('Self-consumption Rate', 'erneuerbar'); ?></label>
            <div class="self-consumption-slider">
                <input type="range" class="self-consumption-range" 
                       min="20" max="80" value="50" step="5">
                <div class="slider-labels">
                    <span>20%</span>
                    <span class="consumption-percentage">50%</span>
                    <span>80%</span>
                </div>
                <div class="slider-visualization">
                    <svg viewBox="0 0 300 60">
                        <rect class="consumption-bar" x="0" y="10" width="150" height="20" />
                        <rect class="grid-bar" x="150" y="10" width="150" height="20" />
                        <text x="75" y="50" text-anchor="middle">Self-consumed</text>
                        <text x="225" y="50" text-anchor="middle">Fed to grid</text>
                    </svg>
                </div>
            </div>
        </div>
        
        <div class="additional-options">
            <h4><?php _e('Additional Options', 'erneuerbar'); ?></h4>
            <div class="checkbox-group">
                <div class="checkbox-item">
                    <input type="checkbox" id="mounting" data-price="500">
                    <label for="mounting">
                        <?php _e('Mounting System', 'erneuerbar'); ?><br>
                        <span class="price">CHF 500</span>
                    </label>
                </div>
                <div class="checkbox-item">
                    <input type="checkbox" id="battery" data-price="5000">
                    <label for="battery">
                        <?php _e('Battery Storage', 'erneuerbar'); ?><br>
                        <span class="price">CHF 5,000</span>
                    </label>
                </div>
            </div>
        </div>
    </div>
    
    <div class="calculation-results">
        <!-- Results populated via JavaScript -->
    </div>
</div>
```

### `/views/blocks/social-wall.php`
**Purpose:** Social media post display

**Masonry Grid Layout:**
```php
<?php
$posts = get_field('social_posts');
if ($posts) : ?>
    <div class="wp-block-acf-erneuerbar-social-wall">
        <div class="social-posts-grid">
            <?php foreach ($posts as $post) : ?>
                <article class="social-post">
                    <?php if ($post['image']) : ?>
                        <div class="post-image">
                            <?php echo wp_get_attachment_image($post['image'], 'medium'); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="post-content">
                        <div class="post-text">
                            <?php echo wpautop($post['text']); ?>
                        </div>
                        
                        <?php if ($post['link']) : ?>
                            <a href="<?php echo esc_url($post['link']); ?>" 
                               class="post-link" target="_blank" rel="noopener">
                                <?php _e('View Original Post', 'erneuerbar'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <footer class="post-meta">
                        <span class="platform">
                            <i class="fab fa-<?php echo esc_attr($post['platform']); ?>"></i>
                            <?php echo ucfirst($post['platform']); ?>
                        </span>
                        <time class="post-date" datetime="<?php echo esc_attr($post['date']); ?>">
                            <?php echo date_i18n(get_option('date_format'), strtotime($post['date'])); ?>
                        </time>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
```

---

## Key Development Patterns for Reference

### 1. **Swiss E-commerce Logistics**
- Canton-based shipping calculation with postcode mapping
- Seasonal pricing modes (winter/summer delivery)
- Multi-modal transport options (standard, e-transport, spedition)
- Product category-specific handling (batteries, large items)

### 2. **Document Generation Pipeline**
- HTML-to-PDF conversion with DocRaptor integration
- Document assembly using Ghostscript for multi-file PDFs
- S3 storage integration for generated documents
- Compliance document templates with dynamic content

### 3. **B2B Dropshipping Workflow**
- User role-based functionality with partner codes
- Automatic voucher application for resellers
- Dual email system (partner billing + customer delivery)
- Order metadata and cleanup automation

### 4. **Interactive Calculators**
- Real-time delivery cost calculation via REST API
- Solar ROI calculator with product selection
- SVG-based visualization for consumption rates
- AJAX-driven form interactions without page reload

### 5. **Social Media Integration**
- Multi-platform publishing automation via Zapier
- Masonry grid layout for dynamic content
- Platform-specific icon and styling systems
- Automated content syndication across channels

### 6. **Performance & UX Optimization**
- CSS Grid with responsive breakpoints
- JavaScript module organization with clear namespacing
- Progressive enhancement for calculator functionality
- Smooth animations and state transitions

This theme demonstrates sophisticated e-commerce functionality specifically tailored for the renewable energy market, with particular emphasis on Swiss logistics requirements and regulatory compliance. The combination of complex shipping calculations, document generation, and B2B workflows provides excellent examples of industry-specific WordPress development.