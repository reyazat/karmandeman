export interface ApiError {
    code: string;
    message: string;
    details?: Record<string, unknown>;
}

export type ApiResult<T> =
    | { ok: true; status: number; data: T }
    | { ok: false; status: number; error: ApiError };

interface RequestOptions {
    method?: 'GET' | 'POST' | 'PATCH';
    body?: unknown;
    token?: string;
    idempotencyKey?: string;
}

const fallbackError: ApiError = { code: 'REQUEST_FAILED', message: 'امکان انجام درخواست وجود ندارد.' };

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/** Thin client for the Laravel /api/v1 envelope; the backend stays authoritative. */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<ApiResult<T>> {
    const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
    if (options.token) {
        headers.Authorization = `Bearer ${options.token}`;
    }
    if (options.idempotencyKey) {
        headers['Idempotency-Key'] = options.idempotencyKey;
    }

    let response: Response;
    try {
        response = await fetch(`/api/v1${path}`, {
            method: options.method ?? 'GET',
            headers,
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
        });
    } catch {
        return { ok: false, status: 0, error: { code: 'NETWORK_ERROR', message: 'ارتباط با سرور برقرار نشد.' } };
    }

    const payload: unknown = await response.json().catch(() => null);
    if (response.ok && isRecord(payload) && 'data' in payload) {
        return { ok: true, status: response.status, data: payload.data as T };
    }
    const error = isRecord(payload) && isRecord(payload.error) ? (payload.error as unknown as ApiError) : fallbackError;

    return { ok: false, status: response.status, error };
}
