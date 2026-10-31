// @vitest-environment node
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Runs the Code nodes of the exported n8n workflow (ADR-036) as n8n would,
 * with the Crypto nodes' HMAC computed here, so the checks are exercised on
 * the exact JavaScript that is imported into n8n.
 */
type Item = { json: Record<string, any>; binary?: Record<string, any> };

const workflow = JSON.parse(readFileSync(new URL('../workflows/product-extraction.v1.json', import.meta.url), 'utf8'));
const BASE = 'http://app';
const SECRET = 'start-token-for-tests-0123456789abcdef';
const RUN = '11111111-2222-4333-8444-555555555555';
const PHOTOS = ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff'];
const NOW = Date.parse('2026-10-31T12:00:00Z');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

function source(name: string, base: string | null = BASE): string {
    const code: string = workflow.nodes.find((node: any) => node.name === name).parameters.jsCode;

    return base === null ? code : code.replace("'__KM_APP_BASE_URL__'", JSON.stringify(base));
}

async function run(name: string, input: Item[], nodes: Record<string, Item[]> = {}, context: object = {}, base: string | null = BASE): Promise<Item[]> {
    const $input = { first: () => input[0], all: () => input };
    const $ = (node: string) => ({ first: () => nodes[node][0], all: () => nodes[node] });

    return new AsyncFunction('$input', '$', source(name, base)).call(context, $input, $);
}

function envelope(overrides: Record<string, any> = {}): Record<string, any> {
    return {
        schema_version: 'product-extraction.input.v1',
        workflow_version: 'product-extraction.v1',
        run_id: RUN,
        project_id: '99999999-2222-4333-8444-555555555555',
        attempt: 1,
        locale: 'fa-IR',
        assets: PHOTOS.map((id) => ({
            id, mime: 'image/jpeg', size_bytes: 160, width: 1, height: 1,
            download_url: `${BASE}/api/webhooks/internal/n8n/runs/${RUN}/assets/${id}?expires=1&signature=s`,
        })),
        limits: { max_candidates: 30, max_model_requests: 40, max_input_tokens: 400000, max_output_tokens: 60000 },
        deadline_at: new Date(NOW + 15 * 60 * 1000).toISOString(),
        callback_url: `${BASE}/api/webhooks/internal/n8n/runs`,
        ...overrides,
    };
}

/** Webhook -> Read request -> Sign request -> Check envelope, as n8n runs them. */
async function check(raw: string, options: { timestamp?: number; secret?: string; signature?: string; base?: string | null } = {}): Promise<Record<string, any>> {
    const timestamp = String(options.timestamp ?? Math.floor(NOW / 1000));
    const signature = options.signature ?? 'v1=' + createHmac('sha256', options.secret ?? SECRET).update(`${timestamp}.${raw}`).digest('hex');
    const [request] = await run('Read request', [{ json: { headers: { 'x-km-timestamp': timestamp, 'x-km-signature': signature } }, binary: { data: {} } }], {}, {
        helpers: { getBinaryDataBuffer: async () => Buffer.from(raw, 'utf8') },
    });
    // The Crypto node, with the start token credential.
    const expected = createHmac('sha256', SECRET).update(`${request.json.timestamp}.${request.json.raw}`).digest('hex');
    const [result] = await run('Check envelope', [{ json: { ...request.json, expected } }], { 'Read request': [request] }, {}, options.base === undefined ? BASE : options.base);

    return result.json;
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Check envelope', () => {
    it('accepts a start Laravel signed for links at its own address', async () => {
        const result = await check(JSON.stringify(envelope()));

        expect(result.valid).toBe(true);
        expect(result.envelope.run_id).toBe(RUN);
    });

    it.each([
        ['an old timestamp', { timestamp: Math.floor(NOW / 1000) - 301 }, 'STALE_TIMESTAMP'],
        ['a future timestamp', { timestamp: Math.floor(NOW / 1000) + 301 }, 'STALE_TIMESTAMP'],
        ['another secret', { secret: 'x'.repeat(40) }, 'BAD_SIGNATURE'],
        ['a missing signature', { signature: '' }, 'BAD_SIGNATURE'],
        ['the address never set at import', { base: null }, 'NOT_CONFIGURED'],
        ['plain http to a public host', { base: 'http://app.example.com' }, 'NOT_CONFIGURED'],
        ['an address with credentials', { base: 'https://user@app.example.com' }, 'NOT_CONFIGURED'],
    ])('refuses %s', async (_label, options, reason) => {
        const result = await check(JSON.stringify(envelope()), options);

        expect(result).toEqual({ valid: false, reason });
    });

    it.each([
        ['a callback elsewhere', envelope({ callback_url: 'http://evil.test/collect' }), 'BAD_CALLBACK_URL'],
        ['a photo link of another run', envelope({ assets: [{ ...envelope().assets[0], download_url: `${BASE}/api/webhooks/internal/n8n/runs/${PHOTOS[1]}/assets/${PHOTOS[0]}?x` }] }), 'BAD_ASSET'],
        ['a photo link elsewhere', envelope({ assets: [{ ...envelope().assets[0], download_url: `http://evil.test/api/webhooks/internal/n8n/runs/${RUN}/assets/${PHOTOS[0]}?` }] }), 'BAD_ASSET'],
        ['the same photo twice', envelope({ assets: [envelope().assets[0], envelope().assets[0]] }), 'BAD_ASSET'],
        ['an id in an array', envelope({ assets: [{ ...envelope().assets[0], id: [PHOTOS[0]] }] }), 'BAD_ASSET'],
        ['an unknown photo field', envelope({ assets: [{ ...envelope().assets[0], extra: 1 }] }), 'BAD_ASSET'],
        ['a run id in an array', envelope({ run_id: [RUN] }), 'BAD_ENVELOPE'],
        ['an unknown root field', envelope({ extra: true }), 'BAD_ENVELOPE'],
        ['too little time left', envelope({ deadline_at: new Date(NOW + 60 * 1000).toISOString() }), 'PAST_DEADLINE'],
        ['no photos', envelope({ assets: [] }), 'BAD_ASSETS'],
        ['a string limit', envelope({ limits: { ...envelope().limits, max_candidates: '30' } }), 'BAD_LIMITS'],
    ])('refuses %s', async (_label, body, reason) => {
        expect(await check(JSON.stringify(body))).toEqual({ valid: false, reason });
    });

    it('refuses bodies that are not one envelope object', async () => {
        for (const raw of ['not json', '[]', 'null', '{"__proto__":{"valid":true}}']) {
            expect((await check(raw)).valid).toBe(false);
        }
    });
});

describe('Model step (fixture)', () => {
    const checked = () => ({ 'Check envelope': [{ json: { valid: true, envelope: envelope() } }] });
    const photo = (mime = 'image/jpeg'): Item => ({ json: {}, binary: { photo: { mimeType: mime } } });

    it('proposes one labelled test product per photo and calls nothing', async () => {
        const [result] = await run('Model step (fixture)', [photo(), photo('image/jpeg; charset=binary')], checked());

        expect(result.json.status).toBe('succeeded');
        expect(result.json.candidates.map((c: any) => [c.name, c.price, c.source_asset_ids])).toEqual([
            ['نمونه آزمایشی 1', null, [PHOTOS[0]]],
            ['نمونه آزمایشی 2', null, [PHOTOS[1]]],
        ]);
        expect(result.json.usage).toEqual({ model_requests: 0, input_tokens: 0, output_tokens: 0, provider: 'fixture', model: 'fixture-1' });
    });

    it.each([
        ['a failed download', [photo(), { json: { error: 'Forbidden' } }]],
        ['another file type', [photo(), photo('text/html')]],
        ['a missing photo', [photo()]],
        ['an extra item', [photo(), photo(), photo()]],
    ])('reports %s instead of guessing', async (_label, photos) => {
        const [result] = await run('Model step (fixture)', photos as Item[], checked());

        expect(result.json).toMatchObject({ status: 'failed', error_code: 'ASSET_FETCH_FAILED', candidates: [] });
    });

    it('stops once the deadline has passed', async () => {
        vi.setSystemTime(NOW + 16 * 60 * 1000);
        const [result] = await run('Model step (fixture)', [photo(), photo()], checked());

        expect(result.json.error_code).toBe('DEADLINE_PASSED');
    });
});

describe('Build callback', () => {
    const usage = { model_requests: 1, input_tokens: 10, output_tokens: 5, provider: 'fixture', model: 'fixture-1' };
    const build = async (step: Record<string, any>) => {
        const [result] = await run('Build callback', [{ json: step }], { 'Check envelope': [{ json: { valid: true, envelope: envelope() } }] });

        return { ...result.json, parsed: JSON.parse(result.json.body) };
    };

    it('signs and sends exactly one output.v1 string to the checked callback', async () => {
        const result = await build({ status: 'succeeded', error_code: null, candidates: [], usage });

        expect(result.url).toBe(`${BASE}/api/webhooks/internal/n8n/runs`);
        expect(result.timestamp).toBe(String(Math.floor(NOW / 1000)));
        expect(Object.keys(result.parsed)).toEqual(['schema_version', 'run_id', 'status', 'error_code', 'usage', 'candidates']);
        expect(result.parsed).toMatchObject({ schema_version: 'product-extraction.output.v1', run_id: RUN, status: 'succeeded', usage });
    });

    it('keeps the token counts of a malformed step so its cost is still recorded', async () => {
        const result = await build({ status: 'succeeded', candidates: [], usage: { ...usage, input_tokens: 1.5 } });

        expect(result.parsed).toMatchObject({ status: 'failed', error_code: 'MODEL_OUTPUT_INVALID', candidates: [] });
        expect(result.parsed.usage).toEqual({ ...usage, input_tokens: 2 });
    });

    it.each([
        ['a provider Laravel refuses', { status: 'succeeded', candidates: [], usage: { ...usage, provider: 'OpenAI' } }, 'MODEL_OUTPUT_INVALID'],
        ['too many candidates', { status: 'succeeded', candidates: new Array(31).fill({}), usage }, 'MODEL_OUTPUT_INVALID'],
        ['an unknown status', { status: 'done', candidates: [], usage }, 'MODEL_OUTPUT_INVALID'],
        ['a bad error code', { status: 'failed', error_code: 'boom', candidates: [], usage }, 'MODEL_FAILED'],
    ])('reports %s as a failure', async (_label, step, code) => {
        const result = await build(step);

        expect(result.parsed).toMatchObject({ status: 'failed', error_code: code, candidates: [] });
        expect(result.parsed.usage.provider).toMatch(/^[a-z0-9][a-z0-9._-]{0,63}$/);
    });
});
