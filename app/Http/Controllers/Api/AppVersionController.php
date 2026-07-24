<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    /**
     * Public mobile version policy for force-update checks.
     */
    public function show(Request $request): JsonResponse
    {
        $platform = strtolower((string) $request->query('platform', ''));

        $androidMin = (string) config('app_version.min_version_android');
        $iosMin = (string) config('app_version.min_version_ios');
        $androidLatest = (string) config('app_version.latest_version_android');
        $iosLatest = (string) config('app_version.latest_version_ios');
        $androidStore = (string) config('app_version.android_store_url');
        $iosStore = (string) config('app_version.ios_store_url');
        $message = (string) config('app_version.force_update_message');

        $payload = [
            'android' => [
                'min_version' => $androidMin,
                'latest_version' => $androidLatest,
                'store_url' => $androidStore,
            ],
            'ios' => [
                'min_version' => $iosMin,
                'latest_version' => $iosLatest,
                'store_url' => $iosStore,
            ],
            'message' => $message,
        ];

        if ($platform === 'android' || $platform === 'ios') {
            $payload['platform'] = $platform;
            $payload['min_version'] = $platform === 'android' ? $androidMin : $iosMin;
            $payload['latest_version'] = $platform === 'android' ? $androidLatest : $iosLatest;
            $payload['store_url'] = $platform === 'android' ? $androidStore : $iosStore;
        }

        return ResponseHelper::success($payload, 'App version policy retrieved.');
    }
}
