<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\ApiIdempotencyRepositoryInterface;
use App\Repositories\Contracts\BrandRepositoryInterface;
use App\Repositories\Contracts\ConversationEventRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\MemoryItemRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Repositories\Contracts\ServiceRequestRepositoryInterface;
use App\Repositories\Contracts\TelegramAuthRequestRepositoryInterface;
use App\Repositories\Contracts\TelegramOnboardingRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Contracts\WorkspaceRepositoryInterface;
use App\Repositories\Eloquents\ApiIdempotency\EloquentApiIdempotencyRepository;
use App\Repositories\Eloquents\Brand\EloquentBrandRepository;
use App\Repositories\Eloquents\ConversationEvent\EloquentConversationEventRepository;
use App\Repositories\Eloquents\Employee\EloquentEmployeeRepository;
use App\Repositories\Eloquents\MemoryItem\EloquentMemoryItemRepository;
use App\Repositories\Eloquents\Project\EloquentProjectRepository;
use App\Repositories\Eloquents\User\EloquentTelegramAuthRequestRepository;
use App\Repositories\Eloquents\User\EloquentUserRepository;
use App\Repositories\Eloquents\WalletLedger\EloquentWalletRepository;
use App\Repositories\Eloquents\Workspace\EloquentServiceRequestRepository;
use App\Repositories\Eloquents\Workspace\EloquentTelegramOnboardingRepository;
use App\Repositories\Eloquents\Workspace\EloquentWorkspaceRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ApiIdempotencyRepositoryInterface::class, EloquentApiIdempotencyRepository::class);
        $this->app->bind(TelegramAuthRequestRepositoryInterface::class, EloquentTelegramAuthRequestRepository::class);
        $this->app->bind(TelegramOnboardingRepositoryInterface::class, EloquentTelegramOnboardingRepository::class);
        $this->app->bind(ServiceRequestRepositoryInterface::class, EloquentServiceRequestRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(WorkspaceRepositoryInterface::class, EloquentWorkspaceRepository::class);
        $this->app->bind(EmployeeRepositoryInterface::class, EloquentEmployeeRepository::class);
        $this->app->bind(ProjectRepositoryInterface::class, EloquentProjectRepository::class);
        $this->app->bind(WalletRepositoryInterface::class, EloquentWalletRepository::class);
        $this->app->bind(ConversationEventRepositoryInterface::class, EloquentConversationEventRepository::class);
        $this->app->bind(BrandRepositoryInterface::class, EloquentBrandRepository::class);
        $this->app->bind(MemoryItemRepositoryInterface::class, EloquentMemoryItemRepository::class);
    }
}
