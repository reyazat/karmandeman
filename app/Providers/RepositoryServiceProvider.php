<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Bind Repository contracts to their Eloquent implementations here.
// Example: $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
