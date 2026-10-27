import { apiRequest, type ApiError, type ApiResult } from '../../../shared/api/client';
import type { AccountDeletion } from '../types/accountDeletion';

export type SessionResult =
    | { kind: 'session'; token: string }
    | { kind: 'pending'; deletion: AccountDeletion | null }
    | { kind: 'error'; error: ApiError };

export async function startSession(initData: string): Promise<SessionResult> {
    const result = await apiRequest<{ token: string }>('/auth/telegram/verify', {
        method: 'POST',
        body: { initData },
        idempotencyKey: crypto.randomUUID(),
    });
    if (result.ok) {
        return { kind: 'session', token: result.data.token };
    }
    if (result.error.code === 'ACCOUNT_DELETION_PENDING') {
        const deletion = (result.error.details?.deletion ?? null) as AccountDeletion | null;

        return { kind: 'pending', deletion };
    }

    return { kind: 'error', error: result.error };
}

/** Fresh initData is the re-authentication; `confirm` is the explicit final confirmation (FR-085). */
export function requestAccountDeletion(token: string, initData: string): Promise<ApiResult<AccountDeletion>> {
    return apiRequest<AccountDeletion>('/account/deletion', {
        method: 'POST',
        token,
        body: { initData, confirm: true },
    });
}
