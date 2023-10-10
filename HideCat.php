<?php
/*
Plugin Name: HideCat: Hide out of stock products by category
Description: Filter WooCommerce products by multiple categories and hide individual category out-of-stock products for selected categories.
Version: 1.0.0
Author: Jestin Joseph
Author URI: https://jestinjoseph.netlify.app/
*/
// Check WooCommerce installed, activated, and compatible version
function WC_visibility_version_check($version = '3.0') {
    if (!class_exists('WooCommerce')) {
        ?>
        <div class="error notice">
            <p><?php echo 'WooCommerce not found. Please install and activate it to use HideCat: Hide out of stock products by category.'; ?></p>
        </div>
        <?php
        return;
    }

    if (version_compare(WC_VERSION, $version, "<")) {
        ?>
        <div class="error notice">
            <p><?php echo 'WooCommerce version too low! HideCat: Hide out of stock products by category requires version 3.0 or greater.'; ?></p>
        </div>
        <?php
        return;
    }
}
add_action('init', 'WC_visibility_version_check');

// Change the query to exclude products that are out of stock and have the categories to be hidden
function WC_visibility_hide_outofstock_products($q) {

    if (!$q->is_main_query() || is_admin()) {
        return;
    }

    // Get all hidden categories
    $args = array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'fields' => 'ids',
        'meta_query' => array(
            'key' => 'hide_products_in_cat',
            'value' => 'yes',
        ),
    );
    $terms = get_terms($args);

    // Get products with categories that should hide
    $post_ids = get_posts(
        array(
            'post_type' => 'product',
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_taxonomy_id',
                    'terms' => $terms,
                    'operator' => 'IN'
                )
            )
        )
    );

    $hidden_cats_IDs = array();
    foreach ($post_ids as $id) {
        $hidden_cats_IDs[] = $id;
    }

    // Get products that are out of stock
    $outofstock_term = get_term_by('name', 'outofstock', 'product_visibility');
    $post_ids = get_posts(
        array(
            'post_type' => 'product',
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_visibility',
                    'field' => 'term_taxonomy_id',
                    'terms' => array($outofstock_term->term_taxonomy_id),
                    'operator' => 'IN'
                )
            )
        )
    );

    $outofstock_IDs = array();
    foreach ($post_ids as $id) {
        $outofstock_IDs[] = $id;
    }

    // Find the overlap
    $overlap = array_intersect($hidden_cats_IDs, $outofstock_IDs);

    // Remove them from the main query
    $q->set('post__not_in', $overlap);

    remove_action('pre_get_posts', 'WC_visibility_hide_outofstock_products');

}
add_action('pre_get_posts', 'WC_visibility_hide_outofstock_products');

// Add to new term page
function WC_visibility_added_hide_in_cat($taxonomy) {
    $hide_all_setting = get_option('woocommerce_hide_out_of_stock_items', true);
    $checkbox_output = '<input type="checkbox" id="hide_products_in_cat" name="hide_products_in_cat" value="yes" />';
    if ($hide_all_setting == 'yes') {
        $checkbox_output = '<strong>Error: </strong> this feature only works when "Hide out of stock items from the catalog" in WooCommerce settings is unchecked';
    }
    ?>
    <div class="form-field term-group">
        <label for="hide_products_in_cat">
            Hide products when out of stock <?php echo $checkbox_output; ?>
        </label>
    </div>
    <?php
}
add_action('product_cat_add_form_fields', 'WC_visibility_added_hide_in_cat', 99, 2);

// Add to edit term page
function WC_visibility_edited_hide_in_cat($term, $taxonomy) {
    $hide_products_in_cat = get_term_meta($term->term_id, 'hide_products_in_cat', true);

    $hide_all_setting = get_option('woocommerce_hide_out_of_stock_items', true);
    $checked = ($hide_products_in_cat) ? checked($hide_products_in_cat, 'yes', false) : "";
    $checkbox_output = '<input type="checkbox" id="hide_products_in_cat" name="hide_products_in_cat" value="yes" ' . $checked . '/>';
    if ($hide_all_setting == 'yes') {
        $checkbox_output = '<strong>Error: </strong> this feature only works when "Hide out of stock items from the catalog" in WooCommerce settings is unchecked';
    }
    ?>
    <tr class="form-field term-group-wrap">
        <th scope="row">
            <label for="hide_products_in_cat">Hide products when out of stock</label>
        </th>
        <td>
            <?php echo $checkbox_output; ?><p>You can also go to category settings to change this option</p>

        </td>
    </tr>
    <?php
}
add_action('product_cat_edit_form_fields', 'WC_visibility_edited_hide_in_cat', 99, 2);

// Save category settings
function WC_visibility_save_hide_in_cat($term_id, $tag_id) {
    $setting = sanitize_key($_POST['hide_products_in_cat']);
    if (!empty($setting)) {
        update_term_meta($term_id, 'hide_products_in_cat', 'yes');
    } else {
        delete_term_meta($term_id, 'hide_products_in_cat');
    }
}
add_action('created_product_cat', 'WC_visibility_save_hide_in_cat', 10, 2);
add_action('edited_product_cat', 'WC_visibility_save_hide_in_cat', 10, 2);

// Add a custom menu in the WordPress admin sidebar
function WC_visibility_category_menu() {
    add_menu_page(
        'Category Settings',
        'Category Settings',
        'manage_options',
        'category-settings',
        'WC_visibility_category_settings_page',
        'dashicons-category',
        57
    );
}
add_action('admin_menu', 'WC_visibility_category_menu');

function WC_visibility_category_settings_page() {
    if (isset($_POST['category_settings'])) {
        // Handle form submission
        $category_settings = isset($_POST['category_settings']) ? $_POST['category_settings'] : array();
        // Get all product categories
        $product_categories = get_terms('product_cat', array('hide_empty' => false));
        // Loop through all categories and update settings
        foreach ($product_categories as $category) {
            $category_id = $category->term_id;
            $setting = in_array($category_id, $category_settings) ? 'yes' : 'no';
            update_term_meta($category_id, 'hide_products_in_cat', $setting);
        }
        echo '<div class="updated"><p>Category settings saved.</p></div>';
    }
    // Display the settings form
    ?>
    <div class="wrap">
        <h2>Category Settings</h2>
        <p>This plugin allows you to hide individual category product when it is out of stock.</p>
        <form method="post">
            <h3>Custom Category Settings</h3>
            <select name="category_settings[]" id="category_settings" multiple style="width: 100%;" multiple multiselect-search="true">
                <?php
                // Get all product categories
                $product_categories = get_terms('product_cat', array('hide_empty' => false));

                // Loop through categories and populate the select options
                foreach ($product_categories as $category) {
                    $hide_products_in_cat = get_term_meta($category->term_id, 'hide_products_in_cat', true);
                    $selected = $hide_products_in_cat === 'yes' ? 'selected' : '';

                    echo '<option value="' . $category->term_id . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
                }
                ?>
            </select>
            <p>You can also go to products > categories to select this option</p>

            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

add_action('admin_post_save_category_settings', 'WC_visibility_category_settings_page');


// Save category settings
function WC_visibility_save_category_settings() {
    if (isset($_POST['category_settings']) && is_array($_POST['category_settings'])) {
        foreach ($_POST['category_settings'] as $category_id => $setting) {
            $setting = sanitize_key($setting);
            if (!empty($setting)) {
                update_term_meta($category_id, 'hide_products_in_cat', 'yes');
            } else {
                delete_term_meta($category_id, 'hide_products_in_cat');
            }
        }
    }
}

// Save category settings for the custom sidebar menu
function WC_visibility_save_category_settings_sidebar() {
    if (isset($_POST['category_settings_sidebar']) && is_array($_POST['category_settings_sidebar'])) {
        foreach ($_POST['category_settings_sidebar'] as $category_id => $setting) {
            $setting = sanitize_key($setting);
            if (!empty($setting)) {
                update_term_meta($category_id, 'hide_products_in_cat', 'yes');
            } else {
                delete_term_meta($category_id, 'hide_products_in_cat');
            }
        }
    }
}
add_action('admin_post_save_category_settings_sidebar', 'WC_visibility_save_category_settings_sidebar');

// Hook into the admin action to handle the form submission
add_action('admin_init', function () {
    if (isset($_POST['_wpnonce']) && isset($_POST['action']) && ($_POST['action'] === 'save_category_settings' || $_POST['action'] === 'save_category_settings_sidebar')) {
        WC_visibility_save_category_settings();
        WC_visibility_save_category_settings_sidebar();
    }
});

// Enqueue styles for the custom admin page
function WC_visibility_enqueue_admin_styles() {
    if (isset($_GET['page']) && ($_GET['page'] === 'category-settings' || $_GET['page'] === 'category-sidebar-settings')) {
        wp_enqueue_style('wp-admin');
    }
    wp_enqueue_script('chosen', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', array('jquery'), '1.8.7', true);
    wp_enqueue_script('filter', plugin_dir_url(__FILE__) . 'filter.js', array('jquery'), '1.0', true);
    wp_enqueue_style('chosen-css', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css', array(), '1.8.7');
    wp_enqueue_style('filter-css', plugin_dir_url(__FILE__) . 'css/filter.css');
}
add_action('admin_enqueue_scripts', 'WC_visibility_enqueue_admin_styles');
