<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — marketing front door + SPAs
|
| The site is three first-class surfaces, all served from /public:
|   /           → landing.html      (marketing + public plan catalogue + signup)
|   /app        → app/index.html    (tenant SPA)
|   /app/admin.html → platform console (super-admin SPA)
|
| Routes (rather than raw static files only) so the same code paths work
| under `php artisan serve`, real web servers AND the HTTP feature tests.
|--------------------------------------------------------------------------
*/

$serveFile = function (string $absolutePath, string $contentType) {
    abort_unless(is_file($absolutePath), 404);
    return response(file_get_contents($absolutePath), 200, ['Content-Type' => $contentType]);
};

$landing = fn () => $serveFile(public_path('landing.html'), 'text/html; charset=UTF-8');
Route::get('/', $landing);
Route::get('/landing.html', $landing);

// Tenant + platform SPA shells
Route::get('/app', fn () => redirect('/app/index.html'));
Route::get('/app/index.html', fn () => $serveFile(public_path('app/index.html'), 'text/html; charset=UTF-8'));
Route::get('/app/admin.html', fn () => $serveFile(public_path('app/admin.html'), 'text/html; charset=UTF-8'));

// SPA assets (JS/CSS) — path is confined to public/app with a realpath guard.
Route::get('/app/assets/{file}', function (string $file) use ($serveFile) {
    $base = realpath(public_path('app/assets'));
    $path = realpath(public_path('app/assets/'.$file));
    abort_unless($path && $base && str_starts_with($path, $base), 404);

    $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'js'    => 'application/javascript; charset=UTF-8',
        'css'   => 'text/css; charset=UTF-8',
        'html'  => 'text/html; charset=UTF-8',
        'json'  => 'application/json; charset=UTF-8',
        default => 'application/octet-stream',
    };

    return $serveFile($path, $mime);
})->where('file', '.+');
