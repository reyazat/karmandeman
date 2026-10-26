<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'category',
        'currency_display_unit',
        'tone',
        'target_audience',
        'brand_description',
        'logo_asset_id',
        'color_palette',
        'contact_channels',
        'catalog_price_visibility_default',
        'default_outputs',
        'required_product_fields',
        'profile_version',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected function casts(): array
    {
        return [
            'color_palette' => 'array',
            'contact_channels' => 'array',
            'catalog_price_visibility_default' => 'boolean',
            'default_outputs' => 'array',
            'required_product_fields' => 'array',
        ];
    }
}
