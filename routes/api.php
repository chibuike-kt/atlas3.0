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


/*
|--------------------------------------------------------------------------
| Step 5 — Accounts & Transactions (added after Mono integration)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->group(function () {

  // Accounts
  Route::prefix('accounts')->group(function () {
    Route::get('/',              [\App\Http\Controllers\Api\AccountController::class, 'index']);
    Route::post('/link',         [\App\Http\Controllers\Api\AccountController::class, 'link']);
    Route::get('/{id}',          [\App\Http\Controllers\Api\AccountController::class, 'show']);
    Route::post('/{id}/sync',    [\App\Http\Controllers\Api\AccountController::class, 'sync']);
    Route::post('/{id}/primary', [\App\Http\Controllers\Api\AccountController::class, 'setPrimary']);
    Route::delete('/{id}',       [\App\Http\Controllers\Api\AccountController::class, 'unlink']);
  });

  // Transactions
  Route::prefix('transactions')->group(function () {
    Route::get('/',          [\App\Http\Controllers\Api\TransactionController::class, 'index']);
    Route::get('/summary',   [\App\Http\Controllers\Api\TransactionController::class, 'summary']);
    Route::get('/{id}',      [\App\Http\Controllers\Api\TransactionController::class, 'show']);
  });
});


/*
|--------------------------------------------------------------------------
| Step 6 — Financial Intelligence
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('financial-profile')->group(function () {
  Route::get('/',            [\App\Http\Controllers\Api\FinancialProfileController::class, 'show']);
  Route::post('/refresh',    [\App\Http\Controllers\Api\FinancialProfileController::class, 'refresh']);
  Route::get('/projection',  [\App\Http\Controllers\Api\FinancialProfileController::class, 'projection']);
  Route::get('/idle-cash',   [\App\Http\Controllers\Api\FinancialProfileController::class, 'idleCash']);
});


/*
|--------------------------------------------------------------------------
| Step 7 — Advisory Insights
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('insights')->group(function () {
  Route::get('/',              [\App\Http\Controllers\Api\InsightController::class, 'index']);
  Route::get('/summary',       [\App\Http\Controllers\Api\InsightController::class, 'summary']);
  Route::post('/refresh',      [\App\Http\Controllers\Api\InsightController::class, 'refresh']);
  Route::post('/read-all',     [\App\Http\Controllers\Api\InsightController::class, 'markAllRead']);
  Route::post('/{id}/read',    [\App\Http\Controllers\Api\InsightController::class, 'markRead']);
  Route::post('/{id}/action',  [\App\Http\Controllers\Api\InsightController::class, 'action']);
  Route::delete('/{id}',       [\App\Http\Controllers\Api\InsightController::class, 'dismiss']);
});


/*
|--------------------------------------------------------------------------
| Step 8 — Rules Engine
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('rules')->group(function () {
  Route::get('/',                  [\App\Http\Controllers\Api\RuleController::class, 'index']);
  Route::post('/',                 [\App\Http\Controllers\Api\RuleController::class, 'store']);
  Route::post('/parse',            [\App\Http\Controllers\Api\RuleController::class, 'parse']);
  Route::get('/{id}',              [\App\Http\Controllers\Api\RuleController::class, 'show']);
  Route::put('/{id}',              [\App\Http\Controllers\Api\RuleController::class, 'update']);
  Route::delete('/{id}',           [\App\Http\Controllers\Api\RuleController::class, 'destroy']);
  Route::post('/{id}/pause',       [\App\Http\Controllers\Api\RuleController::class, 'pause']);
  Route::post('/{id}/resume',      [\App\Http\Controllers\Api\RuleController::class, 'resume']);
  Route::get('/{id}/executions',   [\App\Http\Controllers\Api\RuleController::class, 'executions']);
});


/*
|--------------------------------------------------------------------------
| Step 10 — Executions & Receipts
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->group(function () {

  // Executions
  Route::prefix('executions')->group(function () {
    Route::get('/',                  [\App\Http\Controllers\Api\ExecutionController::class, 'index']);
    Route::get('/{id}',              [\App\Http\Controllers\Api\ExecutionController::class, 'show']);
    Route::post('/trigger/{ruleId}', [\App\Http\Controllers\Api\ExecutionController::class, 'trigger']);
    Route::get('/{id}/receipt',      [\App\Http\Controllers\Api\ExecutionController::class, 'receipt']);
  });

  // Receipts
  Route::prefix('receipts')->group(function () {
    Route::get('/',      [\App\Http\Controllers\Api\ExecutionController::class, 'receipts']);
    Route::get('/{id}',  [\App\Http\Controllers\Api\ExecutionController::class, 'showReceipt']);
  });

  Route::middleware('auth:api')->group(function () {

    // Disputes
    Route::prefix('disputes')->group(function () {
      Route::get('/',                  [\App\Http\Controllers\Api\DisputeController::class, 'index']);
      Route::post('/',                 [\App\Http\Controllers\Api\DisputeController::class, 'store']);
      Route::get('/{id}',              [\App\Http\Controllers\Api\DisputeController::class, 'show']);
      Route::post('/{id}/evidence',    [\App\Http\Controllers\Api\DisputeController::class, 'addEvidence']);
      Route::post('/{id}/cancel',      [\App\Http\Controllers\Api\DisputeController::class, 'cancel']);
    });

    // Contacts
    Route::prefix('contacts')->group(function () {
      Route::get('/',                  [\App\Http\Controllers\Api\ContactController::class, 'index']);
      Route::post('/',                 [\App\Http\Controllers\Api\ContactController::class, 'store']);
      Route::post('/resolve',          [\App\Http\Controllers\Api\ContactController::class, 'resolve']);
      Route::get('/{id}',              [\App\Http\Controllers\Api\ContactController::class, 'show']);
      Route::put('/{id}',              [\App\Http\Controllers\Api\ContactController::class, 'update']);
      Route::delete('/{id}',           [\App\Http\Controllers\Api\ContactController::class, 'destroy']);
      Route::post('/{id}/favourite',   [\App\Http\Controllers\Api\ContactController::class, 'toggleFavourite']);
    });

    // Wallet
    Route::prefix('wallet')->group(function () {
      Route::get('/',                  [\App\Http\Controllers\Api\WalletController::class, 'index']);
      Route::get('/rates',             [\App\Http\Controllers\Api\WalletController::class, 'rates']);
      Route::get('/{network}',         [\App\Http\Controllers\Api\WalletController::class, 'show']);
      Route::post('/{network}/withdraw', [\App\Http\Controllers\Api\WalletController::class, 'withdraw']);
    });

    /*
|--------------------------------------------------------------------------
| Step 12 — NLP Chat Interface
|--------------------------------------------------------------------------
*/
    Route::middleware('auth:api')->prefix('chat')->group(function () {
      Route::post('/',          [\App\Http\Controllers\Api\ChatController::class, 'message']);
      Route::get('/history',    [\App\Http\Controllers\Api\ChatController::class, 'history']);
      Route::delete('/history', [\App\Http\Controllers\Api\ChatController::class, 'clearHistory']);
      Route::get('/starters',   [\App\Http\Controllers\Api\ChatController::class, 'starters']);
    });

    /*--------------------------------------------------------------------------
| Step 13 — Salary Advance
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('advance')->group(function () {
    Route::get('/eligibility', [\App\Http\Controllers\Api\SalaryAdvanceController::class, 'eligibility']);
    Route::post('/request',    [\App\Http\Controllers\Api\SalaryAdvanceController::class, 'request']);
    Route::get('/',            [\App\Http\Controllers\Api\SalaryAdvanceController::class, 'index']);
    Route::get('/{id}',        [\App\Http\Controllers\Api\SalaryAdvanceController::class, 'show']);
});

    /*
|--------------------------------------------------------------------------
| Step 14 — Bill Payments
|--------------------------------------------------------------------------
*/
    Route::middleware('auth:api')->prefix('bills')->group(function () {
      Route::get('/providers',           [\App\Http\Controllers\Api\BillPaymentController::class, 'providers']);
      Route::get('/variations/{serviceId}', [\App\Http\Controllers\Api\BillPaymentController::class, 'variations']);
      Route::post('/verify',             [\App\Http\Controllers\Api\BillPaymentController::class, 'verify']);
      Route::post('/pay',                [\App\Http\Controllers\Api\BillPaymentController::class, 'pay']);
      Route::get('/history',             [\App\Http\Controllers\Api\BillPaymentController::class, 'history']);
      Route::get('/history/{id}',        [\App\Http\Controllers\Api\BillPaymentController::class, 'show']);
    });

    /*
|--------------------------------------------------------------------------
| Step 15 — Admin Panel
|--------------------------------------------------------------------------
*/
    Route::middleware(['auth:api', 'admin'])->prefix('admin')->group(function () {

      // Dashboard
      Route::get('/dashboard',                    [\App\Http\Controllers\Admin\AdminDashboardController::class, 'index']);
      Route::get('/dashboard/executions',         [\App\Http\Controllers\Admin\AdminDashboardController::class, 'recentExecutions']);
      Route::get('/dashboard/advances',           [\App\Http\Controllers\Admin\AdminDashboardController::class, 'advances']);

      // Users
      Route::get('/users',                        [\App\Http\Controllers\Admin\AdminUserController::class, 'index']);
      Route::get('/users/{id}',                   [\App\Http\Controllers\Admin\AdminUserController::class, 'show']);
      Route::post('/users/{id}/suspend',          [\App\Http\Controllers\Admin\AdminUserController::class, 'suspend']);
      Route::post('/users/{id}/unsuspend',        [\App\Http\Controllers\Admin\AdminUserController::class, 'unsuspend']);
      Route::post('/users/{id}/make-admin',       [\App\Http\Controllers\Admin\AdminUserController::class, 'makeAdmin']);
      Route::delete('/users/{id}/make-admin',     [\App\Http\Controllers\Admin\AdminUserController::class, 'revokeAdmin']);

      // Disputes
      Route::get('/disputes',                     [\App\Http\Controllers\Admin\AdminDisputeController::class, 'index']);
      Route::get('/disputes/{id}',                [\App\Http\Controllers\Admin\AdminDisputeController::class, 'show']);
      Route::post('/disputes/{id}/review',        [\App\Http\Controllers\Admin\AdminDisputeController::class, 'review']);
      Route::post('/disputes/{id}/resolve',       [\App\Http\Controllers\Admin\AdminDisputeController::class, 'resolve']);

      // System Settings
      Route::get('/settings',                     [\App\Http\Controllers\Admin\AdminSystemSettingController::class, 'index']);
      Route::get('/settings/{key}',               [\App\Http\Controllers\Admin\AdminSystemSettingController::class, 'show']);
      Route::put('/settings/{key}',               [\App\Http\Controllers\Admin\AdminSystemSettingController::class, 'update']);
      Route::put('/settings',                     [\App\Http\Controllers\Admin\AdminSystemSettingController::class, 'bulkUpdate']);
    });
  });
});
