<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Privacy\EnsureVoiceIntakeAllowedAction;
use App\Exceptions\UndecryptableValue;
use App\Models\VoiceAsset;
use App\Repositories\Contracts\AiWorkflowRunRepositoryInterface;
use App\Repositories\Contracts\VoiceAssetRepositoryInterface;
use App\Services\AI\AIWorkflowService;
use App\Services\AI\RunAssetUrlSigner;
use App\Services\Storage\OggOpusTagStripper;
use App\Services\Storage\PrivateAssetStore;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * n8n fetches one voice of a run it was given (ADR-047), with the same
 * checks as a run photo (ADR-033, ADR-038): the signed link names one run
 * and one of its input voices, works only after the claim, and stops when
 * the run closes, passes its deadline or the account is being erased. A
 * voice also needs voice intake allowed now: the flag, an approved policy
 * that declares voice and the seller's consent to it. Every refusal is the
 * same answer. The voice leaves without its comment header.
 */
final readonly class ReadRunVoiceAction
{
    public const FORBIDDEN = 'RUN_VOICE_FORBIDDEN';

    public function __construct(
        private AiWorkflowRunRepositoryInterface $runs,
        private VoiceAssetRepositoryInterface $voices,
        private RunAssetUrlSigner $signer,
        private PrivateAssetStore $store,
        private OggOpusTagStripper $stripper,
        private AIWorkflowService $ai,
        private EnsureVoiceIntakeAllowedAction $voiceAllowed,
    ) {}

    /**
     * @return array{VoiceAsset, string} the voice and its cleaned bytes
     *
     * @throws DomainException RUN_VOICE_FORBIDDEN, VOICE_INTEGRITY_FAILED or VOICE_DAMAGED
     */
    public function execute(string $runPublicId, string $voicePublicId, string $expires, string $signature): array
    {
        $runPublicId = strtolower($runPublicId);
        $voicePublicId = strtolower($voicePublicId);
        $run = $this->runs->findByPublicId($runPublicId);
        // Cheap state checks first: during erasure the workspace key may be gone,
        // and the MAC must never reach the key service for a run that cannot serve.
        if ($run === null
            || ! $run->status->isOpen()
            || $run->claimed_at === null
            || $run->workspace->status !== 'active'
            || ! $this->ai->canProcessRun($run)
            || ! in_array($voicePublicId, $run->input_voice_ids ?? [], true)
            || ! $this->signer->validVoice($run, $voicePublicId, $expires, $signature, CarbonImmutable::now()->getTimestamp())) {
            throw new DomainException(self::FORBIDDEN);
        }
        try {
            $this->voiceAllowed->execute($run->workspace);
        } catch (DomainException) {
            throw new DomainException(self::FORBIDDEN);
        }
        $voice = $this->voices->findPublicForWorkspace($run->workspace, $voicePublicId);
        if ($voice === null || $voice->project_id !== $run->project_id) {
            throw new DomainException(self::FORBIDDEN);
        }
        try {
            $bytes = $this->store->readObject($run->workspace, $voice->storage_key, $voice->public_id, PrivateAssetStore::KIND_VOICE);
        } catch (UndecryptableValue) {
            Log::critical('voice.integrity_failed', ['voice' => $voice->public_id]);

            throw new DomainException('VOICE_INTEGRITY_FAILED');
        }

        return [$voice, $this->stripper->strip($bytes)];
    }
}
