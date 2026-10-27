import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PrivacyPage from './PrivacyPage.vue';

const receipt = {
    id: '0b8a3c1e-2f43-4d4e-9a55-1c2b3d4e5f60',
    status: 'requested',
    requested_at: '2026-10-27T10:00:00+00:00',
    deadline_at: '2026-11-03T10:00:00+00:00',
};

function respond(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function stubTelegram(initData: string | null): void {
    vi.stubGlobal('Telegram', initData === null ? undefined : { WebApp: { initData, ready: vi.fn() } });
}

async function mountReady(fetchMock: ReturnType<typeof vi.fn>) {
    fetchMock.mockResolvedValueOnce(respond(200, { data: { token: 'session-token' } }));
    const wrapper = mount(PrivacyPage);
    await flushPromises();

    return wrapper;
}

describe('PrivacyPage', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        stubTelegram('signed-init-data');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('refuses to work outside Telegram and calls no API', async () => {
        stubTelegram(null);
        const wrapper = mount(PrivacyPage);
        await flushPromises();

        expect(wrapper.text()).toContain('فقط از داخل Mini App');
        expect(wrapper.find('button').exists()).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('requires the irreversibility acknowledgement and cancel sends nothing', async () => {
        const wrapper = await mountReady(fetchMock);
        await wrapper.get('button').trigger('click');

        const confirm = wrapper.findAll('button')[0];
        expect(confirm.attributes('disabled')).toBeDefined();
        await confirm.trigger('click');
        await wrapper.findAll('button')[1].trigger('click');

        expect(wrapper.text()).toContain('حذف کامل داده‌ها و حساب');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('sends fresh initData with explicit confirmation and shows the receipt', async () => {
        const wrapper = await mountReady(fetchMock);
        fetchMock.mockResolvedValueOnce(respond(201, { data: receipt }));

        await wrapper.get('button').trigger('click');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit];
        expect(url).toBe('/api/v1/account/deletion');
        expect((init.headers as Record<string, string>).Authorization).toBe('Bearer session-token');
        expect(JSON.parse(init.body as string)).toEqual({ initData: 'signed-init-data', confirm: true });
        expect(wrapper.text()).toContain(receipt.id);
        expect(wrapper.text()).toContain('در حال رسیدگی');
        expect(wrapper.text()).not.toContain('بله، همه داده‌ها');
    });

    it('shows the existing receipt when the account is already contained', async () => {
        fetchMock.mockResolvedValueOnce(respond(409, {
            error: { code: 'ACCOUNT_DELETION_PENDING', message: 'pending', details: { deletion: receipt } },
        }));
        const wrapper = mount(PrivacyPage);
        await flushPromises();

        expect(wrapper.text()).toContain(receipt.id);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('asks to reopen the Mini App when re-authentication is stale', async () => {
        const wrapper = await mountReady(fetchMock);
        fetchMock.mockResolvedValueOnce(respond(401, { error: { code: 'REAUTHENTICATION_REQUIRED', message: 'stale' } }));

        await wrapper.get('button').trigger('click');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('دوباره باز کنید');
        expect(wrapper.text()).toContain('حذفی ثبت نشد');
    });

    it('reports network failure without claiming deletion', async () => {
        const wrapper = await mountReady(fetchMock);
        fetchMock.mockRejectedValueOnce(new TypeError('offline'));

        await wrapper.get('button').trigger('click');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('ارتباط با سرور برقرار نشد');
        expect(wrapper.text()).not.toContain('در حال رسیدگی');
    });
});
