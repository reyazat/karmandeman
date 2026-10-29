<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Telegram\BotReply;
use App\Enums\ProductStatus;
use App\Enums\ProjectState;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\Telegram\TelegramProductCard;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * `/done` in the bot (ADR-027): closes the input of the bot's current batch
 * (ADR-028), or shows it again once in review, with one card and
 * approve button per product still waiting for Gate A and, when none is, the
 * batch approval button for the version shown. Refusals are answered.
 */
final readonly class FinishTelegramBatchAction
{
    private const MAX_CARDS = 10;

    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private FinishProjectInputAction $finish,
        private TelegramProductCard $card,
    ) {}

    /**
     * @return list<BotReply> the summary first, then one card per waiting product
     */
    public function execute(Workspace $workspace, User $user, string $messageKey): array
    {
        // The same current batch photos and /product use (ADR-028).
        $project = $this->projects->currentForBot($workspace);
        if ($project === null) {
            return [new BotReply('پروژه بازی نداری؛ با /new پروژه بساز.')];
        }
        if (! in_array($project->state, [ProjectState::CollectingInput, ProjectState::ReviewRequired], true)) {
            return [new BotReply("پروژه جاری «{$project->name}» بسته است؛ با /projects پروژه دیگری انتخاب کن یا با /new بساز.")];
        }
        if ($project->state === ProjectState::CollectingInput) {
            try {
                [$project] = $this->finish->execute($workspace, $user, 'telegram-'.substr($messageKey, 0, 48), $project->public_id, $project->version);
            } catch (AuthorizationException) {
                return [new BotReply('پروژه یافت نشد.')];
            } catch (DomainException $e) {
                return [new BotReply(match ($e->getMessage()) {
                    'PROJECT_HAS_NO_PRODUCTS' => "پروژه «{$project->name}» هنوز محصولی ندارد؛ اول با /product محصول ثبت کن.",
                    'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                    default => 'پایان ورودی انجام نشد؛ دوباره /done را بفرست.',
                })];
            }
        }

        $active = $this->products->activeForProject($workspace, $project);
        // Approved means its current revision is the approved one, as batch approval checks.
        $pending = $active->reject(fn (Product $product): bool => $product->status === ProductStatus::Approved
            && $product->approved_revision === $product->current_revision)->values();
        if ($active->isEmpty()) {
            return [new BotReply("پروژه «{$project->name}» محصول فعالی ندارد و تأیید نمی‌شود.")];
        }
        if ($pending->isEmpty()) {
            return [new BotReply(
                "ورودی پروژه «{$project->name}» بسته است و هر {$active->count()} محصول تأیید شده‌اند. برای ثبت نسخه نهایی پروژه را تأیید کن.",
                [[['text' => 'تأیید پروژه', 'callback_data' => "project:approve:{$project->public_id}:{$project->version}"]]],
            )];
        }

        $replies = [new BotReply(
            "ورودی پروژه «{$project->name}» بسته است. {$pending->count()} از {$active->count()} محصول هنوز تأیید نشده‌اند"
            .($pending->count() > self::MAX_CARDS ? '؛ ۱۰ مورد اول در زیر آمده‌اند' : '')
            .'. پس از تأیید همه، دوباره /done را بفرست.',
        )];
        // Each button travels with the exact revision it approves (ADR-026).
        foreach ($pending->take(self::MAX_CARDS) as $product) {
            $revision = $this->products->revision($product, $product->current_revision);
            if ($revision !== null) {
                $replies[] = $this->card->reply($product, $revision, 'در انتظار تأیید: '.$this->card->summary($revision));
            }
        }

        return $replies;
    }
}
