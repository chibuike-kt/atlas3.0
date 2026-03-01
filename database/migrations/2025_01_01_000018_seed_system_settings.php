<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $settings = [
            [
                'key'         => 'usd_ngn_rate',
                'value'       => '1600',
                'type'        => 'float',
                'description' => 'Current USD to NGN exchange rate used for crypto conversions.',
                'is_public'   => true,
            ],
            [
                'key'         => 'usdt_buy_rate',
                'value'       => '1620',
                'type'        => 'float',
                'description' => 'Rate Atlas uses when buying USDT (with spread applied).',
                'is_public'   => true,
            ],
            [
                'key'         => 'usdt_sell_rate',
                'value'       => '1580',
                'type'        => 'float',
                'description' => 'Rate Atlas uses when selling USDT back to NGN.',
                'is_public'   => true,
            ],
            [
                'key'         => 'atlas_float_rate',
                'value'       => '18.0',
                'type'        => 'float',
                'description' => 'Annual interest rate Atlas pays users on float balances.',
                'is_public'   => true,
            ],
            [
                'key'         => 'salary_advance_rate_min',
                'value'       => '2.0',
                'type'        => 'float',
                'description' => 'Minimum salary advance fee percent (7-day term).',
                'is_public'   => false,
            ],
            [
                'key'         => 'salary_advance_rate_max',
                'value'       => '5.0',
                'type'        => 'float',
                'description' => 'Maximum salary advance fee percent (7-day term).',
                'is_public'   => false,
            ],
            [
                'key'         => 'maintenance_mode',
                'value'       => 'false',
                'type'        => 'boolean',
                'description' => 'Put Atlas in maintenance mode — blocks all executions.',
                'is_public'   => false,
            ],
            [
                'key'         => 'execution_fee_tier_1',
                'value'       => '2000',
                'type'        => 'integer',
                'description' => 'Execution fee in kobo for rules with 1 to 5 steps.',
                'is_public'   => true,
            ],
            [
                'key'         => 'execution_fee_tier_2',
                'value'       => '3000',
                'type'        => 'integer',
                'description' => 'Execution fee in kobo for rules with 6 to 10 steps.',
                'is_public'   => true,
            ],
            [
                'key'         => 'execution_fee_tier_3',
                'value'       => '5000',
                'type'        => 'integer',
                'description' => 'Execution fee in kobo for rules with 11 or more steps.',
                'is_public'   => true,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('system_settings')->insert(array_merge($setting, [
                'id'         => (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'usd_ngn_rate', 'usdt_buy_rate', 'usdt_sell_rate',
            'atlas_float_rate', 'salary_advance_rate_min', 'salary_advance_rate_max',
            'maintenance_mode', 'execution_fee_tier_1', 'execution_fee_tier_2', 'execution_fee_tier_3',
        ])->delete();
    }
};
