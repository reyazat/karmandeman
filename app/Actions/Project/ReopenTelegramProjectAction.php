<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Telegram\BotReply;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Services\Telegram\TelegramProductCard;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The bot's "reopen" button (ADR-029): an approved batch goes back to review
 * for exactly the version the offer showed, becomes the bot's current batch,
 * and every product comes back as a card to reply to. Refusals are answered.
 */
final readonly class ReopenTelegramProjectAction
{
    private const MAX_CARDS = 10;

    public function __construct(
        private ReopenProjectAction $reopen,
        private ProjectRepositoryInterface $projects,
        private ProductRepositoryInterface $products,
        private TelegramProductCard $card,
    ) {}

    /**
     * @return list<BotReply> the summary first, then one card per product
     */
    public function execute(Workspace $workspace, User $user, string $callbackId, string $projectPublicId, int $version): array
    {
        try {
            [$project] = $this->reopen->execute(
                $workspace, $user, 'telegram-cb-'.substr(hash('sha256', $callbackId), 0, 48), $projectPublicId, $version,
            );
        } catch (AuthorizationException) {
            return [new BotReply('پروژه یافت نشد.')];
        } catch (DomainException $e) {
            return [new BotReply(match ($e->getMessage()) {
                'PROJECT_NOT_FOUND' => 'پروژه یافت نشد.',
                'PROJECT_VERSION_CONFLICT' => 'پروژه پس از این پیام تغییر کرده است؛ دوباره به کارت محصول پاسخ بده.',
                'PROJECT_STATE_CONFLICT' => 'این پروژه در وضعیت تأییدشده نیست و بازگشایی نمی‌شود.',
                'POLICY_CONSENT_REQUIRED' => 'پیش از ادامه، شرایط و سیاست داده را با /start بپذیر.',
                // Never thrown: a rollback would also drop the button's acknowledgement.
                default => 'بازگشایی انجام نشد؛ دوباره دکمه را بزن.',
            })];
        }
        // Photos stay refused (review, not input); /done shows this batch again (ADR-028).
        $this->projects->selectForBot($project);

        $active = $this->products->activeForProject($workspace, $project);
        $replies = [new BotReply(
            "پروژه «{$project->name}» برای اصلاح باز شد و پروژه جاری ربات است. نسخه نهایی قبلی دیگر خروجی معتبر نیست.\n"
            .'به کارت هر محصول پاسخ بده تا اصلاح شود'
            .($active->count() > self::MAX_CARDS ? ' (۱۰ کارت اول در زیر آمده‌اند)' : '')
            .'؛ سپس /done را بفرست و پروژه را دوباره تأیید کن.',
        )];
        foreach ($active->take(self::MAX_CARDS) as $product) {
            $revision = $this->products->revision($product, $product->current_revision);
            if ($revision !== null) {
                $replies[] = $this->card->reply($product, $revision, 'محصول: '.$this->card->summary($revision));
            }
        }

        return $replies;
    }
}
