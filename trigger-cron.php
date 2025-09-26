<?php
// trigger-cron.php
// Place this in your WP root (same folder as wp-load.php).
// Call like: https://mydomain.gr/trigger-cron.php?key=YOUR_SECRET&batch=50

// quick safety
@set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/wp-load.php';

// allow only GET/HEAD (simple)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ( ! in_array( $method, ['GET','HEAD'], true ) ) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// read params
$key   = isset($_GET['key']) ? trim( $_GET['key'] ) : '';
$batch = isset($_GET['batch']) ? intval($_GET['batch']) : null;
$reset = isset($_GET['reset']) ? boolval(intval($_GET['reset'])) : false;

// validate secret stored in options
$expected = get_option( 'plano_cron_key_secret', '' );
if ( empty( $expected ) ) {
    // No secret configured — deny for safety
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([ 'ok' => false, 'error' => 'No secret configured on site' ]);
    exit;
}
if ( ! hash_equals( $expected, $key ) ) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([ 'ok' => false, 'error' => 'Unauthorized' ]);
    exit;
}

// find importer core
$core = null;
if ( isset( $GLOBALS['WP_Woo_Plano_Importer'] ) && isset( $GLOBALS['WP_Woo_Plano_Importer']->core ) ) {
    $core = $GLOBALS['WP_Woo_Plano_Importer']->core;
} elseif ( class_exists( 'Plano_Importer_Core' ) ) {
    // instantiate with defaults (safe)
    $core = new Plano_Importer_Core();
} else {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([ 'ok' => false, 'error' => 'Importer not available (plugin inactive?)' ]);
    exit;
}

// optional reset pointer
if ( $reset ) {
    if ( method_exists( $core, 'reset_pointer' ) ) {
        $core->reset_pointer();
    } else {
        update_option( 'plano_import_offset', 0, false );
    }
}

// normalize batch
if ( $batch === null ) {
    $batch = intval( get_option( 'plano_importer_opts', [] )['cron_batch'] ?? 50 );
}
$batch = max(1, min(500, intval($batch)));

// check for lock transient to be extra-safe (core also checks)
if ( get_transient( 'plano_import_lock' ) ) {
    header('Content-Type: application/json');
    echo json_encode([ 'ok' => false, 'error' => 'Import locked (another job running)' ]);
    exit;
}

// run batch and capture result
try {
    $processed = 0;
    if ( method_exists( $core, 'do_import_batch' ) ) {
        $processed = intval( $core->do_import_batch( $batch ) );
    } else {
        throw new Exception('do_import_batch method missing');
    }

    // read offset for status if present
    $offset = get_option( 'plano_import_offset', null );

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'processed' => $processed,
        'batch_requested' => $batch,
        'offset' => $offset,
        'time' => date_i18n('Y-m-d H:i:s'),
    ]);
    exit;
} catch ( Exception $e ) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([ 'ok' => false, 'error' => $e->getMessage() ]);
    exit;
}
