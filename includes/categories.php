<?php
// Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Global arrays to store category data and mappings
// We'll declare these as global to make them accessible across functions.
// Consider wrapping this in a class for larger plugins to avoid global pollution.
global $old_site_categories_map;
$old_site_categories_map = array(); // old_id => ['name' => '...', 'slug' => '...', 'parent' => old_parent_id]

global $old_to_new_category_id_map;
$old_to_new_category_id_map = array(); // old_id => new_id (after creation on new site)

global $old_uncategorised_id; 
$old_uncategorised_id = null;

/**
 * Initializes the category conversion library.
 * Fetches all categories from the old site and creates them on the new site,
 * building a mapping between old and new IDs.
 * This function should be called once before importing posts.
 *
 * @param string $old_site_base_url The base URL of the old WordPress site (e.g., 'https://your-old-site.com').
 * @return bool True if initialization was successful, false otherwise.
 */
function init_category_conversion( $old_site_base_url ) {
    global $old_site_categories_map, $old_to_new_category_id_map, $old_uncategorised_id;

    // 1. Fetch all categories from the old site
    $url = trailingslashit( $old_site_base_url ) . "wp-json/wp/v2/categories?per_page=100";

    $response = wp_remote_get( $url );

    if ( is_wp_error( $response ) ) {
        error_log( 'CATEGORY_CONVERSION_ERROR: Error fetching old site categories: ' . $response->get_error_message() );
        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    $categories_data = json_decode( $body, true );

    if ( ! is_array( $categories_data ) || empty( $categories_data ) ) {
        error_log( 'CATEGORY_CONVERSION_ERROR: No categories found or failed to decode JSON from old site.' );
        return false;
    }

    foreach ( $categories_data as $cat ) {
        $old_site_categories_map[ $cat['id'] ] = array(
            'name'        => $cat['name'],
            'slug'        => $cat['slug'],
            'description' => $cat['description'],
            'parent'      => $cat['parent'],
        );
    }

    // 2. Create categories on the new site and build the ID mapping
    // This is the multi-pass logic to handle hierarchy and ensure idempotency.

    // First pass: Process top-level categories
    foreach ( $old_site_categories_map as $old_id => $cat_data ) {
        if ( $cat_data['parent'] == 0 ) {
            _get_or_create_single_category_and_map( $old_id, $cat_data );
        }
        if ( $cat_data['slug'] === 'uncategorised'){
            $old_uncategorised_id = $old_id;
            error_log(sprintf("old_uncategorised_id set to %d", $old_uncategorised_id));
        }
    }

    // Subsequent passes for children until all processed
    $processed_count = count( $old_to_new_category_id_map );
    $prev_processed_count = 0;

    while ( $processed_count > $prev_processed_count ) {
        $prev_processed_count = $processed_count;

        foreach ( $old_site_categories_map as $old_id => $cat_data ) {
            // Skip if already processed or is a top-level category
            if ( isset( $old_to_new_category_id_map[ $old_id ] ) || $cat_data['parent'] == 0 ) {
                continue;
            }

            // Attempt to process child category
            $new_cat_id = _get_or_create_single_category_and_map( $old_id, $cat_data );
            if ( ! is_wp_error( $new_cat_id ) ) {
                $processed_count++;
            }
        }
    }

    // Report any categories that couldn't be imported (e.g., due to circular dependencies)
    foreach ( $old_site_categories_map as $old_id => $cat_data ) {
        if ( ! isset( $old_to_new_category_id_map[ $old_id ] ) ) {
            error_log( 'CATEGORY_CONVERSION_WARNING: Could not import category: "' . $cat_data['name'] . '" (Old ID: ' . $old_id . '). Parent might be missing or other issue. This category will be skipped for posts.' );
        }
    }

    return true;
}
/**
 * Helper function: Gets or creates a single category on the new site.
 * Populates the $old_to_new_category_id_map with the result.
 *
 * @param int   $old_id The original ID of the category from the old site.
 * @param array $cat_data The category data (name, slug, description, parent) from the old site map.
 * @return int|WP_Error The term_id on the new site, or WP_Error on failure.
 */
function _get_or_create_single_category_and_map( $old_id, $cat_data ) {
    global $old_to_new_category_id_map;

    // Check if this category has already been processed and mapped
    if ( isset( $old_to_new_category_id_map[ $old_id ] ) ) {
        return $old_to_new_category_id_map[ $old_id ];
    }

    $category_name = $cat_data['name'];
    $category_slug = ! empty( $cat_data['slug'] ) ? $cat_data['slug'] : sanitize_title( $category_name );
    // Translate slug and name if needed
    if ( $category_slug === 'build-environment'){
        $category_slug = 'energy-and-built-environment';
        $category_name = 'Energy & Built Environment';
    } else if ( $category_slug === 'newletters' ) {
        $category_slug = 'news';
        $category_name = 'News';
    } else if ( $category_slug === 'upcoming-events'){
        $category_slug = 'news';
        $category_name = 'News';
    } else if ( $category_slug === 'carbon'){
        $category_slug = 'carbon-cutters';
        $category_name = 'Carbon cutters';
    }
    // 1. Check if category exists by slug (most reliable for uniqueness)
    $existing_term = get_term_by( 'slug', $category_slug, 'category' );

    // If not found by slug, try by name (less reliable if names aren't unique without slug)
    if ( ! $existing_term ) {
        $existing_term = get_term_by( 'name', $category_name, 'category' );
    }

    if ( $existing_term && ! is_wp_error( $existing_term ) ) {
        // Category already exists, map and return its ID
        $old_to_new_category_id_map[ $old_id ] = $existing_term->term_id;
        return $existing_term->term_id;
    }

    // Determine the new parent ID if applicable
    $new_parent_id = 0;
    if ( ! empty( $cat_data['parent'] ) ) {
        if ( isset( $old_to_new_category_id_map[ $cat_data['parent'] ] ) ) {
            $new_parent_id = $old_to_new_category_id_map[ $cat_data['parent'] ];
        } else {
            // Parent not yet mapped, means it hasn't been created yet.
            // Return an error to be retried in a subsequent pass.
            return new WP_Error( 'category_parent_not_found', 'Parent category not yet imported for ' . $category_name . ' (Old ID: ' . $old_id . '). Parent old ID: ' . $cat_data['parent'] );
        }
    }

    // Category doesn't exist, create it
    $term_args = array(
        'slug'        => $category_slug,
        'description' => ! empty( $cat_data['description'] ) ? $cat_data['description'] : '',
        'parent'      => $new_parent_id,
    );

    $inserted_term = wp_insert_term( $category_name, 'category', $term_args );

    if ( is_wp_error( $inserted_term ) ) {
        error_log( 'CATEGORY_CONVERSION_ERROR: Failed to create category "' . $category_name . '": ' . $inserted_term->get_error_message() );
        return $inserted_term;
    } else {
        $old_to_new_category_id_map[ $old_id ] = $inserted_term['term_id'];
        return $inserted_term['term_id'];
    }
}

/**
 * Converts an array of old category IDs to an array of new category IDs.
 * This function should be called for each post after init_category_conversion has run.
 *
 * @param array $old_category_ids An array of integer IDs from the old site's categories.
 * @return array An array of integer IDs of the corresponding categories on the new site.
 * Categories that could not be mapped will be excluded.
 */
function get_new_category_ids_from_old( $old_category_ids, $source ) {
    global $old_to_new_category_id_map, $old_uncategorised_id;
    $new_category_ids = array();
    if ( $source === 'WW'){
        $ww_category = get_term_by('slug', 'wildlife-wardens', 'category');
        $new_category_ids[] = $ww_category->term_id;
        return $new_category_ids;
    } 
    if ( ! is_array( $old_category_ids ) || empty( $old_category_ids ) ) {
        return array();
    }

    foreach ( $old_category_ids as $old_id ) {
        if ( count($old_category_ids) > 1 && $old_id === $old_uncategorised_id){
            // do nothing
            error_log(sprintf("Uncategorised used when %d categories assigned", count($old_category_ids)));
        } else if ( isset( $old_to_new_category_id_map[ $old_id ] ) ) {
            $new_category_ids[] = (int) $old_to_new_category_id_map[ $old_id ];
        } else {
            error_log( 'CATEGORY_CONVERSION_WARNING: Old category ID ' . $old_id . ' not found in mapping. This category will be skipped for the current post.' );
        }
    }

    return array_unique($new_category_ids); // Use array_unique in case of any duplicate mappings
}
?>
