<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Gateway\ChatCompletionsController;
use App\Models\App;
use App\Models\AppApiKey;
use App\Services\Gateway\GatewayError;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chat from the customer panel. Runs through the same gateway pipeline as an app would,
 * billed to the chosen app under a dedicated "panel chat" key so it shows up in usage logs.
 */
class PlaygroundController extends Controller
{
    use Concerns;

    public function __invoke(Request $request, App $app, ChatCompletionsController $proxy, SettingsService $settings): Response
    {
        $this->ownedApp($request, $app);

        $key = $app->apiKeys()->firstOrCreate(['name' => AppApiKey::PLAYGROUND_NAME], [
            'key_prefix' => 'panel',
            'key_hash' => AppApiKey::hash(Str::random(64)),
        ]);

        if ($app->is_active === false || $key->is_active === false) {
            return GatewayError::response($request, 403, 'این اپ یا کلید چت پنل غیرفعال است.', 'permission_error');
        }

        if ($app->balance <= $settings->get('min_balance') || $key->isOverSpendLimit()) {
            return GatewayError::response($request, 402, 'موجودی اپ کافی نیست. لطفاً کیف پول را شارژ کنید.', 'billing_error');
        }

        $request->attributes->set('app_key', $key->setRelation('app', $app));

        return $proxy($request);
    }
}
