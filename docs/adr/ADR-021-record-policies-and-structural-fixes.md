# ADR-021 — Policy رکورد، خواندن برند زیر قفل و Resource کیف پول

تاریخ: ۲۸ اکتبر ۲۰۲۶. وضعیت: تصمیم فنی برش ششم گام ۳. سه یافته ساختاری P3 در [PROJECT_AUDIT](../PROJECT_AUDIT_2026-10-26.md) را می‌بندد (ENGINEERING_STANDARDS: «Policy برای مجوزهای دامنه‌ای»، «تمام endpointهای عمومی با Resource»، FR-086). **مجوز intake واقعی، انتشار یا پایان گام ۲ نیست.**

## زمینه

ممیزی پس از commit `7d1fbaa` سه یافته ساختاری باز ثبت کرد:

- `GET /api/v1/brands/current` برند را با `firstOrCreate` بیرون از قفل ردیف Workspace می‌ساخت. درخواستی که پیش از T0 حذف حساب مجوز گرفته، می‌توانست پس از T0 ردیف تازه بنویسد.
- فقط `WorkspacePolicy` وجود داشت. پروژه، محصول و کیف پول فقط به فیلتر `workspace_id` در repository متکی بودند. ENGINEERING_STANDARDS می‌گوید Policy باید مجوز را مستقل از فیلتر query بررسی کند.
- `WalletLedgerController` آرایه خام برمی‌گرداند.

## تصمیم

۱. **Policy رکورد:** `ProjectPolicy`، `ProductPolicy` و `WalletPolicy`.
   - مجوز فقط از `workspace_id` خود رکورد می‌آید. Workspace با همین کلید دوباره خوانده می‌شود و همان شرط `WorkspacePolicy::access` اعمال می‌شود: کاربر فعال، Workspace فعال، مالک با عضویت owner.
   - relation `workspace` که caller روی مدل گذاشته معتبر نیست. repository محصول برای صرفه‌جویی در query، Workspace درخواست را روی مدل می‌گذارد. اگر فیلتر خراب شود، این relation رکورد بیگانه را خودی نشان می‌دهد.
   - پایلوت یک نقش دارد (مالک فعال). پس `view` و `update` امروز یکسان‌اند. کیف پول فقط `view` دارد، چون اعتبار فقط از Actionهای ledger جابه‌جا می‌شود.
   - هزینه: هر بررسی دو query کوچک دارد (Workspace و عضویت). فهرست‌ها یک بار روی پروژه بررسی می‌شوند، نه برای هر محصول.

۲. **محل بررسی:**
   - mutationها (افزودن، اصلاح، Gate A و رد محصول؛ finish-input، approve-products، reopen و cancel): داخل Action، بلافاصله پس از یافتن رکورد زیر قفل Workspace و پیش از هر بررسی وضعیت یا نوشتن. Actionهای پروژه به‌جای `int $userId` مدل `User` می‌گیرند.
   - خواندن‌های دارای Action (`/products/{id}/approved` و `/projects/{id}/snapshot`): داخل Action پیش از رمزگشایی و بررسی یکپارچگی.
   - خواندن ساده repository (نمایش پروژه، فهرست و summary محصولات، نمایش محصول، ledger): در Controller با `Gate::authorize`.
   - رد Policy پاسخ `403 FORBIDDEN` در envelope استاندارد می‌دهد. رکورد Workspace دیگر همچنان با فیلتر repository پاسخ `404` می‌گیرد. پس `403` فقط وقتی دیده می‌شود که فیلتر شکسته باشد، و وجود رکورد را به مالک دیگر نشان نمی‌دهد.
   - `ProjectController::index` و ایجاد پروژه رکوردی برای بررسی ندارند و زیر `WorkspacePolicy::access` در middleware می‌مانند.

۳. **خواندن برند:** `EnsureBrandAction` اول فقط می‌خواند. برند موجود بدون قفل و بدون نوشتن برمی‌گردد. برند نبود فقط داخل تراکنش و پس از `LockActiveWorkspaceAction` ساخته می‌شود. Workspace که پس از مجوز به `deletion_pending` رفته، `409 ACCOUNT_DELETION_PENDING` می‌گیرد و ردیفی ساخته نمی‌شود.

۴. **Resource کیف پول:** `WalletLedgerResource` همان شکل قبلی را برمی‌گرداند: `data.balance` و `data.entries` با `WalletLedgerEntryResource`. قرارداد API تغییر نمی‌کند.

## محدودیت‌ها و کار باز

- **تیکت:** مدل یا endpoint تیکت وجود ندارد. `ServiceRequest` فقط از مسیر تلگرام ثبت می‌شود و API عمومی ندارد. Policy آن با اولین endpoint ساخته شود.
- نقش‌های غیرمالک (عضو فقط‌خواندنی، اپراتور) تعریف نشده‌اند. وقتی تعریف شوند، `view` و `update` جدا می‌شوند.
- مسیر تلگرام پروژه و برند را با `WorkspacePolicy` در `TelegramConversationService` کنترل می‌کند و رکورد پروژه یا محصول را با شناسه ورودی نمی‌خواند.
- **replay idempotency بدون Policy:** `RunIdempotentMutationAction` پاسخ ذخیره‌شده را پیش از اجرای closure برمی‌گرداند، پس replay به Policy رکورد نمی‌رسد. امروز اثری ندارد: middleware همان `WorkspacePolicy::access` را اجرا می‌کند و کلید به Workspace و کاربر بسته است. وقتی نقش‌ها جدا شوند، کاربری که نقشش کم شده می‌تواند پاسخ mutation قبلی خودش را دوباره بگیرد. این فقط داده‌ای است که قبلاً دریافت کرده، ولی پیش از تعریف نقش باید بررسی شود.
- **تلگرام `/mybrand` در لحظه شروع حذف:** اگر برند هنوز ساخته نشده باشد و حذف حساب در همان لحظه شروع شود، `EnsureBrandAction` خطا می‌دهد، webhook `rejected` برمی‌گرداند و کاربر پاسخی نمی‌گیرد. onboarding برند را زودتر می‌سازد، پس این حالت نادر است و از نوشتن پس از T0 بهتر است.
- Policyها Workspace را مستقیم با Eloquent می‌خوانند، نه از repository، مانند `WorkspacePolicy` فعلی.
- endpointهای mutation تا تصویب سیاست در production با `403 POLICY_CONSENT_REQUIRED` بسته می‌مانند.
