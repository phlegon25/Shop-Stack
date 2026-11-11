<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/** Self-documenting API surface for the console's API section. */
class ApiStatusController extends Controller
{
    public function index(): JsonResponse
    {
        $v1 = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1'))
            ->map(fn ($r) => implode('|', $r->methods()) . ' /' . $r->uri())
            ->unique()->sort()->values();

        return response()->json([
            'name'        => config('pos.platform_name') . ' REST API',
            'version'     => 'v1',
            'base_url'    => url('/api/v1'),
            'auth'        => 'Bearer token (Sanctum), short-lived; super-admin plane isolated',
            'rate_limits' => [
                'tenant.api'      => '120 req/min per user',
                'sa.api'          => '60 req/min per admin',
                'auth.login'      => '10 req/min per IP',
                'sa.auth'         => '8 req/min per IP',
                'payments.submit' => '10 req/min',
                'impersonate'     => '10/hr',
            ],
            'endpoint_count' => $v1->count(),
            'endpoints'      => $v1,
            'status'         => 'operational',
            'checked_at'     => now()->toISOString(),
        ]);
    }
}
