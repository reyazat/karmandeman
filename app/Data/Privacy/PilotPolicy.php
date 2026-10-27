<?php

declare(strict_types=1);

namespace App\Data\Privacy;

final readonly class PilotPolicy
{
    /** @param list<string> $blockers */
    public function __construct(
        public string $version,
        public string $digest,
        public string $terms,
        public string $privacy,
        public string $retention,
        public array $blockers,
    ) {}

    public function isApproved(): bool
    {
        return $this->blockers === [];
    }

    public function consentToken(): string
    {
        return substr($this->digest, 0, 32);
    }

    public function unavailableNotice(): string
    {
        return 'نسخه شرایط و سیاست داده هنوز برای استفاده عمومی تأیید نشده است؛ شناخت برند و ثبت رضایت نهایی فعلاً متوقف است. فایل تجاری نفرستید. برای پشتیبانی /support و برای ثبت درخواست حذف /delete_data را بفرستید. ثبت رسید به معنی تکمیل حذف نیست.';
    }
}
