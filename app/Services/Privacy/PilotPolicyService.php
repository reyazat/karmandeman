<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Data\Privacy\PilotPolicy;
use App\Models\Workspace;
use App\Repositories\Contracts\UserRepositoryInterface;
use DateTimeImmutable;
use Throwable;

/**
 * The policy bundle a seller accepts (FR-002). Everyone gets the selected,
 * approved bundle. Telegram accounts on the owner's tester list get the
 * selected test bundle instead (ADR-057): an unapproved draft whose approval
 * and evidence blockers are waived for them only, under its own "-test"
 * version and digest, so a tester's consent never counts as consent to a
 * published bundle and nobody else is let in.
 */
final class PilotPolicyService
{
    /** Blockers about approval and evidence: the only ones a test bundle may carry. */
    private const WAIVED_FOR_TESTERS = [
        'POLICY_NOT_APPROVED', 'RETENTION_NOT_APPROVED', 'APPROVAL_PRODUCT_OWNER_MISSING', 'APPROVAL_PRIVACY_REVIEWER_MISSING',
        'APPROVAL_DATE_INVALID', 'APPROVAL_DIGEST_MISMATCH', 'EVIDENCE_RETENTION_ENFORCEMENT_MISSING',
        'EVIDENCE_COMPLETE_ERASURE_AND_RESTORE_MISSING', 'EVIDENCE_ENCRYPTION_AND_KEY_SEPARATION_MISSING',
        'EVIDENCE_SUPPORT_AND_PROCESSOR_INVENTORY_MISSING',
    ];

    public function __construct(private UserRepositoryInterface $users) {}

    /** The published bundle: what every seller who is not a tester accepts. */
    public function current(): PilotPolicy
    {
        return $this->load((string) config('pilot_policy.directory'));
    }

    /** The bundle for this Telegram account: the test bundle for a tester, otherwise the published one. */
    public function forTelegramId(int|string|null $telegramId): PilotPolicy
    {
        return $telegramId !== null && $this->isTester($telegramId) ? $this->test() : $this->current();
    }

    /** The bundle for the workspace's owner. */
    public function forWorkspace(Workspace $workspace): PilotPolicy
    {
        $owner = $this->users->find($workspace->owner_id);

        return $this->forTelegramId($owner === null ? null : $this->users->telegramChatId($owner));
    }

    public function isTester(int|string $telegramId): bool
    {
        return (string) config('pilot_policy.test_directory') !== ''
            && in_array((string) $telegramId, (array) config('pilot_policy.tester_telegram_ids'), true);
    }

    public function isTesterWorkspace(Workspace $workspace): bool
    {
        return $this->forWorkspace($workspace)->testOnly;
    }

    private function test(): PilotPolicy
    {
        $draft = $this->load((string) config('pilot_policy.test_directory'));
        $blocking = array_values(array_diff($draft->blockers, self::WAIVED_FOR_TESTERS));
        if ($blocking !== []) {
            return $draft;
        }

        return new PilotPolicy(
            // Its own version and digest: a tester's consent is never consent to the published bundle.
            $draft->version.'-test', hash('sha256', 'test:'.$draft->digest),
            PilotPolicy::TEST_NOTICE."\n\n".$draft->terms, $draft->privacy, $draft->retention,
            [], $draft->aiProviders, $draft->voiceIntake, testOnly: true,
        );
    }

    private function load(string $directory): PilotPolicy
    {
        try {
            $manifest = json_decode($this->read($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $terms = $this->read($directory.'/terms.fa.txt');
            $privacy = $this->read($directory.'/privacy.fa.txt');
            $retentionText = $this->read($directory.'/retention.json');
            $retention = json_decode($retentionText, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! is_array($retention) || ! is_array($retention['categories'] ?? null)) {
                throw new \RuntimeException('Malformed policy bundle.');
            }

            $blockers = [];
            $signed = [
                'version' => $manifest['version'] ?? null,
                'scope' => $manifest['scope'] ?? null,
                'operator' => $manifest['operator'] ?? null,
                'release_evidence' => $manifest['release_evidence'] ?? null,
                'terms' => $terms,
                'privacy' => $privacy,
                'retention' => $retentionText,
            ];
            // AI processors are part of what the seller consents to (ADR-037). Only bundles that
            // declare them sign them, so the digest and consent of earlier bundles stay valid.
            if (array_key_exists('ai_processing', $manifest)) {
                $signed['ai_processing'] = $manifest['ai_processing'];
            }
            if (array_key_exists('voice_intake', $manifest)) {
                $signed['voice_intake'] = $manifest['voice_intake'];
            }
            $digest = hash('sha256', json_encode($signed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $voiceIntake = $manifest['voice_intake'] ?? null;
            if ($voiceIntake !== null && $voiceIntake !== ['enabled' => true]) {
                $blockers[] = 'VOICE_INTAKE_INVALID';
            }
            $providers = $manifest['ai_processing']['providers'] ?? [];
            if (! is_array($providers) || ! array_is_list($providers)
                || array_filter($providers, fn (mixed $provider): bool => ! is_string($provider) || preg_match('/^[a-z0-9-]{1,32}$/D', $provider) !== 1) !== []) {
                $blockers[] = 'AI_PROCESSING_INVALID';
                $providers = [];
            }
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

            return new PilotPolicy($version, $digest, $terms, $privacy, implode("\n\n", $rows), $blockers, $providers, $voiceIntake === ['enabled' => true]);
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
