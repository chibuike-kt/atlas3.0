<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Atlas API Routes
|--------------------------------------------------------------------------
| All routes are prefixed with /api automatically via bootstrap/app.php
| Routes will be added here as each layer is built.
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
  return response()->json([
    'success' => true,
    'message' => 'Atlas API is running.',
    'data'    => [
      'version'   => config('atlas.version'),
      'tagline'   => config('atlas.tagline'),
      'timestamp' => now()->toISOString(),
      'timezone'  => config('app.timezone'),
    ],
  ]);
});
