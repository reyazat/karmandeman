<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\AccountDeletionRepositoryInterface;
use App\Repositories\Contracts\AccountErasureRepositoryInterface;
use App\Repositories\Contracts\ApiIdempotencyRepositoryInterface;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\ConversationEventRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ProjectSnapshotRepositoryInterface;
use App\Repositories\Contracts\RetentionRepositoryInterface;
use App\Repositories\Contracts\ServiceRequestRepositoryInterface;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use App\Repositories\Contracts\TelegramMediaIntakeRepositoryInterface;
use App\Repositories\Contracts\TelegramOnboardingRepositoryInterface;
use App\Repositories\Contracts\TelegramOutboxRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceConsentRepositoryInterface;
use App\Repositories\Contracts\WorkspaceDataKeyRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Repositories\Eloquents\AccountDeletion\EloquentAccountDeletionRepository;
use App\Repositories\Eloquents\AccountErasure\EloquentAccountErasureRepository;
use App\Repositories\Eloquents\ApiIdempotency\EloquentApiIdempotencyRepository;
use App\Repositories\Eloquents\Asset\EloquentAssetRepository;
use App\Repositories\Eloquents\Brand\EloquentBrandRepository;
use App\Repositories\Eloquents\ConversationEvent\EloquentConversationEventRepository;
use App\Repositories\Eloquents\Employee\EloquentEmployeeRepository;
use App\Repositories\Eloquents\MemoryItem\EloquentMemoryItemRepository;
use App\Repositories\Eloquents\Product\EloquentProductRepository;
use App\Repositories\Eloquents\Project\EloquentProjectRepository;
use App\Repositories\Eloquents\ProjectSnapshot\EloquentProjectSnapshotRepository;
use App\Repositories\Eloquents\Retention\EloquentRetentionRepository;
use App\Repositories\Eloquents\ServiceRequest\EloquentServiceRequestRepository;
use App\Repositories\Eloquents\TelegramAuthRequest\EloquentTelegramAuthRequestRepository;
use App\Repositories\Eloquents\TelegramMediaIntake\EloquentTelegramMediaIntakeRepository;
use App\Repositories\Eloquents\TelegramOnboarding\EloquentTelegramOnboardingRepository;
use App\Repositories\Eloquents\TelegramOutbox\DatabaseTelegramOutboxRepository;
use App\Repositories\Eloquents\User\EloquentUserRepository;
use App\Repositories\Eloquents\WalletLedger\EloquentWalletRepository;
use App\Repositories\Eloquents\Workspace\EloquentWorkspaceRepository;
use App\Repositories\Eloquents\WorkspaceConsent\EloquentWorkspaceConsentRepository;
use App\Repositories\Eloquents\WorkspaceDataKey\EloquentWorkspaceDataKeyRepository;
use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountDeletionRepositoryInterface::class, EloquentAccountDeletionRepository::class);
        $this->app->bind(AccountErasureRepositoryInterface::class, EloquentAccountErasureRepository::class);
        $this->app->bind(TelegramOutboxRepositoryInterface::class, DatabaseTelegramOutboxRepository::class);
        $this->app->bind(ApiIdempotencyRepositoryInterface::class, EloquentApiIdempotencyRepository::class);
        $this->app->bind(TelegramAuthRequestRepositoryInterface::class, EloquentTelegramAuthRequestRepository::class);
        $this->app->bind(TelegramOnboardingRepositoryInterface::class, EloquentTelegramOnboardingRepository::class);
        $this->app->bind(ServiceRequestRepositoryInterface::class, EloquentServiceRequestRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(WorkspaceConsentRepositoryInterface::class, EloquentWorkspaceConsentRepository::class);
        $this->app->bind(WorkspaceDataKeyRepositoryInterface::class, EloquentWorkspaceDataKeyRepository::class);
        $this->app->bind(WorkspaceRepositoryInterface::class, EloquentWorkspaceRepository::class);
        $this->app->bind(EmployeeRepositoryInterface::class, EloquentEmployeeRepository::class);
        $this->app->bind(ProjectRepositoryInterface::class, EloquentProjectRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(ProjectSnapshotRepositoryInterface::class, EloquentProjectSnapshotRepository::class);
        $this->app->bind(AssetRepositoryInterface::class, EloquentAssetRepository::class);
        $this->app->bind(TelegramMediaIntakeRepositoryInterface::class, EloquentTelegramMediaIntakeRepository::class);
        $this->app->bind(RetentionRepositoryInterface::class, EloquentRetentionRepository::class);
        $this->app->bind(WalletRepositoryInterface::class, EloquentWalletRepository::class);
        $this->app->bind(ConversationEventRepositoryInterface::class, EloquentConversationEventRepository::class);
        $this->app->bind(BrandRepositoryInterface::class, EloquentBrandRepository::class);
        $this->app->bind(MemoryItemRepositoryInterface::class, EloquentMemoryItemRepository::class);
    }
}
