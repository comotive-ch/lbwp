# Content Arena Theme Documentation

**Theme:** content-arena-s03  
**Purpose:** Sports content platform with user authentication and content collections  
**Reference:** Modern content platform patterns for S03 child themes

---

## src/ Directory - PHP Architecture

### `/src/ContentArena/Theme/Core.php`
**Extends:** `Standard03\Theme\Core`  
**Purpose:** Main theme controller with comprehensive feature management

**Component Registration:**
```php
$this->registerComponents(array(
    '\ContentArena\Component\ACF',
    '\ContentArena\Component\Backend',
    '\ContentArena\Component\Frontend',
    '\ContentArena\Component\User',
    '\ContentArena\Component\Search',
    '\ContentArena\Component\Filter',
    '\ContentArena\Component\Collection',
    '\ContentArena\Component\Content',
    '\ContentArena\Component\Actor',
    '\ContentArena\Component\News',
    '\ContentArena\Component\Import',
    '\ContentArena\Component\Grabber',
    '\ContentArena\Component\Feedback',
));
```

**Security Headers Setup:**
```php
public function setupSecurityHeaders() {
    add_action('wp_head', function() {
        echo '<meta http-equiv="Content-Security-Policy" content="
            default-src \'self\';
            script-src \'self\' \'unsafe-inline\' https://analytics.example.com;
            style-src \'self\' \'unsafe-inline\';
            img-src \'self\' data: https:;
            connect-src \'self\' https://api.example.com;
        ">';
    });
}
```

### `/src/ContentArena/Component/User.php`
**Purpose:** Keycloak SSO integration and user profile management

**Keycloak Authentication:**
```php
public function setupKeycloakIntegration() {
    add_action('init', array($this, 'handleKeycloakCallback'));
    add_action('wp_login', array($this, 'syncKeycloakUser'), 10, 2);
    add_filter('authenticate', array($this, 'keycloakAuthenticate'), 30, 3);
}

public function keycloakAuthenticate($user, $username, $password) {
    if (empty($username) || empty($password)) {
        return $user;
    }
    
    $keycloak_response = $this->validateWithKeycloak($username, $password);
    
    if ($keycloak_response['success']) {
        // Create or update WordPress user
        $wp_user = $this->createOrUpdateUser($keycloak_response['user_data']);
        return $wp_user;
    }
    
    return new WP_Error('keycloak_auth_failed', __('Authentication failed', 'content-arena'));
}
```

**Profile Management:**
```php
public function updateUserProfile($user_id, $profile_data) {
    // Validate profile data
    $validated_data = $this->validateProfileData($profile_data);
    
    // Update WordPress user meta
    foreach ($validated_data as $key => $value) {
        update_user_meta($user_id, "ca_profile_{$key}", sanitize_text_field($value));
    }
    
    // Sync with Keycloak
    $this->syncProfileToKeycloak($user_id, $validated_data);
    
    // Update activity log
    $this->logUserActivity($user_id, 'profile_updated', $validated_data);
}
```

### `/src/ContentArena/Component/Collection.php`
**Purpose:** User content collections (favorites/wishlists) management

**Collection Management:**
```php
public function addToCollection($user_id, $post_id, $collection_type = 'favorites') {
    $collections = get_user_meta($user_id, "ca_collection_{$collection_type}", true);
    if (!is_array($collections)) {
        $collections = array();
    }
    
    if (!in_array($post_id, $collections)) {
        $collections[] = $post_id;
        update_user_meta($user_id, "ca_collection_{$collection_type}", $collections);
        
        // Log activity
        $this->logCollectionActivity($user_id, 'added', $post_id, $collection_type);
        
        // Send notification
        do_action('ca_item_added_to_collection', $user_id, $post_id, $collection_type);
        
        return true;
    }
    
    return false;
}

public function getCollectionItems($user_id, $collection_type = 'favorites', $args = array()) {
    $collections = get_user_meta($user_id, "ca_collection_{$collection_type}", true);
    
    if (empty($collections)) {
        return array();
    }
    
    $default_args = array(
        'post__in' => $collections,
        'post_type' => array('post', 'ca_content', 'ca_event'),
        'posts_per_page' => 20,
        'orderby' => 'post__in'
    );
    
    $query_args = wp_parse_args($args, $default_args);
    return new WP_Query($query_args);
}
```

**REST API Endpoints:**
```php
public function registerRestEndpoints() {
    register_rest_route('content-arena/v1', '/collections/(?P<type>[a-zA-Z0-9_-]+)', array(
        'methods' => 'GET',
        'callback' => array($this, 'getCollectionAPI'),
        'permission_callback' => array($this, 'checkUserPermission'),
        'args' => array(
            'type' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return in_array($param, array('favorites', 'watchlist', 'completed'));
                }
            )
        )
    ));
    
    register_rest_route('content-arena/v1', '/collections/(?P<type>[a-zA-Z0-9_-]+)/(?P<post_id>\d+)', array(
        'methods' => array('POST', 'DELETE'),
        'callback' => array($this, 'manageCollectionItemAPI'),
        'permission_callback' => array($this, 'checkUserPermission')
    ));
}
```

### `/src/ContentArena/Component/Search.php`
**Purpose:** Advanced search functionality with faceted filtering

**Search Implementation:**
```php
public function setupAdvancedSearch() {
    add_action('pre_get_posts', array($this, 'modifySearchQuery'), 10, 1);
    add_filter('posts_search', array($this, 'customSearchQuery'), 10, 2);
    add_action('wp_ajax_ca_search_autocomplete', array($this, 'handleAutocomplete'));
    add_action('wp_ajax_nopriv_ca_search_autocomplete', array($this, 'handleAutocomplete'));
}

public function customSearchQuery($search, $query) {
    global $wpdb;
    
    if (!$query->is_search() || empty($search)) {
        return $search;
    }
    
    $search_term = $query->get('s');
    $search_term = $wpdb->esc_like($search_term);
    
    // Multi-field search
    $custom_search = "AND (
        ({$wpdb->posts}.post_title LIKE '%{$search_term}%')
        OR ({$wpdb->posts}.post_content LIKE '%{$search_term}%')
        OR EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} 
            WHERE {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID 
            AND {$wpdb->postmeta}.meta_value LIKE '%{$search_term}%'
        )
        OR EXISTS (
            SELECT 1 FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE tr.object_id = {$wpdb->posts}.ID
            AND t.name LIKE '%{$search_term}%'
        )
    )";
    
    return $custom_search;
}
```

**Autocomplete Functionality:**
```php
public function handleAutocomplete() {
    check_ajax_referer('ca_search_nonce', 'nonce');
    
    $search_term = sanitize_text_field($_POST['term']);
    $suggestions = array();
    
    // Search posts
    $posts = get_posts(array(
        's' => $search_term,
        'post_type' => array('post', 'ca_content', 'ca_event'),
        'posts_per_page' => 5
    ));
    
    foreach ($posts as $post) {
        $suggestions[] = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $post->post_type,
            'url' => get_permalink($post->ID),
            'excerpt' => wp_trim_words(strip_tags($post->post_content), 20)
        );
    }
    
    // Search taxonomy terms
    $terms = get_terms(array(
        'taxonomy' => array('category', 'ca_sport', 'ca_level'),
        'name__like' => $search_term,
        'number' => 3
    ));
    
    foreach ($terms as $term) {
        $suggestions[] = array(
            'id' => $term->term_id,
            'title' => $term->name,
            'type' => 'term',
            'url' => get_term_link($term),
            'excerpt' => $term->description
        );
    }
    
    wp_send_json_success($suggestions);
}
```

### `/src/ContentArena/Component/Content.php`
**Purpose:** Custom content types and metadata management

**Custom Post Types:**
```php
public function registerCustomPostTypes() {
    // Sports Content
    register_post_type('ca_content', array(
        'labels' => array(
            'name' => __('Sports Content', 'content-arena'),
            'singular_name' => __('Content', 'content-arena'),
        ),
        'public' => true,
        'supports' => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
        'taxonomies' => array('ca_sport', 'ca_level', 'ca_equipment'),
        'has_archive' => true,
        'rewrite' => array('slug' => 'content'),
        'menu_icon' => 'dashicons-video-alt3'
    ));
    
    // Events
    register_post_type('ca_event', array(
        'labels' => array(
            'name' => __('Events', 'content-arena'),
            'singular_name' => __('Event', 'content-arena'),
        ),
        'public' => true,
        'supports' => array('title', 'editor', 'thumbnail', 'custom-fields'),
        'has_archive' => true,
        'rewrite' => array('slug' => 'events'),
        'menu_icon' => 'dashicons-calendar-alt'
    ));
}
```

**Content Rating System:**
```php
public function handleContentRating() {
    add_action('wp_ajax_ca_rate_content', array($this, 'processRating'));
    add_action('wp_ajax_nopriv_ca_rate_content', array($this, 'processRating'));
}

public function processRating() {
    check_ajax_referer('ca_rating_nonce', 'nonce');
    
    $post_id = intval($_POST['post_id']);
    $rating = intval($_POST['rating']);
    $user_id = get_current_user_id();
    
    if ($rating < 1 || $rating > 5) {
        wp_send_json_error(__('Invalid rating', 'content-arena'));
    }
    
    // Store individual rating
    $user_ratings = get_post_meta($post_id, 'ca_user_ratings', true);
    if (!is_array($user_ratings)) {
        $user_ratings = array();
    }
    
    $user_ratings[$user_id] = array(
        'rating' => $rating,
        'timestamp' => current_time('timestamp')
    );
    
    update_post_meta($post_id, 'ca_user_ratings', $user_ratings);
    
    // Calculate average rating
    $total_ratings = array_sum(array_column($user_ratings, 'rating'));
    $count_ratings = count($user_ratings);
    $average_rating = $total_ratings / $count_ratings;
    
    update_post_meta($post_id, 'ca_average_rating', $average_rating);
    update_post_meta($post_id, 'ca_rating_count', $count_ratings);
    
    wp_send_json_success(array(
        'average_rating' => round($average_rating, 1),
        'rating_count' => $count_ratings
    ));
}
```

### `/src/ContentArena/Component/ACF.php`
**Purpose:** ACF block registration and field management

**Block Registration:**
```php
public function registerBlocks() {
    $blocks = array(
        'ca-hero' => array(
            'title' => 'Content Arena Hero',
            'description' => 'Hero section with background and content',
            'category' => 'media',
            'supports' => array('mode' => false)
        ),
        'ca-search' => array(
            'title' => 'Advanced Search',
            'description' => 'Search interface with filters',
            'category' => 'widgets'
        ),
        'ca-event-calendar' => array(
            'title' => 'Event Calendar',
            'description' => 'Calendar view for events',
            'category' => 'widgets'
        ),
        'ca-collection-detail' => array(
            'title' => 'Collection Detail',
            'description' => 'User collection management interface',
            'category' => 'widgets'
        ),
        'ca-profile-overview' => array(
            'title' => 'Profile Overview',
            'description' => 'User profile display',
            'category' => 'widgets'
        )
    );
    
    foreach ($blocks as $name => $config) {
        acf_register_block_type(array_merge($config, array(
            'name' => $name,
            'render_template' => "views/blocks/block-{$name}.php",
            'mode' => 'preview',
            'supports' => array_merge(
                array('align' => false, 'anchor' => true),
                $config['supports'] ?? array()
            )
        )));
    }
}
```

---

## assets/ Directory - Styles and Scripts

### `/assets/scripts/Main.js`
**Purpose:** Modern ES6 application entry point

**Module Architecture:**
```javascript
import Navigation from './modules/Navigation.js?v=2.1.0';
import Search from './modules/Search.js?v=2.1.0';
import Collection from './modules/Collection.js?v=2.1.0';
import UserSettings from './modules/UserSettings.js?v=2.1.0';
import EventCalendar from './modules/EventCalendar.js?v=2.1.0';
import PostSlider from './modules/PostSlider.js?v=2.1.0';

class ContentArenaApp {
    constructor() {
        this.config = window.caConfig || {};
        this.user = window.caUser || {};
        this.modules = {};
        
        this.init();
    }
    
    init() {
        // Initialize core modules
        this.modules.navigation = new Navigation();
        this.modules.search = new Search(this.config.search);
        this.modules.collection = new Collection(this.user);
        this.modules.userSettings = new UserSettings(this.user);
        this.modules.eventCalendar = new EventCalendar();
        this.modules.postSlider = new PostSlider();
        
        // Setup global event listeners
        this.setupGlobalEvents();
        
        // Initialize tooltips and modals
        this.initializeUIComponents();
    }
    
    setupGlobalEvents() {
        // Global click handler for collection actions
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-collection-action]')) {
                e.preventDefault();
                this.modules.collection.handleAction(e.target);
            }
        });
        
        // Global form submission handler
        document.addEventListener('submit', (e) => {
            if (e.target.matches('.ca-ajax-form')) {
                e.preventDefault();
                this.handleAjaxForm(e.target);
            }
        });
    }
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.ContentArena = new ContentArenaApp();
});
```

### `/assets/scripts/modules/Collection.js`
**Purpose:** User collection management module

**Collection Management:**
```javascript
export default class Collection {
    constructor(user) {
        this.user = user;
        this.apiBase = '/wp-json/content-arena/v1';
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.initCollectionViews();
    }
    
    bindEvents() {
        // Add to collection buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('.add-to-collection')) {
                this.handleAddToCollection(e.target);
            }
        });
        
        // Remove from collection buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('.remove-from-collection')) {
                this.handleRemoveFromCollection(e.target);
            }
        });
        
        // Collection type switcher
        document.addEventListener('change', (e) => {
            if (e.target.matches('.collection-type-select')) {
                this.loadCollectionType(e.target.value);
            }
        });
    }
    
    async handleAddToCollection(button) {
        const postId = button.dataset.postId;
        const collectionType = button.dataset.collectionType || 'favorites';
        
        if (!this.user.id) {
            this.showLoginPrompt();
            return;
        }
        
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
        
        try {
            const response = await fetch(`${this.apiBase}/collections/${collectionType}/${postId}`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': this.user.nonce,
                    'Content-Type': 'application/json'
                }
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.updateCollectionButton(button, 'added');
                this.showNotification('Added to collection', 'success');
                this.updateCollectionCount(collectionType, 1);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            console.error('Collection error:', error);
            this.showNotification('Failed to add to collection', 'error');
        } finally {
            button.disabled = false;
        }
    }
    
    async loadCollectionType(collectionType) {
        const container = document.querySelector('.collection-items-container');
        if (!container) return;
        
        container.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
        
        try {
            const response = await fetch(`${this.apiBase}/collections/${collectionType}?per_page=20`);
            const result = await response.json();
            
            if (result.success) {
                this.renderCollectionItems(result.data, container);
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            container.innerHTML = '<p class="error-message">Failed to load collection items.</p>';
        }
    }
}
```

### `/assets/scripts/modules/Search.js`
**Purpose:** Advanced search functionality

**Search Implementation:**
```javascript
export default class Search {
    constructor(config = {}) {
        this.config = {
            debounceDelay: 300,
            minLength: 2,
            maxSuggestions: 8,
            ...config
        };
        
        this.searchInput = document.querySelector('.ca-search-input');
        this.suggestionsContainer = document.querySelector('.ca-search-suggestions');
        this.resultsContainer = document.querySelector('.ca-search-results');
        
        this.debounceTimer = null;
        this.currentRequest = null;
        
        this.init();
    }
    
    init() {
        if (!this.searchInput) return;
        
        this.bindEvents();
        this.setupKeyboardNavigation();
    }
    
    bindEvents() {
        // Search input with debouncing
        this.searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => {
                this.handleSearch(query);
            }, this.config.debounceDelay);
        });
        
        // Focus and blur events
        this.searchInput.addEventListener('focus', () => {
            this.showSuggestions();
        });
        
        this.searchInput.addEventListener('blur', (e) => {
            // Delay hiding to allow for suggestion clicks
            setTimeout(() => {
                this.hideSuggestions();
            }, 150);
        });
        
        // Form submission
        const searchForm = this.searchInput.closest('form');
        if (searchForm) {
            searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.performSearch(this.searchInput.value);
            });
        }
    }
    
    async handleSearch(query) {
        if (query.length < this.config.minLength) {
            this.hideSuggestions();
            return;
        }
        
        // Cancel previous request
        if (this.currentRequest) {
            this.currentRequest.abort();
        }
        
        // Create new request
        this.currentRequest = new AbortController();
        
        try {
            const response = await fetch('/wp-admin/admin-ajax.php', {
                method: 'POST',
                signal: this.currentRequest.signal,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'ca_search_autocomplete',
                    term: query,
                    nonce: window.caConfig.searchNonce
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.displaySuggestions(result.data);
            } else {
                this.hideSuggestions();
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Search error:', error);
            }
        }
    }
    
    displaySuggestions(suggestions) {
        if (!this.suggestionsContainer || suggestions.length === 0) {
            this.hideSuggestions();
            return;
        }
        
        const html = suggestions.map((item, index) => `
            <div class="suggestion-item" data-index="${index}" data-url="${item.url}">
                <div class="suggestion-icon">
                    <i class="fas fa-${this.getIconForType(item.type)}"></i>
                </div>
                <div class="suggestion-content">
                    <div class="suggestion-title">${this.highlightMatch(item.title)}</div>
                    <div class="suggestion-excerpt">${item.excerpt}</div>
                    <div class="suggestion-meta">${item.type}</div>
                </div>
            </div>
        `).join('');
        
        this.suggestionsContainer.innerHTML = html;
        this.showSuggestions();
        
        // Bind suggestion click events
        this.suggestionsContainer.querySelectorAll('.suggestion-item').forEach(item => {
            item.addEventListener('click', (e) => {
                window.location.href = item.dataset.url;
            });
        });
    }
}
```

### `/assets/styles/scss/child-theme.scss`
**Purpose:** Main stylesheet with sports content styling

**Base Styles:**
```scss
// Import parent theme styles and variables
@import 'lbwp/resources/css/gutenberg-base';
@import '../../../lbwp-standard-03/assets/styles/scss/settings/__s03-colors';
@import '../../../lbwp-standard-03/assets/styles/scss/_mixins-s03';

// Theme-specific settings
@import '__settings';
@import '_fonts';
@import '_mixins';
@import '_typo';

:root {
    // Content Arena color system
    --ca-primary-blue: #0066CC;
    --ca-secondary-orange: #FF6B35;
    --ca-accent-green: #28A745;
    --ca-dark-gray: #333333;
    --ca-light-gray: #F8F9FA;
    --ca-border-color: #E9ECEF;
    
    // Sports-specific colors
    --ca-sport-football: #2E7D32;
    --ca-sport-basketball: #FF5722;
    --ca-sport-tennis: #FFC107;
    --ca-sport-swimming: #2196F3;
    
    // Interactive states
    --ca-hover-opacity: 0.8;
    --ca-focus-shadow: 0 0 0 3px rgba(0, 102, 204, 0.25);
}

body {
    font-family: 'Roboto Condensed', -apple-system, BlinkMacSystemFont, sans-serif;
    font-weight: 400;
    line-height: 1.6;
    color: var(--ca-dark-gray);
    background-color: var(--ca-light-gray);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
```

**Component Architecture:**
```scss
// Sports content cards
.ca-content-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: all 0.3s ease;
    
    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    }
    
    .card-header {
        position: relative;
        aspect-ratio: 16 / 9;
        overflow: hidden;
        
        .featured-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .sport-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: var(--ca-primary-blue);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .difficulty-level {
            position: absolute;
            top: 1rem;
            right: 1rem;
            
            .level-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                display: inline-block;
                margin: 0 2px;
                
                &.active {
                    background: var(--ca-accent-green);
                }
                
                &.inactive {
                    background: rgba(255, 255, 255, 0.4);
                }
            }
        }
    }
    
    .card-content {
        padding: 1.5rem;
        
        .content-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--ca-dark-gray);
            
            a {
                color: inherit;
                text-decoration: none;
                
                &:hover {
                    color: var(--ca-primary-blue);
                }
            }
        }
        
        .content-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: #666;
            
            .meta-item {
                display: flex;
                align-items: center;
                gap: 0.25rem;
                
                i {
                    width: 16px;
                }
            }
        }
        
        .content-excerpt {
            color: #666;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
    }
    
    .card-actions {
        padding: 0 1.5rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .collection-status {
            font-size: 0.875rem;
            color: #666;
        }
    }
}
```

**Search Interface:**
```scss
.ca-search-container {
    position: relative;
    max-width: 600px;
    margin: 0 auto;
    
    .search-form {
        position: relative;
        
        .search-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--ca-border-color);
            border-radius: 50px;
            font-size: 1rem;
            background: white;
            transition: all 0.3s ease;
            
            &:focus {
                outline: none;
                border-color: var(--ca-primary-blue);
                box-shadow: var(--ca-focus-shadow);
            }
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            pointer-events: none;
        }
        
        .search-submit {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: var(--ca-primary-blue);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            
            &:hover {
                background: darken(var(--ca-primary-blue), 10%);
            }
        }
    }
    
    .search-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid var(--ca-border-color);
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        z-index: 1000;
        max-height: 400px;
        overflow-y: auto;
        
        .suggestion-item {
            padding: 1rem;
            border-bottom: 1px solid var(--ca-border-color);
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: background-color 0.2s ease;
            
            &:hover {
                background: var(--ca-light-gray);
            }
            
            &:last-child {
                border-bottom: none;
            }
            
            .suggestion-icon {
                width: 40px;
                height: 40px;
                background: var(--ca-light-gray);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--ca-primary-blue);
                flex-shrink: 0;
            }
            
            .suggestion-content {
                flex: 1;
                
                .suggestion-title {
                    font-weight: 600;
                    margin-bottom: 0.25rem;
                    color: var(--ca-dark-gray);
                }
                
                .suggestion-excerpt {
                    font-size: 0.875rem;
                    color: #666;
                    margin-bottom: 0.25rem;
                    line-height: 1.4;
                }
                
                .suggestion-meta {
                    font-size: 0.75rem;
                    color: #999;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
            }
        }
    }
}
```

**Collection Management:**
```scss
.ca-collection-manager {
    .collection-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        
        .collection-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--ca-dark-gray);
        }
        
        .collection-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
            
            .collection-type-select {
                padding: 0.5rem 1rem;
                border: 1px solid var(--ca-border-color);
                border-radius: 6px;
                background: white;
                
                &:focus {
                    outline: none;
                    border-color: var(--ca-primary-blue);
                }
            }
            
            .collection-actions {
                display: flex;
                gap: 0.5rem;
            }
        }
    }
    
    .collection-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 2rem;
        
        @include media-breakpoint-down(md) {
            grid-template-columns: 1fr;
        }
    }
    
    .collection-item {
        position: relative;
        
        .remove-from-collection {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: all 0.3s ease;
            
            &:hover {
                background: #dc3545;
                transform: scale(1.1);
            }
        }
        
        &:hover {
            .remove-from-collection {
                opacity: 1;
            }
        }
    }
    
    .empty-collection {
        text-align: center;
        padding: 4rem 2rem;
        color: #666;
        
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--ca-border-color);
        }
        
        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .empty-description {
            margin-bottom: 2rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
    }
}
```

---

## views/ Directory - Templates

### `/views/layout/header.php`
**Purpose:** Complex header with user authentication and search

**Navigation Structure:**
```php
<header class="ca-header" role="banner">
    <div class="header-top">
        <div class="container">
            <div class="header-top-content">
                <div class="user-info">
                    <?php if (is_user_logged_in()) : ?>
                        <div class="user-welcome">
                            <span class="welcome-text">
                                <?php printf(__('Welcome, %s', 'content-arena'), wp_get_current_user()->display_name); ?>
                            </span>
                            <div class="user-dropdown">
                                <button class="user-dropdown-toggle" aria-expanded="false">
                                    <?php echo get_avatar(get_current_user_id(), 32); ?>
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <div class="user-dropdown-menu">
                                    <a href="<?php echo admin_url('profile.php'); ?>" class="dropdown-item">
                                        <i class="fas fa-user"></i> <?php _e('Profile', 'content-arena'); ?>
                                    </a>
                                    <a href="<?php echo home_url('/collections/'); ?>" class="dropdown-item">
                                        <i class="fas fa-heart"></i> <?php _e('My Collections', 'content-arena'); ?>
                                    </a>
                                    <a href="<?php echo wp_logout_url(); ?>" class="dropdown-item">
                                        <i class="fas fa-sign-out-alt"></i> <?php _e('Logout', 'content-arena'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="auth-links">
                            <a href="<?php echo wp_login_url(); ?>" class="login-link">
                                <?php _e('Login', 'content-arena'); ?>
                            </a>
                            <a href="<?php echo wp_registration_url(); ?>" class="register-link">
                                <?php _e('Register', 'content-arena'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="header-actions">
                    <button class="search-toggle" aria-label="<?php _e('Toggle Search', 'content-arena'); ?>">
                        <i class="fas fa-search"></i>
                    </button>
                    <button class="mobile-nav-toggle" aria-label="<?php _e('Toggle Navigation', 'content-arena'); ?>">
                        <span class="hamburger-line"></span>
                        <span class="hamburger-line"></span>
                        <span class="hamburger-line"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="header-main">
        <div class="container">
            <div class="header-main-content">
                <div class="site-branding">
                    <a href="<?php echo home_url(); ?>" class="site-logo">
                        <?php
                        $logo = get_theme_mod('custom_logo');
                        if ($logo) {
                            echo wp_get_attachment_image($logo, 'full', false, array(
                                'alt' => get_bloginfo('name'),
                                'class' => 'logo-image'
                            ));
                        } else {
                            echo '<span class="site-title">' . get_bloginfo('name') . '</span>';
                        }
                        ?>
                    </a>
                </div>
                
                <nav class="main-navigation" role="navigation">
                    <?php
                    wp_nav_menu(array(
                        'theme_location' => 'primary-navigation',
                        'container' => false,
                        'menu_class' => 'nav-menu',
                        'walker' => new ContentArena_Nav_Walker()
                    ));
                    ?>
                </nav>
                
                <div class="header-search">
                    <?php $this->view('components/ca-search-bar'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="search-overlay">
        <div class="search-overlay-content">
            <button class="search-close" aria-label="<?php _e('Close Search', 'content-arena'); ?>">
                <i class="fas fa-times"></i>
            </button>
            <?php $this->view('parts/ca-search-overlay'); ?>
        </div>
    </div>
</header>
```

### `/views/blocks/block-ca-collection-detail.php`
**Purpose:** User collection management interface

**Collection Interface:**
```php
<?php
$collection_type = get_field('collection_type') ?: 'favorites';
$show_type_switcher = get_field('show_type_switcher');
$items_per_page = get_field('items_per_page') ?: 12;

if (!is_user_logged_in()) {
    echo '<div class="ca-login-prompt">';
    echo '<h3>' . __('Please log in to view your collections', 'content-arena') . '</h3>';
    echo '<a href="' . wp_login_url() . '" class="btn btn--primary">' . __('Login', 'content-arena') . '</a>';
    echo '</div>';
    return;
}

$user_id = get_current_user_id();
$collection_component = new ContentArena\Component\Collection();
$collection_items = $collection_component->getCollectionItems($user_id, $collection_type, array(
    'posts_per_page' => $items_per_page
));
?>

<div class="wp-block-acf-ca-collection-detail" data-collection-type="<?php echo esc_attr($collection_type); ?>">
    <div class="ca-collection-manager">
        <div class="collection-header">
            <h2 class="collection-title">
                <?php
                $titles = array(
                    'favorites' => __('My Favorites', 'content-arena'),
                    'watchlist' => __('My Watchlist', 'content-arena'),
                    'completed' => __('Completed', 'content-arena')
                );
                echo $titles[$collection_type] ?? __('My Collection', 'content-arena');
                ?>
            </h2>
            
            <?php if ($show_type_switcher) : ?>
                <div class="collection-controls">
                    <select class="collection-type-select" data-collection-manager="type-switcher">
                        <option value="favorites" <?php selected($collection_type, 'favorites'); ?>>
                            <?php _e('Favorites', 'content-arena'); ?>
                        </option>
                        <option value="watchlist" <?php selected($collection_type, 'watchlist'); ?>>
                            <?php _e('Watchlist', 'content-arena'); ?>
                        </option>
                        <option value="completed" <?php selected($collection_type, 'completed'); ?>>
                            <?php _e('Completed', 'content-arena'); ?>
                        </option>
                    </select>
                    
                    <div class="collection-actions">
                        <button class="btn btn--secondary" data-action="export-collection">
                            <i class="fas fa-download"></i> <?php _e('Export', 'content-arena'); ?>
                        </button>
                        <button class="btn btn--secondary" data-action="share-collection">
                            <i class="fas fa-share"></i> <?php _e('Share', 'content-arena'); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="collection-items-container" data-collection-container>
            <?php if ($collection_items->have_posts()) : ?>
                <div class="collection-grid">
                    <?php while ($collection_items->have_posts()) : $collection_items->the_post(); ?>
                        <div class="collection-item" data-post-id="<?php echo get_the_ID(); ?>">
                            <div class="ca-content-card">
                                <div class="card-header">
                                    <?php if (has_post_thumbnail()) : ?>
                                        <img src="<?php echo get_the_post_thumbnail_url(null, 'medium'); ?>" 
                                             alt="<?php echo esc_attr(get_the_title()); ?>" 
                                             class="featured-image">
                                    <?php else : ?>
                                        <div class="placeholder-image">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $sport = get_field('sport');
                                    if ($sport) :
                                    ?>
                                        <span class="sport-badge"><?php echo esc_html($sport); ?></span>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $difficulty = get_field('difficulty_level');
                                    if ($difficulty) :
                                    ?>
                                        <div class="difficulty-level">
                                            <?php for ($i = 1; $i <= 5; $i++) : ?>
                                                <span class="level-dot <?php echo ($i <= $difficulty) ? 'active' : 'inactive'; ?>"></span>
                                            <?php endfor; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <button class="remove-from-collection" 
                                            data-post-id="<?php echo get_the_ID(); ?>"
                                            data-collection-type="<?php echo esc_attr($collection_type); ?>"
                                            title="<?php _e('Remove from collection', 'content-arena'); ?>">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                
                                <div class="card-content">
                                    <h3 class="content-title">
                                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                    </h3>
                                    
                                    <div class="content-meta">
                                        <span class="meta-item">
                                            <i class="fas fa-clock"></i>
                                            <?php
                                            $duration = get_field('duration');
                                            echo $duration ? esc_html($duration . ' min') : __('Variable', 'content-arena');
                                            ?>
                                        </span>
                                        
                                        <?php
                                        $equipment = get_field('equipment_needed');
                                        if ($equipment) :
                                        ?>
                                            <span class="meta-item">
                                                <i class="fas fa-tools"></i>
                                                <?php echo esc_html($equipment); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="content-excerpt">
                                        <?php echo wp_trim_words(get_the_excerpt(), 20); ?>
                                    </div>
                                </div>
                                
                                <div class="card-actions">
                                    <a href="<?php the_permalink(); ?>" class="btn btn--primary">
                                        <?php _e('View Content', 'content-arena'); ?>
                                    </a>
                                    
                                    <div class="collection-status">
                                        <i class="fas fa-heart text-red"></i>
                                        <?php _e('In Collection', 'content-arena'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
                
                <?php if ($collection_items->max_num_pages > 1) : ?>
                    <div class="collection-pagination">
                        <button class="btn btn--secondary load-more-collections" 
                                data-page="2" 
                                data-max-pages="<?php echo $collection_items->max_num_pages; ?>">
                            <?php _e('Load More', 'content-arena'); ?>
                        </button>
                    </div>
                <?php endif; ?>
                
            <?php else : ?>
                <div class="empty-collection">
                    <div class="empty-icon">
                        <i class="fas fa-heart-broken"></i>
                    </div>
                    <h3 class="empty-title"><?php _e('No items in this collection', 'content-arena'); ?></h3>
                    <p class="empty-description">
                        <?php _e('Start adding content to your collection by clicking the heart icon on any content page.', 'content-arena'); ?>
                    </p>
                    <a href="<?php echo home_url('/content/'); ?>" class="btn btn--primary">
                        <?php _e('Browse Content', 'content-arena'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php wp_reset_postdata(); ?>
```

---

## Key Development Patterns for Reference

### 1. **Modern JavaScript Architecture**
- ES6 modules with versioned imports for cache busting
- Class-based component structure with clear separation of concerns
- Async/await patterns for API interactions
- Event delegation for dynamic content handling

### 2. **User Authentication & Authorization**
- Keycloak SSO integration with fallback authentication
- Role-based access control throughout the application
- Secure API endpoints with nonce verification
- Cross-platform user synchronization

### 3. **Content Collection System**
- RESTful API for collection management
- Real-time UI updates without page refresh
- Multiple collection types (favorites, watchlist, completed)
- Export and sharing functionality

### 4. **Advanced Search Implementation**
- Debounced autocomplete with request cancellation
- Multi-field search across posts and metadata
- Keyboard navigation support
- Progressive enhancement for accessibility

### 5. **Sports Content Specialization**
- Custom post types for sports content and events
- Difficulty level system with visual indicators
- Equipment and duration metadata
- Sport-specific categorization and filtering

### 6. **Performance Optimization**
- Lazy loading for content grids
- Efficient database queries with proper indexing
- Image optimization with responsive breakpoints
- CSS and JavaScript code splitting

### 7. **Security Implementation**
- Content Security Policy headers
- Input sanitization and validation
- Secure file uploads and downloads
- Rate limiting for API endpoints

This theme demonstrates a sophisticated content platform implementation with modern development practices, comprehensive user management, and specialized sports content features. The architecture provides excellent examples of scalable WordPress application development with external service integration.