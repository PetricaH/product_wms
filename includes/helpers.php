<?php
/**
 * asset(): map a logical path to its revved filename (if any).
 * Usage: asset('styles/global.min.css') → '/dist/styles/global.min-<hash>.css'
 *        asset('styles/global.css')     → '/styles/global.css'
 */
function asset(string $path): string {
    static $manifest = null;
    $manifestFile = __DIR__ . '/../dist/manifest/rev-manifest.json';

    if ($manifest === null) {
        if (is_readable($manifestFile)) {
            $manifest = json_decode(file_get_contents($manifestFile), true);
        } else {
            $manifest = [];
        }
    }

    // Get the base URL for the project
    $baseUrl = '/product_wms';  // Add this line
    
    // In production, return revved if available
    if (getenv('NODE_ENV') === 'production' && isset($manifest[$path])) {
        return $baseUrl . '/dist/' . $manifest[$path];  // Updated this line
    }

    // Otherwise fallback to unrevved
    return $baseUrl . '/' . $path;  // Updated this line
}

/** in_prod(): are we in production mode? */
function in_prod(): bool {
    return getenv('NODE_ENV') === 'production';
}
