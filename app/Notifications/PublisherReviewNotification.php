<?php

namespace App\Notifications;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The admin reviewed a publisher's listing: published, sent back, or its changes applied or turned down.
 */
class PublisherReviewNotification extends Notification
{
    use Queueable;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CHANGES_APPLIED = 'changes_applied';

    public const CHANGES_REJECTED = 'changes_rejected';

    public function __construct(public Agent $agent, public string $outcome) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->line($this->message());

        if ($this->agent->review_note) {
            $mail->line('یادداشت بررسی: '.$this->agent->review_note);
        }

        return $mail->action('مشاهدهٔ ایجنت', rtrim(config('billing.panel_url'), '/')."/publisher/agents/{$this->agent->id}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'publisher_review',
            'outcome' => $this->outcome,
            'agent_id' => $this->agent->id,
            'title' => $this->title(),
            'message' => $this->message(),
        ];
    }

    private function title(): string
    {
        return match ($this->outcome) {
            self::APPROVED => "«{$this->agent->name}» در بازارچه منتشر شد",
            self::REJECTED => "«{$this->agent->name}» نیاز به اصلاح دارد",
            self::CHANGES_APPLIED => "تغییرات «{$this->agent->name}» منتشر شد",
            default => "تغییرات «{$this->agent->name}» پذیرفته نشد",
        };
    }

    private function message(): string
    {
        return in_array($this->outcome, [self::APPROVED, self::CHANGES_APPLIED], true)
            ? 'مشتری‌ها از همین حالا نسخهٔ تازه را می‌بینند.'
            : ($this->agent->review_note ?: 'یادداشت بررسی را در پنل ناشر ببینید.');
    }
}
