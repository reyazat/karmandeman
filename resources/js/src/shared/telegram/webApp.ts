interface TelegramWebApp {
    initData: string;
    ready(): void;
}

declare global {
    interface Window {
        Telegram?: { WebApp?: TelegramWebApp };
    }
}

/** Signed initData from the Telegram client; null outside Telegram. Never trust initDataUnsafe. */
export function telegramInitData(): string | null {
    const webApp = window.Telegram?.WebApp;
    webApp?.ready();

    return webApp?.initData ? webApp.initData : null;
}
