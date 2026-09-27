<?php

namespace App\Notifications;

use App\Models\AgentRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NumberFormatter;

/**
 * A new agent report is ready, or a run was skipped because the organization has no units left.
 */
class AgentRunNotification extends Notification
{
    use Queueable;

    public function __construct(public AgentRun $run) {}

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
            ->action($this->isReport() ? 'خواندن گزارش' : 'خرید بسته', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->isReport() ? 'agent_report' : 'agent_no_credits',
            'agent_instance_id' => $this->run->agent_instance_id,
            'agent_run_id' => $this->run->id,
            'title' => $this->title(),
            'message' => $this->message(),
        ];
    }

    private function isReport(): bool
    {
        return $this->run->status === AgentRun::STATUS_SUCCEEDED;
    }

    private function title(): string
    {
        $name = $this->run->instance->name;

        return $this->isReport() ? "گزارش تازهٔ «{$name}» آماده است" : "«{$name}» به دلیل تمام شدن اعتبار اجرا نشد";
    }

    private function message(): string
    {
        return $this->isReport()
            ? (new NumberFormatter('fa_IR', NumberFormatter::DECIMAL))->format($this->run->items_found).' خبر تازه بررسی و خلاصه شد.'
            : "برای ادامهٔ پایش، یک بسته «{$this->run->agent->unit_name}» بخرید.";
    }

    private function url(): string
    {
        $base = rtrim(config('billing.panel_url'), '/');

        return $this->isReport()
            ? "{$base}/agents/{$this->run->agent_instance_id}?run={$this->run->id}"
            : "{$base}/agents";
    }
}
