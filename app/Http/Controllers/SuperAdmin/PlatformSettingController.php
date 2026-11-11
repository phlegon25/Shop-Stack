<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformSettingUpdateRequest;
use App\Models\PlatformSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * Platform payment collection details (MoMo / bank). Tenant endpoints have
 * read-only access to a sanitized projection; ONLY a super-admin holding the
 * settings.manage permission may change them — every change is audited.
 */
class PlatformSettingController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => PlatformSetting::instance()]);
    }

    public function update(PlatformSettingUpdateRequest $request): JsonResponse
    {
        $settings = PlatformSetting::instance();
        $data     = $request->validated();

        // Record WHICH fields changed (not their values, to keep account
        // numbers out of general-purpose logs).
        $changed = [];
        foreach ($data as $key => $value) {
            if ($settings->{$key} !== $value) {
                $changed[] = $key;
            }
        }

        $settings->fill($data);
        $settings->updated_by = $request->user()->id;
        $settings->save();

        if ($changed !== []) {
            $this->audit->log('platform.payment_settings_changed', [
                'subject' => $settings,
                'context' => ['fields_changed' => $changed],
            ]);
        }

        return response()->json([
            'message' => 'Platform payment settings updated.',
            'data'    => $settings->fresh(),
        ]);
    }
}
