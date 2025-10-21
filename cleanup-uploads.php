<?php
// cleanup-uploads.php
// Usage (GET):
//  ?secret=CHANGE_THIS_SECRET&action=preview   -> list what would be removed
//  ?secret=CHANGE_THIS_SECRET&action=delete    -> perform deletion
// Run once, then delete this file.

error_reporting(E_ALL);
ini_set('display_errors', 1);

// bootstrap WP
$boot = dirname(__FILE__) . '/wp-load.php';
require_once $boot;
if (php_sapi_name() !== 'cli' && empty($_GET['secret'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$SECRET = get_option('plano_cron_key_secret');

$action = isset($_GET['action']) ? $_GET['action'] : 'preview';
$batch = isset($_GET['batch']) ? max(1, intval($_GET['batch'])) : 200;

if ( ! isset($_GET['secret']) || $_GET['secret'] !== $SECRET ) {
    http_response_code(403);
    echo 'Forbidden (bad secret)';
    exit;
}

if ( ! file_exists( $boot ) ) {
    header('Content-Type: application/json');
    echo json_encode([ 'error' => 'Cannot find wp-load.php. Place this file in WP root.' ], JSON_PRETTY_PRINT);
    exit;
}
if ( ! defined('ABSPATH') ) {
    header('Content-Type: application/json');
    echo json_encode([ 'error' => 'Failed loading WordPress.' ], JSON_PRETTY_PRINT);
    exit;
}

global $wpdb;
set_time_limit(0);
ini_set('memory_limit','512M');

// Configuration: folder to target (relative inside uploads)
$relative_folder = '2025/09';
$upload_dir = wp_get_upload_dir();
$full_folder = trailingslashit( $upload_dir['basedir'] ) . $relative_folder;

// Option name to store offset
$option_name = 'plano_cleanup_offset_2025_09';
$offset = intval( get_option( $option_name, 0 ) );

// Find all attachment IDs that reference the folder (guid, _wp_attached_file or plano meta)
$sql = $wpdb->prepare(
    "SELECT DISTINCT p.ID
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm_att ON p.ID = pm_att.post_id AND pm_att.meta_key = '_wp_attached_file'
     LEFT JOIN {$wpdb->postmeta} pm_plano ON p.ID = pm_plano.post_id AND pm_plano.meta_key = '_plano_original_url'
     WHERE p.post_type = 'attachment'
       AND (
           p.guid LIKE %s
           OR pm_att.meta_value LIKE %s
           OR pm_plano.meta_value LIKE %s
       )
     ORDER BY p.ID ASC",
    '%' . $wpdb->esc_like( '/wp-content/uploads/' . $relative_folder ) . '%',
    $relative_folder . '%',
    '%' . $relative_folder . '%'
);

$all_ids = $wpdb->get_col( $sql );
$all_ids = array_map('intval', array_filter($all_ids));
$total = count($all_ids);

// Helper to attempt to remove the folder if empty
function maybe_remove_folder($path){
    if ( ! is_dir($path) ) return false;
    // try rmdir if empty
    if ( count(scandir($path)) <= 2 ) return @rmdir($path);
    // otherwise attempt recursive removal only if empty after deletions (guarded)
    $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){
        // do not unlink here: deletions should have removed files via wp_delete_attachment
        // attempt to remove leftover files if writable
        if ($file->isFile() && is_writable($file->getRealPath())) @unlink($file->getRealPath());
        if ($file->isDir()) @rmdir($file->getRealPath());
    }
    return @rmdir($path);
}

header('Content-Type: application/json');

if ( $action === 'reset' ) {
    update_option( $option_name, 0, false );
    echo json_encode([ 'status' => 'ok', 'message' => 'Offset reset to 0.' ], JSON_PRETTY_PRINT);
    exit;
}

// Determine slice for this run
if ( $offset >= $total ) $offset = 0; // safety
$to_process = array_slice( $all_ids, $offset, $batch );

if ( $action === 'preview' ) {
    $next_ids_preview = array_slice( $all_ids, $offset, min(50, $batch) ); // limit preview size
    echo json_encode([
        'found_attachment_count' => $total,
        'offset' => $offset,
        'batch' => $batch,
        'next_count' => count($to_process),
        'next_ids_preview' => $next_ids_preview,
        'target_filesystem_path' => $full_folder,
        'folder_exists' => is_dir($full_folder),
    ], JSON_PRETTY_PRINT);
    exit;
}

if ( $action === 'delete' ) {
    if ( $total === 0 ) {
        echo json_encode([ 'status' => 'ok', 'message' => 'No attachments found for target folder.', 'total' => 0 ], JSON_PRETTY_PRINT);
        exit;
    }

    $deleted = [];
    $failed = [];
    foreach ( $to_process as $aid ) {
        if ( $aid <= 0 ) continue;
        // attempt deletion via WP API
        $ok = wp_delete_attachment( $aid, true ); // true = force delete files
        if ( $ok ) $deleted[] = $aid;
        else $failed[] = $aid;
    }

    $deleted_count = count($deleted);
    $new_offset = $offset + count($to_process);
    $finished = false;
    if ( $new_offset >= $total ) {
        // we've processed all known attachments; reset offset to 0
        update_option( $option_name, 0, false );
        $finished = true;
        // attempt to clean leftover plano meta referencing the folder
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_plano_original_url','_plano_file_hash') AND meta_value LIKE %s",
            '%' . $wpdb->esc_like( $relative_folder ) . '%'
        ) );
        // try to remove the folder if possible
        $folder_removed = maybe_remove_folder( $full_folder );
    } else {
        update_option( $option_name, $new_offset, false );
        $folder_removed = false;
    }

    echo json_encode([
        'status' => 'ok',
        'total_found' => $total,
        'offset_before' => $offset,
        'processed_requested' => count($to_process),
        'deleted_count' => $deleted_count,
        'deleted_ids' => $deleted,
        'failed_ids' => $failed,
        'offset_after' => $finished ? 0 : $new_offset,
        'finished' => $finished,
        'folder_removed' => isset($folder_removed) ? $folder_removed : false,
    ], JSON_PRETTY_PRINT);
    exit;
}

// unknown action
http_response_code(400);
echo json_encode([ 'error' => 'Unknown action' ], JSON_PRETTY_PRINT);
exit;

// $action = isset($_GET['action']) ? $_GET['action'] : 'preview';
// if (!isset($_GET['secret']) || $_GET['secret'] !== $SECRET) {
//     http_response_code(403);
//     echo 'Forbidden (bad secret)';
//     exit;
// }

// // bootstrap WP
// $boot = dirname(__FILE__) . '/wp-load.php';
// if (!file_exists($boot)) {
//     echo "Cannot find wp-load.php. Place this file in WP root.\n";
//     exit;
// }
// require_once $boot;
// if (!defined('ABSPATH')) {
//     echo "Failed loading WordPress.\n";
//     exit;
// }

// global $wpdb;
// set_time_limit(0);
// ini_set('memory_limit', '512M');

// $relative_folder = '2025/09';
// $upload_dir = wp_get_upload_dir();
// $full_folder = trailingslashit($upload_dir['basedir']) . $relative_folder;

// // find attachments referencing that folder (guid or _wp_attached_file or plano meta)
// $sql = $wpdb->prepare(
//     "
//     SELECT p.ID
//     FROM {$wpdb->posts} p
//     LEFT JOIN {$wpdb->postmeta} pm_att ON p.ID = pm_att.post_id AND pm_att.meta_key = '_wp_attached_file'
//     LEFT JOIN {$wpdb->postmeta} pm_plano ON p.ID = pm_plano.post_id AND pm_plano.meta_key = '_plano_original_url'
//     WHERE p.post_type = 'attachment'
//       AND (
//           p.guid LIKE %s
//           OR pm_att.meta_value LIKE %s
//           OR pm_plano.meta_value LIKE %s
//       )
// ",
//     '%' . $wpdb->esc_like('/wp-content/uploads/' . $relative_folder) . '%',
//     $relative_folder . '%',
//     '%' . $relative_folder . '%'
// );

// $ids = $wpdb->get_col($sql);
// $ids = array_map('intval', array_filter($ids));

// $result = [
//     'found_attachment_count' => count($ids),
//     'attachment_ids' => $ids,
//     'target_filesystem_path' => $full_folder,
//     'folder_exists' => is_dir($full_folder),
// ];

// if ($action === 'preview') {
//     header('Content-Type: application/json');
//     echo json_encode($result, JSON_PRETTY_PRINT);
//     exit;
// }

// // action == delete
// $deleted_attachments = [];
// $failed_attachments = [];
// if (!empty($ids)) {
//     foreach ($ids as $aid) {
//         // use WP function to delete attachment and files & postmeta
//         $ok = wp_delete_attachment($aid, true); // true = force delete physical files
//         if ($ok)
//             $deleted_attachments[] = $aid;
//         else
//             $failed_attachments[] = $aid;
//     }
// }

// // also remove any leftover plano meta rows referencing that folder (defensive)
// $wpdb->query($wpdb->prepare(
//     "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_plano_original_url','_plano_file_hash') AND meta_value LIKE %s",
//     '%' . $wpdb->esc_like($relative_folder) . '%'
// ));

// // attempt to remove the folder recursively if it still exists
// $filesystem_deleted = false;
// function rrmdir($dir)
// {
//     if (!is_dir($dir))
//         return false;
//     $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
//     $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
//     foreach ($files as $file) {
//         if ($file->isDir())
//             rmdir($file->getRealPath());
//         else
//             unlink($file->getRealPath());
//     }
//     return rmdir($dir);
// }
// if (is_dir($full_folder)) {
//     $filesystem_deleted = rrmdir($full_folder);
// }

// $response = [
//     'deleted_attachments' => $deleted_attachments,
//     'failed_attachments' => $failed_attachments,
//     'plano_meta_deleted_matching_folder' => true,
//     'folder_deleted' => $filesystem_deleted,
// ];

// header('Content-Type: application/json');
// echo json_encode($response, JSON_PRETTY_PRINT);
// exit;
