<?php

namespace App\Support;

/**
 * Channels an agent can deliver its reports to (besides the panel itself).
 */
enum DeliveryChannel: string
{
    case Telegram = 'telegram';
    case Bale = 'bale';
    case Email = 'email';
    case Webhook = 'webhook';
    /** A channel of the Rahap messenger, through its incoming webhook (POST /hooks/{secret}). */
    case Rahap = 'rahap';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'تلگرام',
            self::Bale => 'بله',
            self::Email => 'ایمیل',
            self::Webhook => 'وب‌هوک',
            self::Rahap => 'پیام‌رسان رهاپ',
        };
    }

    /**
     * Validation rules for `settings` (keys relative to `settings.`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Telegram, self::Bale => [
                'chat_id' => ['required', 'string', 'max:100', 'regex:/^(@[A-Za-z0-9_]{4,64}|-?\d{3,20})$/'],
                'bot_token' => [config("services.{$this->value}.bot_token") ? 'nullable' : 'required', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'],
            ],
            self::Email => [
                'emails' => ['required', 'array', 'min:1', 'max:10'],
                'emails.*' => ['required', 'email', 'max:255'],
            ],
            self::Webhook => [
                'url' => ['required', 'url:https,http', 'max:500'],
            ],
            self::Rahap => [
                'url' => ['required', 'url:https,http', 'max:500', 'regex:#/hooks/[A-Za-z0-9_-]+$#'],
            ],
        };
    }

    public function isMessenger(): bool
    {
        return $this === self::Telegram || $this === self::Bale;
    }
}
