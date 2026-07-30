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

    private const HASH_SCHEMA_VERSION = '2';

    private $feeds = [];
    private $log_file;
    private $uploads_dir;

    /**
     * Use one normalized SKU form for every feed map, hash, and WooCommerce lookup.
     */
    private function normalize_sku($value): string
    {
        return trim((string) $value);
    }

    /**
     * Validate and normalize a feed price to the WooCommerce decimal precision.
     */
    private function normalize_feed_price($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        if (function_exists('wc_format_decimal')) {
            $normalized = wc_format_decimal($raw, $decimals);
        } else {
            $normalized = number_format((float) $raw, $decimals, '.', '');
        }

        return $normalized === '' ? null : (string) $normalized;
    }

    /**
     * Normalize a saved WooCommerce price for a stable comparison.
     */
    private function normalize_saved_price($value): string
    {
        if ($value === null || (string) $value === '') {
            return '';
        }

        $normalized = $this->normalize_feed_price($value);
        return $normalized === null ? '' : $normalized;
    }

    private function product_has_expected_price($product, array $expected): bool
    {
        if (!$product || !method_exists($product, 'get_regular_price')) {
            return false;
        }

        $expected_regular = $this->normalize_saved_price($expected['price_with_vat'] ?? '');
        if ($expected_regular === '') {
            return false;
        }

        $actual_regular = $this->normalize_saved_price($product->get_regular_price('edit'));
        if ($actual_regular !== $expected_regular) {
            return false;
        }

        $expected_sale = $expected['sale_price'] ?? null;
        $actual_sale = $this->normalize_saved_price($product->get_sale_price('edit'));
        $actual_active = $this->normalize_saved_price($product->get_price('edit'));

        if ($expected_sale === null) {
            return $actual_sale === '' && $actual_active === $expected_regular;
        }

        $expected_sale = $this->normalize_saved_price($expected_sale);
        return $expected_sale !== ''
            && $actual_sale === $expected_sale
            && $actual_active === $expected_sale;
    }

    /**
     * Check the saved WooCommerce values, not only the feed hash.
     */
    private function stored_product_price_is_synced(string $sku, array $expected): bool
    {
        if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product')) {
            return false;
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return false;
        }

        $product = wc_get_product($product_id);
        return $product && $product->is_type('simple')
            && $this->product_has_expected_price($product, $expected);
    }

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
        if (!is_array($data)) return [];

        $normalized = [];
        foreach ($data as $sku) {
            $sku = $this->normalize_sku($sku);
            if ($sku !== '') {
                $normalized[] = $sku;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function save_seen_skus(array $skus): bool
    {
        $path = $this->get_seen_skus_file_path();
        $tmp = $path . '.tmp';
        $normalized = [];
        foreach ($skus as $sku) {
            $sku = $this->normalize_sku($sku);
            if ($sku !== '') {
                $normalized[] = $sku;
            }
        }
        $json = json_encode(array_values(array_unique($normalized)), JSON_PRETTY_PRINT);
        if ($json === false) return false;
        if (file_put_contents($tmp, $json) === false) return false;
        return rename($tmp, $path);
    }

    private function reconcile_deletions(array $seen_skus): void
    {
        global $wpdb;

        // Get all product IDs marked as plano-imported
        $imported_ids = $wpdb->get_results(
            "SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} 
            WHERE meta_key = '_plano_imported' AND meta_value != ''"
        );

        if (empty($imported_ids)) {
            $this->log("Reconciliation: no _plano_imported products found, skipping.");
            return;
        }

        // Build SKU => post_id map from DB
        $db_skus = [];
        foreach ($imported_ids as $row) {
            $sku = $this->normalize_sku(get_post_meta($row->post_id, '_sku', true));
            if ($sku !== '') $db_skus[$sku] = intval($row->post_id);
        }

        $seen_set = array_flip($seen_skus);
        $missing = array_diff_key($db_skus, $seen_set);

        if (empty($missing)) {
            $this->log("Reconciliation: no deleted products detected.");
            return;
        }

        foreach ($missing as $sku => $post_id) {
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            $this->log("Reconciliation: unpublished SKU={$sku} (ID={$post_id}) — not found in feed.");
        }

        $this->log("Reconciliation complete: " . count($missing) . " products unpublished.");
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
            $code = $this->normalize_sku($img->ItemCode);
            $url = (string) $img->ImageUrl;
            $order = isset($img->OrderNo) ? intval($img->OrderNo) : 0;
            if ($code === '' || empty($url))
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
            $item_code = $this->normalize_sku($f->ItemCode);
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
            $item_code = $this->normalize_sku($a->ItemCode);
            $attribute_code = (string) $a->AttributeCode;
            $value = (string) $a->Value;
            if ($item_code === '')
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
        if (!$xml) {
            $this->log('Prices-GR import stopped: the feed could not be loaded.');
            return false;
        }

        $map = [];
        $rows = 0;
        $duplicates = 0;
        $normalized_codes = 0;
        $sale_rows = 0;
        $invalid = [];
        $conflicts = [];

        foreach ($xml->prices as $p) {
            $rows++;
            $raw_code = (string) $p->Code;
            $code = $this->normalize_sku($raw_code);
            if ($raw_code !== $code) {
                $normalized_codes++;
            }
            if ($code === '') {
                $invalid[] = '(empty SKU)';
                continue;
            }

            $price_with_vat = $this->normalize_feed_price($p->CurrentPriceWithVat);
            if ($price_with_vat === null) {
                $invalid[] = "{$code} (invalid CurrentPriceWithVat)";
                continue;
            }

            $sale_price = null;
            $sale_price_raw = isset($p->SalePrice) ? trim((string) $p->SalePrice) : '';
            if ($sale_price_raw !== '') {
                $sale_price = $this->normalize_feed_price($sale_price_raw);
                if ($sale_price === null || (float) $sale_price >= (float) $price_with_vat) {
                    $invalid[] = "{$code} (invalid SalePrice)";
                    continue;
                }
                $sale_rows++;
            }

            $entry = [
                'price_with_vat' => $price_with_vat,
                'sale_price' => $sale_price,
            ];

            if (isset($map[$code])) {
                $duplicates++;
                if ($map[$code] !== $entry) {
                    $conflicts[] = $code;
                }
                continue;
            }

            $map[$code] = $entry;
        }

        $created_at = isset($xml['createdAt']) ? (string) $xml['createdAt'] : 'unknown';
        $this->log(
            "Prices-GR loaded: rows={$rows}, unique=" . count($map)
            . ", duplicates={$duplicates}, normalized_skus={$normalized_codes}, "
            . "sale_prices={$sale_rows}, created_at={$created_at}"
        );

        if (!empty($invalid)) {
            $sample = implode(', ', array_slice(array_unique($invalid), 0, 10));
            $this->log(
                'Prices-GR import stopped: invalid rows=' . count($invalid)
                . ". Sample: {$sample}"
            );
            return false;
        }

        if (!empty($conflicts)) {
            $sample = implode(', ', array_slice(array_unique($conflicts), 0, 10));
            $this->log(
                'Prices-GR import stopped: conflicting duplicate SKUs=' . count(array_unique($conflicts))
                . ". Sample: {$sample}"
            );
            return false;
        }

        if (empty($map)) {
            $this->log('Prices-GR import stopped: the validated price map is empty.');
            return false;
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
        try {
            $offset = max(0, intval(get_option('plano_import_offset', 0)));

            // Fetch maps once per batch.
            $images_map = $this->fetch_images_map();
            $series_map = $this->fetch_series_map();
            $features_map = $this->fetch_features_map();
            $item_features_map = $this->fetch_item_features_map();
            $item_attributes_map = $this->fetch_item_attributes_map();
            $prices_map = $this->fetch_prices_map();
            if ($prices_map === false) {
                throw new RuntimeException(
                    'Import stopped because Prices-GR did not pass validation.'
                );
            }

            // Fetch the items feed.
            $xml = $this->fetch_url_xml($this->feeds['items']);
            if (!$xml) {
                $this->log('Failed fetching Items feed');
                throw new RuntimeException('Import stopped because Items could not be loaded.');
            }

            $items_arr = [];
            $item_indexes = [];
            $duplicate_items = 0;
            $normalized_item_codes = 0;
            $invalid_item_codes = 0;
            $conflicting_items = [];

            foreach ($xml->Item as $it) {
                $raw_code = (string) $it->Code;
                $code = $this->normalize_sku($raw_code);
                if ($raw_code !== $code) {
                    $normalized_item_codes++;
                }
                if ($code === '') {
                    $invalid_item_codes++;
                    continue;
                }

                if (isset($item_indexes[$code])) {
                    $duplicate_items++;
                    $existing_item = $items_arr[$item_indexes[$code]];
                    if ($this->canonical_json($existing_item) !== $this->canonical_json($it)) {
                        $conflicting_items[] = $code;
                    }
                    continue;
                }

                $item_indexes[$code] = count($items_arr);
                $items_arr[] = $it;
            }

            $items_created_at = isset($xml['createdAt']) ? (string) $xml['createdAt'] : 'unknown';
            $this->log(
                'Items loaded: rows=' . count($xml->Item)
                . ', unique=' . count($items_arr)
                . ", duplicates={$duplicate_items}, normalized_skus={$normalized_item_codes}, "
                . "invalid_skus={$invalid_item_codes}, created_at={$items_created_at}"
            );

            if ($invalid_item_codes > 0 || !empty($conflicting_items)) {
                $sample = implode(', ', array_slice(array_unique($conflicting_items), 0, 10));
                $this->log(
                    "Import stopped: invalid_item_skus={$invalid_item_codes}, "
                    . 'conflicting_duplicate_skus=' . count(array_unique($conflicting_items))
                    . ($sample !== '' ? ". Sample: {$sample}" : '')
                );
                throw new RuntimeException(
                    'Import stopped because Items contains invalid or conflicting SKUs.'
                );
            }

            if (empty($items_arr)) {
                $this->log('Import stopped: the validated Items feed is empty.');
                throw new RuntimeException('Import stopped because Items is empty.');
            }

            $missing_prices = [];
            foreach ($items_arr as $item) {
                $sku = $this->normalize_sku($item->Code);
                if (!isset($prices_map[$sku])) {
                    $missing_prices[] = $sku;
                }
            }

            if (!empty($missing_prices)) {
                $sample = implode(', ', array_slice($missing_prices, 0, 10));
                $this->log(
                    'Import stopped: Prices-GR has no valid price for '
                    . count($missing_prices) . " Items SKUs. Sample: {$sample}"
                );
                throw new RuntimeException(
                    'Import stopped because Prices-GR does not cover every Items SKU.'
                );
            }

            $orphan_price_count = count(array_diff_key($prices_map, $item_indexes));
            if ($orphan_price_count > 0) {
                $this->log("Prices-GR contains {$orphan_price_count} SKUs that are not in Items.");
            }

            if ($offset >= count($items_arr)) {
                update_option('plano_import_offset', 0, false);
                $this->log("Pointer was at/after end ({$offset}). Reset to 0.");
                return 0;
            }

            $hash_map = $this->load_hash_map();
            $slice = array_slice($items_arr, $offset, $batch);
            $consumed = count($slice);
            $processed = 0;
            $skipped = 0;
            $failed = 0;
            $updated_skus = [];

            foreach ($slice as $slice_index => $item) {
                $check = $this->check_item_changed($item, $hash_map, 'Code', $prices_map);
                if (!$check['changed']) {
                    $expected_price = $prices_map[$check['key']];
                    if ($this->stored_product_price_is_synced($check['key'], $expected_price)) {
                        $skipped++;
                        $processed++;
                        continue;
                    }
                    $this->log(
                        "Price repair required for SKU={$check['key']}: "
                        . 'feed hash matched, but WooCommerce did not match Prices-GR.'
                    );
                }

                try {
                    $product_id = $this->process_item(
                        $item,
                        $images_map,
                        $series_map,
                        $features_map,
                        $item_features_map,
                        $item_attributes_map,
                        $prices_map
                    );
                    if (!$product_id) {
                        $failed++;
                        continue;
                    }

                    $processed++;
                    $updated_skus[] = $check['key'];
                    $this->update_hash_map_entry($hash_map, $check['key'], $check['hash']);
                } catch (Throwable $e) {
                    $failed++;
                    $item_offset = $offset + $slice_index;
                    $error_type = get_class($e);
                    $this->log(
                        "Failed item SKU={$check['key']} at offset {$item_offset}: "
                        . "{$error_type}: {$e->getMessage()}"
                    );
                }
            }
            if (!$this->save_hash_map($hash_map)) {
                $this->log('Warning: the item hash map could not be saved.');
            }

            if ($skipped > 0) {
                $sku_list = !empty($updated_skus) ? 'updated SKU: ' . implode(', ', $updated_skus) : '';
                $this->log("Skipped {$skipped} unchanged items" . ($sku_list ? ", {$sku_list}" : ''));
            }
            if ($failed > 0) {
                $this->log("Batch had {$failed} failed items. Their hashes were not saved.");
            }

            $seen_skus = $this->load_seen_skus();
            foreach ($slice as $item) {
                $sku = $this->normalize_sku($item->Code);
                if ($sku) $seen_skus[] = $sku;
            }
            $this->save_seen_skus($seen_skus);

            // Always advance by the number of feed rows in this slice.
            $new_offset = $offset + $consumed;
            if ($new_offset >= count($items_arr)) {
                update_option('plano_import_offset', 0, false);
                $this->log(
                    "Consumed {$consumed} feed items, processed {$processed}, "
                    . "and reached the feed end. Pointer reset to 0."
                );

                $seen_skus = $this->load_seen_skus();
                $this->reconcile_deletions($seen_skus);
                $this->save_seen_skus([]);
            } else {
                update_option('plano_import_offset', $new_offset, false);
                $this->log(
                    "Batch finished: consumed={$consumed}, processed={$processed}, "
                    . "failed={$failed}, pointer={$new_offset}"
                );
            }

            return $processed;
        } finally {
            delete_transient('plano_import_lock');
        }
    }

    public function process_item(
        $item_xml,
        $images_map = [],
        $series_map = [], 
        $features_map =[], 
        $item_features_map = [], 
        $item_attributes_map = [],
        $prices_map = []
    ): int {
        if (!function_exists('wc_get_product_id_by_sku')) {
            $this->log('WooCommerce functions not available. Aborting item processing.');
            return 0;
        }

        $code = $this->normalize_sku($item_xml->Code);
        if ($code === '') {
            $this->log('Item with empty Code skipped');
            return 0;
        }

        if (!isset($prices_map[$code])) {
            $this->log("Item skipped: Prices-GR has no validated price for SKU={$code}");
            return 0;
        }

        $price_data = $prices_map[$code];
        $sku = $code;
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            $product = wc_get_product($existing_id);
            if (!$product) {
                $this->log("Product lookup failed for SKU={$sku} (ID={$existing_id}). Item skipped.");
                return 0;
            }
            if (!$product->is_type('simple')) {
                $product_type = $product->get_type();
                $this->log(
                    "Product type mismatch for SKU={$sku} (ID={$existing_id}): "
                    . "expected=simple, actual={$product_type}. Item skipped."
                );
                return 0;
            }
            $this->log("Updating product SKU={$sku} (ID={$existing_id})");
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku($sku);
            $this->log("Creating product SKU={$sku}");
        }

        $name = (string) $item_xml->Name ?: (string) $item_xml->NameEn;
        $desc = (string) $item_xml->DetailedDescription ?: (string) $item_xml->DetailedDescriptionEn;
        $slug = sanitize_title((string) $item_xml->Slug ?: $name);

        $product->set_name($name);
        $product->set_slug($slug);

        $product->set_regular_price($price_data['price_with_vat']);
        $product->set_date_on_sale_from(null);
        $product->set_date_on_sale_to(null);
        if ($price_data['sale_price'] !== null) {
            $product->set_sale_price($price_data['sale_price']);
            $product->set_price($price_data['sale_price']);
        } else {
            $product->set_sale_price('');
            $product->set_price($price_data['price_with_vat']);
        }

        $product->set_description($desc);
        $product->set_short_description(wp_trim_words(strip_tags($desc), 30));

        // categories (hierarchical — child slugs built from name only; do NOT strip '%' or control chars)
        $cat_path = (string) $item_xml->CategoryFullPath;
        if ($cat_path) {
            // $parts = array_values(array_filter(array_map('trim', explode('/', $cat_path)))); OLD CODE DONT USE
            // split on '/' only when the character immediately after it is an uppercase letter (Unicode-aware)
            $raw_parts = preg_split('/\/(?=\p{Lu})/u', $cat_path);
            $parts = array_values(array_filter(array_map('trim', $raw_parts)));
            $parent_id = 0;
            $term_ids = [];

            foreach ($parts as $name) {
                if ($name === '')
                    continue;

                // slug derived from name only (no parent prefix)
                $slug_base = sanitize_title($name);
                if ($slug_base === '') {
                    $slug_base = 'cat-' . substr(md5($name), 0, 6);
                }

                // Do NOT strip '%' or control chars here — keep slug as WP would normally create it
                $slug = $slug_base;
                $term_id = 0;

                // find existing term by slug
                $existing = get_term_by('slug', $slug, 'product_cat');

                if ($existing && !is_wp_error($existing)) {
                    // If same-name term exists (byte-for-byte), reuse it and ensure correct parent
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
                            if (is_wp_error($new)) {
                                $this->log("Failed creating category '{$name}': " . $new->get_error_message());
                                continue;
                            }
                            $term_id = intval($new['term_id']);
                        }
                    }
                } else {
                    // create term with slug from name only
                    $new = wp_insert_term($name, 'product_cat', ['slug' => $slug, 'parent' => $parent_id]);
                    if (is_wp_error($new)) {
                        $this->log("Failed creating category '{$name}': " . $new->get_error_message());
                        continue;
                    }
                    $term_id = intval($new['term_id']);
                }


                $term_ids[] = $term_id;
                $parent_id = $term_id;
            }

            // assign deepest category if product exists
            if (!empty($term_ids)) {
                $product_id_for_terms = $product->get_id() ?: 0;
                $deepest = intval(end($term_ids));
                if ($product_id_for_terms) {
                    wp_set_object_terms($product_id_for_terms, [$deepest], 'product_cat', false);
                }
            }
        }


        // prepare attributes array
        $attrs_array = $product->get_attributes();
        if (!is_array($attrs_array)) {
            $attrs_array = [];
        }

        // series -> product attribute Series
        $series_code = (string) $item_xml->ProductSeriesCode;
        if ($series_code && isset($series_map[$series_code])) {
            $series_name = $series_map[$series_code];
            $attr = new WC_Product_Attribute();
            $attr->set_name('Series');
            $attr->set_options([$series_name]);
            $attr->set_visible(true);
            $attr->set_variation(false);
            // $product->set_attributes([$attr]);
            $attrs_array[] = $attr;
        }

        // images
        $code_key = $code;
        if (isset($images_map[$code_key]) && is_array($images_map[$code_key])) {
            ksort($images_map[$code_key]);
            $attach_ids = [];
            foreach ($images_map[$code_key] as $order => $img_url) {
                $aid = $this->sideload_image_to_media(($img_url));
                if ($aid)
                    $attach_ids[] = $aid;
            }
            if (!empty($attach_ids)) {
                $attach_ids = array_values(array_unique(($attach_ids)));
                $product->set_image_id($attach_ids[0]);
                if (count($attach_ids) > 1) {
                    $product->set_gallery_image_ids(array_slice($attach_ids, 1));
                }
            }
        }

        // After the existing image setting block, add this to check and fix duplicates
        $featured_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();

        if (!empty($featured_id) && !empty($gallery_ids)) {
            $first_gallery_id = $gallery_ids[0];
            $url1 = wp_get_attachment_url($featured_id);
            $url2 = wp_get_attachment_url($first_gallery_id);

            if ($url1 && $url2 && $url1 === $url2) {
                // Remove the duplicate by shifting gallery (keep featured, remove first gallery item)
                $new_gallery = array_slice($gallery_ids, 1);
                $product->set_gallery_image_ids($new_gallery);
            }
        }

        

        $item_code = $code;
        
        // --- Item attributes ---
        if (!empty($item_attributes_map) && isset($item_attributes_map[$item_code])) {
            $entries = $item_attributes_map[$item_code];
            // ensure list
            if (!is_array($entries) || (isset($entries['attribute_code']) && isset($entries['value']))){
                $entries = [$entries];
            }

            foreach ($entries as $entry) {
                $attribute_code = isset($entry['attribute_code']) ? trim($entry['attribute_code']) : '';
                $attribute_value = isset($entry['value']) ? trim($entry['value']) : '';

                if ($attribute_code === '' || $attribute_value === '') continue;
                

                $attr_name = $attribute_code;

                // merge into existing attribute if present
                $merged = false;
                foreach ($attrs_array as $existing_attr) {
                    if (is_object($existing_attr) && strcasecmp($existing_attr->get_name(), $attr_name) === 0) {
                        $options = (array) $existing_attr->get_options();
                        if (!in_array($attribute_value, $options, true)) {
                            $options[] = $attribute_value;
                            $existing_attr->set_options($options);
                        }
                        $merged = true;
                        break;
                    }
                }
                if ($merged)  continue;
                
                // create new custom product attribute
                $new_attr = new WC_Product_Attribute();
                $new_attr->set_name($attr_name);
                $new_attr->set_options([$attribute_value]);
                $new_attr->set_visible(true);
                $new_attr->set_variation(false);
                $attrs_array[] = $new_attr;
            }
        }


        // --- Item features (brands) ---
        $brand_term_ids = [];
        if (!empty($item_features_map) && isset($item_features_map[$item_code])) {
            $feature_ids = $item_features_map[$item_code];
            if (!is_array($feature_ids))
                $feature_ids = [$feature_ids];

            foreach ($feature_ids as $fid) {
                if (empty($fid)) continue;

                // $fdef = isset($features_map[$fid]) ? $features_map[$fid] : null;
                $fdef = $features_map[$fid] ?? null;
                $term_name = $fdef['value'] ?? $fdef['description'] ?? $fid;
                $term_slug = sanitize_title($term_name);

                // try to find existing brand term by slug
                $existing = get_term_by('slug', $term_slug, 'product_brand');
                if ($existing && !is_wp_error($existing)) {
                    $term_id = intval($existing->term_id);
                    // update description/name if changed
                    $update_args = [];
                    if (strcmp($existing->name, $term_name) !== 0)
                        $update_args['name'] = $term_name;
                    $fdesc = trim($fdef['description'] ?? '');
                    if ($fdesc !== '' && strcmp($existing->description, $fdesc) !== 0)
                        $update_args['description'] = $fdesc;
                    if (!empty($update_args))
                        wp_update_term($term_id, 'product_brand', $update_args);
                } else {
                    // create new brand term
                    $insert = wp_insert_term($term_name, 'product_brand', ['slug' => $term_slug, 
                    'description' => (isset($fdef['description']) ? $fdef['description'] : '')]);
                    if (is_wp_error($insert)) {
                        $this->log("Failed to create brand term '{$term_name}': " . $insert->get_error_message());
                        continue;
                    }
                    $term_id = intval($insert['term_id']);
                }

                // attach image to term if available 
                // (sideload to media and save thumbnail id)
                if (!empty($fdef['image'])) {
                    $aid = $this->sideload_image_to_media($fdef['image']);
                    if ($aid) {
                        update_term_meta($term_id, 'thumbnail_id', $aid);
                        update_term_meta($term_id, '_plano_feature_image', esc_url_raw($fdef['image']));
                    }
                }

                if (!in_array($term_id, $brand_term_ids, true)){
                    $brand_term_ids[] = $term_id;
                }
            }
            // if product already exists, assign brands now
            $existing_product_id = $product->get_id();
            if ($existing_product_id)
                wp_set_object_terms($existing_product_id, $brand_term_ids, 'product_brand', false);
        }
        
        if (!empty($attrs_array))
            $product->set_attributes($attrs_array);
        
        // Save product
        $product_id = $product->save();
        if (!$product_id) {
            $this->log("Failed saving product SKU={$sku}");
            return 0;
        } else {
            if (function_exists('clean_post_cache')) {
                clean_post_cache($product_id);
            }
            $saved_product = function_exists('wc_get_product')
                ? wc_get_product($product_id)
                : $product;

            if (!$this->product_has_expected_price($saved_product, $price_data)) {
                $this->log(
                    "Failed verifying saved price for SKU={$sku} ID={$product_id}; "
                    . 'the item hash will not be updated.'
                );
                return 0;
            }

            $sale_log = $price_data['sale_price'] === null ? 'none' : $price_data['sale_price'];
            $this->log(
                "Saved product SKU={$sku} ID={$product_id}, "
                . "regular_price={$price_data['price_with_vat']}, sale_price={$sale_log}"
            );
            update_post_meta($product_id, '_plano_imported', 1);
            // ensure categories (if we assigned earlier with product id 0)
            if (isset($term_ids) && !empty($term_ids))
                wp_set_object_terms($product_id, $term_ids, 'product_cat', false);

            if (!empty($brand_term_ids) && !empty($product_id))
                wp_set_object_terms($product_id, $brand_term_ids, 'product_brand', false);
        }
        return intval($product_id);
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
        if (!is_array($data))
            return [];

        $normalized = [];
        foreach ($data as $sku => $hash) {
            $sku = $this->normalize_sku($sku);
            if ($sku !== '' && is_string($hash)) {
                $normalized[$sku] = $hash;
            }
        }
        return $normalized;
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
        $json = 'schema=' . self::HASH_SCHEMA_VERSION . "\x00" . $this->canonical_json($item);
        if ($price_data !== null) {
            $price_json = $this->canonical_json($price_data);
            $json .= "\x00" . $price_json;
        }
        return 'v' . self::HASH_SCHEMA_VERSION . ':' . hash($algo, $json);
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
        $key = $this->normalize_sku($key);
        
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
    public $core;

    private $option_name = 'plano_importer_opts';

    private $defaults = [
        'items_url' => '',
        'series_url' => '',
        'images_url' => '',
        'attributes_url' => '',
        'features_url' => '',
        'prices_url' => '',
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
        $feeds['prices-gr'] = $opts['prices_url'] ?: '';

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
            <?php if (isset($_GET['plano_imported']) && sanitize_key(wp_unslash($_GET['plano_imported'])) === 'failed'): ?>
                <div class="notice notice-error">
                    <p>The import stopped. Review the importer log below.</p>
                </div>
            <?php endif; ?>
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
                        <th>Prices-GR feed URL</th>
                        <td><input type="text" name="prices_url" value="<?php echo esc_attr($opts['prices_url']); ?>"
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
        $fields = [
            'items_url',
            'series_url',
            'images_url',
            'attributes_url',
            'features_url',
            'prices_url',
            'batch',
            'cron_batch'
        ];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $opts[$f] = sanitize_text_field(wp_unslash($_POST[$f]));
                $posted = true;
            }
        }
        if ($posted)
            update_option($this->option_name, $opts);

        // Reset first, then run the requested batch from the start.
        if (isset($_POST['reset_pointer']) && $_POST['reset_pointer']) {
            $this->core->reset_pointer();
        }

        $batch = isset($_POST['batch']) ? intval($_POST['batch']) : intval($opts['batch']);
        if ($batch < 1)
            $batch = 10;

        $result = 'processed';
        try {
            $this->core->do_import_batch($batch);
        } catch (Throwable $e) {
            $result = 'failed';
            $this->core->log('Manual import failed: ' . $e->getMessage());
        }

        // redirect back with notice
        $redirect = add_query_arg(
            'plano_imported',
            $result,
            wp_get_referer() ?: admin_url('tools.php?page=plano-importer')
        );
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
