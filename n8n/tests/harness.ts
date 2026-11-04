import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';

/**
 * Runs the Code nodes of an exported n8n workflow (ADR-036) as n8n would,
 * with the Crypto nodes' HMAC computed here, so the checks are exercised on
 * the exact JavaScript that is imported into n8n.
 */
export type Item = { json: Record<string, any>; binary?: Record<string, any> };

export const BASE = 'http://app';
export const SECRET = 'start-token-for-tests-0123456789abcdef';
export const RUN = '11111111-2222-4333-8444-555555555555';
export const PHOTOS = ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff'];
export const NOW = Date.parse('2026-10-31T12:00:00Z');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

export function readSchema(name: string): Record<string, any> {
    return JSON.parse(readFileSync(new URL(`../schemas/${name}`, import.meta.url), 'utf8'));
}

/**
 * @param file an export in n8n/workflows; @param version the workflow_version Laravel starts it with;
 * @param input the input schema version the export reads (v2 adds seller text and tone, ADR-050)
 */
export function harness(file: string, version: string, input = 'product-extraction.input.v1') {
    const v2 = input === 'product-extraction.input.v2';
    const workflow = JSON.parse(readFileSync(new URL(`../workflows/${file}`, import.meta.url), 'utf8'));

    function source(name: string, base: string | null = BASE): string {
        const code: string = workflow.nodes.find((node: any) => node.name === name).parameters.jsCode;

        return base === null ? code : code.replace("'__KM_APP_BASE_URL__'", JSON.stringify(base));
    }

    async function run(name: string, input: Item[], nodes: Record<string, Item[]> = {}, context: object = {}, base: string | null = BASE): Promise<Item[]> {
        const $input = { first: () => input[0], all: () => input };
        const $ = (node: string) => ({ first: () => nodes[node][0], all: () => nodes[node] });
        const $execution = { id: '4217' };

        return new AsyncFunction('$input', '$', '$execution', source(name, base)).call(context, $input, $, $execution);
    }

    function envelope(overrides: Record<string, any> = {}): Record<string, any> {
        return {
            schema_version: input,
            workflow_version: version,
            run_id: RUN,
            project_id: '99999999-2222-4333-8444-555555555555',
            attempt: 1,
            locale: 'fa-IR',
            ...(v2 ? { tone: null } : {}),
            assets: PHOTOS.map((id) => ({
                id, mime: 'image/jpeg', size_bytes: 160, width: 1, height: 1,
                download_url: `${BASE}/api/webhooks/internal/n8n/runs/${RUN}/assets/${id}?expires=1&signature=s`,
                ...(v2 ? { seller_text: null } : {}),
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

    return { workflow, run, envelope, check };
}
