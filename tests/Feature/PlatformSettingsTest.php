<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Tests\SecurityTestCase;

/**
 * MoMo / bank collection details are PLATFORM settings. Tenants can read a
 * sanitized projection but can never modify them; every change is audited.
 */
class PlatformSettingsTest extends SecurityTestCase
{
    public function test_tenant_cannot_modify_platform_payment_settings(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->asTenantUser($owner)->putJson('/api/v1/super-admin/settings/payment', [
            'momo_number' => '0555555555',
        ])->assertForbidden();

        $this->assertNotSame('0555555555', PlatformSetting::instance()->momo_number);
    }

    public function test_tenant_reads_only_the_public_projection(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $info = $this->asTenantUser($owner)->getJson('/api/v1/billing/platform-payment-info')->assertOk();

        // Public projection: provider list + bank block + instructions only.
        $this->assertArrayHasKey('momo', $info->json());
        $this->assertArrayHasKey('bank', $info->json());
        $this->assertArrayHasKey('payment_instructions', $info->json());
        $this->assertArrayHasKey('currency', $info->json());
        $this->assertArrayNotHasKey('updated_by', $info->json());
        $this->assertArrayNotHasKey('updated_at', $info->json());
    }

    public function test_two_momo_providers_surface_in_public_projection(): void
    {
        $settings = PlatformSetting::instance();
        $settings->update([
            'momo_provider' => 'MTN MoMo', 'momo_name' => 'Main', 'momo_number' => '0240000000',
            'momo_provider_2' => 'Telecel Cash', 'momo_name_2' => 'Backup', 'momo_number_2' => '0200000000',
        ]);

        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $momo = $this->asTenantUser($owner)
            ->getJson('/api/v1/billing/platform-payment-info')->assertOk()->json('momo');

        $this->assertCount(2, $momo);
        $this->assertSame('MTN MoMo', $momo[0]['provider']);
        $this->assertSame('Telecel Cash', $momo[1]['provider']);
        $this->assertSame('0240000000', $momo[0]['number']);
        $this->assertSame('0200000000', $momo[1]['number']);
    }

    public function test_super_admin_with_permission_can_update_and_it_is_audited(): void
    {
        $admin = $this->makeSuperAdmin(['permissions' => ['settings.manage']]);

        $this->asSuperAdmin($admin)->putJson('/api/v1/super-admin/settings/payment', [
            'momo_number' => '0241234567',
            'momo_name'   => 'Collections Account',
        ])->assertOk();

        $settings = PlatformSetting::instance();
        $this->assertSame('0241234567', $settings->momo_number);
        $this->assertSame($admin->id, $settings->updated_by);

        $audit = \App\Models\Audit::where('action', 'platform.payment_settings_changed')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertContains('momo_number', $audit->context['fields_changed']);
        // raw account numbers are not written into the audit payload
        $this->assertStringNotContainsString('0241234567', json_encode($audit->context));
    }

    public function test_super_admin_without_permission_cannot_update_settings(): void
    {
        $admin = $this->makeSuperAdmin(['permissions' => ['tenants.view']]);

        $this->asSuperAdmin($admin)->putJson('/api/v1/super-admin/settings/payment', [
            'momo_number' => '0241234567',
        ])->assertForbidden();
    }

    public function test_settings_validation_rules(): void
    {
        $admin = $this->makeSuperAdmin(['permissions' => ['settings.manage']]);

        $this->asSuperAdmin($admin)->putJson('/api/v1/super-admin/settings/payment', [
            'momo_number' => 'not a number <>;',
        ])->assertStatus(422);
    }
}
