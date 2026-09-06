<?php

declare(strict_types=1);

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
|
| The dashboard is a React SPA; the server hands over one HTML document and
| React Router owns every path from there. All data goes through
| /api/admin/v1.
|
| The catch-all deliberately excludes the API and Sanctum prefixes so a
| mistyped API path returns a JSON 404 rather than the SPA shell with a 200 —
| which is a genuinely confusing thing to debug against a device.
|
*/

Route::get('/{any?}', SpaController::class)
    ->where('any', '^(?!api|sanctum|broadcasting|storage|build|up).*$')
    ->name('spa');
