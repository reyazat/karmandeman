<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\WorkspaceEncrypted;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $public_id */
class Brand extends Model
{
    use HasPublicId;

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
        // Private brand profile fields use the workspace key (NFR-11, ADR-013).
        return [
            'name' => WorkspaceEncrypted::class,
            'category' => WorkspaceEncrypted::class,
            'tone' => WorkspaceEncrypted::class,
            'target_audience' => WorkspaceEncrypted::class,
            'brand_description' => WorkspaceEncrypted::class,
            'color_palette' => WorkspaceEncrypted::class.':array',
            'contact_channels' => WorkspaceEncrypted::class.':array',
            'default_outputs' => WorkspaceEncrypted::class.':array',
            'catalog_price_visibility_default' => 'boolean',
            'required_product_fields' => 'array',
        ];
    }
}
