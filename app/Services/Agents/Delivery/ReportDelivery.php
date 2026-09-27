<?php

namespace App\Services\Agents\Delivery;

use App\Mail\AgentReportMail;
use App\Models\AgentDestination;
use App\Models\AgentRun;
use App\Services\Agents\AgentException;
use App\Services\Agents\UrlGuard;
use App\Support\DeliveryChannel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use IntlDateFormatter;
use Throwable;

/**
 * Sends a run's report to the instance's destinations. A failing destination never fails the
 * run: its error is kept on the destination and in the run's delivery results.
 */
class ReportDelivery
{
    public function __construct(private ReportFormatter $formatter, private UrlGuard $guard) {}

    /**
     * @return list<array{destination_id: int, type: string, label: string, ok: bool, error: ?string}>
     */
    public function deliver(AgentRun $run): array
    {
        $instance = $run->instance;
        $destinations = $instance->destinations()->where('is_active', true)->get();

        return $destinations->map(fn (AgentDestination $destination) => $this->attempt($destination, fn () => $this->send(
            $destination,
            $this->title($run),
            $run->report ?? 'در این نوبت خبر تازه‌ای پیدا نشد.',
            $this->payload($run),
        )))->all();
    }

    /**
     * A short message proving the destination is reachable; returns the error, if any.
     */
    public function test(AgentDestination $destination): ?string
    {
        $name = $destination->instance->name;
        $result = $this->attempt($destination, fn () => $this->send(
            $destination,
            "آزمایش اتصال «{$name}»",
            'اتصال برقرار است. گزارش‌های این ایجنت از این پس اینجا فرستاده می‌شوند.',
            ['event' => 'agent.test', 'agent_instance_id' => $destination->agent_instance_id, 'name' => $name],
        ));

        return $result['error'];
    }

    /**
     * @return array{destination_id: int, type: string, label: string, ok: bool, error: ?string}
     */
    private function attempt(AgentDestination $destination, callable $send): array
    {
        try {
            $send();
            $destination->update(['last_delivered_at' => now(), 'last_error' => null]);
            $error = null;
        } catch (Throwable $e) {
            $error = $e instanceof AgentException ? $e->getMessage() : 'ارسال انجام نشد.';
            report_unless($e instanceof AgentException, $e);
            $destination->update(['last_error' => Str::limit($error, 480)]);
        }

        return [
            'destination_id' => $destination->id,
            'type' => $destination->type->value,
            'label' => $destination->label,
            'ok' => $error === null,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  body for webhooks
     */
    private function send(AgentDestination $destination, string $title, string $markdown, array $payload): void
    {
        match ($destination->type) {
            DeliveryChannel::Telegram => $this->messenger($destination, $this->formatter->telegram($title, $markdown), 'HTML'),
            DeliveryChannel::Bale => $this->messenger($destination, $this->formatter->plain($title, $markdown)),
            DeliveryChannel::Email => $this->email($destination, $title, $markdown),
            DeliveryChannel::Webhook => $this->webhook($destination, $payload + ['title' => $title, 'report' => $markdown]),
        };
    }

    /**
     * @param  list<string>  $messages
     */
    private function messenger(AgentDestination $destination, array $messages, ?string $parseMode = null): void
    {
        $channel = $destination->type->value;
        $token = $destination->setting('bot_token') ?: config("services.{$channel}.bot_token");

        if (! $token) {
            throw new AgentException('توکن ربات تنظیم نشده است.');
        }

        foreach ($messages as $text) {
            $response = Http::timeout(15)->asJson()->post(rtrim(config("services.{$channel}.api_url"), '/')."/bot{$token}/sendMessage", array_filter([
                'chat_id' => $destination->setting('chat_id'),
                'text' => $text,
                'parse_mode' => $parseMode,
                'disable_web_page_preview' => true,
            ], fn ($value) => $value !== null));

            if (! $response->successful() || $response->json('ok') === false) {
                throw new AgentException($this->messengerError($destination, $response));
            }
        }
    }

    private function messengerError(AgentDestination $destination, Response $response): string
    {
        $reason = (string) $response->json('description', "HTTP {$response->status()}");
        $bot = $destination->type->label();

        return match (true) {
            str_contains(Str::lower($reason), 'chat not found') => "کانال یا گروه در {$bot} پیدا نشد. شناسه را بررسی کنید و ربات را به آن اضافه کنید.",
            str_contains(Str::lower($reason), 'not enough rights'), str_contains(Str::lower($reason), 'forbidden') => 'ربات اجازهٔ ارسال پیام ندارد. آن را ادمین کانال کنید.',
            str_contains(Str::lower($reason), 'unauthorized') => 'توکن ربات معتبر نیست.',
            default => "{$bot} پیام را نپذیرفت: {$reason}",
        };
    }

    private function email(AgentDestination $destination, string $title, string $markdown): void
    {
        Mail::to($destination->setting('emails', []))->send(new AgentReportMail($title, $this->formatter->html($markdown)));
    }

    /**
     * JSON POST signed with the destination's secret: X-Signature = sha256 HMAC of the body.
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhook(AgentDestination $destination, array $payload): void
    {
        $url = (string) $destination->setting('url');
        $this->guard->assertPublic($url);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = Http::timeout(15)
            ->withHeaders(['X-Signature' => hash_hmac('sha256', $body, (string) $destination->setting('secret'))])
            ->withOptions(['allow_redirects' => false])
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new AgentException("وب‌هوک پاسخ {$response->status()} داد.");
        }
    }

    private function title(AgentRun $run): string
    {
        $date = (new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::LONG, IntlDateFormatter::NONE, config('billing.display_timezone'), IntlDateFormatter::TRADITIONAL))
            ->format($run->created_at);

        return "{$run->instance->name} — {$date}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AgentRun $run): array
    {
        return [
            'event' => $run->report ? 'agent.report' : 'agent.empty',
            'agent' => $run->agent->slug,
            'agent_instance_id' => $run->agent_instance_id,
            'name' => $run->instance->name,
            'run_id' => $run->id,
            'items_found' => $run->items_found,
            'created_at' => $run->created_at->toIso8601String(),
        ];
    }
}
