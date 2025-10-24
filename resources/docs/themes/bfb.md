# BFB Theme Documentation

**Theme:** bfb-s03  
**Purpose:** Educational institution website with course management and bilingual content  
**Reference:** Educational platform patterns for S03 child themes

---

## src/ Directory - PHP Architecture

### `/src/BFB/Theme/Core.php`
**Extends:** `Standard03\Theme\Core`  
**Purpose:** Main theme controller with educational features and Welante integration

**Component Registration:**
```php
$this->registerComponents(array(
    '\BFB\Component\Frontend',
    '\BFB\Component\ACF',
    '\BFB\Component\Course',
    '\BFB\Component\Welante',
));
```

**Comprehensive Icon System:**
```php
// Educational content icon mapping (40+ icons)
private function setupIconMapping() {
    $icon_mapping = array(
        'diamond' => '01-diamond.svg',
        'speech' => '02-speech.svg',
        'square' => '03-square.svg',
        'star' => '04-star.svg',
        'diagram' => '05-diagram.svg',
        'connection' => '06-connection.svg',
        'circle' => '07-circle.svg',
        'eye' => '08-eye.svg',
        'heart' => '09-heart.svg',
        'group' => '10-group.svg',
        // ... extensive icon library for educational content
    );
    
    add_filter('bfb_content_icons', function($icons) use ($icon_mapping) {
        return array_merge($icons, $icon_mapping);
    });
}
```

**Language Support Setup:**
```php
public function setupMultilingual() {
    load_theme_textdomain('bfb', get_template_directory() . '/assets/languages');
    
    // Add support for French and German
    add_action('init', function() {
        $locale = get_locale();
        if (in_array($locale, array('fr_FR', 'fr_CH', 'de_DE', 'de_CH'))) {
            // Load appropriate language files
            $this->loadLanguageSpecificAssets($locale);
        }
    });
}
```

### `/src/BFB/Component/Course.php`
**Purpose:** Course management and display functionality

**Custom Post Type Registration:**
```php
public function registerCoursePostType() {
    register_post_type('bfb_course', array(
        'labels' => array(
            'name' => __('Courses', 'bfb'),
            'singular_name' => __('Course', 'bfb'),
            'add_new' => __('Add New Course', 'bfb'),
            'edit_item' => __('Edit Course', 'bfb'),
        ),
        'public' => true,
        'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
        'taxonomies' => array('bfb_course_category', 'bfb_education_level'),
        'has_archive' => true,
        'rewrite' => array('slug' => 'courses'),
        'menu_icon' => 'dashicons-welcome-learn-more',
        'show_in_rest' => true
    ));
}
```

**Course Metadata Management:**
```php
public function setupCourseFields() {
    add_action('acf/init', function() {
        acf_add_local_field_group(array(
            'key' => 'group_course_details',
            'title' => 'Course Details',
            'fields' => array(
                array(
                    'key' => 'field_course_duration',
                    'label' => 'Duration (hours)',
                    'name' => 'course_duration',
                    'type' => 'number',
                    'min' => 1,
                    'step' => 0.5
                ),
                array(
                    'key' => 'field_course_price',
                    'label' => 'Price (CHF)',
                    'name' => 'course_price',
                    'type' => 'number',
                    'min' => 0,
                    'step' => 1
                ),
                array(
                    'key' => 'field_course_max_participants',
                    'label' => 'Max Participants',
                    'name' => 'course_max_participants',
                    'type' => 'number',
                    'min' => 1
                ),
                array(
                    'key' => 'field_course_location',
                    'label' => 'Location',
                    'name' => 'course_location',
                    'type' => 'text'
                ),
                array(
                    'key' => 'field_course_instructor',
                    'label' => 'Instructor',
                    'name' => 'course_instructor',
                    'type' => 'post_object',
                    'post_type' => array('bfb_person')
                ),
                array(
                    'key' => 'field_course_success_rate',
                    'label' => 'Success Rate (%)',
                    'name' => 'course_success_rate',
                    'type' => 'range',
                    'min' => 0,
                    'max' => 100,
                    'step' => 1
                )
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'bfb_course'
                    )
                )
            )
        ));
    });
}
```

**Course Registration Integration:**
```php
public function handleCourseRegistration() {
    add_action('wp_ajax_bfb_register_course', array($this, 'processCourseRegistration'));
    add_action('wp_ajax_nopriv_bfb_register_course', array($this, 'processCourseRegistration'));
}

public function processCourseRegistration() {
    check_ajax_referer('bfb_course_registration', 'nonce');
    
    $course_id = intval($_POST['course_id']);
    $user_data = array(
        'first_name' => sanitize_text_field($_POST['first_name']),
        'last_name' => sanitize_text_field($_POST['last_name']),
        'email' => sanitize_email($_POST['email']),
        'phone' => sanitize_text_field($_POST['phone']),
        'company' => sanitize_text_field($_POST['company']),
        'special_requirements' => sanitize_textarea_field($_POST['special_requirements'])
    );
    
    // Validate required fields
    if (empty($user_data['first_name']) || empty($user_data['last_name']) || empty($user_data['email'])) {
        wp_send_json_error(__('Please fill in all required fields.', 'bfb'));
    }
    
    // Check course availability
    $max_participants = get_field('course_max_participants', $course_id);
    $current_registrations = $this->getCourseRegistrationCount($course_id);
    
    if ($current_registrations >= $max_participants) {
        wp_send_json_error(__('This course is fully booked.', 'bfb'));
    }
    
    // Save registration
    $registration_id = $this->saveRegistration($course_id, $user_data);
    
    if ($registration_id) {
        // Send confirmation emails
        $this->sendRegistrationConfirmation($registration_id, $user_data);
        $this->notifyAdministrators($registration_id, $course_id, $user_data);
        
        wp_send_json_success(array(
            'message' => __('Registration successful! You will receive a confirmation email shortly.', 'bfb'),
            'registration_id' => $registration_id
        ));
    } else {
        wp_send_json_error(__('Registration failed. Please try again.', 'bfb'));
    }
}
```

### `/src/BFB/Component/Welante.php`
**Purpose:** Integration with Welante course management system

**API Integration:**
```php
class Welante {
    private $api_base;
    private $api_key;
    
    public function __construct() {
        $this->api_base = defined('BFB_WELANTE_API_BASE') ? BFB_WELANTE_API_BASE : '';
        $this->api_key = defined('BFB_WELANTE_API_KEY') ? BFB_WELANTE_API_KEY : '';
        
        $this->init();
    }
    
    public function fetchCourseData($course_id) {
        $cache_key = "welante_course_{$course_id}";
        $cached_data = wp_cache_get($cache_key, 'bfb_welante');
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $response = wp_remote_get("{$this->api_base}/courses/{$course_id}", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Welante API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Welante API JSON Error: ' . json_last_error_msg());
            return false;
        }
        
        // Cache for 1 hour
        wp_cache_set($cache_key, $data, 'bfb_welante', HOUR_IN_SECONDS);
        
        return $data;
    }
    
    public function syncCourseSchedule($course_id) {
        $welante_data = $this->fetchCourseData($course_id);
        
        if (!$welante_data) {
            return false;
        }
        
        // Update WordPress course with Welante data
        $course_meta = array(
            'welante_course_id' => $welante_data['id'],
            'course_start_date' => $welante_data['start_date'],
            'course_end_date' => $welante_data['end_date'],
            'course_schedule' => $welante_data['schedule'],
            'course_availability' => $welante_data['available_spots'],
            'course_status' => $welante_data['status']
        );
        
        foreach ($course_meta as $key => $value) {
            update_post_meta($course_id, $key, $value);
        }
        
        // Update course status
        if ($welante_data['available_spots'] == 0) {
            update_post_meta($course_id, 'course_full', true);
        }
        
        return true;
    }
}
```

**Course URL Rewriting:**
```php
public function setupCourseUrlRewriting() {
    add_action('init', function() {
        // Legacy Welante URL redirects
        add_rewrite_rule(
            '^welante/course/([0-9]+)/?$',
            'index.php?post_type=bfb_course&welante_id=$matches[1]',
            'top'
        );
        
        // Course category pages
        add_rewrite_rule(
            '^courses/([^/]+)/?$',
            'index.php?post_type=bfb_course&course_category=$matches[1]',
            'top'
        );
    });
    
    add_filter('query_vars', function($vars) {
        $vars[] = 'welante_id';
        $vars[] = 'course_category';
        return $vars;
    });
    
    add_action('template_redirect', function() {
        global $wp_query;
        
        if (get_query_var('welante_id')) {
            $welante_id = get_query_var('welante_id');
            $course_id = $this->getCourseByWelante($welante_id);
            
            if ($course_id) {
                wp_redirect(get_permalink($course_id), 301);
                exit;
            }
        }
    });
}
```

### `/src/BFB/Component/Frontend.php`
**Purpose:** Frontend functionality and asset management

**Dynamic Color Theme System:**
```php
public function setupColorThemes() {
    $color_themes = array(
        'grundbildung' => array(
            'primary' => '#E74C3C',
            'secondary' => '#C0392B',
            'accent' => '#FADBD8'
        ),
        'weiterbildung' => array(
            'primary' => '#3498DB',
            'secondary' => '#2980B9',
            'accent' => '#D6EAF8'
        ),
        'dienstleistungen' => array(
            'primary' => '#2ECC71',
            'secondary' => '#27AE60',
            'accent' => '#D5F4E6'
        ),
        'beratung' => array(
            'primary' => '#F39C12',
            'secondary' => '#E67E22',
            'accent' => '#FCF3CF'
        ),
        // ... additional education area themes
    );
    
    add_action('wp_head', function() use ($color_themes) {
        $current_theme = $this->getCurrentPageTheme();
        if (isset($color_themes[$current_theme])) {
            $theme = $color_themes[$current_theme];
            echo "<style>
                :root {
                    --bfb-theme-primary: {$theme['primary']};
                    --bfb-theme-secondary: {$theme['secondary']};
                    --bfb-theme-accent: {$theme['accent']};
                }
            </style>";
        }
    });
}
```

---

## assets/ Directory - Styles and Scripts

### `/assets/scripts/child-theme.js`
**Purpose:** Frontend interactions and mega menu functionality

**Mega Menu with Hover Delays:**
```javascript
var BFB = {
    init: function() {
        this.initMegaMenu();
        this.initScrollAnimations();
        this.initCourseRegistration();
        this.initContactPersonSlider();
    },
    
    initMegaMenu: function() {
        const megaMenuItems = document.querySelectorAll('.mega-menu-trigger');
        let hoverTimeout;
        
        megaMenuItems.forEach(item => {
            const megaMenu = item.querySelector('.mega-menu-content');
            
            item.addEventListener('mouseenter', function() {
                clearTimeout(hoverTimeout);
                
                // Hide other open mega menus
                document.querySelectorAll('.mega-menu-content.show').forEach(menu => {
                    if (menu !== megaMenu) {
                        menu.classList.remove('show');
                    }
                });
                
                // Show current mega menu with delay
                hoverTimeout = setTimeout(() => {
                    megaMenu.classList.add('show');
                }, 200);
            });
            
            item.addEventListener('mouseleave', function() {
                clearTimeout(hoverTimeout);
                
                hoverTimeout = setTimeout(() => {
                    megaMenu.classList.remove('show');
                }, 300);
            });
            
            // Keep menu open when hovering over menu content
            megaMenu.addEventListener('mouseenter', function() {
                clearTimeout(hoverTimeout);
            });
            
            megaMenu.addEventListener('mouseleave', function() {
                hoverTimeout = setTimeout(() => {
                    megaMenu.classList.remove('show');
                }, 300);
            });
        });
    },
    
    initScrollAnimations: function() {
        // Jump navigation scroll spy
        const jumpNavItems = document.querySelectorAll('.jump-nav a[href^="#"]');
        const sections = document.querySelectorAll('[id]');
        
        if (jumpNavItems.length === 0) return;
        
        function updateActiveNavItem() {
            let current = '';
            
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.offsetHeight;
                
                if (window.pageYOffset >= sectionTop - 200) {
                    current = section.getAttribute('id');
                }
            });
            
            jumpNavItems.forEach(item => {
                item.classList.remove('active');
                if (item.getAttribute('href') === '#' + current) {
                    item.classList.add('active');
                }
            });
        }
        
        // Smooth scroll for jump navigation
        jumpNavItems.forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('href').substring(1);
                const targetElement = document.getElementById(targetId);
                
                if (targetElement) {
                    const headerHeight = document.querySelector('.bfb-header').offsetHeight;
                    const targetPosition = targetElement.offsetTop - headerHeight - 20;
                    
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });
        
        // Update active item on scroll
        window.addEventListener('scroll', updateActiveNavItem);
        updateActiveNavItem(); // Initial call
    },
    
    initCourseRegistration: function() {
        const registrationForms = document.querySelectorAll('.course-registration-form');
        
        registrationForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('action', 'bfb_register_course');
                formData.append('nonce', bfbAjax.nonce);
                
                const submitButton = this.querySelector('[type="submit"]');
                const originalText = submitButton.textContent;
                
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
                
                fetch(bfbAjax.ajaxUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        BFB.showNotification(data.data.message, 'success');
                        form.reset();
                        
                        // Show success state
                        form.innerHTML = `
                            <div class="registration-success">
                                <i class="fas fa-check-circle"></i>
                                <h3>Registration Successful!</h3>
                                <p>${data.data.message}</p>
                            </div>
                        `;
                    } else {
                        BFB.showNotification(data.data || 'Registration failed', 'error');
                    }
                })
                .catch(error => {
                    console.error('Registration error:', error);
                    BFB.showNotification('Registration failed. Please try again.', 'error');
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                });
            });
        });
    },
    
    initContactPersonSlider: function() {
        const sliders = document.querySelectorAll('.contact-person-slider');
        
        sliders.forEach(slider => {
            if (typeof Swiper !== 'undefined') {
                new Swiper(slider, {
                    slidesPerView: 1,
                    spaceBetween: 20,
                    loop: true,
                    autoplay: {
                        delay: 5000,
                        disableOnInteraction: false
                    },
                    pagination: {
                        el: slider.querySelector('.swiper-pagination'),
                        clickable: true
                    },
                    navigation: {
                        nextEl: slider.querySelector('.swiper-button-next'),
                        prevEl: slider.querySelector('.swiper-button-prev')
                    },
                    breakpoints: {
                        768: {
                            slidesPerView: 2
                        },
                        1024: {
                            slidesPerView: 3
                        }
                    }
                });
            }
        });
    },
    
    showNotification: function(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `bfb-notification bfb-notification--${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button class="notification-close" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.classList.add('fade-out');
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 5000);
        
        // Manual close
        notification.querySelector('.notification-close').addEventListener('click', () => {
            notification.remove();
        });
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    BFB.init();
});
```

### `/assets/styles/scss/child-theme.scss`
**Purpose:** Main stylesheet with educational institution styling

**Color Theme System:**
```scss
// Import parent theme styles
@import 'lbwp/resources/css/gutenberg-base';
@import '../../../lbwp-standard-03/assets/styles/scss/settings/__s03-colors';
@import '../../../lbwp-standard-03/assets/styles/scss/_mixins-s03';

// BFB-specific settings
@import '__settings';
@import '_fonts';
@import '_mixins';
@import '_typo';
@import '_settings-bootstrap-bfb';

:root {
    // Base BFB colors
    --bfb-primary-blue: #003366;
    --bfb-secondary-orange: #FF6B35;
    --bfb-accent-gray: #F8F9FA;
    --bfb-dark-gray: #333333;
    --bfb-border-color: #E9ECEF;
    
    // Educational area color themes (dynamic)
    --bfb-theme-primary: var(--bfb-primary-blue);
    --bfb-theme-secondary: var(--bfb-secondary-orange);
    --bfb-theme-accent: var(--bfb-accent-gray);
    
    // Semantic colors
    --bfb-success: #28A745;
    --bfb-warning: #FFC107;
    --bfb-danger: #DC3545;
    --bfb-info: #17A2B8;
}

body {
    font-family: 'Roboto', -apple-system, BlinkMacSystemFont, sans-serif;
    font-weight: 400;
    line-height: 1.6;
    color: var(--bfb-dark-gray);
    background-color: white;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
```

**Course Card Design:**
```scss
.bfb-course-card {
    background: white;
    border: 1px solid var(--bfb-border-color);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    height: 100%;
    
    &:hover {
        box-shadow: 0 8px 24px rgba(0, 51, 102, 0.15);
        transform: translateY(-4px);
    }
    
    .course-header {
        position: relative;
        padding: 1.5rem;
        background: linear-gradient(135deg, var(--bfb-theme-primary), var(--bfb-theme-secondary));
        color: white;
        
        .course-category {
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            opacity: 0.9;
        }
        
        .course-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            line-height: 1.3;
            
            a {
                color: inherit;
                text-decoration: none;
                
                &:hover {
                    text-decoration: underline;
                }
            }
        }
        
        .course-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.875rem;
            
            .meta-item {
                display: flex;
                align-items: center;
                gap: 0.25rem;
                
                i {
                    opacity: 0.8;
                }
            }
        }
    }
    
    .course-content {
        padding: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        
        .course-excerpt {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.5;
            flex: 1;
        }
        
        .course-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            
            .detail-item {
                text-align: center;
                padding: 0.75rem;
                background: var(--bfb-accent-gray);
                border-radius: 6px;
                
                .detail-value {
                    display: block;
                    font-size: 1.25rem;
                    font-weight: 600;
                    color: var(--bfb-theme-primary);
                    margin-bottom: 0.25rem;
                }
                
                .detail-label {
                    font-size: 0.75rem;
                    color: #666;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
            }
        }
        
        .success-rate {
            margin-bottom: 1.5rem;
            
            .success-label {
                font-size: 0.875rem;
                font-weight: 500;
                margin-bottom: 0.5rem;
                color: var(--bfb-dark-gray);
            }
            
            .success-bar {
                height: 8px;
                background: #E9ECEF;
                border-radius: 4px;
                overflow: hidden;
                
                .success-fill {
                    height: 100%;
                    background: linear-gradient(90deg, var(--bfb-success), #20C997);
                    border-radius: 4px;
                    transition: width 0.6s ease;
                }
            }
            
            .success-percentage {
                font-size: 0.875rem;
                font-weight: 600;
                color: var(--bfb-success);
                margin-top: 0.25rem;
            }
        }
    }
    
    .course-footer {
        padding: 1rem 1.5rem;
        background: var(--bfb-accent-gray);
        border-top: 1px solid var(--bfb-border-color);
        
        .course-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            
            .course-price {
                font-size: 1.25rem;
                font-weight: 600;
                color: var(--bfb-theme-primary);
            }
            
            .register-button {
                background: var(--bfb-theme-primary);
                color: white;
                border: none;
                padding: 0.75rem 1.5rem;
                border-radius: 6px;
                font-weight: 500;
                text-decoration: none;
                transition: background-color 0.3s ease;
                
                &:hover {
                    background: var(--bfb-theme-secondary);
                    color: white;
                }
                
                &:disabled {
                    background: #6C757D;
                    cursor: not-allowed;
                }
            }
        }
        
        .course-status {
            font-size: 0.875rem;
            text-align: center;
            padding: 0.5rem;
            border-radius: 4px;
            
            &.status-available {
                background: #D4EDDA;
                color: #155724;
            }
            
            &.status-limited {
                background: #FFF3CD;
                color: #856404;
            }
            
            &.status-full {
                background: #F8D7DA;
                color: #721C24;
            }
        }
    }
}
```

**Mega Menu Styling:**
```scss
.bfb-mega-menu {
    .mega-menu-trigger {
        position: relative;
        
        > a {
            display: block;
            padding: 1rem 1.5rem;
            color: var(--bfb-dark-gray);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            
            &:hover {
                color: var(--bfb-theme-primary);
                background: var(--bfb-accent-gray);
            }
        }
        
        .mega-menu-content {
            position: absolute;
            top: 100%;
            left: 0;
            width: 800px;
            background: white;
            border: 1px solid var(--bfb-border-color);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1000;
            
            &.show {
                opacity: 1;
                visibility: visible;
                transform: translateY(0);
            }
            
            .mega-menu-inner {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 2rem;
                padding: 2rem;
                
                .menu-section {
                    .section-title {
                        font-size: 1rem;
                        font-weight: 600;
                        color: var(--bfb-theme-primary);
                        margin-bottom: 1rem;
                        padding-bottom: 0.5rem;
                        border-bottom: 2px solid var(--bfb-theme-primary);
                    }
                    
                    .section-links {
                        list-style: none;
                        margin: 0;
                        padding: 0;
                        
                        li {
                            margin-bottom: 0.5rem;
                            
                            a {
                                display: block;
                                padding: 0.5rem 0;
                                color: var(--bfb-dark-gray);
                                text-decoration: none;
                                font-size: 0.9rem;
                                transition: color 0.3s ease;
                                
                                &:hover {
                                    color: var(--bfb-theme-primary);
                                    padding-left: 0.5rem;
                                }
                            }
                        }
                    }
                }
                
                .featured-content {
                    background: var(--bfb-accent-gray);
                    border-radius: 6px;
                    padding: 1.5rem;
                    
                    .featured-title {
                        font-size: 1rem;
                        font-weight: 600;
                        margin-bottom: 0.5rem;
                    }
                    
                    .featured-excerpt {
                        font-size: 0.875rem;
                        color: #666;
                        margin-bottom: 1rem;
                        line-height: 1.5;
                    }
                    
                    .featured-link {
                        display: inline-flex;
                        align-items: center;
                        gap: 0.5rem;
                        color: var(--bfb-theme-primary);
                        text-decoration: none;
                        font-weight: 500;
                        
                        &:hover {
                            text-decoration: underline;
                        }
                    }
                }
            }
        }
    }
}
```

---

## views/ Directory - Templates

### `/views/component/course/single.php`
**Purpose:** Comprehensive course detail page template

**Course Header and Navigation:**
```php
<?php
$course_id = get_the_ID();
$course_category = get_the_terms($course_id, 'bfb_course_category');
$instructor = get_field('course_instructor');
$welante_data = BFB\Component\Welante::getCourseData($course_id);
?>

<article class="bfb-course-single">
    <div class="course-hero">
        <div class="container">
            <div class="course-hero-content">
                <div class="course-breadcrumb">
                    <?php $this->view('parts/bfb-breadcrumb'); ?>
                </div>
                
                <?php if ($course_category && !is_wp_error($course_category)) : ?>
                    <div class="course-category">
                        <span class="category-badge" style="background-color: <?php echo get_field('category_color', 'bfb_course_category_' . $course_category[0]->term_id); ?>">
                            <?php echo esc_html($course_category[0]->name); ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <h1 class="course-title"><?php the_title(); ?></h1>
                
                <div class="course-meta">
                    <div class="meta-item">
                        <i class="fas fa-clock"></i>
                        <span><?php echo get_field('course_duration') . ' ' . __('hours', 'bfb'); ?></span>
                    </div>
                    
                    <div class="meta-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo get_field('course_location') ?: __('Various locations', 'bfb'); ?></span>
                    </div>
                    
                    <div class="meta-item">
                        <i class="fas fa-users"></i>
                        <span><?php printf(__('Max %d participants', 'bfb'), get_field('course_max_participants')); ?></span>
                    </div>
                    
                    <?php if ($instructor) : ?>
                        <div class="meta-item">
                            <i class="fas fa-user-tie"></i>
                            <span><?php echo get_the_title($instructor->ID); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="course-pricing">
                    <div class="price-main">
                        <span class="price-amount">CHF <?php echo number_format(get_field('course_price'), 0, '.', "'"); ?></span>
                        <span class="price-note"><?php _e('per person', 'bfb'); ?></span>
                    </div>
                    
                    <?php if (get_field('early_bird_price')) : ?>
                        <div class="price-early-bird">
                            <span class="early-bird-label"><?php _e('Early Bird:', 'bfb'); ?></span>
                            <span class="early-bird-price">CHF <?php echo number_format(get_field('early_bird_price'), 0, '.', "'"); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="course-hero-actions">
                <?php
                $registration_status = $this->getCourseRegistrationStatus($course_id);
                ?>
                
                <?php if ($registration_status === 'open') : ?>
                    <button class="btn btn--primary btn--large course-register-btn" 
                            data-course-id="<?php echo $course_id; ?>">
                        <i class="fas fa-user-plus"></i>
                        <?php _e('Register Now', 'bfb'); ?>
                    </button>
                <?php elseif ($registration_status === 'waitlist') : ?>
                    <button class="btn btn--secondary btn--large course-waitlist-btn" 
                            data-course-id="<?php echo $course_id; ?>">
                        <i class="fas fa-clock"></i>
                        <?php _e('Join Waitlist', 'bfb'); ?>
                    </button>
                <?php else : ?>
                    <button class="btn btn--disabled btn--large" disabled>
                        <i class="fas fa-times"></i>
                        <?php _e('Registration Closed', 'bfb'); ?>
                    </button>
                <?php endif; ?>
                
                <button class="btn btn--outline btn--large course-info-btn" 
                        data-toggle="modal" data-target="#course-info-modal">
                    <i class="fas fa-info-circle"></i>
                    <?php _e('Course Information', 'bfb'); ?>
                </button>
            </div>
        </div>
    </div>
    
    <div class="course-content">
        <div class="container">
            <div class="course-layout">
                <aside class="course-sidebar">
                    <div class="course-navigation sticky-navigation">
                        <nav class="jump-nav">
                            <h4 class="jump-nav-title"><?php _e('Course Content', 'bfb'); ?></h4>
                            <ul class="jump-nav-list">
                                <li><a href="#course-overview"><?php _e('Overview', 'bfb'); ?></a></li>
                                <li><a href="#course-objectives"><?php _e('Learning Objectives', 'bfb'); ?></a></li>
                                <li><a href="#course-content"><?php _e('Content', 'bfb'); ?></a></li>
                                <li><a href="#course-schedule"><?php _e('Schedule', 'bfb'); ?></a></li>
                                <li><a href="#course-instructor"><?php _e('Instructor', 'bfb'); ?></a></li>
                                <li><a href="#course-location"><?php _e('Location', 'bfb'); ?></a></li>
                                <li><a href="#course-registration"><?php _e('Registration', 'bfb'); ?></a></li>
                            </ul>
                        </nav>
                        
                        <div class="course-quick-facts">
                            <h4><?php _e('Quick Facts', 'bfb'); ?></h4>
                            
                            <div class="fact-item">
                                <span class="fact-label"><?php _e('Success Rate:', 'bfb'); ?></span>
                                <div class="success-rate-display">
                                    <div class="success-bar">
                                        <div class="success-fill" style="width: <?php echo get_field('course_success_rate'); ?>%"></div>
                                    </div>
                                    <span class="success-percentage"><?php echo get_field('course_success_rate'); ?>%</span>
                                </div>
                            </div>
                            
                            <?php if (get_field('course_certification')) : ?>
                                <div class="fact-item">
                                    <span class="fact-label"><?php _e('Certification:', 'bfb'); ?></span>
                                    <span class="fact-value"><?php echo get_field('course_certification'); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="fact-item">
                                <span class="fact-label"><?php _e('Language:', 'bfb'); ?></span>
                                <span class="fact-value">
                                    <?php
                                    $languages = get_field('course_languages');
                                    echo $languages ? implode(', ', $languages) : __('German', 'bfb');
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </aside>
                
                <main class="course-main">
                    <section id="course-overview" class="course-section">
                        <h2><?php _e('Course Overview', 'bfb'); ?></h2>
                        <div class="course-description">
                            <?php the_content(); ?>
                        </div>
                        
                        <?php if (has_post_thumbnail()) : ?>
                            <div class="course-featured-image">
                                <?php the_post_thumbnail('large', array('class' => 'img-responsive')); ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    
                    <?php if (get_field('learning_objectives')) : ?>
                        <section id="course-objectives" class="course-section">
                            <h2><?php _e('Learning Objectives', 'bfb'); ?></h2>
                            <div class="objectives-list">
                                <?php foreach (get_field('learning_objectives') as $objective) : ?>
                                    <div class="objective-item">
                                        <i class="fas fa-check-circle"></i>
                                        <span><?php echo esc_html($objective['objective']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                    
                    <?php if (get_field('course_modules')) : ?>
                        <section id="course-content" class="course-section">
                            <h2><?php _e('Course Content', 'bfb'); ?></h2>
                            <div class="course-modules">
                                <?php foreach (get_field('course_modules') as $index => $module) : ?>
                                    <div class="module-item">
                                        <div class="module-header">
                                            <span class="module-number"><?php echo $index + 1; ?></span>
                                            <h3 class="module-title"><?php echo esc_html($module['module_title']); ?></h3>
                                            <span class="module-duration"><?php echo esc_html($module['module_duration']); ?></span>
                                        </div>
                                        <div class="module-content">
                                            <?php echo wpautop($module['module_content']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                    
                    <?php if ($welante_data && isset($welante_data['schedule'])) : ?>
                        <section id="course-schedule" class="course-section">
                            <h2><?php _e('Course Schedule', 'bfb'); ?></h2>
                            <div class="schedule-container">
                                <?php foreach ($welante_data['schedule'] as $session) : ?>
                                    <div class="schedule-item">
                                        <div class="schedule-date">
                                            <div class="date-day"><?php echo date('d', strtotime($session['date'])); ?></div>
                                            <div class="date-month"><?php echo date('M', strtotime($session['date'])); ?></div>
                                        </div>
                                        <div class="schedule-details">
                                            <h4 class="session-title"><?php echo esc_html($session['title']); ?></h4>
                                            <div class="session-time">
                                                <i class="fas fa-clock"></i>
                                                <?php echo esc_html($session['start_time'] . ' - ' . $session['end_time']); ?>
                                            </div>
                                            <div class="session-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo esc_html($session['location']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </div>
</article>
```

### `/views/blocks/person-directory.php`
**Purpose:** Staff directory with filtering and contact information

**Person Directory Implementation:**
```php
<?php
$department_filter = get_field('show_department_filter');
$contact_display = get_field('contact_display_mode');
$persons_per_page = get_field('persons_per_page') ?: 12;

$persons_query = new WP_Query(array(
    'post_type' => 'bfb_person',
    'posts_per_page' => $persons_per_page,
    'meta_key' => 'person_sort_order',
    'orderby' => 'meta_value_num',
    'order' => 'ASC'
));
?>

<div class="wp-block-acf-person-directory">
    <div class="person-directory-container">
        <?php if ($department_filter) : ?>
            <div class="directory-filters">
                <div class="filter-tabs">
                    <button class="filter-tab active" data-filter="all">
                        <?php _e('All Departments', 'bfb'); ?>
                    </button>
                    
                    <?php
                    $departments = get_terms(array(
                        'taxonomy' => 'bfb_department',
                        'hide_empty' => true
                    ));
                    
                    foreach ($departments as $department) :
                    ?>
                        <button class="filter-tab" data-filter="<?php echo esc_attr($department->slug); ?>">
                            <?php echo esc_html($department->name); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="person-directory-grid">
            <?php if ($persons_query->have_posts()) : ?>
                <?php while ($persons_query->have_posts()) : $persons_query->the_post(); ?>
                    <?php
                    $person_id = get_the_ID();
                    $person_department = get_the_terms($person_id, 'bfb_department');
                    $department_slug = $person_department && !is_wp_error($person_department) ? $person_department[0]->slug : '';
                    ?>
                    
                    <div class="person-card" data-department="<?php echo esc_attr($department_slug); ?>">
                        <div class="person-image">
                            <?php if (has_post_thumbnail()) : ?>
                                <?php the_post_thumbnail('medium', array('class' => 'person-photo')); ?>
                            <?php else : ?>
                                <img src="<?php echo get_template_directory_uri(); ?>/assets/img/bfb-person-placeholder.jpg" 
                                     alt="<?php echo esc_attr(get_the_title()); ?>" 
                                     class="person-photo placeholder">
                            <?php endif; ?>
                            
                            <div class="person-overlay">
                                <button class="contact-button" data-person-id="<?php echo $person_id; ?>">
                                    <i class="fas fa-envelope"></i>
                                    <?php _e('Contact', 'bfb'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="person-info">
                            <h3 class="person-name"><?php the_title(); ?></h3>
                            
                            <?php $person_title = get_field('person_title'); ?>
                            <?php if ($person_title) : ?>
                                <p class="person-title"><?php echo esc_html($person_title); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($person_department && !is_wp_error($person_department)) : ?>
                                <p class="person-department"><?php echo esc_html($person_department[0]->name); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($contact_display === 'visible' || $contact_display === 'hover') : ?>
                                <div class="person-contact <?php echo $contact_display === 'hover' ? 'contact-on-hover' : ''; ?>">
                                    <?php $person_email = get_field('person_email'); ?>
                                    <?php if ($person_email) : ?>
                                        <div class="contact-item">
                                            <i class="fas fa-envelope"></i>
                                            <a href="mailto:<?php echo esc_attr($person_email); ?>">
                                                <?php echo esc_html($person_email); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php $person_phone = get_field('person_phone'); ?>
                                    <?php if ($person_phone) : ?>
                                        <div class="contact-item">
                                            <i class="fas fa-phone"></i>
                                            <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $person_phone)); ?>">
                                                <?php echo esc_html($person_phone); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php $person_office = get_field('person_office'); ?>
                                    <?php if ($person_office) : ?>
                                        <div class="contact-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo esc_html($person_office); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php $person_bio = get_field('person_bio'); ?>
                            <?php if ($person_bio) : ?>
                                <div class="person-bio">
                                    <?php echo wp_trim_words($person_bio, 25); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php $person_specialties = get_field('person_specialties'); ?>
                            <?php if ($person_specialties) : ?>
                                <div class="person-specialties">
                                    <strong><?php _e('Specialties:', 'bfb'); ?></strong>
                                    <div class="specialty-tags">
                                        <?php foreach ($person_specialties as $specialty) : ?>
                                            <span class="specialty-tag"><?php echo esc_html($specialty['specialty']); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else : ?>
                <div class="no-persons-found">
                    <p><?php _e('No team members found.', 'bfb'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($persons_query->max_num_pages > 1) : ?>
            <div class="directory-pagination">
                <button class="btn btn--secondary load-more-persons" 
                        data-page="2" 
                        data-max-pages="<?php echo $persons_query->max_num_pages; ?>">
                    <?php _e('Load More', 'bfb'); ?>
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Contact Modal -->
<div id="person-contact-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php _e('Contact Person', 'bfb'); ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form class="person-contact-form">
                    <input type="hidden" name="person_id" id="contact-person-id">
                    
                    <div class="form-group">
                        <label for="contact-name"><?php _e('Your Name', 'bfb'); ?> *</label>
                        <input type="text" id="contact-name" name="contact_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact-email"><?php _e('Your Email', 'bfb'); ?> *</label>
                        <input type="email" id="contact-email" name="contact_email" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact-subject"><?php _e('Subject', 'bfb'); ?> *</label>
                        <input type="text" id="contact-subject" name="contact_subject" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact-message"><?php _e('Message', 'bfb'); ?> *</label>
                        <textarea id="contact-message" name="contact_message" class="form-control" rows="5" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="privacy_consent" required>
                            <?php printf(__('I agree to the %sprivacy policy%s', 'bfb'), '<a href="/privacy-policy" target="_blank">', '</a>'); ?>
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn--secondary" data-dismiss="modal">
                    <?php _e('Cancel', 'bfb'); ?>
                </button>
                <button type="submit" form="person-contact-form" class="btn btn--primary">
                    <i class="fas fa-paper-plane"></i>
                    <?php _e('Send Message', 'bfb'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Department filtering
    const filterTabs = document.querySelectorAll('.filter-tab');
    const personCards = document.querySelectorAll('.person-card');
    
    filterTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const filter = this.dataset.filter;
            
            // Update active tab
            filterTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Filter person cards
            personCards.forEach(card => {
                if (filter === 'all' || card.dataset.department === filter) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });
    
    // Contact modal
    const contactButtons = document.querySelectorAll('.contact-button');
    const contactModal = document.getElementById('person-contact-modal');
    const personIdInput = document.getElementById('contact-person-id');
    
    contactButtons.forEach(button => {
        button.addEventListener('click', function() {
            const personId = this.dataset.personId;
            personIdInput.value = personId;
            
            // Show modal (assuming Bootstrap modal)
            if (typeof $ !== 'undefined') {
                $(contactModal).modal('show');
            }
        });
    });
});
</script>

<?php wp_reset_postdata(); ?>
```

---

## Key Development Patterns for Reference

### 1. **Educational Institution Architecture**
- Course management system with custom post types and taxonomies
- Dynamic color theming based on educational areas/departments
- Multilingual content support with proper localization
- Integration with external course management systems (Welante)

### 2. **Advanced Mega Menu Implementation**
- Hover delays and smooth transitions for better UX
- Grid-based layout with featured content sections
- Accessibility considerations with proper ARIA attributes
- Mobile-responsive design with touch-friendly interactions

### 3. **Course Registration System**
- AJAX-based registration with real-time validation
- Integration with external APIs for course data synchronization
- Waitlist functionality for fully booked courses
- Email notifications for confirmations and updates

### 4. **Staff Directory Features**
- Filterable person directory with department categorization
- Contact modal with form validation and privacy consent
- Image placeholders for missing staff photos
- Responsive grid layout with hover effects

### 5. **Performance & User Experience**
- Lazy loading for image-heavy directories
- Smooth scroll navigation with active state tracking
- Progressive enhancement for JavaScript functionality
- Optimized database queries with proper caching

### 6. **Design System Implementation**
- Comprehensive SCSS architecture with component-based styling
- Custom icon system with SVG graphics and dynamic coloring
- Consistent spacing and typography scales
- Responsive breakpoints with mobile-first approach

### 7. **Third-Party Integration Patterns**
- API wrapper classes for external service integration
- Error handling and fallback mechanisms
- Data caching strategies for improved performance
- URL rewriting for legacy system compatibility

This theme demonstrates sophisticated educational platform development with modern WordPress practices, comprehensive course management features, and excellent user experience design. The architecture provides excellent examples for building institutional websites with complex content management requirements.