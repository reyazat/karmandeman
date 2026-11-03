<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\BotReply;
use App\Enums\ProjectState;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProjectRepositoryInterface;

/**
 * `/projects` in the bot (FR-080, ADR-028, ADR-044): the latest batches with
 * date, state, files and credit used, and a select button for each other
 * batch still open for input (ADR-041).
 */
final readonly class TelegramProjectList
{
    public const SHOWN = 5;

    public function __construct(private ProjectRepositoryInterface $projects) {}

    public function reply(Workspace $workspace): BotReply
    {
        /** @var list<Project> $latest */
        $latest = $this->projects->paginateFor($workspace, self::SHOWN)->items();
        if ($latest === []) {
            return new BotReply('هنوز پروژه‌ای نداری. با /new یکی بساز یا عکس محصول را بفرست.');
        }
        $current = $this->projects->currentForBot($workspace);
        $activity = $this->projects->activityFor($workspace, $latest);
        $lines = array_map(function (Project $project) use ($current, $activity): string {
            $used = $activity[$project->id];

            return "• {$project->name} — ".self::label($project->state).' — '.$project->created_at?->format('Y-m-d')
                .($project->id === $current?->id ? ' (جاری)' : '')
                ."\n  {$used->photos} عکس، {$used->tables} فایل CSV، ".($used->voices > 0 ? "{$used->voices} ویس، " : '')
                ."{$used->products} محصول؛ مصرف {$used->creditsUsed} اعتبار";
        }, $latest);
        // Open batches can be made current (ADR-028), beyond the newest few if needed.
        $buttons = array_values(array_map(
            fn (Project $project): array => [['text' => 'انتخاب: '.mb_strimwidth((string) $project->name, 0, 30, '…'), 'callback_data' => "project:select:{$project->public_id}"]],
            array_filter($this->projects->openForBot($workspace, 10), fn (Project $project): bool => $project->id !== $current?->id),
        ));

        return new BotReply("آخرین پروژه‌ها:\n".implode("\n", $lines), $buttons);
    }

    public static function label(ProjectState $state): string
    {
        return match ($state) {
            ProjectState::New, ProjectState::CollectingInput => 'در حال دریافت ورودی',
            ProjectState::Extracting => 'در حال استخراج',
            ProjectState::NeedsClarification => 'منتظر پاسخ تو',
            ProjectState::ReviewRequired => 'در بررسی',
            ProjectState::ProductApproved => 'تأییدشده',
            ProjectState::Quoted => 'پیش‌فاکتور خروجی',
            ProjectState::CreditReserved => 'اعتبار خروجی رزرو شده',
            ProjectState::Generating => 'در حال ساخت خروجی',
            ProjectState::OutputReviewRequired => 'بررسی خروجی',
            ProjectState::Completed => 'کامل',
            ProjectState::Cancelled => 'لغوشده',
            ProjectState::RetryableFailure => 'خطا؛ قابل تکرار',
            ProjectState::Failed => 'ناموفق',
            ProjectState::Expired => 'منقضی',
        };
    }
}
