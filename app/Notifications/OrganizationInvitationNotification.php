<?php

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public OrganizationInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization->name;

        return (new MailMessage)
            ->subject("دعوت به سازمان {$organization}")
            ->line("شما با نقش «{$this->invitation->role->label()}» به سازمان {$organization} دعوت شده‌اید.")
            ->line('اگر حساب کاربری ندارید، اول با همین ایمیل ثبت‌نام کنید.')
            ->action('پذیرفتن دعوت', $this->invitation->url())
            ->line('این لینک '.OrganizationInvitation::VALID_DAYS.' روز اعتبار دارد.');
    }
}
