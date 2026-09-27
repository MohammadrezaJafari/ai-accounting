<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResource;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppController extends Controller
{
    use Concerns;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AppResource::collection($request->user()->apps()->withCount('apiKeys')->latest()->get());
    }

    public function store(Request $request): AppResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return new AppResource($request->user()->apps()->create($data));
    }

    public function show(Request $request, App $app): AppResource
    {
        return new AppResource($this->ownedApp($request, $app)->loadCount('apiKeys'));
    }

    public function update(Request $request, App $app): AppResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->ownedApp($request, $app)->update($data);

        return new AppResource($app);
    }

    public function destroy(Request $request, App $app): JsonResponse
    {
        $this->ownedApp($request, $app);
        abort_if($app->balance > 0, 422, 'Apps with a remaining balance cannot be deleted.');
        $app->delete();

        return response()->json(['ok' => true]);
    }
}
