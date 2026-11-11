<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        // Placeholder values — the Super Admin must set the real collection
        // details through the audited settings endpoint.
        PlatformSetting::instance()->fill([
            'momo_number'          => '0240000000',
            'momo_name'            => 'ShopStack Collections',
            'momo_provider'        => 'MTN',
            'bank_name'            => 'GCB Bank',
            'bank_account_number'  => '1000000000000',
            'bank_account_name'    => 'ShopStack Ltd',
            'payment_instructions' => 'Pay your exact plan amount and submit the transaction reference.',
        ])->save();
    }
}
