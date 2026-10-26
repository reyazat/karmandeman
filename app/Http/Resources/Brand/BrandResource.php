<?php

declare(strict_types=1);

namespace App\Http\Resources\Brand;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Brand */
class BrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'currency_display_unit' => $this->currency_display_unit,
            'tone' => $this->tone,
            'target_audience' => $this->target_audience,
            'brand_description' => $this->brand_description,
            'logo_asset_id' => $this->logo_asset_id,
            'color_palette' => $this->color_palette,
            'contact_channels' => $this->contact_channels,
            'catalog_price_visibility_default' => $this->catalog_price_visibility_default,
            'default_outputs' => $this->default_outputs,
            'required_product_fields' => $this->required_product_fields,
            'profile_version' => $this->profile_version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
