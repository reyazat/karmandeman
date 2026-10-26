# ممیزی اسناد، کد و آزمون‌های کارمند من

تاریخ: ۲۶ اکتبر ۲۰۲۶. دامنه: وضعیت فعلی working tree شامل تغییرات ثبت‌نشده قبلی، اسناد مرجع، backend، frontend و templateهای استقرار. این گزارش تأیید انتشار یا ادعای تکمیل MVP نیست. تغییرات قبلی حفظ شده‌اند؛ هیچ commit، push یا استقرار production انجام نشده است.

## نتیجه اجرایی

پروژه در مرحله **پیاده‌سازی بخشی از پایه MVP** است. هویت تلگرام، Workspace/Employee، ثبت پروژه، پروفایل برند/حافظه ساده و هدیه اعتبار وجود دارند. مسیر اصلی ارزش یعنی دریافت ورودی → استخراج محصول → تأیید → خروجی هنوز کامل نشده است. تعداد پوشه‌ها/enumها/جدول‌های خالی به معنی پیاده‌سازی قابلیت نیست.

در شروع، ۲۱ تست / ۸۰ assertion روی SQLite پاس شد، اما lint فرانت‌اند روی Vue خطا داد و Pint یک خطا داشت. بعد از اصلاحات، **۵۰ تست / ۲۸۶ assertion روی PostgreSQL واقعی و موقت** پاس شد. این نتیجه شامل ۵ تست integration برای هم‌زمانی و commit است؛ هیچ فایل مشتری یا API واقعی Telegram/n8n/AI/payment در این تست‌ها استفاده نشد.

## تطبیق محدوده با اسناد

| Epic / مرجع | شواهد کد فعلی | وضعیت واقعی |
|---|---|---|
| E01 / FR-001,003,004 | Identity Actions، Sanctum، Workspace/Employee repositories و migrations | ثبت‌نام و هدیه در retry/هم‌زمانی تست شده؛ هویت مستقل از Telegram ID داخلی است؛ رضایت FR-002 هنوز وجود ندارد |
| E02 / FR-010…016 | Brand API و memory_items با source/revision | ویرایش مستقیم برند و ذخیره حافظه ساده؛ onboarding مکالمه‌ای، voice/logo، حذف و تاریخچه کامل وجود ندارد |
| E03 / FR-020…024 | CreateProjectAction و index API | فقط ثبت/list پروژه؛ assets/storage intake، grouping، بستن Batch و resume وجود ندارد |
| E04/E05 / استخراج و تأیید | صرفاً پوشه‌های canonical و enum وضعیت | Product/Revision/Evidence/Clarification/Approval و state machine اجرایی وجود ندارد |
| E06 / FR-050…055 | بدون generator یا artifact model | CSV/PDF/JSON/ZIP/کاتالوگ آنلاین پیاده نشده؛ build Vue آزمون خروجی نیست |
| E07 / FR-060…064 | outbox اعلان Telegram اضافه شده | retry اعلان و dedupe دامنه وجود دارد؛ task_run، workflow callback، cancellation و cost cap AI وجود ندارد |
| E08 / FR-070…075 | Wallet + Grant + ledger خواندنی | فقط grant مثبت؛ reserve/capture/release/refund/quote/quota کامل وجود ندارد؛ پرداخت غیرفعال |
| E09 / FR-080…084 | فهرست پروژه‌ها | فایل/مصرف/دانلود مجدد/expiry/revoke/حذف وجود ندارد |
| E10 / عملیات | مدل AuditLog و Horizon gate | AuditLog هنوز جریان ثبت جامع ندارد؛ admin/support/analytics حذف داده و cost telemetry آماده نیست |
| وب / Strategy P2 | App.vue و Router با routes خالی | اسکلت TypeScript/Vue؛ هیچ Review UI یا Web Workspace عملیاتی وجود ندارد |
| n8n / KM-ENG §۶–۸ | مسیر canonical خالی از workflow/schema | قرارداد JSON، HMAC callback، TTL/replay و workflow نسخه‌دار وجود ندارد؛ وجود کانتینر n8n اثبات ادغام نیست |

## نواقص تأییدشده و اصلاحات انجام‌شده

شدت P1 در جدول یعنی مانع مهم پیش از پایلوت واقعی؛ P2 یعنی مشکل کیفیت/قابلیت اتکا. مسیرها به کد اصلاح‌شده اشاره می‌کنند؛ سناریوی ستون دوم رفتار قبل از این بازبینی است.

| شدت | نقص و سناریوی خرابی | اصلاح و شاهد |
|---|---|---|
| P1 | هر عضو Workspace بدون Policy و حتی حساب/Workspace تعلیق‌شده می‌توانست داده بخواند یا برند را تغییر دهد | `app/Policies/WorkspacePolicy.php` + middleware؛ owner فعال با عضویت owner شرط است؛ تست member/suspended/two-owner |
| P1 | `/balance` یا `/mybrand` در گروه می‌توانست اطلاعات Workspace شخص فرستنده را برای همه گروه بفرستد | `TelegramConversationService` فقط private با chat ID برابر sender؛ تست عدم ثبت حساب/صف/HTTP برای group/mismatch |
| P1 | initData آینده یا ساختار user نامعتبر می‌توانست پذیرفته شود/خطا دهد؛ کلید تکراری query مبهم بود؛ توکن دائمی و login بدون throttle | اعتبارسنجی scalar/unique/HMAC، TTL پنج دقیقه با skew سی ثانیه، User DTO معتبر، token هشت ساعت، rate limit؛ تست forged/old/future/arrays/invalid ID/expired bearer/429 |
| P1 | webhook فاقد update_id ثبت می‌شد؛ خطای domain با 200 بلعیده و dedupe دائمی باعث حذف فرصت retry می‌شد | `TelegramWebhookRequest` و `HandleTelegramUpdateAction`؛ transaction شامل dedupe/domain/outbox، rollback و 503؛ retry موفق تست شده |
| P1 | HTTP تلگرام داخل مسیر webhook باعث تأخیر/قطعی و گم‌شدن پاسخ می‌شد؛ exception HTTP شامل token URL بود | outbox رمز‌شده در همان DB، worker اختصاصی، timeout/backoff، خطای عمومی بدون URL و حذف آرگومان‌ها از trace؛ تست worker و ماندن job پس از شکست |
| P1 | هم‌زمانی اولین ثبت‌نام می‌توانست unique violation یا منابع تکراری بسازد | savepoint برای identity، قفل User و uniqueهای Brand/Employee/Memory؛ ۶ پردازش مستقل هم‌زمان تست شده |
| P1 | ledger قبل از قفل، idempotency را می‌خواند؛ کلید متعلق به wallet دیگر یا مبلغ متفاوت بدون conflict برمی‌گشت؛ نوع‌های رزرو/مصرف صرفاً delta می‌زدند | قفل پیش از lookup؛ تطبیق wallet/type/amount/reference/reason؛ تا چرخه مالی کامل فقط grant مثبت مجاز؛ grant هم‌زمان/کلید مشترک/مبلغ متفاوت/نوع نامجاز تست شده |
| P1 | با هدیه صفر Wallet ساخته نمی‌شد و API اعتبار حساب جدید 404 می‌داد | Wallet مستقل از مقدار هدیه در ProvisionWorkspace؛ تست موجودی صفر و ledger خالی |
| P1 | `composer setup` کلید موجود را تولید مجدد و migration را با force اجرا می‌کرد | key generation و migration خودکار حذف شدند؛ `composer test` دیگر config محیط را پاک نمی‌کند |
| P2 | نسخه برند و حافظه بدون transaction می‌توانستند از هم جدا شوند یا update هم‌زمان را از دست بدهند | transaction و قفل Workspace؛ تست rollback خطای memory و ۶ update هم‌زمان |
| P2 | pagination فهرست پروژه metadata/links را از دست می‌داد؛ `/me` و خطاهای framework envelope یکسان نداشتند | پاسخ مشترک در `app/Http/Responses`، handler مرکزی و حفظ Retry-After؛ تست page ۲ و auth/validation/429 |
| P1 | درخواست بدون `Accept: application/json` به API حفاظت‌شده به route ناموجود login می‌رفت و به‌جای 401، خطای 500 می‌داد | در smoke تصویر پیدا شد؛ ابتدا با تست feature بازتولید شد، سپس guest redirect برای API حذف شد و تست 401 پاس شد |
| P2 | نام پروژه طولانی در PostgreSQL می‌توانست webhook را وارد retry دائمی کند؛ arrayهای برند بدون قرارداد محدود بودند | کنترل طول در adapter، nested validation و سقف ترجیحات؛ تست نام طولانی و object نامعتبر |
| P1 | مسیر PHP در Caddy `/srv/public` بود، ولی app فایل را در `/var/www/html/public` دارد | root مخصوص FastCGI در `deploy/Caddyfile` اصلاح شد؛ ارتباط واقعی web → PHP با تصاویر ساخته‌شده روی شبکه موقت تست شد |
| P1 | build تصویر production در مرحله mbstring با `Package oniguruma not found` شکست خورد | وابستگی runtime و headerهای oniguruma اضافه شدند؛ headerهای PostgreSQL به libpq-dev محدود و parallel build به دو job محدود شد؛ rebuild هر دو target موفق بود |
| P1 | Compose آدرس داخلی n8n را ثابت به سرویس پشت profile خاموش می‌فرستاد و مقدار هدیه محیط را به app نمی‌رساند | `N8N_INTERNAL_URL` و `KM_GIFT_CREDITS` در allowlist محیط قابل تنظیم شدند؛ هیچ credential یا instance واقعی تغییر نکرد |
| P1 | audit npm دو dependency با شدت critical و یک moderate در زنجیره ابزار تست گزارش کرد | ارتقای Vitest به 5.0.5 و بازسازی lock روی Node 24؛ `npm audit` اکنون صفر گزارش دارد؛ الزام تست واقعی فرانت‌اند همچنان باز است |
| P2 | ESLint فایل Vue را parse نمی‌کرد و PHPStan/Larastan وجود نداشت؛ اسناد راهنما همه قابلیت‌ها را «پیاده‌نشده» معرفی می‌کردند | parser/rules واقعی Vue/TS، PHPStan سطح ۵ بدون baseline پنهان‌کننده، types اصلاح‌شده، CI پایه و راهنماهای به‌روز |

## نواقص باقی‌مانده و اولویت بعدی

### پیشرفت گام ۱ پس از ممیزی اولیه (۲۶ اکتبر ۲۰۲۶)

`POST /api/v1/projects` و `PATCH /api/v1/brands/current` اکنون هدر اجباری `Idempotency-Key` (۸ تا ۱۲۸ نویسه ASCII شامل حروف، رقم، `_` و `-`) دارند. PATCH علاوه بر آن `expected_version` عدد صحیح مثبت در بدنه می‌خواهد؛ نسخه جاری از `GET /api/v1/brands/current` با `profile_version` خوانده می‌شود. درخواست بدون پیش‌شرط `422`، نسخه منقضی یا استفاده از کلید با payload دیگر `409` برمی‌گرداند. تکرار دقیق POST پس از `201` با `200` و همان snapshot داده پاسخ می‌گیرد؛ تکرار PATCH با `200` و snapshot نسخه اولیه پاسخ داده می‌شود (`meta.request_id` تازه است). scope رکوردهای تکرار `(workspace_id, user_id, operation, key)` و اثر ایجاد پروژه/حافظه همراه رکورد تکرار در یک تراکنش است؛ قفل Workspace و unique index برای هم‌زمانی استفاده می‌شوند. مسیر `/new` تلگرام همچنان از dedupe مستقل `update_id` استفاده می‌کند.

Migration جدید `2026_10_26_120001_create_api_idempotency_records_table.php` فقط روی دیتابیس تست ایزوله از طریق تست‌ها اعمال شد؛ روی دیتابیس اصلی اعمال نشده است. این جدول snapshot خام مدل برند (شامل داده برند) را نگه می‌دارد؛ retention/deletion با سیاست مصوب گام ۰/۲ باید تعریف شود و پیش از اجرای migration در staging/production به backup، rehearsal و rollback نیاز است. snapshot همان داده ثبت‌شده را نگه می‌دارد، نه side effect تازه؛ هنوز تاریخچه حافظه محسوب نمی‌شود.

شاهد جدید: PostgreSQL `karmandeman_testing`، PHP 8.4: **۵۸ تست پاس / ۳۴۷ assertion**، شامل دو تست هم‌زمانی جدید برای replay پروژه و conflict نسخه برند. SQLite حافظه‌ای: **۵۱ تست پاس / ۲۸۳ assertion، ۷ integration skip**؛ SQLite مبنای آزمون قفل نیست. Pint `--test` روی ۱۰۳ فایل و PHPStan سطح ۵ بدون خطا. تست اتصال واقعی Telegram، AI، UI، CI میزبانی‌شده یا مهاجرت staging انجام نشده است. جدول شواهد پایین نتایج ممیزی اولیه را حفظ کرده است.

### ادامه گام ۱: شناسه عمومی و ورود تکرارپذیر (۲۶ اکتبر ۲۰۲۶)

- `public_id` UUID روی users، workspaces، brands، projects و credit_ledger به کلید عددی داخلی اضافه شد؛ Resourceهای فعلی فقط UUID را در `id` نمایش می‌دهند. فیلد `logo_asset_id` عددی و بدون مدل Asset عمومی از BrandResource حذف شد. `GET /api/v1/projects/{uuid}` تنها با Workspace احرازشده/Policy و فیلتر repository پاسخ می‌دهد؛ شناسه عددی، UUID ناموجود و UUID متعلق به مالک دیگر 404 و کاربر تعلیق‌شده 403 می‌گیرد. API پیشین با شناسه عددی در پاسخ‌ها ناسازگار می‌شود؛ مصرف‌کنندگان باید به UUID مهاجرت کنند. شناسه‌های داخلی DB و روابط دست‌نخورده‌اند.
- Migration `120002_add_public_ids` برای رکوردهای موجود UUID تولید می‌کند، unique/not-null اعمال می‌کند و snapshotهای `api_idempotency_records` برند/پروژه را با UUID جدید backfill می‌کند. تست جداگانه روی SQLite ایزوله، backfill داده قدیمی و حفظ PK داخلی را می‌سنجد؛ PostgreSQL با schema تازه تست شده است. حجم و lock مدت migration روی داده واقعی و اجرای rehearsal/rollback عملیاتی هنوز اندازه‌گیری نشده است.
- `POST /api/v1/auth/telegram/verify` حالا هدر `Idempotency-Key` را پس از اعتبارسنجی امضای Telegram می‌خواهد؛ دامنه کلید user تأییدشده است. برای همان `initData` و کلید، همان bearer token هشت‌ساعته برمی‌گردد؛ payload دیگر با همان کلید `409` می‌گیرد. با انقضا یا لغو توکن، replay همان کلید `409` است و ورود تازه کلید جدید می‌خواهد. plaintext توکن در جدول `telegram_auth_requests` به‌صورت **رمزنگاری‌شده با کلید Laravel** است، نه در `api_idempotency_records` عمومی. ciphertext پس از انقضای توکن فعلاً باقی می‌ماند؛ مدت نگهداری، حذف/revocation و چرخش کلید باید قبل از migration عملیاتی در گام ۰/۲ تعیین شوند. ثبت‌نام و gift همچنان idempotent هستند؛ webhook با `update_id` dedupe می‌شود و GET برند که برای اولین بار default را می‌سازد همچنان first-or-create است.
- شواهد این نوبت: PostgreSQL `karmandeman_testing` با PHP 8.4: **۶۴ تست پاس / ۴۰۷ assertion** (۸ تست integration از جمله replay هم‌زمان توکن). SQLite حافظه‌ای: **۵۶ پاس / ۳۳۴ assertion، ۸ integration skip**. Pint روی ۱۱۲ فایل و PHPStan سطح ۵ بدون خطا؛ `composer validate --strict`، Compose production config، Vue typecheck و lint پاس شدند. Vite build معمولی محلی به دلیل فایل‌های root-owned در `node_modules/.vite-temp` و `public/build` با EACCES شکست خورد؛ build در مسیر موقت با `--configLoader runner --outDir /tmp/karmandeman-ci-build-20261026 --emptyOutDir false` موفق شد. این موفقیت اجرای job GitHub یا build در مسیر معمول را اثبات نمی‌کند.
- CI میزبانی‌شده **تأیید نشده**: `git remote -v` خروجی ندارد و دسترسی GitHub در ابزارهای این محیط موجود نیست. برای تأیید روی همین تغییرات باید remote/لینک run و مجوز دسترسی شبکه ارائه شود؛ نسخه فعلی تغییرات هنوز commit/push نشده است. staging و production migration، backup/restore، سیاست retention و rollback اجرا/تصویب نشده‌اند. جدول نتایج زیر snapshot ممیزی اولیه است، نه نتایج این نوبت.

| شدت / زمان رفع | محل و سناریوی مشخص | اقدام بعدی |
|---|---|---|
| P1 / گام ۱ در جریان | POST پروژه، PATCH برند و POST صدور توکن قرارداد replay دارند؛ webhook بر پایه update_id است؛ endpointهای آینده هنوز این قرارداد را ندارند | بررسی mutationهای جدید هنگام پیاده‌سازی؛ retention snapshot و token، تست migration/rollback در staging و تأیید CI hosted |
| P1 / پیش از intake | FR-002، FR-084 و نبود endpoint رضایت/حذف | رضایت نسخه‌دار، سیاست retention، رسید/اجرای حذف و دسترسی اپراتور؛ پیام صادقانه فعلی جای قابلیت را نمی‌گیرد |
| P1 / پیش از پردازش هزینه‌دار | `EloquentWalletRepository::appendEntry` فقط grant است؛ ledger هنوز از update/delete سطح DB محافظت نشده | Reserve/Capture/Release/Refund، balance invariants، append-only و reconciliation؛ هیچ پرداختی فعال نشود |
| P1 / پیش از انتشار | `EloquentMemoryItemRepository.php:40` revision را زیاد و value را جایگزین می‌کند؛ مقدار قبلی برای بازبینی نگه‌داری نمی‌شود | history immutable، revocation API، actor/source و AuditLog واقعی؛ atomic update فعلی معادل history نیست |
| P2 / گام ۱ در جریان | Resourceهای موجود اکنون UUID برمی‌گردانند؛ API clientهای قدیمی ممکن است هنوز integer ID فرض کنند | سازگاری مصرف‌کنندگان و آزمون staging روی داده قدیمی؛ هیچ PK داخلی تغییر نکرده است |
| P1 / پیش از release | namespaceهای خالی Product/Approval/Export/Jobs/Contracts | اجرای برش عمودی گام ۳ [برنامه توسعه](DEVELOPMENT_PLAN.md)، سپس schema و workflow n8n؛ پرکردن همه پوشه‌ها پیش از use case لازم نیست |
| P1 / پیش از release | templateهای Compose و Runbook | DB runtime کم‌دسترسی، secret management، digest/SBOM/image scan، backup/restore، TLS و n8n editor واقعی بررسی نشده‌اند؛ container list محلی امنیت دامنه عمومی را اثبات نمی‌کند |
| P2 / نگه‌داری مخزن | bootstrap/cache و یک view کامپایل‌شده از قبل tracked و dirty هستند | از build context و فایل‌های آینده exclude شدند؛ محتوای تغییرات قبلی بازگردانی/حذف نشد؛ حذف از index در cleanup جدا با حفظ source انجام شود |
| P2 / قبل از Review UI | `resources/js/src/router/index.ts:5`، نبود فایل تست Vue | Vitest/E2E واقعی همراه اولین جریان UI؛ استفاده از passWithNoTests یا تست صوری انجام نشده است |

## شواهد آزمون و محدودیت

| بررسی | نتیجه ثبت‌شده |
|---|---|
| PostgreSQL 17.11، PHP 8.4، DB موقت `karmandeman_testing` روی tmpfs | **۵۰ تست پاس / ۲۸۶ assertion**؛ ۵ integration شامل ۶ فرایند concurrent برای ثبت‌نام، هدیه، webhook و برند و آزمون commit visibility |
| SQLite حافظه‌ای | **۴۵ تست پاس / ۲۴۱ assertion؛ ۵ integration عمداً skip**؛ SQLite مبنای تأیید lock نیست |
| Pint | ۱۱۶ فایل پاس |
| PHPStan + Larastan | سطح ۵، بدون ignore/baseline؛ صفر خطا. افزایش به سطح ۶ و سپس ۸ در توسعه بعدی |
| Composer validate / audit | معتبر / بدون advisory گزارش‌شده؛ این نتیجه license review نیست |
| Node 24 `npm ci --ignore-scripts` | نصب lock موفق؛ ناسازگاری cross-platform lock شناسایی و بازتولید شد |
| TypeScript و ESLint | typecheck و lint با `--max-warnings=0` پاس |
| npm audit | صفر آسیب‌پذیری گزارش‌شده پس از اصلاح dependency |
| Vite build | موفق؛ هشدار اختیاری fontaine باقی است؛ UI کاربردی را اثبات نمی‌کند |
| Vitest | **ناموفق: No test files found**؛ gap واقعی است و پنهان نشده |
| Compose config | production reference و dev override با `--quiet` معتبر؛ مقدار secrets نمایش داده نشد |
| Gitleaks 8.24 | snapshot فایل‌های tracked و untracked مجاز اسکن شد؛ صفر leak گزارش‌شده؛ `.env` واقعی، cache و تاریخچه Git اسکن نشدند؛ تضمین عدم نشت در runtime نیست |
| CI | `.github/workflows/foundation-checks.yml` برای PHP/PostgreSQL، frontend و build هر دو image افزوده شد؛ اجرای hosted GitHub هنوز انجام نشده؛ همه gateهای release را پوشش نمی‌دهد |
| اعتبارسنجی workflow و migration جدید | actionlint 1.7.7 پاس؛ rollback یک migration یکتایی و اجرای مجدد آن روی PostgreSQL موقت موفق |
| Docker image / HTTP | targetهای app و web با lockfile ساخته شدند؛ `/up` و `/api/v1/ping` با 200، `/api/v1/me` بدون Accept با 401، webhook بدون secret با 403 و manifest استاتیک با 200 تست شدند؛ PHP با www-data اجرا شد؛ شبکه موقت بدون اینترنت و بدون انتشار پورت میزبان بود |
| Platform / فایل محیط در تصویر | `composer check-platform-reqs --no-dev` پاس و بارگذاری واقعی pdo_pgsql/redis/mbstring/gd/bcmath/intl/zip/exif/pcntl/curl/OPcache بررسی شد؛ در filesystem نهایی app فایل dotenv، cached config و Vite hot pointer نبود؛ اسکن همه layerها/SBOM انجام نشد |
| HTTP staging واقعی / TLS / restore / S3 / AI / پرداخت | اجرا و تأیید نشده؛ smoke کانتینر محلی جای rehearsal staging، TLS، کنترل n8n editor، آزمون provider یا backup/restore نیست |

تست‌ها اکنون قبل از RefreshDatabase مقصد را کنترل می‌کنند و فقط `:memory:` یا `karmandeman_testing` را می‌پذیرند. cache/session/mail به array و queue عمومی به sync محدود شده‌اند تا env کانتینر تست را به Redis واقعی منحرف نکند. آزمون outbox از connection دیتابیس اختصاصی خود استفاده می‌کند. در اجرای اولیه rate limiter به cache محلی متصل شد؛ تست‌ها اکنون در همه adapterهای environment به array محدودند و هیچ cache موجودی flush نشده است.

## پوشش پذیرش PRD §۱۳

- QA-01: ثبت‌نام، هدیه یک‌بار و تکرار هم‌زمان تست شده.
- QA-18: جعل initData، تازگی، ساختار نامعتبر، token expiry و throttle تست شده؛ مرورگر واقعی Mini App تست نشده.
- QA-08 و QA-17: فقط بخش duplicate webhook/پروژه/هدیه/اعلان پوشش دارد؛ approval/AI/charge و شبکه موبایل پوشش ندارد.
- QA-14: APIهای فعلی بین دو مالک و نقش/تعلیق تست شده‌اند؛ **دانلود Asset دیگران هنوز تست‌پذیر نیست چون قابلیت وجود ندارد**.
- QA-02…07، QA-09…13 و QA-15…16: بدون پیاده‌سازی مرتبط، تأیید نشده‌اند. سناریوی RTL/CSV/Woo/AI injection/deletion/retention باید با قابلیت مربوطه اضافه شود.

## تصمیم مسیر توسعه

[DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md) ترتیب dependencyها و gate پایان را مشخص می‌کند. Wallet/Policy/recovery نباید مطابق زمان‌بندی اولیه تا «هفته ۱۰» عقب بیفتند؛ باید پیش از کار هزینه‌دار آماده باشند. ابتدا قرارداد Product و یک برش کوچک قابل تأیید، سپس ادغام AI چندرسانه‌ای و خروجی‌های کامل. توسعه وب کامل و پرداخت همچنان وابسته به gateهای مصوب‌اند.

شرح transaction/outbox و محدودیت اعلان در [ADR-008](adr/ADR-008-foundation-hardening.md) آمده است. Telegram sendMessage تضمین exactly-once ندارد؛ timeout مبهم ممکن است اعلان تکراری بدهد، هرچند تغییرات دامنه و هدیه با replay تکرار نمی‌شوند. migration یکتایی روی داده دارای duplicate عمداً fail می‌شود؛ قبل از اعمال روی دیتابیس موجود باید preflight، backup، rehearsal و rollback بررسی شوند. در این ممیزی migration فقط روی DB موقت تست اجرا شد.

سرویس `telegram_worker` در template Compose اضافه شده ولی بالا آورده نشده است. آزمون providerهای خارجی همچنان mock است. در حین کار WSL دچار خطای اجرای فرمان شد و با اجازه صریح کاربر راه‌اندازی مجدد شد؛ همه نتایج نهایی PHP و frontend بالا پس از بازیابی در محیط Linux/Docker گرفته شدند. تلاش اجرای موقت Windows/UNC به علت محدودیت مسیر، مبنای تأیید تست‌ها نیست.

دیتابیس PostgreSQL تست روی tmpfs و کانتینرها/شبکه HTTP موقت پس از بررسی حذف شدند. تصاویر محلی `karmandeman:audit-app` و `karmandeman:audit-web` برای بازبینی باقی‌اند؛ هیچ پورت public یا سرویس production جدیدی فعال نشده است. پاک‌سازی پوشه‌های کمکی Windows با نام‌های `km-audit-source`، `km-audit-php84` و `km-audit-npm` در Temp توسط کنترل خودکار با پیام «blocked by policy» رد شد و انجام نشد؛ این پوشه‌ها خارج از مخزن هستند و کپی source فاقد dotenv واقعی است.

مرجع خارجی برای جزئیات احراز هویت: [Telegram Mini Apps](https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app). مرجع صف: [Laravel 13 queues](https://laravel.com/docs/13.x/queues). TTL و انتخاب outbox تصمیم پروژه در ADR-008 هستند. بررسی حقوقی یا تغییر سیاست پرداخت در این ممیزی انجام نشده است.
