<?php

namespace App\Notifications;

use App\Models\App;
use App\Models\AppApiKey;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An app or API key reached 80% or 100% of its spend limit for the current period.
 */
class SpendLimitAlert extends Notification
{
    use Queueable;

    public function __construct(public App|AppApiKey $budgeted, public int $level) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->message())
            ->action('مشاهدهٔ اپ', rtrim(config('billing.panel_url'), '/').'/apps/'.$this->app()->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'spend_limit',
            'level' => $this->level,
            'subject' => $this->budgeted instanceof AppApiKey ? 'key' : 'app',
            'app_id' => $this->app()->id,
            'app_name' => $this->app()->name,
            'key_name' => $this->budgeted instanceof AppApiKey ? $this->budgeted->name : null,
            'period' => $this->budgeted->spend_limit_period->value,
            'limit' => Money::toUsd($this->budgeted->spend_limit),
            'spent' => Money::toUsd($this->budgeted->spentThisPeriod()),
            'title' => $this->title(),
            'message' => $this->message(),
        ];
    }

    private function app(): App
    {
        return $this->budgeted instanceof AppApiKey ? $this->budgeted->app : $this->budgeted;
    }

    private function subjectName(): string
    {
        return $this->budgeted instanceof AppApiKey
            ? "کلید «{$this->budgeted->name}» از اپ «{$this->app()->name}»"
            : "اپ «{$this->app()->name}»";
    }

    private function title(): string
    {
        return $this->level >= 100
            ? "سقف هزینهٔ {$this->subjectName()} پر شد"
            : "{$this->subjectName()} به {$this->level}٪ سقف هزینه رسید";
    }

    private function message(): string
    {
        $period = $this->budgeted->spend_limit_period->label();
        $spent = Money::format($this->budgeted->spentThisPeriod());
        $limit = Money::format($this->budgeted->spend_limit);

        $message = "{$this->subjectName()} از سقف هزینهٔ {$period} {$limit}، مبلغ {$spent} را مصرف کرده است.";

        return $this->level >= 100
            ? $message.' تا شروع دورهٔ بعد یا افزایش سقف، درخواست‌ها با خطای 402 رد می‌شوند.'
            : $message;
    }
}
