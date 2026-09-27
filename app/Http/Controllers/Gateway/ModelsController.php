<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AppApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModelsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var AppApiKey $key */
        $key = $request->attributes->get('app_key');

        $models = AiModel::query()->available()->with('provider')->orderBy('public_id')->get()
            ->filter(fn (AiModel $m) => $key->allowsModel($m))
            ->map(fn (AiModel $m) => [
                'id' => $m->public_id,
                'object' => 'model',
                'created' => $m->created_at?->timestamp,
                'owned_by' => $m->provider->slug,
            ])
            ->values();

        return response()->json(['object' => 'list', 'data' => $models]);
    }
}
