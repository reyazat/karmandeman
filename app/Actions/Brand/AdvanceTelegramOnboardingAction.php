<?php

declare(strict_types=1);

namespace App\Actions\Brand;

use App\Actions\Workspace\LockActiveWorkspaceAction;
use App\Data\Telegram\OnboardingReply;
use App\Models\TelegramOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use App\Repositories\Contracts\TelegramOnboardingRepositoryInterface;
use App\Repositories\Contracts\WorkspaceConsentRepositoryInterface;
use App\Services\Privacy\PilotPolicyService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class AdvanceTelegramOnboardingAction
{
    public function __construct(
        private TelegramOnboardingRepositoryInterface $onboardings,
        private LockActiveWorkspaceAction $lockActive,
        private BrandRepositoryInterface $brands,
        private MemoryItemRepositoryInterface $memory,
        private UpdateBrandProfileAction $updateBrand,
        private PilotPolicyService $policies,
        private WorkspaceConsentRepositoryInterface $consents,
    ) {}

    public function execute(Workspace $workspace, User $user, string $event = 'prompt', ?string $value = null): OnboardingReply
    {
        Gate::forUser($user)->authorize('access', $workspace);

        return DB::transaction(function () use ($workspace, $user, $event, $value): OnboardingReply {
            $this->lockActive->execute($workspace);
            $state = $this->onboardings->ensure($workspace);
            $policy = $this->policies->current();
            if (! $policy->isApproved()) {
                return new OnboardingReply($policy->unavailableNotice());
            }
            $version = $policy->version;
            $consent = $this->consents->find($workspace, $version);
            if ($consent !== null && $consent->policy_document_hash !== $policy->digest) {
                // Same version, different text: never rebind an old receipt, never fail the webhook.
                return new OnboardingReply("متن نسخه {$version} پس از پذیرش شما تغییر کرده است. تا انتشار نسخه تازه، ادامه ممکن نیست؛ برای پیگیری /support را بفرستید.");
            }
            if ($consent === null) {
                if ($event !== 'accept' || $value !== $policy->consentToken()) {
                    return new OnboardingReply(
                        "پیش از شناخت برند، پذیرش نسخه {$version} لازم است.\n".
                        "شرایط /terms، سیاست داده /privacy و مدت‌های نگهداری /retention را بخوانید؛ حذف کامل شامل نسخه‌های پشتیبان هم هست و فوری فرض نمی‌شود.\n".
                        'این مرحله فقط شناخت برند است؛ فایل و محصول هنوز دریافت نمی‌شود. فایل تجاری نفرستید. برای ثبت درخواست حذف /delete_data را بفرستید.',
                        [[['text' => 'شرایط و سیاست داده را می‌پذیرم', 'callback_data' => 'onboard:accept:'.$policy->consentToken()]]],
                    );
                }
                $this->consents->record($workspace, $user, $version, $policy->digest);
            }

            if ($state->step === 'consent') {
                $this->onboardings->save($state, 'name');
            }

            if ($event === 'edit' && in_array($state->step, ['ready', 'review'], true)) {
                $brand = $this->brands->ensureFor($workspace);
                $draft = $state->step === 'review' ? ($state->draft ?? []) : [
                    'name' => $brand->name,
                    'currency_display_unit' => $brand->currency_display_unit,
                    'category' => $brand->category,
                    'tone' => $brand->tone,
                ];
                $this->onboardings->save($state, 'name', $draft, $brand->profile_version);
            }

            if ($event === 'forget' && $state->step === 'ready') {
                if (! in_array($value, ['name', 'category', 'tone'], true)) {
                    return new OnboardingReply('فقط نام، دسته یا لحن برند با /forget name|category|tone قابل حذف است.');
                }
                $brand = $this->brands->ensureFor($workspace);
                if ($brand->{$value} !== null) {
                    $this->updateBrand->execute($workspace, [$value => null], $user->id, $brand->profile_version);
                }
                if ($value === 'name') {
                    $this->onboardings->save($state, 'name');
                }
            }

            if ($event === 'confirm' && $state->step === 'review') {
                if ($value !== null && $value !== $this->reviewToken($state)) {
                    return $this->prompt($state);
                }
                $draft = $state->draft ?? [];
                $brand = $this->brands->ensureFor($workspace);
                if ($brand->profile_version !== $state->expected_brand_version) {
                    $this->onboardings->save($state, 'name');

                    return new OnboardingReply('برند از مسیر دیگری تغییر کرده است؛ برای جلوگیری از بازنویسی، اطلاعات را دوباره وارد کنید. نام برند؟');
                }
                $changes = [];
                foreach (['name', 'currency_display_unit', 'category', 'tone'] as $field) {
                    if (array_key_exists($field, $draft) && $brand->{$field} !== $draft[$field]) {
                        $changes[$field] = $draft[$field];
                    }
                }
                if (! $this->memory->activeFor($workspace, 'workspace_profile')
                    ->contains('key', 'brand.currency_display_unit')) {
                    $changes['currency_display_unit'] = $draft['currency_display_unit'];
                }
                if ($changes !== []) {
                    $this->updateBrand->execute($workspace, $changes, $user->id, $brand->profile_version);
                }
                $this->onboardings->save($state, 'ready');

                return new OnboardingReply('برند تأیید شد؛ شناخت اولیه رایگان است. با /new پروژه بسازید و عکس محصولات را بفرستید.');
            }

            if ($event === 'input' || $event === 'skip' || $event === 'unit') {
                $reply = $this->advanceInput($workspace, $state, $event, $value);
                if ($reply !== null) {
                    return $reply;
                }
            }

            return $this->prompt($state);
        });
    }

    public function isReady(Workspace $workspace): bool
    {
        $policy = $this->policies->current();
        if (! $policy->isApproved()) {
            return false;
        }
        $state = $this->onboardings->find($workspace);

        return $this->consents->find($workspace, $policy->version)?->policy_document_hash === $policy->digest
            && $state?->step === 'ready'
            && $this->brands->forWorkspace($workspace)?->name !== null;
    }

    private function advanceInput(Workspace $workspace, TelegramOnboarding $state, string $event, ?string $value): ?OnboardingReply
    {
        $step = $state->step;
        if (! in_array($step, ['name', 'unit', 'category', 'tone'], true)) {
            return null;
        }
        if ($event === 'unit' && $step !== 'unit') {
            return $this->prompt($state);
        }
        if ($event === 'skip' && $value !== null && $value !== $step) {
            return $this->prompt($state);
        }
        if ($event === 'skip' && ! in_array($step, ['category', 'tone'], true)) {
            return new OnboardingReply('نام برند و واحد قیمت الزامی‌اند؛ لطفاً پاسخ دهید.');
        }
        if ($event !== 'skip' && ($value === null || trim($value) === '')) {
            return $this->prompt($state);
        }
        $draft = $state->draft ?? [];
        if ($step === 'unit') {
            $unit = match (trim((string) $value)) {
                'تومان', 'toman' => 'toman',
                'ریال', 'irr' => 'irr',
                default => null,
            };
            if ($unit === null) {
                return new OnboardingReply('واحد قیمت را «تومان» یا «ریال» انتخاب کنید.', $this->unitButtons());
            }
            $draft['currency_display_unit'] = $unit;
        } elseif ($step === 'name' && $event !== 'skip') {
            if (mb_strlen($value) > 255 || str_starts_with($value, '/')) {
                return new OnboardingReply('نام برند باید متن ساده و حداکثر ۲۵۵ نویسه باشد.');
            }
            $draft['name'] = trim($value);
        } else {
            if ($event !== 'skip' && (mb_strlen($value) > 255 || str_starts_with($value, '/'))) {
                return new OnboardingReply('پاسخ باید متن ساده و حداکثر ۲۵۵ نویسه باشد.');
            }
            $draft[$step] = $event === 'skip' ? null : trim($value);
        }

        $next = match ($step) {
            'name' => 'unit', 'unit' => 'category', 'category' => 'tone', 'tone' => 'review',
        };
        $brandVersion = $state->expected_brand_version ?? $this->brands->ensureFor($workspace)->profile_version;
        $this->onboardings->save($state, $next, $draft, $brandVersion);

        return $this->prompt($state);
    }

    private function prompt(TelegramOnboarding $state): OnboardingReply
    {
        return match ($state->step) {
            'name' => new OnboardingReply('نام برندتان چیست؟ (برای اصلاح دوباره همین‌جا نام جدید را بفرستید.)'),
            'unit' => new OnboardingReply('واحد نمایش قیمت را انتخاب کنید: تومان یا ریال.', $this->unitButtons()),
            'category' => new OnboardingReply('دسته کسب‌وکار چیست؟ اختیاری است؛ /skip را بفرستید.', $this->skipButtons('category')),
            'tone' => new OnboardingReply('لحن برند چیست؟ اختیاری است؛ /skip را بفرستید.', $this->skipButtons('tone')),
            'review' => new OnboardingReply($this->summary($state->draft ?? []), [[
                ['text' => 'تأیید برند', 'callback_data' => 'onboard:confirm:'.$this->reviewToken($state)],
                ['text' => 'اصلاح', 'callback_data' => 'onboard:edit'],
            ]]),
            'ready' => new OnboardingReply('برند تأیید شده است. برای مشاهده /mybrand، برای اصلاح /brand و برای شروع پروژه /new را بفرستید؛ عکس محصولات بعد از /new پذیرفته می‌شود.'),
            default => throw new DomainException('INVALID_ONBOARDING_STEP'),
        };
    }

    private function summary(array $draft): string
    {
        $unit = ($draft['currency_display_unit'] ?? null) === 'irr' ? 'ریال' : 'تومان';

        return "لطفاً خلاصه برند را بررسی کنید:\nنام: ".($draft['name'] ?? '—').
            "\nواحد قیمت: {$unit}\nدسته: ".($draft['category'] ?? '—').
            "\nلحن: ".($draft['tone'] ?? '—')."\nتا قبل از تأیید، این داده‌ها وارد حافظه نمی‌شوند.";
    }

    private function unitButtons(): array
    {
        return [[
            ['text' => 'تومان', 'callback_data' => 'onboard:unit:toman'],
            ['text' => 'ریال', 'callback_data' => 'onboard:unit:irr'],
        ]];
    }

    private function skipButtons(string $step): array
    {
        return [[['text' => 'بعداً', 'callback_data' => 'onboard:skip:'.$step]]];
    }

    private function reviewToken(TelegramOnboarding $state): string
    {
        return $state->expected_brand_version.':'.substr(
            hash('sha256', json_encode($state->draft, JSON_THROW_ON_ERROR)), 0, 16
        );
    }
}
