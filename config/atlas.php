<?php

return [

  /*
    |--------------------------------------------------------------------------
    | Atlas Core Configuration
    |--------------------------------------------------------------------------
    */

  'name'    => env('APP_NAME', 'Atlas'),
  'version' => '3.0.0',

  /*
    |--------------------------------------------------------------------------
    | Fee Structure
    |--------------------------------------------------------------------------
    | All monetary amounts stored in Kobo (100 kobo = 1 naira).
    */

  'fees' => [
    'execution' => [
      ['max_steps' => 5,            'amount_kobo' => 2000],  // ₦20
      ['max_steps' => 10,           'amount_kobo' => 3000],  // ₦30
      ['max_steps' => PHP_INT_MAX,  'amount_kobo' => 5000],  // ₦50
    ],
    'crypto_fx_spread'     => 0.005, // 0.5%
    'crypto_withdrawal_usd' => 0.50,
    'salary_advance_rate'  => 0.03,  // 3% flat fee
    'bill_commission_rate' => 0.015, // 1.5% average
  ],

  /*
    |--------------------------------------------------------------------------
    | Mono — Open Banking
    |--------------------------------------------------------------------------
    */

  'mono' => [
    'secret_key'     => env('MONO_SECRET_KEY'),
    'public_key'     => env('MONO_PUBLIC_KEY'),
    'base_url'       => env('MONO_BASE_URL', 'https://api.withmono.com'),
    'webhook_secret' => env('MONO_WEBHOOK_SECRET'),
  ],

  /*
    |--------------------------------------------------------------------------
    | VTpass — Bill Payments
    |--------------------------------------------------------------------------
    */

  'vtpass' => [
    'api_key'    => env('VTPASS_API_KEY'),
    'secret_key' => env('VTPASS_SECRET_KEY'),
    'public_key' => env('VTPASS_PUBLIC_KEY'),
    'base_url'   => env('VTPASS_ENV', 'sandbox') === 'production'
      ? 'https://vtpass.com/api'
      : 'https://sandbox.vtpass.com/api',
    'env'        => env('VTPASS_ENV', 'sandbox'),
  ],

  /*
    |--------------------------------------------------------------------------
    | Anthropic — NLP + Advisory AI
    |--------------------------------------------------------------------------
    */

  'anthropic' => [
    'api_key'  => env('ANTHROPIC_API_KEY'),
    'model'    => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-20241022'),
    'base_url' => 'https://api.anthropic.com/v1',
    'timeout'  => 30,
  ],

  /*
    |--------------------------------------------------------------------------
    | PiggyVest
    |--------------------------------------------------------------------------
    */

  'piggyvest' => [
    'api_key'  => env('PIGGYVEST_API_KEY'),
    'base_url' => env('PIGGYVEST_BASE_URL', 'https://api.piggyvest.com'),
    'env'      => env('PIGGYVEST_ENV', 'sandbox'),
  ],

  /*
    |--------------------------------------------------------------------------
    | Cowrywise
    |--------------------------------------------------------------------------
    */

  'cowrywise' => [
    'client_id'     => env('COWRYWISE_CLIENT_ID'),
    'client_secret' => env('COWRYWISE_CLIENT_SECRET'),
    'base_url'      => env('COWRYWISE_BASE_URL', 'https://sandbox.cowrywise.com'),
    'env'           => env('COWRYWISE_ENV', 'sandbox'),
  ],

  /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

  'security' => [
    'velocity' => [
      'max_executions_per_hour' => 10,
      'max_executions_per_day'  => 30,
      'max_transfers_per_hour'  => 5,
      'max_amount_per_day_kobo' => 50_000_000, // ₦500,000
    ],
    'idempotency_ttl_minutes' => 1440,
    'login_max_attempts'      => 5,
    'login_lockout_minutes'   => 15,
  ],

  /*
    |--------------------------------------------------------------------------
    | Advisory Engine
    |--------------------------------------------------------------------------
    */

  'advisory' => [
    'min_transactions_for_profile' => 10,
    'salary_confidence_threshold'  => 0.75,
    'projection_days_ahead'        => 30,
    'idle_cash_threshold_days'     => 7,
    'idle_cash_minimum_amount'       => 1000000,  // ₦10,000 kobo
    'savings_rate_target_percent'    => 20,
    'food_spend_target_ratio'      => 0.25,
    'insight_refresh_hours'          => 24,
    'insight_max_per_day'            => 3,
    'suggestion_cooldown_days'       => 14,
  ],

  /*
    |--------------------------------------------------------------------------
    | Crypto Networks
    |--------------------------------------------------------------------------
    */

  'crypto' => [
    'networks' => [
      'bep20'    => ['label' => 'BEP-20 (BSC)',      'token' => 'USDT', 'withdrawal_fee_usd' => 0.50],
      'trc20'    => ['label' => 'TRC-20 (Tron)',     'token' => 'USDT', 'withdrawal_fee_usd' => 1.00],
      'erc20'    => ['label' => 'ERC-20 (Ethereum)', 'token' => 'USDT', 'withdrawal_fee_usd' => 5.00],
      'polygon'  => ['label' => 'Polygon',           'token' => 'USDT', 'withdrawal_fee_usd' => 0.10],
      'solana'   => ['label' => 'Solana',            'token' => 'USDT', 'withdrawal_fee_usd' => 0.10],
      'arbitrum' => ['label' => 'Arbitrum',          'token' => 'USDT', 'withdrawal_fee_usd' => 0.50],
    ],
    'default_network' => 'trc20',
  ],

  /*
    |--------------------------------------------------------------------------
    | Nigerian Banks (offline fallback)
    |--------------------------------------------------------------------------
    */

  'banks' => [
    ['code' => '044',    'name' => 'Access Bank'],
    ['code' => '050',    'name' => 'EcoBank Nigeria'],
    ['code' => '070',    'name' => 'Fidelity Bank'],
    ['code' => '011',    'name' => 'First Bank of Nigeria'],
    ['code' => '214',    'name' => 'First City Monument Bank'],
    ['code' => '058',    'name' => 'Guaranty Trust Bank'],
    ['code' => '030',    'name' => 'Heritage Bank'],
    ['code' => '301',    'name' => 'Jaiz Bank'],
    ['code' => '082',    'name' => 'Keystone Bank'],
    ['code' => '526',    'name' => 'Kuda Microfinance Bank'],
    ['code' => '323',    'name' => 'Moniepoint Microfinance Bank'],
    ['code' => '999992', 'name' => 'OPay'],
    ['code' => '076',    'name' => 'Polaris Bank'],
    ['code' => '221',    'name' => 'Stanbic IBTC Bank'],
    ['code' => '068',    'name' => 'Standard Chartered Bank'],
    ['code' => '232',    'name' => 'Sterling Bank'],
    ['code' => '032',    'name' => 'Union Bank'],
    ['code' => '033',    'name' => 'United Bank for Africa'],
    ['code' => '035',    'name' => 'Wema Bank'],
    ['code' => '057',    'name' => 'Zenith Bank'],
    ['code' => '120001', 'name' => 'PalmPay'],
    ['code' => '101',    'name' => 'ProvidusBank'],
  ],

];
