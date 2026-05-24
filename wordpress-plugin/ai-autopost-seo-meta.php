<?php
/**
 * Plugin Name: AI AutoPost SEO Meta
 * Plugin URI: https://github.com/ai-autopost-seo
 * Description: Register Yoast SEO & Rank Math meta fields for REST API - ให้ AI AutoPost สามารถเขียน Focus Keyword และ Meta Description ได้อัตโนมัติ
 * Version: 1.0.0
 * Author: AI AutoPost SEO
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Yoast SEO meta fields for REST API
 */
add_action('init', function() {
    // ===========================================
    // YOAST SEO META FIELDS
    // ===========================================

    // Focus Keyword
    register_post_meta('post', '_yoast_wpseo_focuskw', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // Meta Description
    register_post_meta('post', '_yoast_wpseo_metadesc', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // SEO Title
    register_post_meta('post', '_yoast_wpseo_title', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // Primary Category
    register_post_meta('post', '_yoast_wpseo_primary_category', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'integer',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // ===========================================
    // RANK MATH SEO META FIELDS
    // ===========================================

    // Focus Keyword
    register_post_meta('post', 'rank_math_focus_keyword', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // Meta Description
    register_post_meta('post', 'rank_math_description', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // SEO Title
    register_post_meta('post', 'rank_math_title', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'string',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // SEO Score
    register_post_meta('post', 'rank_math_seo_score', [
        'show_in_rest' => true,
        'single' => true,
        'type' => 'integer',
        'auth_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);

    // ===========================================
    // ALSO REGISTER FOR 'PAGE' POST TYPE
    // ===========================================

    $page_meta_fields = [
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_title',
        'rank_math_focus_keyword',
        'rank_math_description',
        'rank_math_title'
    ];

    foreach ($page_meta_fields as $field) {
        register_post_meta('page', $field, [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function() {
                return current_user_can('edit_pages');
            }
        ]);
    }

}, 99); // Priority 99 to run after Yoast/Rank Math

/**
 * Add admin notice on activation
 */
register_activation_hook(__FILE__, function() {
    set_transient('ai_autopost_seo_meta_activated', true, 30);
});

add_action('admin_notices', function() {
    if (get_transient('ai_autopost_seo_meta_activated')) {
        delete_transient('ai_autopost_seo_meta_activated');
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>AI AutoPost SEO Meta</strong> - เปิดใช้งานสำเร็จ! ตอนนี้ระบบ AI AutoPost สามารถเขียน Focus Keyword และ Meta Description ได้แล้ว</p>
        </div>
        <?php
    }
});
