<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Data\Telegram\BotReply;
use App\Data\Wallet\CreditUsageLine;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Billing\CreditTariff;
use Illuminate\Support\Facades\Gate;

/**
 * `/balance` in the bot: the balance, what is held, and the latest charges,
 * each with the project and output it paid for (FR-073). One number per
 * charge; usage and rates stay out of the seller's view (ADR-042).
 */
final readonly class ShowTelegramBalanceAction
{
    public const CHARGES_SHOWN = 5;

    public function __construct(
        private WalletRepositoryInterface $wallets,
        private ListCreditUsageAction $usage,
    ) {}

    public function execute(Workspace $workspace, User $user): BotReply
    {
        $wallet = $this->wallets->forWorkspace($workspace);
        if ($wallet === null || Gate::forUser($user)->denies('view', $wallet)) {
            return new BotReply('موجودی اعتبار تو: 0 اعتبار.');
        }
        // Held credit is still the seller's until its run succeeds (ADR-039).
        $text = $wallet->reserved_cached > 0
            ? "موجودی اعتبار تو: {$wallet->balance_cached} اعتبار، که {$wallet->reserved_cached} اعتبار آن برای کار در حال اجرا (استخراج یا ساخت فایل) رزرو شده است."
            : "موجودی اعتبار تو: {$wallet->balance_cached} اعتبار.";

        $charges = $this->usage->execute($wallet)->charges(self::CHARGES_SHOWN);
        if ($charges !== []) {
            $text .= "\n\nآخرین کسرها:\n".implode("\n", array_map($this->chargeLine(...), $charges));
        }

        return new BotReply($text);
    }

    private function chargeLine(CreditUsageLine $line): string
    {
        $credits = -$line->entry->amount;
        $output = match (true) {
            $line->outputClass === CreditTariff::CLASS_FILE_TO_TEXT => 'استخراج محصول از '.count($line->run->input_asset_ids ?? []).' عکس',
            $line->entry->related_type === CreditLedgerEntry::RELATED_ARTIFACT => 'کاتالوگ PDF',
            default => 'خروجی',
        };
        $project = $line->run->project ?? $line->project;

        return $project === null
            ? "• {$credits} اعتبار: {$output}"
            : "• {$credits} اعتبار: {$output}، پروژه «{$project->name}»";
    }
}
