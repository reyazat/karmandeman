<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Data\AI\ProductExtractionResult;
use App\Exceptions\WorkflowOutputRejected;
use App\Services\ProductIntake\ProductDraftRules;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;

/**
 * Checks an extraction callback against `product-extraction.output.v1`
 * (n8n/schemas, ADR-032). Unknown keys fail at every level. Each candidate
 * becomes a product draft whose values are all "suggested", then passes the
 * same draft rules as the manual form, so the model can never confirm a value.
 */
final readonly class ProductExtractionOutputValidator
{
    public const SCHEMA_VERSION = 'product-extraction.output.v1';

    public const ROOT_KEYS = ['schema_version', 'run_id', 'status', 'error_code', 'usage', 'candidates'];

    public const USAGE_KEYS = ['model_requests', 'input_tokens', 'output_tokens', 'provider', 'model'];

    public const CANDIDATE_KEYS = ['name', 'sku', 'description_fa', 'price', 'attributes', 'category_path', 'source_asset_ids'];

    public function __construct(private ValidationFactory $validation, private ProductDraftRules $draftRules) {}

    /**
     * @param  array<mixed>  $body  decoded JSON of a verified callback
     *
     * @throws WorkflowOutputRejected
     */
    public function validate(array $body, int $maxCandidates): ProductExtractionResult
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($body)), self::ROOT_KEYS));
        $validator = $this->validation->make($body, [
            'schema_version' => ['required', 'string', 'in:'.self::SCHEMA_VERSION],
            'run_id' => ['required', 'string', 'uuid'],
            'status' => ['required', 'string', 'in:succeeded,failed'],
            'error_code' => ['nullable', 'required_if:status,failed', 'prohibited_if:status,succeeded', 'string', 'regex:/^[A-Z][A-Z0-9_]{0,63}$/'],
            'usage' => ['required', 'array:'.implode(',', self::USAGE_KEYS)],
            'usage.model_requests' => ['required', 'integer:strict', 'min:0'],
            'usage.input_tokens' => ['required', 'integer:strict', 'min:0'],
            'usage.output_tokens' => ['required', 'integer:strict', 'min:0'],
            'usage.provider' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}$/'],
            'usage.model' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,63}$/'],
            'candidates' => ['present', 'array', 'list', 'max:'.$maxCandidates],
            'candidates.*' => ['array:'.implode(',', self::CANDIDATE_KEYS)],
            'candidates.*.name' => ['present', 'nullable', 'string'],
            'candidates.*.sku' => ['present', 'nullable', 'string'],
            'candidates.*.description_fa' => ['present', 'nullable', 'string'],
            'candidates.*.price' => ['present', 'nullable', 'array:amount,unit'],
            // JSON integers only: "1000" or true is not a price anyone gave. Presence is checked per candidate.
            'candidates.*.price.amount' => ['integer:strict'],
            'candidates.*.price.unit' => ['required_with:candidates.*.price', 'string'],
            'candidates.*.attributes' => ['present', 'nullable', 'array', 'list'],
            'candidates.*.attributes.*' => ['array:name,values'],
            'candidates.*.attributes.*.values' => ['present'],
            'candidates.*.category_path' => ['present', 'nullable', 'array', 'list'],
            'candidates.*.source_asset_ids' => ['required', 'array', 'list', 'min:1', 'max:10'],
            // Repeats inside one candidate fail the draft rules; two candidates may cite one photo.
            'candidates.*.source_asset_ids.*' => ['string', 'uuid'],
        ]);
        if ($validator->fails() || $unknown !== []) {
            throw new WorkflowOutputRejected([...$unknown, ...array_keys($validator->errors()->messages())]);
        }

        $candidates = [];
        $failed = [];
        if ($body['status'] === 'succeeded') {
            foreach ($body['candidates'] as $index => $candidate) {
                // An empty object passes the shape rules; a price needs both keys.
                if (is_array($candidate['price']) && ! isset($candidate['price']['amount'], $candidate['price']['unit'])) {
                    $failed[] = "candidates.{$index}.price";

                    continue;
                }
                $draft = $this->draft($candidate);
                $check = $this->validation->make($draft, ProductDraftRules::rules());
                $check->after(function (Validator $v) use ($draft): void {
                    if ($v->errors()->isEmpty()) {
                        $this->draftRules->checkText($v, $draft);
                    }
                    $this->draftRules->checkStatuses($v, $draft);
                });
                if ($check->fails()) {
                    array_push($failed, ...array_map(fn (string $path): string => "candidates.{$index}.{$path}", array_keys($check->errors()->messages())));

                    continue;
                }
                $candidates[] = ['draft' => $draft, 'source_asset_ids' => $draft['image_asset_ids']];
            }
        } elseif ($body['candidates'] !== []) {
            $failed[] = 'candidates';
        }
        if ($failed !== []) {
            throw new WorkflowOutputRejected($failed);
        }

        return new ProductExtractionResult(
            runId: strtolower($body['run_id']),
            succeeded: $body['status'] === 'succeeded',
            errorCode: $body['error_code'] ?? null,
            usage: array_intersect_key($body['usage'], array_flip(self::USAGE_KEYS)),
            candidates: $candidates,
        );
    }

    /**
     * The draft shape of the manual form, every known value only "suggested".
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function draft(array $candidate): array
    {
        $field = fn (mixed $value): array => ['value' => $value, 'status' => $value === null ? 'unknown' : 'suggested'];
        $draft = [
            'name' => $field($candidate['name']),
            'sku' => $field($candidate['sku']),
            'description_fa' => $field($candidate['description_fa']),
            'attributes' => array_map(fn (array $attribute): array => [
                'name' => $attribute['name'] ?? null,
                'values' => $attribute['values'],
                'status' => $attribute['values'] === null ? 'unknown' : 'suggested',
            ], $candidate['attributes'] ?? []),
            'image_asset_ids' => array_map(fn (string $id): string => strtolower($id), array_values($candidate['source_asset_ids'])),
        ];
        if ($candidate['price'] !== null) {
            $draft['pricing'] = [
                'regular_price' => $candidate['price']['amount'],
                'input_unit' => $candidate['price']['unit'],
                'status' => 'suggested',
            ];
        }
        if ($candidate['category_path'] !== null) {
            $draft['category_path'] = $field($candidate['category_path']);
        }

        return $draft;
    }
}
