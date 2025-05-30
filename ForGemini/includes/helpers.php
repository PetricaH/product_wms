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

    // In production, return revved if available
    if (getenv('NODE_ENV') === 'production' && isset($manifest[$path])) {
        return '/dist/' . $manifest[$path];
    }

    // Otherwise fallback to unrevved
    // strip “.min” if you want unminified in dev, but here we assume dev builds .css/.js without .min
    return '/' . $path;
}

/** in_prod(): are we in production mode? */
function in_prod(): bool {
    return getenv('NODE_ENV') === 'production';
}
