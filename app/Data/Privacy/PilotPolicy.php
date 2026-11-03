<?php

declare(strict_types=1);

namespace App\Data\Privacy;

final readonly class PilotPolicy
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $aiProviders  AI providers the bundle names as processors of seller photos (ADR-037)
     */
    public function __construct(
        public string $version,
        public string $digest,
        public string $terms,
        public string $privacy,
        public string $retention,
        public array $blockers,
        public array $aiProviders = [],
        public bool $voiceIntake = false,
    ) {}

    public function allowsVoiceIntake(): bool
    {
        return $this->isApproved() && $this->voiceIntake;
    }

    /** Whether a seller who accepted this bundle was told photos go to this AI provider. */
    public function allowsAiProvider(string $provider): bool
    {
        return $this->isApproved() && in_array($provider, $this->aiProviders, true);
    }

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
