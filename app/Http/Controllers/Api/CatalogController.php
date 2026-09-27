<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiModelResource;
use App\Http\Resources\PackageResource;
use App\Models\AiModel;
use App\Models\App;
use App\Models\Package;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    use Concerns;

    /**
     * Available models with customer prices (USD per 1M tokens), optionally for a specific app.
     */
    public function models(Request $request): AnonymousResourceCollection
    {
        if ($appId = $request->query('app_id')) {
            $request->attributes->set('pricing_app', $this->ownedApp($request, App::query()->findOrFail($appId)));
        }

        return AiModelResource::collection(
            AiModel::query()->available()->with('provider')->orderBy('provider_id')->orderBy('name')->get()
        );
    }

    public function packages(SettingsService $settings): JsonResponse
    {
        $s = $settings->all();

        return response()->json([
            'data' => PackageResource::collection(Package::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price')->get()),
            'custom_topup' => [
                'enabled' => $s['custom_topup_enabled'],
                'min' => Money::toUsd($s['custom_topup_min']),
                'max' => Money::toUsd($s['custom_topup_max']),
            ],
        ]);
    }
}
