<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Foundation\Http\FormRequest;

/** Product list of one batch, optionally only its review queue (FR-031). */
final class IndexProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'string', 'in:'.ProductRepositoryInterface::FILTER_NEEDS_REVIEW.','.ProductRepositoryInterface::FILTER_INCOMPLETE.','.ProductRepositoryInterface::FILTER_DUPLICATES],
        ];
    }

    public function filter(): ?string
    {
        $filter = $this->validated('filter');

        return is_string($filter) ? $filter : null;
    }
}
