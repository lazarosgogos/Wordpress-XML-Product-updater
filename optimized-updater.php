<?php
/**
 * Normalize an item for stable serialization (recursive key sort).
 * Returns a value ready for json_encode with keys ordered deterministically.
 */
function normalize_item(mixed $item): mixed
{
    if (is_object($item)) {
        $item = (array) $item;
    }
    if (is_array($item)) {
        // Sort associative arrays by key and normalize values recursively.
        $isAssoc = array_keys($item) !== range(0, count($item) - 1);
        if ($isAssoc) {
            ksort($item);
        }
        foreach ($item as $k => $v) {
            $item[$k] = normalize_item($v);
        }
        return $item;
    }
    // Scalars (int/float/string/bool/null) are returned as-is.
    return $item;
}

/**
 * Produce a canonical JSON string for an item (stable across runs).
 */
function canonical_json(mixed $item): string
{
    $normalized = normalize_item($item);
    // JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES keeps it readable; JSON_PRESERVE_ZERO_FRACTION keeps numbers stable.
    return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/**
 * Compute a strong hash for an item using canonical serialization.
 * Default: SHA-256 hex digest.
 */
function compute_item_hash(mixed $item, string $algo = 'sha256'): string
{
    $json = canonical_json($item);
    return hash($algo, $json);
}

/**
 * Given a list of items, return an associative map of key => hash.
 *
 * $items: array of items (arrays or objects)
 * $keyField: optional string field name to use as the map key (e.g. 'sku' or 'id').
 *           If null, numeric indexes (0,1,2...) are used as keys.
 *
 * Returns: [ key => hash ]
 */
function create_hashed_list(array $items, ?string $keyField = null, string $algo = 'sha256'): array
{
    $result = [];
    foreach ($items as $index => $item) {
        $key = $index;
        if ($keyField !== null) {
            if (is_array($item) && array_key_exists($keyField, $item)) {
                $key = (string) $item[$keyField];
            } elseif (is_object($item) && property_exists($item, $keyField)) {
                $key = (string) $item->{$keyField};
            } else {
                // fallback: use index if keyField missing
                $key = $index;
            }
        }
        $result[$key] = compute_item_hash($item, $algo);
    }
    return $result;
}

/**
 * Compare two hashed lists (old vs new) and return added/removed/changed/unchanged keys.
 *
 * $old: [ key => hash ] from previous run (may be empty)
 * $new: [ key => hash ] from current run
 *
 * Returns:
 * [
 *   'added'   => [ keys ],
 *   'removed' => [ keys ],
 *   'changed' => [ keys ],
 *   'unchanged'=> [ keys ]
 * ]
 */
function diff_hashed_lists(array $old, array $new): array
{
    $oldKeys = array_keys($old);
    $newKeys = array_keys($new);

    $added = array_values(array_diff($newKeys, $oldKeys));
    $removed = array_values(array_diff($oldKeys, $newKeys));

    $common = array_values(array_intersect($oldKeys, $newKeys));
    $changed = [];
    $unchanged = [];
    foreach ($common as $k) {
        if (!hash_equals((string) $old[$k], (string) $new[$k])) {
            $changed[] = $k;
        } else {
            $unchanged[] = $k;
        }
    }

    return [
        'added' => $added,
        'removed' => $removed,
        'changed' => $changed,
        'unchanged' => $unchanged,
    ];
}

/**
 * Filter the original item list to return only items that are different compared to old hash map.
 *
 * $items: original items array
 * $oldHashes: [ key => hash ] (previous day's)
 * $keyField: field to use as item key (or null for indexes)
 *
 * Returns: ['added' => [items], 'removed_keys' => [keys], 'changed' => [items]]
 */
function filter_changed_items(array $items, array $oldHashes, ?string $keyField = null, string $algo = 'sha256'): array
{
    $newHashes = create_hashed_list($items, $keyField, $algo);
    $diff = diff_hashed_lists($oldHashes, $newHashes);

    // Build quick map from key => item for new items/changed items
    $keyToItem = [];
    foreach ($items as $index => $item) {
        $k = $index;
        if ($keyField !== null) {
            if (is_array($item) && array_key_exists($keyField, $item)) {
                $k = (string) $item[$keyField];
            } elseif (is_object($item) && property_exists($item, $keyField)) {
                $k = (string) $item->{$keyField};
            } else {
                $k = $index;
            }
        }
        $keyToItem[$k] = $item;
    }

    $addedItems = [];
    foreach ($diff['added'] as $k) {
        if (isset($keyToItem[$k]))
            $addedItems[] = $keyToItem[$k];
    }

    $changedItems = [];
    foreach ($diff['changed'] as $k) {
        if (isset($keyToItem[$k]))
            $changedItems[] = $keyToItem[$k];
    }

    return [
        'added' => $addedItems,
        'removed_keys' => $diff['removed'],
        'changed' => $changedItems,
    ];
}

/**
 * Persist hash map to disk as JSON (atomic-safe write).
 * Returns true on success, false on failure.
 */
function save_hash_map_to_file(array $hashMap, string $path): bool
{
    $tmp = $path . '.tmp';
    $json = json_encode($hashMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false)
        return false;
    if (file_put_contents($tmp, $json) === false)
        return false;
    return rename($tmp, $path);
}

/**
 * Load hash map from disk; returns [] if file missing or invalid.
 */
function load_hash_map_from_file(string $path): array
{
    if (!is_readable($path))
        return [];
    $json = file_get_contents($path);
    if ($json === false)
        return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}
