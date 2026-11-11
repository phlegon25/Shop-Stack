<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Tenant-side settings: business profile, receipt/invoice branding, tax,
 * stock thresholds, subscription lifecycle overview and plan usage.
 */
class SettingsController extends Controller
{
    private const DISK = 'tenant-uploads';

    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $tenant   = $this->context->tenant();
        $settings = $this->settings();

        $sub = $tenant->operationalSubscription();
        $plan = $sub?->plan;

        return response()->json([
            'business' => [
                'name'          => $tenant->name,
                'email'         => $tenant->email,
                'phone'         => $tenant->phone,
                'business_type' => $tenant->business_type,
                'address'       => $tenant->address,
                'city'          => $tenant->city,
                'region'        => $tenant->region,
                'country'       => $tenant->country,
            ],
            'settings' => [
                'currency'           => $settings->currency,
                'tax_rate'           => (float) $settings->tax_rate,
                'invoice_footer'     => $settings->invoice_footer,
                'low_stock_default'  => (int) $settings->low_stock_default,
                'expiry_warn_days'   => (int) $settings->expiry_warn_days,
                'logo_url'           => $settings->logoUrl(),
            ],
            'onboarding_completed' => (bool) $settings->onboarding_completed,
            'lifecycle' => $this->lifecycle($tenant, $sub),
            'usage' => [
                'products' => ['used' => Product::count(), 'limit' => $plan?->product_limit],
                'users'    => ['used' => User::count(), 'limit' => $plan?->user_limit],
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'phone'         => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'email'         => ['nullable', 'email', 'max:190'],
            'business_type' => ['nullable', 'string', 'max:60'],
            'address'       => ['nullable', 'string', 'max:300'],
            'city'          => ['nullable', 'string', 'max:80'],
            'region'        => ['nullable', 'string', 'max:80'],
            'country'       => ['nullable', 'string', 'max:80'],
            'currency'      => ['required', 'string', 'size:3'],
        ]);

        $currency = $data['currency'];
        unset($data['currency']);

        // Slug/status fields are not updatable here — identity is stable.
        $tenant->update($data);

        $settings = $this->settings();
        $settings->update(['currency' => $currency]);

        $this->audit->log('settings.profile_updated', ['context' => ['fields' => array_keys($data)]]);

        return response()->json(['message' => 'Business profile updated.']);
    }

    public function update(Request $request): JsonResponse
    {
        $settings = $this->settings();

        $data = $request->validate([
            'tax_rate'          => ['required', 'numeric', 'min:0', 'max:100'],
            'invoice_footer'    => ['nullable', 'string', 'max:300'],
            'low_stock_default' => ['required', 'integer', 'min:0', 'max:1000000'],
            'expiry_warn_days'  => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $settings->update($data);

        $this->audit->log('settings.business_updated', ['context' => ['fields' => array_keys($data)]]);

        return response()->json(['message' => 'Settings updated.', 'data' => $settings->fresh()]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:' . config('pos.uploads.product_image_max_kb')],
        ]);

        $file      = $request->file('logo');
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };
        // Random server-generated name; original filename is discarded.
        $name = 'logos/' . $this->context->tenantId() . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

        Storage::disk(self::DISK)->putFileAs('', $file, $name);

        $settings = $this->settings();
        if ($settings->logo_path) {
            Storage::disk(self::DISK)->delete($settings->logo_path);
        }
        $settings->update(['logo_path' => $name]);

        $this->audit->log('settings.logo_updated');

        return response()->json(['data' => ['logo_url' => $settings->logoUrl()]]);
    }

    public function logo(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $settings = $this->settings();

        abort_unless($settings->logo_path && Storage::disk(self::DISK)->exists($settings->logo_path), 404);

        return Storage::disk(self::DISK)->response($settings->logo_path);
    }

    public function completeOnboarding(): JsonResponse
    {
        $this->settings()->update(['onboarding_completed' => true]);

        $this->audit->log('settings.onboarding_completed');

        return response()->json(['message' => 'Onboarding completed.']);
    }

    private function settings(): BusinessSetting
    {
        $existing = BusinessSetting::query()->first();
        if ($existing) {
            return $existing;
        }

        // tenant_id is structural — never mass-assigned, set explicitly.
        $settings = new BusinessSetting();
        $settings->tenant_id = $this->context->tenantId();
        $settings->save();

        return $settings;
    }

    private function lifecycle($tenant, ?Subscription $sub): array
    {
        if ($tenant->isSuspended()) {
            return ['stage' => 'SUSPENDED', 'detail' => $tenant->suspension_reason];
        }
        if (! $sub) {
            return ['stage' => 'EXPIRED', 'detail' => null];
        }
        if ($sub->status === Subscription::STATUS_EXPIRED) {
            $days = $sub->grace_ends_at ? (int) now()->diffInDays($sub->grace_ends_at, false) : 0;

            return [
                'stage' => 'GRACE',
                'detail' => "Grace period — {$days} day(s) left before suspension.",
                'grace_ends_at' => $sub->grace_ends_at,
            ];
        }
        $daysLeft = (int) now()->startOfDay()->diffInDays($sub->ends_at->copy()->startOfDay());

        return [
            'stage' => $sub->status === Subscription::STATUS_TRIALING
                ? 'TRIAL'
                : ($daysLeft <= 7 ? 'EXPIRING' : 'ACTIVE'),
            'detail' => null,
            'days_left' => $daysLeft,
            'ends_at' => $sub->ends_at,
            'plan' => $sub->plan?->only(['name', 'slug', 'price', 'duration_days', 'features']),
        ];
    }
}
