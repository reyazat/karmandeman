<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { telegramInitData } from '../../../shared/telegram/webApp';
import { requestAccountDeletion, startSession } from '../api/accountDeletion';
import type { AccountDeletion } from '../types/accountDeletion';

type State = 'loading' | 'outside-telegram' | 'ready' | 'confirming' | 'submitting' | 'deleted' | 'reauth' | 'error';

const state = ref<State>('loading');
const errorMessage = ref('');
const deletion = ref<AccountDeletion | null>(null);
const acknowledged = ref(false);
const confirmHeading = ref<HTMLElement | null>(null);
let token = '';
let initData = '';

const utc = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' });
const formatUtc = (iso: string): string => `${utc.format(new Date(iso))} (UTC)`;

onMounted(async () => {
    initData = telegramInitData() ?? '';
    if (initData === '') {
        state.value = 'outside-telegram';

        return;
    }
    const session = await startSession(initData);
    if (session.kind === 'session') {
        token = session.token;
        state.value = 'ready';
    } else if (session.kind === 'pending') {
        deletion.value = session.deletion;
        state.value = 'deleted';
    } else {
        errorMessage.value = session.error.message;
        state.value = 'error';
    }
});

async function openConfirmation(): Promise<void> {
    acknowledged.value = false;
    state.value = 'confirming';
    await nextTick();
    confirmHeading.value?.focus();
}

function cancel(): void {
    state.value = 'ready';
}

async function confirmDeletion(): Promise<void> {
    if (!acknowledged.value || state.value !== 'confirming') {
        return;
    }
    state.value = 'submitting';
    const result = await requestAccountDeletion(token, initData);
    if (result.ok) {
        deletion.value = result.data;
        state.value = 'deleted';
    } else if (result.error.code === 'REAUTHENTICATION_REQUIRED' || result.status === 401) {
        state.value = 'reauth';
    } else {
        errorMessage.value = result.error.message;
        state.value = 'error';
    }
}
</script>

<template>
  <main
    dir="rtl"
    lang="fa"
    class="mx-auto max-w-xl p-4 text-right leading-7"
  >
    <h1 class="mb-3 text-xl font-semibold">
      حریم خصوصی و حذف حساب
    </h1>

    <p
      v-if="state === 'loading'"
      role="status"
    >
      در حال بررسی حساب…
    </p>

    <p
      v-else-if="state === 'outside-telegram'"
      role="alert"
    >
      این صفحه فقط از داخل Mini App تلگرام کارمند من باز می‌شود.
    </p>

    <section v-else-if="state === 'ready'">
      <p class="mb-3">
        با حذف کامل، حساب، برند، حافظه و تاریخچه، پروژه‌ها، رسیدها و نسخه‌های پشتیبان شما حداکثر ۷ روز پس از تأیید پاک می‌شوند.
        از لحظه تأیید، هیچ تغییر یا پردازش تازه‌ای انجام نمی‌شود. این کار برگشت‌ناپذیر است.
      </p>
      <button
        type="button"
        class="rounded bg-red-700 px-4 py-2 text-white"
        @click="openConfirmation"
      >
        حذف کامل داده‌ها و حساب
      </button>
    </section>

    <section
      v-else-if="state === 'confirming' || state === 'submitting'"
      aria-labelledby="confirm-heading"
    >
      <h2
        id="confirm-heading"
        ref="confirmHeading"
        tabindex="-1"
        class="mb-2 font-semibold"
      >
        تأیید نهایی حذف
      </h2>
      <label class="mb-3 flex items-start gap-2">
        <input
          v-model="acknowledged"
          type="checkbox"
          :disabled="state === 'submitting'"
        >
        <span>می‌دانم حذف برگشت‌ناپذیر است و همه داده‌های حساب، از جمله نسخه‌های پشتیبان، حداکثر ظرف ۷ روز پاک می‌شوند.</span>
      </label>
      <div class="flex gap-2">
        <button
          type="button"
          class="rounded bg-red-700 px-4 py-2 text-white disabled:opacity-50"
          :disabled="!acknowledged || state === 'submitting'"
          @click="confirmDeletion"
        >
          {{ state === 'submitting' ? 'در حال ثبت…' : 'بله، همه داده‌ها و حساب حذف شود' }}
        </button>
        <button
          type="button"
          class="rounded border px-4 py-2"
          :disabled="state === 'submitting'"
          @click="cancel"
        >
          انصراف
        </button>
      </div>
    </section>

    <section
      v-else-if="state === 'deleted'"
      role="status"
    >
      <p class="mb-2 font-semibold">
        درخواست حذف کامل ثبت شده است. وضعیت: در حال رسیدگی.
      </p>
      <dl v-if="deletion">
        <dt>رسید</dt>
        <dd
          class="mb-1 font-mono"
          dir="ltr"
        >
          {{ deletion.id }}
        </dd>
        <dt>زمان ثبت</dt>
        <dd class="mb-1">
          {{ formatUtc(deletion.requested_at) }}
        </dd>
        <dt>مهلت تکمیل</dt>
        <dd>{{ formatUtc(deletion.deadline_at) }}</dd>
      </dl>
      <p class="mt-2">
        تا پایان حذف، هیچ دستوری برای این حساب انجام نمی‌شود.
      </p>
    </section>

    <p
      v-else-if="state === 'reauth'"
      role="alert"
    >
      برای امنیت، تأیید حذف باید ظرف پنج دقیقه از باز کردن Mini App انجام شود. Mini App را ببندید، دوباره باز کنید و تأیید کنید. حذفی ثبت نشد.
    </p>

    <p
      v-else
      role="alert"
    >
      {{ errorMessage }}
    </p>
  </main>
</template>
