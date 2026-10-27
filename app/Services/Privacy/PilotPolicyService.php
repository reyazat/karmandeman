<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Data\Privacy\PilotPolicy;
use DateTimeImmutable;
use Throwable;

final class PilotPolicyService
{
    public function current(): PilotPolicy
    {
        try {
            $directory = (string) config('pilot_policy.directory');
            $manifest = json_decode($this->read($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $terms = $this->read($directory.'/terms.fa.txt');
            $privacy = $this->read($directory.'/privacy.fa.txt');
            $retentionText = $this->read($directory.'/retention.json');
            $retention = json_decode($retentionText, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! is_array($retention) || ! is_array($retention['categories'] ?? null)) {
                throw new \RuntimeException('Malformed policy bundle.');
            }

            $digest = hash('sha256', json_encode([
                'version' => $manifest['version'] ?? null,
                'scope' => $manifest['scope'] ?? null,
                'operator' => $manifest['operator'] ?? null,
                'release_evidence' => $manifest['release_evidence'] ?? null,
                'terms' => $terms,
                'privacy' => $privacy,
                'retention' => $retentionText,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $blockers = [];
            $version = $manifest['version'] ?? '';
            if (! is_string($version) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $version)) {
                $blockers[] = 'POLICY_VERSION_INVALID';
                $version = 'unavailable';
            }
            if (($manifest['scope'] ?? null) !== 'telegram_onboarding') {
                $blockers[] = 'POLICY_SCOPE_INVALID';
            }
            foreach (['name', 'country', 'audience', 'privacy_contact', 'deletion_contact'] as $field) {
                if (! $this->filled($manifest['operator'][$field] ?? null)) {
                    $blockers[] = 'OPERATOR_'.strtoupper($field).'_MISSING';
                }
            }
            if (($manifest['status'] ?? null) !== 'approved') {
                $blockers[] = 'POLICY_NOT_APPROVED';
            }
            if (($retention['status'] ?? null) !== 'approved') {
                $blockers[] = 'RETENTION_NOT_APPROVED';
            }
            foreach (['product_owner', 'privacy_reviewer'] as $field) {
                if (! $this->filled($manifest['approval'][$field] ?? null)) {
                    $blockers[] = 'APPROVAL_'.strtoupper($field).'_MISSING';
                }
            }
            $approvedAt = $manifest['approval']['approved_at'] ?? null;
            if (! is_string($approvedAt) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $approvedAt)) {
                $blockers[] = 'APPROVAL_DATE_INVALID';
            } else {
                $date = new DateTimeImmutable($approvedAt);
                if ($date->format('Y-m-d\TH:i:s\Z') !== $approvedAt || $date->getTimestamp() > time()) {
                    $blockers[] = 'APPROVAL_DATE_INVALID';
                }
            }
            $approvedDigest = $manifest['approval']['bundle_sha256'] ?? null;
            if (! is_string($approvedDigest) || ! hash_equals($digest, $approvedDigest)) {
                $blockers[] = 'APPROVAL_DIGEST_MISMATCH';
            }
            foreach (['retention_enforcement', 'complete_erasure_and_restore', 'encryption_and_key_separation', 'support_and_processor_inventory'] as $field) {
                if (! $this->filled($manifest['release_evidence'][$field] ?? null)) {
                    $blockers[] = 'EVIDENCE_'.strtoupper($field).'_MISSING';
                }
            }
            $rows = [];
            foreach ($retention['categories'] as $category) {
                if (! is_array($category) || ! $this->filled($category['label'] ?? null)
                    || ! $this->filled($category['limit'] ?? null) || ! $this->filled($category['clock'] ?? null)) {
                    throw new \RuntimeException('Malformed retention category.');
                }
                $rows[] = $category['label'].' — '.$category['limit'].'. مبدأ: '.$category['clock'];
            }
            if ($rows === []) {
                $blockers[] = 'RETENTION_SCHEDULE_EMPTY';
            }

            return new PilotPolicy($version, $digest, $terms, $privacy, implode("\n\n", $rows), $blockers);
        } catch (Throwable) {
            return new PilotPolicy('unavailable', '', '', '', '', ['POLICY_BUNDLE_INVALID']);
        }
    }

    private function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException('Policy file unavailable.');
        }
        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new \RuntimeException('Policy file empty.');
        }

        return $contents;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
