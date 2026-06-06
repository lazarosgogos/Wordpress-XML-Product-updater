<?php
/**
 * Plugin Name: Woo Plano Importer
 * Description: Import / update WooCommerce products from Plano XML feeds in safe batches. Manual run, cron-safe support.
 * Version: 2.0
 * Author: Lazaros Gogos
 * License: MIT License 
 */
if (!defined("ABSPATH")) {
    exit;
}

// include("optimized-updater.php");

/**
 * WP wrapper: admin UI, cron hook, activation/deactivation
 */
class Plano_Importer_Core
{

    private $feeds = [];
    private $log_file;
    private $uploads_dir;
    public function __construct($feeds = [])
    {

        $base = PRIVATE_URL;        
        $defaults = [
            'items' => $base . 'Items',
            'series' => $base . 'ProductSeries',
            'images' => $base . 'ItemImages',
            'attributes' => $base . 'Attributes',
            'features' => $base . 'Features',
            'item_attributes' => $base . 'ItemAttributes',
            'item_features' => $base . 'ItemFeatures', 
            'prices-gr' => $base . 'Prices-GR'
        ];
        $this->feeds = wp_parse_args($feeds, $defaults);

        $uploads = wp_get_upload_dir();
        $this->uploads_dir = $uploads['basedir'];
        // $this->log_file = trailingslashit($this->uploads_dir) . 'woo-plano-import-GOGOS.log';
        $this->update_log_file_name();
    }

    public function update_log_file_name(){
        $date = date_i18n('Y-m');
        $this->log_file = trailingslashit($this->uploads_dir) . 'woo-plano-import-'. $date. '.log';
    }

    private function get_seen_skus_file_path(): string
    {
        return trailingslashit($this->uploads_dir) . 'plano_seen_skus.json';
    }

    private function load_seen_skus(): array
    {
        $path = $this->get_seen_skus_file_path();
        if (!is_readable($path)) return [];
        $json = @file_get_contents($path);
        if ($json === false) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function save_seen_skus(array $skus): bool
    {
        $path = $this->get_seen_skus_file_path();
        $tmp = $path . '.tmp';
        $json = json_encode(array_values(array_unique($skus)), JSON_PRETTY_PRINT);
        if ($json === false) return false;
        if (file_put_contents($tmp, $json) === false) return false;
        return rename($tmp, $path);
    }

    private function reconcile_deletions(array $seen_skus): void
    {
        global $wpdb;

        $imported = $wpdb->get_results(
            "SELECT p.ID, p.post_type
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_plano_imported' AND pm.meta_value != ''
               AND p.post_status IN ('publish', 'draft')"
        );

        if (empty($imported)) {
            $this->log("Reconciliation: no _plano_imported products found, skipping.");
            return;
        }

        $seen_set = array_flip($seen_skus);
        $variations_to_draft = [];
        $simples_to_draft = [];
        $parent_ids_affected = [];

        foreach ($imported as $row) {
            $sku = get_post_meta($row->ID, '_sku', true);

            if ($row->post_type === 'product_variation') {
                $parent_id = wp_get_post_parent_id($row->ID);
                if ($parent_id) {
                    $parent_ids_affected[$parent_id] = true;
                }
                if ($sku && !isset($seen_set[$sku])) {
                    $variations_to_draft[] = $row->ID;
                }
            } elseif ($row->post_type === 'product') {
                $product = wc_get_product($row->ID);
                if ($product && $product->is_type('variable')) {
                    $parent_ids_affected[$row->ID] = true;
                } else {
                    if ($sku && !isset($seen_set[$sku])) {
                        $simples_to_draft[] = $row->ID;
                    }
                }
            }
        }

        foreach ($variations_to_draft as $var_id) {
            wp_update_post(['ID' => $var_id, 'post_status' => 'draft']);
            $sku = get_post_meta($var_id, '_sku', true);
            $this->log("Reconciliation: drafted variation SKU={$sku} (ID={$var_id}) — not in feed.");
        }

        foreach ($simples_to_draft as $simple_id) {
            wp_update_post(['ID' => $simple_id, 'post_status' => 'draft']);
            $sku = get_post_meta($simple_id, '_sku', true);
            $this->log("Reconciliation: drafted simple product SKU={$sku} (ID={$simple_id}) — not in feed.");
        }

        $drafted_parents = 0;
        foreach (array_keys($parent_ids_affected) as $parent_id) {
            $children = get_children([
                'post_parent' => $parent_id,
                'post_type' => 'product_variation',
                'post_status' => ['publish', 'draft'],
                'fields' => 'ids',
                'posts_per_page' => -1,
            ]);

            if (empty($children)) {
                wp_update_post(['ID' => $parent_id, 'post_status' => 'draft']);
                $series = get_post_meta($parent_id, '_plano_series', true);
                $this->log("Reconciliation: drafted parent (ID={$parent_id}, series={$series}) — no variations left.");
                $drafted_parents++;
                continue;
            }

            $all_drafted = true;
            foreach ($children as $child_id) {
                if (get_post_status($child_id) !== 'draft') {
                    $all_drafted = false;
                    break;
                }
            }

            if ($all_drafted) {
                wp_update_post(['ID' => $parent_id, 'post_status' => 'draft']);
                $series = get_post_meta($parent_id, '_plano_series', true);
                $this->log("Reconciliation: drafted parent (ID={$parent_id}, series={$series}) — all variations drafted.");
                $drafted_parents++;
            }
        }

        $total = count($variations_to_draft) + count($simples_to_draft) + $drafted_parents;
        $this->log("Reconciliation complete: {$total} products drafted (" . count($variations_to_draft) . " variations, " . count($simples_to_draft) . " simples, {$drafted_parents} parents).");
    }
    /**
     * Fetch XML from URL and return SimpleXMLElement or false
     */
    public function fetch_url_xml($url)
    {
        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) {
            $this->log("HTTP error fetching {$url}: " . $resp->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($resp);
        if (intval($code) !== 200) {
            $this->log("Non-200 response for {$url}: {$code}");
            return false;
        }
        $body = wp_remote_retrieve_body($resp);
        if (empty($body)) {
            $this->log("Empty body from {$url}");
            return false;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if (!$xml) {
            $this->log("Failed parsing XML from {$url}");
            foreach (libxml_get_errors() as $err) {
                $this->log("XML error: " . trim($err->message));
            }
            libxml_clear_errors();
            return false;
        }
        return $xml;
    }

    /**
     * Build images map: ItemCode => [ order => url, ...]
     * @return array
     */
    public function fetch_images_map()
    {
        $url = $this->feeds['images'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml)
            return $map;
        foreach ($xml->image as $img) {
            $code = (string) $img->ItemCode;
            $url = (string) $img->ImageUrl;
            $order = isset($img->OrderNo) ? intval($img->OrderNo) : 0;
            if (empty($code) || empty($url))
                continue;
            if (!isset($map[$code])) {
                $map[$code] = [];
            }
            $map[$code][$order] = $url;
        }
        return $map;
    }

    public function fetch_series_map()
    {
        $url = $this->feeds['series'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml)
            return $map;
        foreach ($xml->serie as $s) {
            $code = (string) $s->Code;
            $name = (string) $s->Name;
            if ($code) {
                $map[$code] = $name;
            }
        }
        return $map;
    }

    public function fetch_attributes_map()
    {
        $url = $this->feeds['attributes'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml)
            return $map;
        foreach ($xml->attribute as $a) {
            $code = (string) $a->Code;
            $name = (string) $a->Name;
            $unit = (string) $a->Unit;
            if ($code) {
                $map[$code] = ['name' => $name, 'unit' => $unit];
            }
        }
        return $map;
    }

    public function fetch_features_map()
    {
        $url = $this->feeds['features'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml)
            return $map;
        foreach ($xml->Feature as $f) {
            $feature_id = (string) $f->FeatureID;
            $value = (string) $f->Value;
            $description = (string) $f->LongDescription;
            $image = (string) $f->Image;
            if ($feature_id) {
                $map[$feature_id] = ['value' => $value, 'description' => $description, 'image' => $image];
            }
        }
        return $map;
    }

    public function fetch_item_features_map()
    {
        $url = $this->feeds['item_features'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml)
            return $map;
        foreach ($xml->ItemFeature as $f) {
            $feature_id = (string) $f->FeatureID;
            $item_code = (string) $f->ItemCode;
            if (empty($feature_id) || empty($item_code))
                continue;
            if (!isset($map[$item_code]))
                $map[$item_code] = [];
            $map[$item_code][] = $feature_id;
            
        }
        return $map;
    }

    public function fetch_item_attributes_map() {
        $url = $this->feeds['item_attributes'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml) 
            return $map;
        foreach ($xml->ItemAttribute as $a) {
            $item_code = (string) $a->ItemCode;
            $attribute_code = (string) $a->AttributeCode;
            $value = (string) $a->Value;
            if (empty($item_code))
                continue;
            if (!isset($map[$item_code]))
                $map[$item_code] = [];
            
            $map[$item_code][] = [
                'attribute_code' => $attribute_code, 
                'value' => $value
            ];
            
        }
        return $map;
    }

    public function fetch_prices_map()
    {
        $url = $this->feeds['prices-gr'];
        $xml = $this->fetch_url_xml($url);
        $map = [];
        if (!$xml)
            return $map;
        foreach ($xml->prices as $p) {
            $code = (string) $p->Code;
            if (empty($code))
                continue;
            $price_with_vat_str = (string) $p->CurrentPriceWithVat;
            if ($price_with_vat_str === '')
                continue;
            $price_with_vat = (float) $price_with_vat_str;
            $sale_price = isset($p->SalePrice) && (string) $p->SalePrice !== '' ? (float) $p->SalePrice : null;
            $map[$code] = [
                'price_with_vat' => $price_with_vat,
                'sale_price' => $sale_price,
            ];
        }
        return $map;
    }

    public function get_items_count()
    {
        $xml = $this->fetch_url_xml($this->feeds['items']);
        if (!$xml)
            return 0;
        return count($xml->Item);
    }

    public function do_import_batch($batch = 10)
    {   
        $this->update_log_file_name();
        if (get_transient('plano_import_lock')) {
            $this->log('Import skipped: lock present');
            return 0;
        }
        set_transient('plano_import_lock', 1, 60 * 30);
        $offset = max(0, intval(get_option('plano_import_offset', 0)));

        $images_map = $this->fetch_images_map();
        $series_map = $this->fetch_series_map();
        $features_map = $this->fetch_features_map();
        $item_features_map = $this->fetch_item_features_map();
        $item_attributes_map = $this->fetch_item_attributes_map();
        $prices_map = $this->fetch_prices_map();

        $xml = $this->fetch_url_xml($this->feeds['items']);
        if (!$xml) {
            $this->log('Failed fetching Items feed');
            delete_transient('plano_import_lock');
            return 0;
        }

        $items_arr = [];
        foreach ($xml->Item as $it)
            $items_arr[] = $it;
        if ($offset >= count($items_arr)) {
            update_option('plano_import_offset', 0, false);
            $this->log("Pointer was at/after end ({$offset}). Reset to 0.");
            delete_transient('plano_import_lock');
            return 0;
        }

        $hash_map = $this->load_hash_map();

        $slice = array_slice($items_arr, $offset, $batch);
        $consumed = count($slice);

        // Build complete ProductSeriesCode groups from the whole feed so variation
        // attributes are detected across the full product family, not one slice.
        $all_groups = [];
        foreach ($items_arr as $feed_item) {
            $series_code = trim((string) $feed_item->ProductSeriesCode);
            if ($series_code === '') {
                continue;
            }
            if (!isset($all_groups[$series_code])) {
                $all_groups[$series_code] = [];
            }
            $all_groups[$series_code][] = $feed_item;
        }

        // Process only series touched by this slice, but pass the complete group.
        $groups = [];
        $singletons = [];
        foreach ($slice as $item) {
            $series_code = trim((string) $item->ProductSeriesCode);
            if (empty($series_code)) {
                $singletons[] = $item;
            } else {
                $groups[$series_code] = $all_groups[$series_code] ?? [$item];
            }
        }

        $processed = 0;
        $skipped = 0;
        $updated_skus = [];

        // Process grouped items as variable products
        foreach ($groups as $series_code => $group_items) {
            $result = $this->process_variable_group(
                $series_code, $group_items,
                $images_map, $series_map, $features_map,
                $item_features_map, $item_attributes_map, $prices_map,
                $hash_map
            );
            $processed += $result['processed'];
            $skipped += $result['skipped'];
            foreach ($result['updated_skus'] as $sku) {
                $updated_skus[] = $sku;
            }
        }

        // Process singleton items (no ProductSeriesCode) as simple products
        foreach ($singletons as $item) {
            $check = $this->check_item_changed($item, $hash_map, 'Code', $prices_map);
            if (!$check['changed']) {
                $skipped++;
                $processed++;
                continue;
            }
            try {
                $this->process_simple_product($item, $images_map, $series_map, $features_map, $item_features_map, $item_attributes_map, $prices_map);
                $processed++;
                $updated_skus[] = $check['key'];
                $this->update_hash_map_entry($hash_map, $check['key'], $check['hash']);
            } catch (Exception $e) {
                $this->log("Exception processing simple product (offset " . ($offset + $processed) . "): " . $e->getMessage());
            }
        }

        $this->save_hash_map($hash_map);

        if ($skipped > 0) {
            $sku_list = !empty($updated_skus) ? 'updated SKU: ' . implode(', ', $updated_skus) : '';
            $this->log("Skipped {$skipped} unchanged items" . ($sku_list ? ", {$sku_list}" : ''));
        }

        // Track seen SKUs
        $seen_skus = $this->load_seen_skus();
        foreach ($slice as $item) {
            $sku = trim((string) $item->Code);
            if ($sku) $seen_skus[] = $sku;
        }
        $this->save_seen_skus($seen_skus);

        // Advance pointer
        $new_offset = $offset + $consumed;
        if ($new_offset >= count($items_arr)) {
            update_option('plano_import_offset', 0, false);
            $this->log("Processed {$processed} grouped items from {$consumed} feed rows and reached feed end — pointer reset to 0.");

            $seen_skus = $this->load_seen_skus();
            $this->reconcile_deletions($seen_skus);
            $this->save_seen_skus([]);
        } else {
            update_option('plano_import_offset', $new_offset, false);
            $this->log("Batch finished: processed={$processed} grouped items from {$consumed} feed rows, pointer set to {$new_offset}");
        }

        delete_transient('plano_import_lock');
        return $consumed;
    }

    // =====================================================================
    // Variable Product Group Processing
    // =====================================================================

    private function process_variable_group(
        string $series_code,
        array   $items,
        array   $images_map,
        array   $series_map,
        array   $features_map,
        array   $item_features_map,
        array   $item_attributes_map,
        array   $prices_map,
        array   &$hash_map
    ): array {
        $processed = 0;
        $skipped = 0;
        $updated_skus = [];

        if (empty($items)) {
            return ['processed' => 0, 'skipped' => 0, 'updated_skus' => []];
        }

        $first_item = $items[0];
        $first_sku = trim((string) $first_item->Code);

        // 1. Find or create parent variable product
        $parent_id = $this->find_parent_by_series($series_code);
        $parent = null;

        if ($parent_id) {
            $parent = wc_get_product($parent_id);
            if (!$parent || !$parent->is_type('variable')) {
                if ($parent_id) {
                    wp_delete_post($parent_id, true);
                }
                $parent = new WC_Product_Variable();
            }
        } else {
            $parent = new WC_Product_Variable();
        }

        // 2. Parent basic data from the series when available.
        $name = $series_map[$series_code] ?? $this->derive_parent_name_from_group($items);
        if ($name === '') {
            $name = (string) $first_item->Name ?: (string) $first_item->NameEn;
        }
        $desc = (string) $first_item->DetailedDescription ?: (string) $first_item->DetailedDescriptionEn;
        $slug = sanitize_title($name);

        $parent->set_name($name);
        $parent->set_slug($slug);
        $parent->set_description($desc);
        $parent->set_short_description(wp_trim_words(strip_tags($desc), 30));

        // 3. Categories from first item
        $term_ids = $this->assign_categories($first_item);

        // 4. Brands from first item
        $brand_term_ids = $this->assign_brands($first_item, $features_map, $item_features_map);

        // 5. Build one variation selector from the product-name difference.
        $variation_data = $this->build_name_variation_data($items, $name);
        $variation_attrs = $variation_data['attrs'];
        $variation_value_map = $variation_data['values'];

        // 6. Ensure global attributes and terms exist
        $this->ensure_variation_attributes($variation_attrs);

        // 7. Set parent attributes (variation + normal + series)
        $differing_item_attrs = $this->detect_differing_item_attributes($items, $item_attributes_map);
        $this->set_parent_attributes($parent, $first_item, $variation_attrs, $item_attributes_map, $series_map, $differing_item_attrs);

        // 8. Parent images from first variation
        $this->assign_images_to_product($parent, $first_sku, $images_map);

        // 9. Save parent to get ID, then set meta
        $parent_id = $parent->save();
        if (!$parent_id) {
            $this->log("Failed saving parent product for series={$series_code}");
            return ['processed' => 0, 'skipped' => 0, 'updated_skus' => []];
        }

        update_post_meta($parent_id, '_plano_imported', 1);
        update_post_meta($parent_id, '_plano_series', $series_code);

        // 10. Set terms on saved parent
        if (!empty($term_ids)) {
            wp_set_object_terms($parent_id, $term_ids, 'product_cat', false);
        }
        if (!empty($brand_term_ids)) {
            wp_set_object_terms($parent_id, $brand_term_ids, 'product_brand', false);
        }

        $this->log("Parent product saved: series={$series_code}, name={$name}, ID={$parent_id}");

        // 11. Process each item as a variation
        foreach ($items as $item) {
            $code = trim((string) $item->Code);
            if (empty($code)) continue;

            $check = $this->check_item_changed($item, $hash_map, 'Code', $prices_map);
            if (!$check['changed'] && $this->sku_has_variation_for_parent($parent_id, $code)) {
                $skipped++;
                $processed++;
                continue;
            }

            try {
                $variation_id = $this->process_variation(
                    $parent, $item,
                    $variation_attrs,
                    $images_map,
                    $prices_map,
                    $item_attributes_map,
                    $variation_value_map
                );
                if ($variation_id) {
                    $processed++;
                    $updated_skus[] = $check['key'];
                    $hash_map[$check['key']] = $check['hash'];
                }
            } catch (Exception $e) {
                $this->log("Exception processing variation SKU={$code}: " . $e->getMessage());
            }
        }

        // 12. Set default variation attributes from first variation
        $this->set_default_variation_attributes($parent, $first_item, $variation_attrs, $item_attributes_map, $variation_value_map);
        $parent->save();

        // 13. Sync existing variations that may be missing new attributes
        $this->sync_existing_variation_attributes($parent_id, $variation_attrs, $item_attributes_map, $variation_value_map);

        if (class_exists('WC_Product_Variable')) {
            WC_Product_Variable::sync($parent_id);
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($parent_id);
        }

        $this->log("Group series={$series_code} complete: {$processed} variations processed, {$skipped} skipped, parent ID={$parent_id}");

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'updated_skus' => $updated_skus,
        ];
    }

    // =====================================================================
    // Simple Product (backward-compatible for items without ProductSeriesCode)
    // =====================================================================

    private function process_simple_product(
        $item_xml,
        $images_map = [],
        $series_map = [],
        $features_map = [],
        $item_features_map = [],
        $item_attributes_map = [],
        $prices_map = []
    ) {
        if (!function_exists('wc_get_product_id_by_sku')) {
            $this->log('WooCommerce functions not available. Aborting simple product processing.');
            return;
        }

        $code = trim((string) $item_xml->Code);
        if (empty($code)) {
            $this->log('Item with empty Code skipped');
            return;
        }
        $sku = $code;
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            $product = wc_get_product($existing_id);
            if (!$product) {
                $product = new WC_Product_Simple();
                $product->set_sku($sku);
            }
            $this->log("Updating simple product SKU={$sku} (ID={$existing_id})");
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku($sku);
            $this->log("Creating simple product SKU={$sku}");
        }

        $name = (string) $item_xml->Name ?: (string) $item_xml->NameEn;
        $desc = (string) $item_xml->DetailedDescription ?: (string) $item_xml->DetailedDescriptionEn;
        $slug = sanitize_title((string) $item_xml->Slug ?: $name);

        $product->set_name($name);
        $product->set_slug($slug);

        if (isset($prices_map[$code])) {
            $p = $prices_map[$code];
            $product->set_regular_price($p['price_with_vat']);
            if ($p['sale_price'] !== null) {
                $product->set_sale_price($p['sale_price']);
            } else {
                $product->set_sale_price('');
            }
        }

        $product->set_description($desc);
        $product->set_short_description(wp_trim_words(strip_tags($desc), 30));

        // Categories
        $term_ids = $this->assign_categories($item_xml);

        // Attributes
        $attrs_array = $product->get_attributes();
        if (!is_array($attrs_array)) {
            $attrs_array = [];
        }

        // Series attribute
        $series_code = (string) $item_xml->ProductSeriesCode;
        if ($series_code && isset($series_map[$series_code])) {
            $series_name = $series_map[$series_code];
            $attr = new WC_Product_Attribute();
            $attr->set_name('Series');
            $attr->set_options([$series_name]);
            $attr->set_visible(true);
            $attr->set_variation(false);
            $attrs_array[] = $attr;
        }

        // Images
        $this->assign_images_to_product($product, $code, $images_map);

        // Item attributes
        $item_code = $code;
        if (!empty($item_attributes_map) && isset($item_attributes_map[$item_code])) {
            $entries = $item_attributes_map[$item_code];
            if (!is_array($entries) || (isset($entries['attribute_code']) && isset($entries['value']))) {
                $entries = [$entries];
            }
            foreach ($entries as $entry) {
                $attribute_code = isset($entry['attribute_code']) ? trim($entry['attribute_code']) : '';
                $attribute_value = isset($entry['value']) ? trim($entry['value']) : '';
                if ($attribute_code === '' || $attribute_value === '') continue;

                $merged = false;
                foreach ($attrs_array as $existing_attr) {
                    if (is_object($existing_attr) && strcasecmp($existing_attr->get_name(), $attribute_code) === 0) {
                        $options = (array) $existing_attr->get_options();
                        if (!in_array($attribute_value, $options, true)) {
                            $options[] = $attribute_value;
                            $existing_attr->set_options($options);
                        }
                        $merged = true;
                        break;
                    }
                }
                if ($merged) continue;

                $new_attr = new WC_Product_Attribute();
                $new_attr->set_name($attribute_code);
                $new_attr->set_options([$attribute_value]);
                $new_attr->set_visible(true);
                $new_attr->set_variation(false);
                $attrs_array[] = $new_attr;
            }
        }

        // Brands
        $brand_term_ids = $this->assign_brands($item_xml, $features_map, $item_features_map);

        if (!empty($attrs_array)) {
            $product->set_attributes($attrs_array);
        }

        $product_id = $product->save();
        if (!$product_id) {
            $this->log("Failed saving simple product SKU={$sku}");
        } else {
            $this->log("Saved simple product SKU={$sku} ID={$product_id}");
            update_post_meta($product_id, '_plano_imported', 1);
            if (!empty($term_ids)) {
                wp_set_object_terms($product_id, $term_ids, 'product_cat', false);
            }
            if (!empty($brand_term_ids)) {
                wp_set_object_terms($product_id, $brand_term_ids, 'product_brand', false);
            }
        }
    }

    // =====================================================================
    // Helper: find parent variable product by _plano_series meta
    // =====================================================================

    private function find_parent_by_series(string $series_code): int
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            '_plano_series',
            $series_code
        );
        $post_id = $wpdb->get_var($sql);
        return $post_id ? intval($post_id) : 0;
    }

    // =====================================================================
    // Helper: canonical attribute slug (WooCommerce max 28 chars)
    // =====================================================================

    private function attr_slug(string $attr_name): string
    {
        $slug = sanitize_title($attr_name);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = trim((string) $slug, '-_');
        if (empty($slug)) {
            $slug = 'attr-' . substr(md5($attr_name), 0, 8);
        }
        if (strlen($slug) > 28) {
            $base = rtrim(substr($slug, 0, 23), '-_');
            if ($base === '') {
                $base = 'attr';
            }
            $slug = $base . '-' . substr(md5($attr_name), 0, 4);
        }
        return $slug;
    }

    private function ensure_attribute_taxonomy(string $attr_name): array
    {
        $slug = $this->attr_slug($attr_name);
        $taxonomy = wc_attribute_taxonomy_name($slug);
        $attribute_id = wc_attribute_taxonomy_id_by_name($slug);

        if (!$attribute_id) {
            $id = wc_create_attribute([
                'name' => $attr_name,
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);

            if (is_wp_error($id)) {
                $this->log("Failed to create attribute '{$attr_name}': " . $id->get_error_message());
                return ['slug' => $slug, 'taxonomy' => $taxonomy, 'id' => 0];
            }

            $attribute_id = intval($id);
            delete_transient('wc_attribute_taxonomies');
            if (class_exists('WC_Cache_Helper')) {
                WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
            }
        }

        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'labels' => [
                    'name' => $attr_name,
                    'singular_name' => $attr_name,
                ],
                'hierarchical' => false,
                'show_ui' => false,
                'query_var' => true,
                'rewrite' => false,
                'public' => false,
                'show_in_nav_menus' => false,
            ]);
        }

        return ['slug' => $slug, 'taxonomy' => $taxonomy, 'id' => intval($attribute_id)];
    }

    private function ensure_attribute_term(string $attr_name, string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $attribute = $this->ensure_attribute_taxonomy($attr_name);
        $taxonomy = $attribute['taxonomy'];
        if (empty($attribute['id']) || !taxonomy_exists($taxonomy)) {
            return null;
        }

        $term_id = 0;
        $exists = term_exists($value, $taxonomy);
        if ($exists) {
            $term_id = is_array($exists) ? intval($exists['term_id']) : intval($exists);
        } else {
            $insert = wp_insert_term($value, $taxonomy);
            if (is_wp_error($insert)) {
                if ($insert->get_error_code() === 'term_exists') {
                    $data = $insert->get_error_data();
                    $term_id = is_array($data) && isset($data['term_id']) ? intval($data['term_id']) : intval($data);
                } else {
                    $this->log("Failed to create term '{$value}' in {$taxonomy}: " . $insert->get_error_message());
                    return null;
                }
            } else {
                $term_id = intval($insert['term_id']);
            }
        }

        $term = $term_id ? get_term($term_id, $taxonomy) : null;
        if (!$term || is_wp_error($term)) {
            return null;
        }

        return [
            'taxonomy' => $taxonomy,
            'attribute_id' => intval($attribute['id']),
            'term_id' => intval($term->term_id),
            'slug' => $term->slug,
            'name' => $term->name,
        ];
    }

    private function get_item_attribute_value(string $item_code, string $attr_name, array $item_attributes_map): string
    {
        if (!isset($item_attributes_map[$item_code])) {
            return '';
        }

        $entries = $item_attributes_map[$item_code];
        if (isset($entries['attribute_code'])) {
            $entries = [$entries];
        }

        $needle_slug = $this->attr_slug($attr_name);
        foreach ($entries as $entry) {
            $entry_code = trim($entry['attribute_code'] ?? '');
            if ($entry_code === '') {
                continue;
            }
            if ($entry_code === $attr_name || $this->attr_slug($entry_code) === $needle_slug) {
                return trim($entry['value'] ?? '');
            }
        }

        return '';
    }

    private function is_variation_attribute(string $attr_code, array $variation_attrs): bool
    {
        $attr_slug = $this->attr_slug($attr_code);
        foreach (array_keys($variation_attrs) as $variation_attr) {
            if ($this->attr_slug($variation_attr) === $attr_slug) {
                return true;
            }
        }
        return false;
    }

    private function sku_has_variation_for_parent(int $parent_id, string $sku): bool
    {
        $existing_id = wc_get_product_id_by_sku($sku);
        if (!$existing_id) {
            return false;
        }

        $product = wc_get_product($existing_id);
        return $product && $product->is_type('variation') && intval($product->get_parent_id()) === $parent_id;
    }

    private function detect_differing_item_attributes(array $items, array $item_attributes_map): array
    {
        $attr_values = [];

        foreach ($items as $item) {
            $item_code = trim((string) $item->Code);
            if ($item_code === '' || empty($item_attributes_map[$item_code])) {
                continue;
            }

            $entries = $item_attributes_map[$item_code];
            if (isset($entries['attribute_code'])) {
                $entries = [$entries];
            }

            foreach ($entries as $entry) {
                $attr_code = trim($entry['attribute_code'] ?? '');
                $attr_value = trim($entry['value'] ?? '');
                if ($attr_code === '' || $attr_value === '') {
                    continue;
                }

                $slug = $this->attr_slug($attr_code);
                if (!isset($attr_values[$slug])) {
                    $attr_values[$slug] = [];
                }
                if (!in_array($attr_value, $attr_values[$slug], true)) {
                    $attr_values[$slug][] = $attr_value;
                }
            }
        }

        $differing = [];
        foreach ($attr_values as $slug => $values) {
            if (count($values) > 1) {
                $differing[$slug] = true;
            }
        }

        return $differing;
    }

    private function derive_parent_name_from_group(array $items): string
    {
        $names = [];
        foreach ($items as $item) {
            $name = trim((string) $item->Name ?: (string) $item->NameEn);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if (empty($names)) {
            return '';
        }

        $prefix = $names[0];
        foreach ($names as $name) {
            $limit = min(strlen($prefix), strlen($name));
            $i = 0;
            while ($i < $limit && $prefix[$i] === $name[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') {
                break;
            }
        }

        $prefix = trim((string) preg_replace('/[\s\-_,;:\/]+$/', '', $prefix));
        return $prefix;
    }

    private function build_name_variation_data(array $items, string $parent_name): array
    {
        $raw_rows = [];
        $value_counts = [];
        foreach ($items as $item) {
            $sku = trim((string) $item->Code);
            if ($sku === '') {
                continue;
            }

            $item_name = trim((string) $item->Name ?: (string) $item->NameEn);
            $value = $item_name !== '' ? $item_name : $sku;

            $raw_rows[] = [
                'sku' => $sku,
                'name' => $item_name,
                'value' => $value,
            ];
            $value_counts[$value] = ($value_counts[$value] ?? 0) + 1;
        }

        $values_by_sku = [];
        $values = [];
        foreach ($raw_rows as $row) {
            $sku = $row['sku'];
            $value = $row['value'];
            if (($value_counts[$value] ?? 0) > 1) {
                $value = $row['name'] !== '' ? $row['name'] : $value . ' (' . $sku . ')';
            }

            $values_by_sku[$sku] = $value;
            if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return [
            'attrs' => ['Επιλογή' => $values],
            'values' => $values_by_sku,
        ];
    }

    // =====================================================================
    // Helper: ensure WooCommerce global attributes and terms exist
    // =====================================================================

    private function ensure_variation_attributes(array $variation_attrs): void
    {
        foreach ($variation_attrs as $attr_name => $values) {
            foreach ($values as $value) {
                $this->ensure_attribute_term($attr_name, $value);
            }
        }
    }

    // =====================================================================
    // Helper: set all attributes on the parent variable product
    // =====================================================================

    private function set_parent_attributes(
        WC_Product_Variable $parent,
        $first_item,
        array $variation_attrs,
        array $item_attributes_map,
        array $series_map,
        array $differing_item_attrs = []
    ): void {
        $attrs = [];
        $first_code = trim((string) $first_item->Code);

        // 1. Series attribute (normal, not for variation)
        $series_code = trim((string) $first_item->ProductSeriesCode);
        if ($series_code && isset($series_map[$series_code])) {
            $series_name = $series_map[$series_code];
            $attr = new WC_Product_Attribute();
            $attr->set_name('Series');
            $attr->set_options([$series_name]);
            $attr->set_visible(true);
            $attr->set_variation(false);
            $attrs[] = $attr;
        }

        // 2. Normal attributes (identical values across all items in the group)
        $normal_attr_names = [];

        if (!empty($item_attributes_map) && isset($item_attributes_map[$first_code])) {
            $entries = $item_attributes_map[$first_code];
            if (isset($entries['attribute_code'])) {
                $entries = [$entries];
            }

            foreach ($entries as $entry) {
                $attr_code = trim($entry['attribute_code'] ?? '');
                $attr_value = trim($entry['value'] ?? '');
                if ($attr_code === '' || $attr_value === '') continue;

                // Skip variation attributes
                if ($this->is_variation_attribute($attr_code, $variation_attrs)) continue;
                if (isset($differing_item_attrs[$this->attr_slug($attr_code)])) continue;
                if (in_array($attr_code, $normal_attr_names, true)) continue;
                $normal_attr_names[] = $attr_code;

                $attr = new WC_Product_Attribute();
                $attr->set_name($attr_code);
                $attr->set_options([$attr_value]);
                $attr->set_visible(true);
                $attr->set_variation(false);
                $attrs[] = $attr;
            }
        }

        // 3. Variation attributes (global / taxonomy-based)
        foreach ($variation_attrs as $attr_name => $values) {
            $taxonomy = '';
            $attr_id = 0;
            $term_ids = [];
            foreach ($values as $value) {
                $term = $this->ensure_attribute_term($attr_name, $value);
                if ($term) {
                    $taxonomy = $term['taxonomy'];
                    $attr_id = $term['attribute_id'];
                    $term_ids[] = $term['term_id'];
                }
            }

            if ($taxonomy && !empty($term_ids)) {
                $attr = new WC_Product_Attribute();
                $attr->set_id($attr_id ?: 0);
                $attr->set_name($taxonomy);
                $attr->set_options(array_values(array_unique($term_ids)));
                $attr->set_visible(true);
                $attr->set_variation(true);
                $attrs[] = $attr;
            }
        }

        $parent->set_attributes($attrs);
    }

    // =====================================================================
    // Helper: set default variation attributes on the parent
    // =====================================================================

    private function set_default_variation_attributes(
        WC_Product_Variable $parent,
        $first_item,
        array $variation_attrs,
        array $item_attributes_map,
        array $variation_value_map = []
    ): void {
        $defaults = [];
        $first_sku = trim((string) $first_item->Code);

        foreach ($variation_attrs as $attr_name => $values) {
            $item_value = $variation_value_map[$first_sku] ?? $this->get_item_attribute_value($first_sku, $attr_name, $item_attributes_map);
            $term = $item_value !== '' ? $this->ensure_attribute_term($attr_name, $item_value) : null;
            if ($term) {
                $defaults[$term['taxonomy']] = $term['slug'];
            }
        }

        $parent->set_default_attributes($defaults);
    }

    // =====================================================================
    // Process a single variation inside a variable product
    // =====================================================================

    private function process_variation(
        WC_Product_Variable $parent,
        $item,
        array $variation_attrs,
        array $images_map,
        array $prices_map,
        array $item_attributes_map,
        array $variation_value_map = []
    ): int {
        $sku = trim((string) $item->Code);
        if (empty($sku)) return 0;

        $parent_id = $parent->get_id();
        if (!$parent_id) return 0;

        // Delete any existing simple product with this SKU (migration)
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            $existing_product = wc_get_product($existing_id);
            if ($existing_product && $existing_product->is_type('simple')) {
                $this->log("Deleting simple product SKU={$sku} (ID={$existing_id}) — converting to variation.");
                wp_delete_post($existing_id, true);
                $existing_id = 0;
            }
        }

        // Find existing variation by SKU within this parent
        $variation = null;
        $children = $parent->get_children();
        foreach ($children as $child_id) {
            $child_sku = get_post_meta($child_id, '_sku', true);
            if ($child_sku === $sku) {
                $variation = wc_get_product($child_id);
                if ($variation && $variation->get_parent_id() != $parent_id) {
                    wp_delete_post($child_id, true);
                    $variation = null;
                }
                break;
            }
        }

        // Also check if variation exists under a different parent (reassign)
        if (!$variation) {
            $existing_var_id = wc_get_product_id_by_sku($sku);
            if ($existing_var_id) {
                $existing_var = wc_get_product($existing_var_id);
                if ($existing_var && $existing_var->is_type('variation')) {
                    if ($existing_var->get_parent_id() != $parent_id) {
                        $this->log("Variation SKU={$sku} exists under different parent, reassigning.");
                        wp_delete_post($existing_var_id, true);
                    } else {
                        $variation = $existing_var;
                    }
                }
            }
        }

        if (!$variation) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation->set_sku($sku);
            $this->log("Creating variation SKU={$sku}");
        } else {
            $this->log("Updating variation SKU={$sku} (ID={$variation->get_id()})");
        }

        // Price
        if (isset($prices_map[$sku])) {
            $p = $prices_map[$sku];
            $variation->set_regular_price($p['price_with_vat']);
            if ($p['sale_price'] !== null) {
                $variation->set_sale_price($p['sale_price']);
            } else {
                $variation->set_sale_price('');
            }
        }

        // Description
        $desc = (string) $item->DetailedDescription ?: (string) $item->DetailedDescriptionEn;
        if ($desc) {
            $variation->set_description($desc);
            $variation->update_meta_data('_variation_description', $desc);
        }

        // Variation attributes (taxonomy-based)
        $var_attributes = [];
        foreach ($variation_attrs as $attr_name => $values) {
            $item_value = $variation_value_map[$sku] ?? $this->get_item_attribute_value($sku, $attr_name, $item_attributes_map);
            $term = $item_value !== '' ? $this->ensure_attribute_term($attr_name, $item_value) : null;
            if ($term) {
                $var_attributes[$term['taxonomy']] = $term['slug'];
            }
        }

        $variation->set_attributes($var_attributes);

        // Stock
        $variation->set_stock_status('instock');
        $variation->set_manage_stock(false);

        // Variation image (first image for this SKU)
        if (isset($images_map[$sku]) && is_array($images_map[$sku])) {
            ksort($images_map[$sku]);
            $attach_ids = [];
            foreach ($images_map[$sku] as $order => $img_url) {
                $aid = $this->sideload_image_to_media($img_url);
                if ($aid) $attach_ids[] = $aid;
            }
            if (!empty($attach_ids)) {
                $attach_ids = array_values(array_unique($attach_ids));
                $variation->set_image_id($attach_ids[0]);
            }
        }

        // Save
        $variation_id = $variation->save();

        if ($variation_id) {
            update_post_meta($variation_id, '_plano_imported', 1);
            $this->log("Saved variation SKU={$sku} ID={$variation_id}");
        } else {
            $this->log("Failed saving variation SKU={$sku}");
        }

        return $variation_id ?: 0;
    }

    // =====================================================================
    // Extracted helpers (reused by both simple and variable product logic)
    // =====================================================================

    private function assign_categories($item_xml): array
    {
        $term_ids = [];
        $cat_path = (string) $item_xml->CategoryFullPath;
        if (empty($cat_path)) return $term_ids;

        $raw_parts = preg_split('/\/(?=\p{Lu})/u', $cat_path);
        $parts = array_values(array_filter(array_map('trim', $raw_parts)));
        $parent_id = 0;

        foreach ($parts as $name) {
            if ($name === '') continue;

            $slug_base = sanitize_title($name);
            if ($slug_base === '') {
                $slug_base = 'cat-' . substr(md5($name), 0, 6);
            }
            $slug = $slug_base;
            $term_id = 0;

            $existing = get_term_by('slug', $slug, 'product_cat');

            if ($existing && !is_wp_error($existing)) {
                if (strcmp($existing->name, $name) === 0) {
                    $term_id = intval($existing->term_id);
                    if (intval($existing->parent) !== intval($parent_id)) {
                        wp_update_term($term_id, 'product_cat', ['parent' => $parent_id]);
                    }
                } else {
                    $term_exists = term_exists($name, 'product_cat', $parent_id);
                    if ($term_exists) {
                        $term_id = is_array($term_exists) ? intval($term_exists['term_id']) : intval($term_exists);
                    } else {
                        $new = wp_insert_term($name, 'product_cat', ['slug' => $slug, 'parent' => $parent_id]);
                        if (!is_wp_error($new)) {
                            $term_id = intval($new['term_id']);
                        } else {
                            $this->log("Failed creating category '{$name}': " . $new->get_error_message());
                        }
                    }
                }
            } else {
                $new = wp_insert_term($name, 'product_cat', ['slug' => $slug, 'parent' => $parent_id]);
                if (!is_wp_error($new)) {
                    $term_id = intval($new['term_id']);
                } else {
                    $this->log("Failed creating category '{$name}': " . $new->get_error_message());
                }
            }

            if ($term_id) {
                $term_ids[] = $term_id;
                $parent_id = $term_id;
            }
        }

        return $term_ids;
    }

    private function assign_brands($item_xml, array $features_map, array $item_features_map): array
    {
        $brand_term_ids = [];
        $item_code = trim((string) $item_xml->Code);

        if (empty($item_features_map) || !isset($item_features_map[$item_code])) {
            return $brand_term_ids;
        }

        $feature_ids = $item_features_map[$item_code];
        if (!is_array($feature_ids)) {
            $feature_ids = [$feature_ids];
        }

        foreach ($feature_ids as $fid) {
            if (empty($fid)) continue;

            $fdef = $features_map[$fid] ?? null;
            $term_name = $fdef['value'] ?? $fdef['description'] ?? $fid;
            $term_slug = sanitize_title($term_name);

            $existing = get_term_by('slug', $term_slug, 'product_brand');
            if ($existing && !is_wp_error($existing)) {
                $term_id = intval($existing->term_id);
                $update_args = [];
                if (strcmp($existing->name, $term_name) !== 0)
                    $update_args['name'] = $term_name;
                $fdesc = trim($fdef['description'] ?? '');
                if ($fdesc !== '' && strcmp($existing->description, $fdesc) !== 0)
                    $update_args['description'] = $fdesc;
                if (!empty($update_args))
                    wp_update_term($term_id, 'product_brand', $update_args);
            } else {
                $insert = wp_insert_term($term_name, 'product_brand', [
                    'slug' => $term_slug,
                    'description' => isset($fdef['description']) ? $fdef['description'] : ''
                ]);
                if (is_wp_error($insert)) {
                    $this->log("Failed to create brand term '{$term_name}': " . $insert->get_error_message());
                    continue;
                }
                $term_id = intval($insert['term_id']);
            }

            if (!empty($fdef['image'])) {
                $aid = $this->sideload_image_to_media($fdef['image']);
                if ($aid) {
                    update_term_meta($term_id, 'thumbnail_id', $aid);
                    update_term_meta($term_id, '_plano_feature_image', esc_url_raw($fdef['image']));
                }
            }

            if (!in_array($term_id, $brand_term_ids, true)) {
                $brand_term_ids[] = $term_id;
            }
        }

        return $brand_term_ids;
    }

    private function assign_images_to_product($product, string $sku, array $images_map): void
    {
        if (!isset($images_map[$sku]) || !is_array($images_map[$sku])) {
            return;
        }

        ksort($images_map[$sku]);
        $attach_ids = [];
        foreach ($images_map[$sku] as $order => $img_url) {
            $aid = $this->sideload_image_to_media($img_url);
            if ($aid) $attach_ids[] = $aid;
        }

        if (!empty($attach_ids)) {
            $attach_ids = array_values(array_unique($attach_ids));
            $product->set_image_id($attach_ids[0]);
            if (count($attach_ids) > 1) {
                $product->set_gallery_image_ids(array_slice($attach_ids, 1));
            }
        }

        // Fix duplicate featured/gallery image
        $featured_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();

        if (!empty($featured_id) && !empty($gallery_ids)) {
            $first_gallery_id = $gallery_ids[0];
            $url1 = wp_get_attachment_url($featured_id);
            $url2 = wp_get_attachment_url($first_gallery_id);

            if ($url1 && $url2 && $url1 === $url2) {
                $new_gallery = array_slice($gallery_ids, 1);
                $product->set_gallery_image_ids($new_gallery);
            }
        }
    }

    private function sync_existing_variation_attributes(
        int $parent_id,
        array $variation_attrs,
        array $item_attributes_map,
        array $variation_value_map = []
    ): void {
        $parent = wc_get_product($parent_id);
        if (!$parent) return;

        $children = $parent->get_children();
        foreach ($children as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation) continue;

            $current_attrs_raw = $variation->get_attributes();
            $current_attrs = [];
            foreach ($current_attrs_raw as $key => $value) {
                $clean_key = strpos($key, 'attribute_') === 0 ? substr($key, 10) : $key;
                $current_attrs[$clean_key] = $value;
            }
            $next_attrs = [];

            foreach ($variation_attrs as $attr_name => $values) {
                $sku = $variation->get_sku();
                $item_value = $sku ? ($variation_value_map[$sku] ?? $this->get_item_attribute_value($sku, $attr_name, $item_attributes_map)) : '';
                if ($item_value === '') {
                    continue;
                }

                $term = $this->ensure_attribute_term($attr_name, $item_value);
                if (!$term) {
                    continue;
                }

                $taxonomy = $term['taxonomy'];
                $next_attrs[$taxonomy] = $term['slug'];
            }

            ksort($current_attrs);
            ksort($next_attrs);
            if (!empty($next_attrs) && $current_attrs !== $next_attrs) {
                $variation->set_attributes($next_attrs);
                $variation->save();
                $this->log("Synced existing variation ID={$child_id} with new variation attributes.");
            }
        }
    }

    private function sideload_image_to_media($image_url)
    {
        if (empty($image_url))
            return false;
        $image_url = esc_url_raw(trim($image_url));
        if (empty($image_url))
            return false;

        // Try to find an existing attachment that was previously imported from the same remote URL
        $existing = $this->find_attachment_by_original_url($image_url);

        if ($existing)
            return $existing;

        // do not attempt if URL is not http/https
        $parts = wp_parse_url($image_url);
        if (empty($parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            $this->log("Skipping non-http image URL: {$image_url}");
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            $this->log("download_url failed for {$image_url}: " . $tmp->get_error_message());
            return false;
        }

        $file_hash = false;
        if (file_exists($tmp)) {
            $file_hash = @md5_file($tmp);
        }
        if ($file_hash) {
            // try to find attachment by file hash (covers same binary served via different URLs)
            $existing_by_hash = $this->find_attachment_by_file_hash(($file_hash));
            if ($existing_by_hash) {
                // no need to keep temp file
                @unlink($tmp);
                return $existing_by_hash;
            }
        }

        // prepare file array
        $file = [];
        $file['name'] = basename(parse_url($image_url, PHP_URL_PATH));
        $file['tmp_name'] = $tmp;

        $attach_id = media_handle_sideload($file, 0);

        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            $this->log("media_handle_sideload failed for {$image_url}: " . $attach_id->get_error_message());
            return false;
        }

        // store the original remote URL and file hash on the attachment so we can detect duplicates later
        update_post_meta($attach_id, '_plano_original_url', $image_url);
        if ($file_hash)
            update_post_meta($attach_id, '_plano_file_hash', $file_hash);

        return $attach_id;
    }

    public function find_attachment_by_original_url($image_url)
    {
        global $wpdb;
        $meta_key = '_plano_original_url';
        $norm = esc_url_raw(trim($image_url));
        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key,
            $norm
        );
        $post_id = $wpdb->get_var($sql);
        return $post_id ? intval($post_id) : false;
    }

    public function find_attachment_by_file_hash($hash)
    {
        if (empty($hash))
            return false;
        global $wpdb;
        $meta_key = '_plano_file_hash';
        $sql = $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", $meta_key, $hash);
        $post_id = $wpdb->get_var($sql);
        return $post_id ? intval($post_id) : false;
    }


    public function log($msg)
    {
        $time = date_i18n('Y-m-d H:i:s');
        $entry = "[PlanoImporter] {$time} - {$msg}\n";
        error_log($entry, 3, $this->log_file);
    }

    public function get_log_tail($chars = 2000)
    {
        if (!file_exists($this->log_file))
            return '';
        $size = filesize($this->log_file);
        $fp = fopen($this->log_file, 'r');
        if (!$fp)
            return '';
        if ($size > $chars)
            fseek($fp, -$chars, SEEK_END);
        $data = fread($fp, $chars);
        fclose($fp);
        return $data;
    }

    public function reset_pointer()
    {
        update_option('plano_import_offset', 0, false);
        $this->log('Pointer reset to 0 via reset_pointer()');
    }

    /**
     * Return path for persistent hash file in uploads dir.
     */
    private function get_hash_file_path(): string
    {
        return trailingslashit($this->uploads_dir) . 'plano_items_hashes.json';
    }

    /**
     * Load saved hash map (SKU => hex-hash). Returns [] on missing/invalid file.
     */
    private function load_hash_map(): array
    {
        $path = $this->get_hash_file_path();
        if (!is_readable($path))
            return [];
        $json = @file_get_contents($path);
        if ($json === false)
            return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Persist hash map (atomic write). Returns bool success.
     */
    private function save_hash_map(array $map): bool
    {
        $path = $this->get_hash_file_path();
        $tmp = $path . '.tmp';
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false)
            return false;
        if (file_put_contents($tmp, $json) === false)
            return false;
        return rename($tmp, $path);
    }

    /**
     * Convert SimpleXMLElement (or nested arrays/objects) into a pure PHP array suitable for canonicalization.
     * Uses json encode/decode to flatten SimpleXML reliably.
     */
    private function xml_to_array(mixed $value): mixed
    {
        if ($value instanceof SimpleXMLElement) {
            $json = json_encode($value);
            $arr = json_decode($json, true);
            return $this->xml_to_array($arr);
        }
        if (is_array($value)) {
            $res = [];
            foreach ($value as $k => $v)
                $res[$k] = $this->xml_to_array($v);
            return $res;
        }
        return $value;
    }

    /**
     * Recursively normalize array for stable serialization: sort associative keys and normalize values.
     */
    private function normalize_for_hash(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            // detect associative array
            $keys = array_keys($value);
            $isAssoc = ($keys !== range(0, count($value) - 1));
            if ($isAssoc)
                ksort($value);
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalize_for_hash($v);
            }
            return $value;
        }
        // Scalars keep as-is (cast floats/ints to scalars)
        return $value;
    }

    /**
     * Return canonical JSON for an item (stable across runs).
     */
    private function canonical_json(mixed $item): string
    {
        $arr = $this->xml_to_array($item);
        $norm = $this->normalize_for_hash($arr);
        // preserve numeric fractions; keep readable.
        return json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Compute strong hex hash for an item, optionally combined with price data.
     * If $price_data is provided, the hash is computed over the concatenation of
     * the item's canonical JSON and the price data's canonical JSON, separated by a null byte.
     *
     * @param mixed $item The item (SimpleXMLElement or array) to hash
     * @param mixed|null $price_data Optional price data array to include in the hash
     * @param string $algo Hash algorithm (default sha256)
     * @return string Hex hash
     */
    private function compute_item_hash(mixed $item, $price_data = null, string $algo = 'sha256'): string
    {
        $json = $this->canonical_json($item);
        if ($price_data !== null) {
            $price_json = $this->canonical_json($price_data);
            $json .= "\x00" . $price_json;
        }
        return hash($algo, $json);
    }

    /**
     * Check whether item (SimpleXMLElement) differs from saved hash map.
     * $keyField: field name to use as SKU/key in the map (default 'Code').
     * $prices_map: optional price data map to include in hash calculation
     * Returns array: [ 'key' => (string)$key, 'hash' => (string)$hash, 'changed' => bool ]
     */
    private function check_item_changed(mixed $item_xml, array $old_map, string $keyField = 'Code', array $prices_map = []): array
    {
        // get key (SKU)
        $key = '';
        if (is_array($item_xml) && isset($item_xml[$keyField])) {
            $key = (string) $item_xml[$keyField];
        } elseif (is_object($item_xml) && property_exists($item_xml, $keyField)) {
            $key = (string) $item_xml->{$keyField};
        } else {
            // best effort: attempt to read Code child
            if (isset($item_xml->Code))
                $key = (string) $item_xml->Code;
        }
        
        // Get price data for this SKU if available
        $price_data = isset($prices_map[$key]) ? $prices_map[$key] : null;
        $hash = $this->compute_item_hash($item_xml, $price_data);
        $old = isset($old_map[$key]) ? (string) $old_map[$key] : null;
        $changed = ($old === null) || !hash_equals((string) $hash, (string) $old);
        return ['key' => (string) $key, 'hash' => $hash, 'changed' => $changed];
    }

    /**
     * Update in-memory hash map for a given SKU key.
     */
    private function update_hash_map_entry(array &$map, string $key, string $hash): void
    {
        $map[$key] = $hash;
    }


}
class WP_Woo_Plano_Importer
{
    private $core;

    private $option_name = 'plano_importer_opts';

    private $defaults = [
        'items_url' => '',
        'series_url' => '',
        'images_url' => '',
        'attributes_url' => '',
        'features_url' => '',
        'batch' => 10,
        'cron_batch' => 50,
    ];

    public function __construct()
    {
        $opts = get_option($this->option_name, []);
        $opts = wp_parse_args($opts, $this->defaults);

        // if user didn't set custom URLs, use the core defaults
        $feeds = [];
        $feeds['items'] = $opts['items_url'] ?: '';
        $feeds['series'] = $opts['series_url'] ?: '';
        $feeds['images'] = $opts['images_url'] ?: '';
        $feeds['attributes'] = $opts['attributes_url'] ?: '';
        $feeds['features'] = $opts['features_url'] ?: '';

        // if any feed missing, let core use defaults
        foreach ($feeds as $k => $v) {
            if (empty($v)) {
                unset($feeds[$k]);
            }
        }

        $this->core = new Plano_Importer_Core($feeds);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_plano_import_run', [$this, 'handle_manual_run']);

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);

        // more WP-CLI commands if available, omitted here
    }

    public function admin_menu()
    {
        add_management_page(
            'Plano Importer',
            'Plano Importer',
            'manage_options',
            'plano-importer',
            [$this, 'admin_page']
        );
    }

    public function admin_page()
    {
        if (!current_user_can('manage_options'))
            return;
        $opts = get_option($this->option_name, []);
        $opts = wp_parse_args($opts, $this->defaults);
        $log_tail = $this->core->get_log_tail(4000);
        ?>
        <div class="wrap">
            <h1>Plano Importer</h1>
            <form method="post" action="<?php echo esc_url(
                admin_url('admin-post.php')
            ); ?>">
                <?php wp_nonce_field('plano_import_run'); ?>
                <input type="hidden" name="action" value="plano_import_run" />
                <table class="form-table">
                    <tr>
                        <th>Items feed URL</th>
                        <td><input type="text" name="items_url" value="<?php echo esc_attr($opts['items_url']); ?>"
                                size="80" /></td>
                    </tr>
                    <tr>
                        <th>ProductSeries feed URL</th>
                        <td><input type="text" name="series_url" value="<?php echo esc_attr($opts['series_url']); ?>"
                                size="80" /></td>
                    </tr>
                    <tr>
                        <th>Images feed URL</th>
                        <td><input type="text" name="images_url" value="<?php echo esc_attr($opts['images_url']); ?>"
                                size="80" /></td>
                    </tr>
                    <tr>
                        <th>Attributes feed URL</th>
                        <td><input type="text" name="attributes_url" value="<?php echo esc_attr($opts['attributes_url']); ?>"
                                size="80" /></td>
                    </tr>
                    <tr>
                        <th>Features feed URL</th>
                        <td><input type="text" name="features_url" value="<?php echo esc_attr($opts['features_url']); ?>"
                                size="80" /></td>
                    </tr>
                    <tr>
                        <th>Manual batch size</th>
                        <td><input type="number" name="batch" value="<?php echo esc_attr($opts['batch']); ?>" min="1"
                                max="500" /></td>
                    </tr>
                    <tr>
                        <th>Cron batch size (per daily run) - DEPRECATED</th>
                        <td><input type="number" name="cron_batch" value="<?php echo esc_attr($opts['cron_batch']); ?>"
                                min="1" max="1000" /></td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save & Run" />
                    &nbsp;&nbsp;
                    <label style="font-weight:normal;"><input type="checkbox" name="reset_pointer" value="1" /> Reset pointer to
                        start</label>
                </p>
            </form>
            <h2>Logs (last lines)</h2>
            <pre
                style="max-height:300px; overflow:auto; padding:10px; background:#fff; border:1px solid #ddd;"><?php echo esc_html($log_tail); ?></pre>

            <h2>Manual actions</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('plano_import_run'); ?>
                <input type="hidden" name="action" value="plano_import_run" />
                <input type="hidden" name="batch" value="<?php echo esc_attr($opts['batch']); ?>" />
                <p>
                    <button class="button button-primary" type="submit">Run one batch now
                        (<?php echo intval($opts['batch']); ?>)</button>
                    &nbsp;
                    <label><input type="checkbox" name="reset_pointer" value="1" /> Reset pointer first</label>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_manual_run()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('plano_import_run');

        $opts = get_option($this->option_name, []);
        $opts = wp_parse_args($opts, $this->defaults);

        // save posted URLs / settings if present
        $posted = false;
        $fields = ['items_url', 'series_url', 'images_url', 'attributes_url', 'features_url', 'batch', 'cron_batch'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $opts[$f] = sanitize_text_field(wp_unslash($_POST[$f]));
                $posted = true;
            }
        }
        if ($posted)
            update_option($this->option_name, $opts);

        // If reset_pointer checkbox is set, perform ONLY the reset and do NOT run the import
        if (isset($_POST['reset_pointer']) && $_POST['reset_pointer']) {
            $this->core->reset_pointer();
            $redirect = add_query_arg('plano_imported', 'reset', wp_get_referer() ?: admin_url('tools.php?page=plano-importer'));
            wp_safe_redirect($redirect);
            exit;
        }

        $batch = isset($_POST['']) ? intval($_POST['batch']) : intval($opts['batch']);
        if ($batch < 1)
            $batch = 10;

        $processed = $this->core->do_import_batch($batch);

        // redirect back with notice
        $redirect = add_query_arg('plano_imported', 'processed', wp_get_referer() ?: admin_url('tools.php?page=plano-importer'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function on_activate()
    {
    }
    public function on_deactivate()
    {
    }

}
$WP_Woo_Plano_Importer = new WP_Woo_Plano_Importer();
