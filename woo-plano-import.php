<?php
/**
 * Plugin Name: Woo Plano Importer
 * Description: Import / update WooCommerce products from Plano XML feeds in safe batches. Manual run, cron-safe support.
 * Version: 1.4
 * Author: Lazaros Gogos
 * License: MIT License 
 */
if (!defined("ABSPATH")) {
    exit;
}

include("optimized-updater.php");

/**
 * WP wrapper: admin UI, cron hook, activation/deactivation
 */
class Plano_Importer_Core {

    private $feeds = [];
    private $log_file;
    private $uploads_dir;
    public function __construct($feeds = [])
    {
        // default feed base
        $base = 'https://plano.plus/api/eshop/Feed/GetEshopFeed/05ea5870-0f66-44be-827d-e501879a0330/';
        $defaults = [
            'items' => $base . 'Items',
            'series' => $base . 'ProductSeries',
            'images' => $base . 'ItemImages',
            'attributes' => $base . 'Attributes',
            'features' => $base . 'Features',
        ];
        $this->feeds = wp_parse_args($feeds, $defaults);

        $uploads = wp_get_upload_dir();
        $this->uploads_dir = $uploads['basedir'];
        $this->log_file = trailingslashit($this->uploads_dir) . 'woo-plano-import-GOGOS.log';
    }

    /**
     * Fetch XML from URL and return SimpleXMLElement or false
     */
    public function fetch_url_xml($url) {
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
        foreach ($xml->feature as $f) {
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

    public function get_items_count()
    {
        $xml = $this->fetch_url_xml($this->feeds['items']);
        if (!$xml)
            return 0;
        return count($xml->Item);
    }

    public function do_import_batch($batch = 10)
    {
        if (get_transient('plano_import_lock')) {
            $this->log('Import skipped: lock present');
            return 0;
        }
        set_transient('plano_import_lock', 1, 60 * 30);
        $offset = max(0, intval(get_option('plano_import_offset', 0)));

        // fetch maps once per batch
        $images_map = $this->fetch_images_map();
        $series_map = $this->fetch_series_map();
        $attributes_map = $this->fetch_attributes_map();
        $features_map = $this->fetch_features_map();

        // fetch items feed
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
            // reached end - reset offset and nothing to process
            update_option('plano_import_offset', 0, false);
            $this->log("Pointer was at/after end ({$offset}). Reset to 0.");
            delete_transient(('plano_import_lock'));
            return 0;
        }
        
        $slice = array_slice($items_arr, $offset, $batch);
        $processed = 0;
        foreach ($slice as $item) {
            try {
                $this->process_item($item, $images_map, $series_map);
                $processed++;
            } catch (Exception $e) {
                $this->log("Exception processing item (offset" . ($offset + $processed) . "): " . $e->getMessage());
            }
        }

        // advance pointer; if we reached the end, reset to 0 so next run can cycle
        $new_offset = $offset + $processed;
        if ($new_offset > count($items_arr)) {
            update_option('plano_import_offset', 0, false);
            $this->log("Processed {$processed} items and reached feed and -> pointer reset to 0.");
        } else {
            update_option('plano_import_offset', $new_offset, false);
            $this->log("Batch finished: processed={$processed}, pointer set to {$new_offset}");
        }

        delete_transient('plano_import_lock');
        return $processed;
    }

    public function process_item($item_xml, $images_map = [], 
    $series_map = []){
        if (! function_exists('wc_get_product_id_by_sku')) {
            $this->log('WooCommerce functions not available. Aborting item processing.');
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
                // fallback create new
                $product = new WC_Product_Simple();
                $product->set_sku($sku);
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

        $price = (string) $item_xml->PriceWithVat;
        if ($price === '') $price = (string) $item_xml->NetPrice;
        if ($price !== '') $product->set_regular_price((float) $price);

        $product->set_description($desc);
        $product->set_short_description(wp_trim_words(strip_tags($desc), 30));

        //categories
        $cat_path = (string) $item_xml->CategoryFullPath;
        if ($cat_path) {
            /**
             * Example:
             *  Input: $cat_path = "parent/ child /grandchild/"
             *  Output: $terms = ["parent", "child", "grandchild"]
             */
            $terms = array_filter(array_map('trim', explode('/',$cat_path)));

            $term_ids = [];
            foreach($terms as $t) {
                $term = term_exists($t, 'product_cat');
                if ($term === 0 || $term === null) {
                    $new = wp_insert_term($t, 'product_cat');
                    if (!is_wp_error($new) && isset ($new['term_id'])) $term_ids[] = intval($new['term_id']);
                } else{ 
                    $term_ids[] = is_array($term) ? intval($term['term_id'])  : intval($term);
                }
            }

            if (!empty ($term_ids)){
                // if product exists, use its ID, else we'll assign after save
                $product_id_for_terms = $product->get_id() ?: 0;
                wp_set_object_terms($product_id_for_terms, $term_ids, 'product_cat', false );
            }
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
            $product->set_attributes([$attr]);
        }

        // images
        $code_key = $code;
        if (isset($images_map[$code_key]) && is_array($images_map[ $code_key ])) {
            ksort($images_map[ $code_key ]);
            $attach_ids = [];
            foreach($images_map[ $code_key ] as $order => $img_url) {
                $aid = $this->sideload_image_to_media(($img_url));
                if ($aid) $attach_ids[] = $aid;
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

            if ($url1 === $url2) {
                // Remove the duplicate by shifting gallery (keep featured, remove first gallery item)
                $new_gallery = array_slice($gallery_ids, 1);
                $product->set_gallery_image_ids($new_gallery);
            }
        }

        // Save product
        $product_id = $product->save();
        if (!$product_id) {
            $this->log("Failed saving product SKU={$sku}");
        } else {
            $this->log("Saved product SKU={$sku} ID={$product_id}");
            // ensure categories (if we assigned earlier with product id 0)
            if (isset($term_ids) && !empty($term_ids)) 
                wp_set_object_terms($product_id, $term_ids, 'product_cat', false);
            
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
}
class WP_Woo_Plano_Importer {
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

    public function __construct() {
        $opts = get_option($this->option_name, []);
        $opts = wp_parse_args($opts, $this->defaults);

        // if user didn't set custom URLs, use the core defaults
        $feeds = [];
        $feeds['items'] = $opts['items_url'] ?: '';
        $feeds['series'] = $opts['series_url'] ?:'';
        $feeds['images'] = $opts['images_url'] ?: '';
        $feeds['attributes'] = $opts['attributes_url'] ?: '';
        $feeds['features'] = $opts['features_url'] ?: '';

        // if any feed missing, let core use defaults
        foreach ( $feeds as $k => $v) {
            if (empty ($v)) {
                unset($feeds[$k]);
            }
        }

        $this->core = new Plano_Importer_Core($feeds);
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_post_plano_import_run', [$this,'handle_manual_run']);

        register_activation_hook(__FILE__, [$this,'on_activate']);
        register_deactivation_hook(__FILE__, [$this,'on_deactivate']);

        // more WP-CLI commands if available, omitted here
    }

    public function admin_menu() {
        add_management_page(
            'Plano Importer',
            'Plano Importer',
            'manage_options',
            'plano-importer',
            [$this, 'admin_page']
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        $opts = get_option($this->option_name, []);
        $opts = wp_parse_args($opts, $this->defaults);
        $log_tail = $this->core->get_log_tail(4000);
        ?>
        <div class="wrap">
            <h1>Plano Importer</h1>
            <form method="post" action="<?php echo esc_url(
                admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('plano_import_run'); ?>
                <input type="hidden" name="action" value="plano_import_run" />
                <table class="form-table">
                    <tr>
                        <th>Items feed URL</th>
                        <td><input type="text" name="items_url" value="<?php echo esc_attr( $opts['items_url'] ); ?>" size="80" /></td>
                    </tr>
                    <tr>
                        <th>ProductSeries feed URL</th>
                        <td><input type="text" name="series_url" value="<?php echo esc_attr( $opts['series_url'] ); ?>" size="80" /></td>
                    </tr>
                    <tr>
                        <th>Images feed URL</th>
                        <td><input type="text" name="images_url" value="<?php echo esc_attr( $opts['images_url'] ); ?>" size="80" /></td>
                    </tr>
                    <tr>
                        <th>Attributes feed URL</th>
                        <td><input type="text" name="attributes_url" value="<?php echo esc_attr( $opts['attributes_url'] ); ?>" size="80" /></td>
                    </tr>
                    <tr>
                        <th>Features feed URL</th>
                        <td><input type="text" name="features_url" value="<?php echo esc_attr( $opts['features_url'] ); ?>" size="80" /></td>
                    </tr>
                    <tr>
                        <th>Manual batch size</th>
                        <td><input type="number" name="batch" value="<?php echo esc_attr( $opts['batch'] ); ?>" min="1" max="500" /></td>
                    </tr>
                    <tr>
                        <th>Cron batch size (per daily run) - DEPRECATED</th>
                        <td><input type="number" name="cron_batch" value="<?php echo esc_attr( $opts['cron_batch'] ); ?>" min="1" max="1000" /></td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save & Run" />
                    &nbsp;&nbsp;
                    <label style="font-weight:normal;"><input type="checkbox" name="reset_pointer" value="1" /> Reset pointer to start</label>
                </p>
            </form>
            <h2>Logs (last lines)</h2>
            <pre style="max-height:300px; overflow:auto; padding:10px; background:#fff; border:1px solid #ddd;"><?php echo esc_html( $log_tail ); ?></pre>

            <h2>Manual actions</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'plano_import_run' ); ?>
                <input type="hidden" name="action" value="plano_import_run" />
                <input type="hidden" name="batch" value="<?php echo esc_attr( $opts['batch'] ); ?>" />
                <p>
                    <button class="button button-primary" type="submit">Run one batch now (<?php echo intval( $opts['batch'] ); ?>)</button>
                    &nbsp;
                    <label><input type="checkbox" name="reset_pointer" value="1" /> Reset pointer first</label>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_manual_run() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('plano_import_run');

        $opts = get_option($this->option_name, []);
        $opts = wp_parse_args($opts, $this->defaults);

        // save posted URLs / settings if present
        $posted = false;
        $fields = ['items_url', 'series_url', 'images_url', 'attributes_url', 'features_url', 'batch', 'cron_batch'];
        foreach( $fields as $f ) {
            if (isset($_POST[$f])) {
                $opts[$f] = sanitize_text_field(wp_unslash($_POST[$f]));
                $posted = true;
            }
        }
        if ($posted) update_option($this->option_name, $opts);

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

    public function on_activate() {}
    public function on_deactivate() {}

}
$WP_Woo_Plano_Importer = new WP_Woo_Plano_Importer();