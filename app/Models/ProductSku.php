<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One SKU reserved by a product in its workspace (ADR-017). */
class ProductSku extends Model
{
    public $timestamps = false;

    protected $fillable = ['workspace_id', 'product_id', 'sku_mac'];
}
