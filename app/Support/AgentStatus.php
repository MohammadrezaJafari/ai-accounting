<?php

namespace App\Support;

/**
 * Review state of a marketplace listing. Only approved listings can be shown in the store
 * (and only while `is_active`). Listings made by the admin start approved.
 */
enum AgentStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::PendingReview => 'در انتظار بررسی',
            self::Approved => 'منتشرشده',
            self::Rejected => 'نیاز به اصلاح',
        };
    }

    /**
     * Whether the publisher edits the listing itself; otherwise edits wait for review.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $status) => [$status->value => $status->label()])->all();
    }
}
