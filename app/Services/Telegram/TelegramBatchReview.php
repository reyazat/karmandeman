<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Data\Telegram\BotReply;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductRevision;
use App\Models\Project;
use App\Models\Workspace;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductIntake\SuggestedPriceGroup;
use Illuminate\Support\Collection;

/**
 * What the bot shows for a batch in review (ADR-027, ADR-035): the batch
 * approval button when every product passed Gate A; otherwise the server
 * counts (FR-040), then one grouped question when two or more products carry
 * a suggested price (FR-032), or else one card per waiting product. The
 * grouped question names the batch version it lists, so its answer acts on
 * exactly the products and prices shown.
 */
final readonly class TelegramBatchReview
{
    private const MAX_CARDS = 10;

    public function __construct(
        private ProductRepositoryInterface $products,
        private TelegramProductCard $card,
        private SuggestedPriceGroup $prices,
    ) {}

    /**
     * @return list<BotReply>
     */
    public function replies(Workspace $workspace, Project $project): array
    {
        $active = $this->products->activeForProject($workspace, $project);
        $pending = $this->pending($active);
        if ($active->isEmpty()) {
            return [new BotReply("پروژه «{$project->name}» محصول فعالی ندارد و تأیید نمی‌شود.")];
        }
        if ($pending->isEmpty()) {
            return [new BotReply(
                "ورودی پروژه «{$project->name}» بسته است و هر {$active->count()} محصول تأیید شده‌اند. برای ثبت نسخه نهایی پروژه را تأیید کن.",
                [[['text' => 'تأیید پروژه', 'callback_data' => "project:approve:{$project->public_id}:{$project->version}"]]],
            )];
        }

        $summary = "ورودی پروژه «{$project->name}» بسته است. {$pending->count()} از {$active->count()} محصول هنوز تأیید نشده‌اند"
            .$this->counts($pending).'. پس از تأیید همه، دوباره /done را بفرست.';
        $grouped = $this->prices->list($workspace, $project);
        // Rows the seller cannot tell apart are never answered together.
        $labels = array_map(fn (array $row): string => $this->card->priceLabel($row[1]), $grouped);
        if (count($grouped) >= 2 && count(array_unique($labels)) === count($labels)) {
            return [new BotReply($summary), $this->priceQuestion($project, $grouped)];
        }

        return [new BotReply($summary), ...$this->cards($workspace, $project)];
    }

    /**
     * One card per product still waiting for Gate A, each with the exact
     * revision its buttons act on (ADR-026).
     *
     * @return list<BotReply>
     */
    public function cards(Workspace $workspace, Project $project): array
    {
        $pending = $this->pending($this->products->activeForProject($workspace, $project));
        $replies = $pending->count() > self::MAX_CARDS
            ? [new BotReply("{$pending->count()} محصول در انتظار تأییدند؛ ۱۰ مورد اول در زیر آمده‌اند.")]
            : [];
        foreach ($pending->take(self::MAX_CARDS) as $product) {
            $revision = $this->products->revision($product, $product->current_revision);
            if ($revision !== null) {
                $replies[] = $this->card->reply($product, $revision, 'در انتظار تأیید: '.$this->card->summary($revision));
            }
        }

        return $replies;
    }

    /** @param  list<array{Product, ProductRevision}>  $grouped */
    private function priceQuestion(Project $project, array $grouped): BotReply
    {
        $lines = [];
        foreach ($grouped as $index => [, $revision]) {
            $lines[] = ($index + 1).'. '.$this->card->priceLine($revision);
        }
        $id = "{$project->public_id}:{$project->version}";

        return new BotReply(
            'قیمت این '.count($grouped).' محصول از عکس‌ها پیشنهاد شده است'
            .(count($grouped) === SuggestedPriceGroup::MAX ? ' (حداکثر '.SuggestedPriceGroup::MAX.' مورد در هر پرسش)' : '')
            .":\n".implode("\n", $lines)
            ."\nفقط قیمت‌ها تأیید یا حذف می‌شوند؛ بقیه مقدارها روی کارت هر محصول می‌مانند.",
            [
                [['text' => 'تأیید همین قیمت‌ها', 'callback_data' => "project:prices:{$id}"]],
                [['text' => 'فعلاً همه بدون قیمت', 'callback_data' => "project:noprices:{$id}"]],
                [['text' => 'بررسی تک‌به‌تک', 'callback_data' => "project:cards:{$id}"]],
            ],
        );
    }

    /** @param  Collection<int, Product>  $pending */
    private function counts(Collection $pending): string
    {
        $parts = array_filter([
            $pending->where('needs_confirmation', true)->count().' مقدار پیشنهادی یا نام خالی دارند',
            $pending->where('price_missing', true)->count().' بدون قیمت‌اند',
            $pending->where('image_missing', true)->count().' بدون عکس‌اند',
        ], fn (string $part): bool => ! str_starts_with($part, '0 '));

        return $parts === [] ? '' : ' ('.implode('، ', $parts).')';
    }

    /**
     * Approved means its current revision is the approved one, as batch approval checks.
     *
     * @param  Collection<int, Product>  $active
     * @return Collection<int, Product>
     */
    private function pending(Collection $active): Collection
    {
        return $active->reject(fn (Product $product): bool => $product->status === ProductStatus::Approved
            && $product->approved_revision === $product->current_revision)->values();
    }
}
