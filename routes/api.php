<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Atlas API Routes
|--------------------------------------------------------------------------
| All routes are automatically prefixed with /api via bootstrap/app.php
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

Route::get('/settings/public', function () {
  return response()->json([
    'success' => true,
    'message' => 'Public settings retrieved.',
    'data'    => \App\Models\SystemSetting::getPublicSettings(),
  ]);
});

Route::prefix('auth')->group(function () {
  Route::post('/register', [AuthController::class, 'register']);
  Route::post('/login',    [AuthController::class, 'login']);
  Route::post('/refresh',  [AuthController::class, 'refresh']);
});

Route::middleware('auth:api')->group(function () {

  Route::prefix('auth')->group(function () {
    Route::get('/me',          [AuthController::class, 'me']);
    Route::post('/logout',     [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/verify-pin', [AuthController::class, 'verifyPin']);
  });

  Route::prefix('profile')->group(function () {
    Route::put('/',                 [ProfileController::class, 'update']);
    Route::put('/password',         [ProfileController::class, 'changePassword']);
    Route::put('/pin',              [ProfileController::class, 'changePin']);
    Route::get('/sessions',         [ProfileController::class, 'sessions']);
    Route::delete('/sessions/{id}', [ProfileController::class, 'revokeSession']);
  });
});

Route::prefix('webhooks')->middleware('mono.webhook')->group(function () {
  Route::post('/mono', [WebhookController::class, 'mono']);
});
