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
- در زمان این پیاده‌سازی، CI میزبانی‌شده تأیید نشده بود و remote در دسترس نبود؛ این محدودیت در بررسی بعدی زیر رفع شد. staging و production migration، backup/restore، سیاست retention و rollback همچنان اجرا/تصویب نشده‌اند. جدول نتایج پایین snapshot ممیزی اولیه است، نه نتایج این نوبت.

### بررسی GitHub و build محلی (۲۶ اکتبر ۲۰۲۶)

- remote واقعی `https://github.com/reyazat/karmandeman.git` است. HEAD محلی، `origin/main` و HEAD روی GitHub همگی `0d48d54db6f562a0272b67393ea7ee45cf39a0e0` بودند. [اجرای Foundation checks شماره ۳](https://github.com/reyazat/karmandeman/actions/runs/40338932139)، از push به main، در backend، frontend-foundation و images موفق بود؛ مدت کل ۴ دقیقه و ۱۴ ثانیه. وضعیت در مرورگر و جزئیات jobها با اتصال GitHub بررسی شد؛ اجرای تازه‌ای برای این بازبینی dispatch نشد.
- backend: validate/install/audit، Pint (۱۳۲ فایل)، PHPStan و Compose config موفق‌اند. لاگ واقعی تست می‌گوید **۱ تست پاس، ۶۳ هشدار، ۴۰۷ assertion**، نه ۶۴ تست بدون هشدار. خروجی فشرده `file_get_contents` بود؛ در کپی تمیز و جدا از مخزن، اجرای `php artisan test` بدون `.env` همان هشدار `file_get_contents(.../.env): No such file or directory` را در phpdotenv بازتولید کرد. تست مستقیم PHPUnit این هشدار formatter را نشان نمی‌داد؛ سبز بودن job به‌تنهایی این تفاوت را آشکار نمی‌کرد.
- اصلاح `.github/workflows/foundation-checks.yml`: پیش از نصب وابستگی‌ها، `.env` از `.env.example` بدون secret واقعی ساخته می‌شود. APP_KEY و مقصد DB تست همچنان از متغیرهای صریح job می‌آیند؛ key generation و migration عملیاتی اضافه نشده‌اند. همان کپی تمیز با این آماده‌سازی، **۵۶ پاس / ۳۳۴ assertion، ۸ integration skip و بدون هشدار نبود `.env`** داد. این آزمون SQLite است و تکرار ۸ آزمون concurrency PostgreSQL محسوب نمی‌شود. اصلاح CI فعلاً محلی است و اجرای hosted روی آن هنوز انجام نشده است.
- frontend-foundation در GitHub: نصب lock، npm audit، typecheck، lint و **build در مسیر عادی** موفق‌اند. images: ساخت targetهای web و app و check-platform-reqs موفق‌اند. نبود Vitest/E2E کاربردی همچنان هشدار صریح workflow و گیت باز release است. اعلان‌های deprecation مربوط به checkout/setup-node قدیمی و شناوربودن ubuntu-latest نیز در run وجود دارند و برای نگه‌داری آینده ثبت می‌شوند؛ در این بازبینی نسخه action یا runner عوض نشد.
- مشکل EACCES محلی جدا رفع شد: فقط مالکیت `node_modules/.vite-temp` و `public/build`، پس از بررسی اینکه داخل همین مخزن هستند، به `user:user` اصلاح شد. سپس Node 24 با Docker و UID/GID کاربر عادی، `npm run build` را در مسیر معمول اجرا کرد و موفق شد. اجرای آینده containerهای build با `--user "$(id -u):$(id -g)"` مانع تولید مجدد فایل‌های root-owned می‌شود. هیچ chmod عمومی یا تغییر مالکیت کل مخزن انجام نشد.
- هیچ کد دامنه، secret، داده یا دیتابیس اصلی در این بررسی تغییر نکرد؛ تغییرهای فایل فقط workflow، راهنمای build و شواهد/وضعیت اسنادند. تأیید CI موجود محدود به commit بالا است؛ اصلاح محلی workflow باید پس از انتشار خودش دوباره اجرا شود. نگهداری snapshot/token، backup/restore، staging و rollback همچنان پیش‌شرط اجرای migration عملیاتی‌اند.

### برش اجرایی گام ۲: شروع گفتگو (۲۷ اکتبر ۲۰۲۶)

- مسیر ربات اکنون `/start` → پذیرش نسخه `onboarding-preview-1` با دکمه → نام برند و انتخاب تومان/ریال → دسته/لحن اختیاری با «بعداً» → خلاصه و دکمه تأیید/اصلاح → `/new` را دارد. وضعیت و draft رمزنگاری‌شده در `telegram_onboardings`، رضایت در `workspace_consents` ثبت می‌شوند؛ `/start` پس از قطع گفتگو مرحله و برند قبلی را پاک نمی‌کند. نسخه عوض‌شده نیازمند پذیرش مجدد است. `/mybrand`, `/brand` و `/forget name|category|tone` فقط در workspace احرازشده عمل می‌کنند.
- draft پیش از تأیید وارد حافظه نمی‌شود. بعد از تأیید، brand و `memory_items` در تراکنش به‌روزرسانی می‌شوند؛ `memory_item_revisions` مقدار، revision، منبع، تأییدکننده و وضعیت فعال/منسوخ را ثبت می‌کند. migration فقط آخرین revision قابل مشاهده رکوردهای قدیمی را backfill می‌کند؛ مقادیر قبلی که پیش از این تغییر overwrite شده‌اند قابل بازسازی نیستند. حذف یک فیلد آن را از حافظه فعال منسوخ می‌کند، اما تاریخچه تا تصویب سیاست حذف باقی می‌ماند؛ حذف قطعی کل داده هنوز پیاده نشده است. append-only بودن history در سطح DB با trigger/role تثبیت نشده است.
- `/support متن` و `/delete_data` رکورد `service_requests` با رسید UUID و وضعیت `received` می‌سازند؛ `/ticket UUID` فقط رسید همان workspace را نمایش می‌دهد. شرح پشتیبانی در DB رمزنگاری و برای تشخیص تکرار با HMAC کلیددار مقایسه می‌شود؛ درخواست حذف **فقط ثبت** می‌شود و نه حذف فوری. تکرار درخواست حذف یا متن پشتیبانی یکسانِ باز همان رسید را بازمی‌گرداند. اپراتور، SLA، اجرای واقعی حذف، حذف مشتقات/بکاپ و اطلاع‌رسانی نتیجه هنوز ساخته نشده‌اند.
- webhook با `update_id` و، برای پیام دارای `message_id`، با hash شناسه چت/پیام dedupe می‌شود؛ دکمه قدیمی نسخه رضایت، «بعداً» مرحله قبل، تأیید draft قدیمی، و تأیید دوباره برند عمل تکراری ندارند. callback فقط در گفتگوی خصوصی با chat ID برابر فرستنده پذیرفته می‌شود؛ پاسخ و ACK در outbox رمزنگاری‌شده بعد از commit به worker تلگرام می‌رسند. retry نامطمئن sendMessage همچنان می‌تواند اعلان تکراری بدهد؛ تغییرات دامنه تکرار نمی‌شوند.
- دریافت فایل تجاری/متن محصول **قبل و بعد از رضایت پیش‌نمایش بسته است**؛ متن رضایت فعلی اعلام می‌کند که سیاست نهایی نگهداری و حذف فایل منتشر نشده و ارسال فایل مجاز نیست. بنابراین FR-002/FR-084 برای intake واقعی و بخش‌های ویس/لوگو FR-011/012 هنوز بسته نشده‌اند. تعیین متن شرایط، retention، رسید اجرای حذف، سطح دسترسی اپراتور و storage خصوصی برای داده واقعی باید با Product/Legal پیش از فعال‌سازی intake تصویب شود. ورود رایگان است؛ gift اولیه همچنان فقط یک‌بار اعطا می‌شود و هیچ مصرف اعتبار جدیدی انجام نمی‌شود.
- migrations جدید `2026_10_26_120004_create_telegram_onboarding_tables.php` و `2026_10_27_000001_dedupe_telegram_message_identity.php` تنها با آزمون‌های دیتابیس ایزوله اعمال شدند؛ دیتابیس اصلی و staging تغییر نکردند. backfill روی SQLite مستقل تست شد؛ میزان lock، preflight داده قدیمی، backup، rehearsal و rollback روی PostgreSQL موجود هنوز آزموده نشده‌اند. افزایش تاریخچه و ciphertext متن پشتیبانی، نیاز به سیاست retention/deletion و حفاظت از کلید APP_KEY را مهم‌تر می‌کند.
- شاهد محلی: PostgreSQL `karmandeman_testing` با PHP 8.4، **۷۰ تست پاس / ۵۲۰ assertion** شامل ۹ integration هم‌زمانی؛ SQLite حافظه‌ای **۶۱ پاس / ۴۳۶ assertion و ۹ integration skip**. Pint روی ۱۲۸ فایل و PHPStan سطح ۵ بدون خطا؛ تست‌ها شامل مسیر مثبت/منفی، دو مالک، replay، تأیید هم‌زمان و rollback موجودند. اتصال زنده ربات، استفاده فروشنده، CI میزبانی‌شده روی این تغییرات، سیاست حقوقی و اجرای واقعی پشتیبانی/حذف آزموده نشده‌اند. تغییر محلی workflow و اسناد CI از کار هم‌زمان دیگر حفظ شده و جزو این شواهد جدید نیست.

| شدت / زمان رفع | محل و سناریوی مشخص | اقدام بعدی |
|---|---|---|
| P1 / گام ۱ در جریان | POST پروژه، PATCH برند و POST صدور توکن قرارداد replay دارند؛ webhook بر پایه update_id است؛ endpointهای آینده هنوز این قرارداد را ندارند | بررسی mutationهای جدید هنگام پیاده‌سازی؛ retention snapshot و token، تست migration/rollback در staging و تأیید CI hosted |
| P1 / پیش از intake | رضایت محدود برای شناخت برند و رسید درخواست حذف اضافه شده؛ FR-002/084 برای فایل واقعی هنوز باز است | تصویب شرایط/retention، اجرای واقعی حذف و دسترسی اپراتور، سپس آزمون intake با سیاست مصوب |
| P1 / پیش از پردازش هزینه‌دار | `EloquentWalletRepository::appendEntry` فقط grant است؛ ledger هنوز از update/delete سطح DB محافظت نشده | Reserve/Capture/Release/Refund، balance invariants، append-only و reconciliation؛ هیچ پرداختی فعال نشود |
| P1 / پیش از انتشار | نسخه‌های جدید حافظه و revocation در جدول history ثبت می‌شوند؛ داده overwrite شده قدیمی بازنمی‌گردد و حذف فیزیکی/حسابرسی جامع آماده نیست | سیاست retention، حفاظت DB از ویرایش history، حذف قطعی طبق درخواست و audit اپراتور |
| P2 / گام ۱ در جریان | Resourceهای موجود اکنون UUID برمی‌گردانند؛ API clientهای قدیمی ممکن است هنوز integer ID فرض کنند | سازگاری مصرف‌کنندگان و آزمون staging روی داده قدیمی؛ هیچ PK داخلی تغییر نکرده است |
| P1 / پیش از release | namespaceهای خالی Product/Approval/Export/Jobs/Contracts | اجرای برش عمودی گام ۳ [برنامه توسعه](DEVELOPMENT_PLAN.md)، سپس schema و workflow n8n؛ پرکردن همه پوشه‌ها پیش از use case لازم نیست |
| P1 / پیش از release | templateهای Compose و Runbook | DB runtime کم‌دسترسی، secret management، digest/SBOM/image scan، backup/restore، TLS و n8n editor واقعی بررسی نشده‌اند؛ container list محلی امنیت دامنه عمومی را اثبات نمی‌کند |
| P2 / نگه‌داری مخزن | bootstrap/cache و یک view کامپایل‌شده از قبل tracked و dirty هستند | از build context و فایل‌های آینده exclude شدند؛ محتوای تغییرات قبلی بازگردانی/حذف نشد؛ حذف از index در cleanup جدا با حفظ source انجام شود |
| P2 / قبل از Review UI | `resources/js/src/router/index.ts:5`، نبود فایل تست Vue | Vitest/E2E واقعی همراه اولین جریان UI؛ استفاده از passWithNoTests یا تست صوری انجام نشده است |

### بسته شرایط و نگهداری و اتصال رضایت به متن (۲۷ اکتبر ۲۰۲۶)

- بهره‌بردار طبق پاسخ مالک پروژه: وحید ریاضت و تیم کارمند من؛ فعالیت و مشتریان ایران. راه فعلی `/support`، `/ticket` و `/delete_data` است. شماره یا نشانی مشخص تلگرام/واتس‌اپ اعلام نشده؛ اطلاعات تماس ساختگی درج نشد.
- [راهنمای بررسی و تصویب](PILOT_DATA_POLICY_v1.0.md) به متن دقیق شرایط، حریم خصوصی، جدول زمان‌های پیشنهادی و manifest در `resources/policies/pilot-2026-10-27-v1/` متصل است. [ADR-009](adr/ADR-009-pilot-policy-and-erasure.md) تعارض وعده حذف فوری با بکاپ در گردش و تفاوت هدف رمزنگاری با پوشش واقعی را ثبت می‌کند. اعداد، مهلت عملیاتی پیشنهادی‌اند؛ تأیید مالک/بازبین حقوقی و فعال‌سازی ثبت نشده‌اند.
- `PilotPolicyService` متن و جدول، هویت بهره‌بردار، نسخه، تأییدکنندگان، تاریخ UTC و ارجاع چهار دسته شواهد را کنترل می‌کند. hash کل بسته در رسید رضایت ذخیره می‌شود؛ تغییر متن/جدول/هویت/شواهد، تأیید قبلی را بی‌اعتبار می‌کند. callback کوتاه از همان hash مشتق شده و receipt کامل را ذخیره می‌کند. صرف status یا env، تأیید محسوب نمی‌شود؛ ارجاع شواهد نیز خودکار صحت اجرای آن‌ها را اثبات نمی‌کند.
- migration جدید فقط ستون nullable `policy_document_hash` را اضافه می‌کند؛ رسیدهای preview قدیمی رضایت نهایی تلقی یا بازنویسی نمی‌شوند. تا تصویب واقعی، مسیر Telegram onboarding هیچ پذیرش یا تغییر برند/حافظه/پروژه‌ای انجام نمی‌دهد. اطلاعات هویتی حداقلی پاسخ‌گویی و رسیدهای پشتیبانی/حذف طبق متن ثبت می‌شوند. پس از تصویب، `/terms`، `/privacy` و `/retention` بدون رضایت یا ثبت‌نام قبلی قابل مشاهده‌اند؛ پیام بلند در قطعه‌های مجاز ارسال می‌شود.
- محدودیت تأییدشده: purge واقعی، حذف حساب/بکاپ، دکمه پنل، پوشش کامل رمزنگاری و اجرای رسیدگی اپراتور ساخته یا اثبات نشده‌اند. API برند/پروژه هنوز پذیرش سیاست معادل ندارد؛ پیش از ارائه عمومی آن کانال باید تکمیل شود. URL عمومی سیاست و تنظیم BotFather، فهرست پردازشگران، بازبینی حقوق ایران و تمرین حذف/restore در staging بازند. دریافت فایل/AI و پرداخت همچنان غیرفعال‌اند.
- شواهد نهایی محلی با PHP 8.4: PostgreSQL جداگانه روی tmpfs، **۸۱ تست پاس / ۵۸۳ assertion** شامل تمام ۹ integration؛ SQLite حافظه‌ای **۷۲ پاس / ۴۹۹ assertion و ۹ integration skip**. تست‌های مثبت/منفی شامل تأیید جعلی/ناقص، تغییر متن، تاریخ آینده، JSON خراب، حفظ رسید قدیمی، جداسازی مالک، عدم پذیرش برند در وضعیت pending و دسترسی مستقل به اسناد/پشتیبانی‌اند. Pint روی **۱۵۵ فایل پاس** و PHPStan سطح ۵ بدون خطا؛ `git diff --check` پاس.
- `policy:check` روی بسته واقعی عمداً `ready=false` و exit code برابر ۱ می‌دهد؛ آزمون read-only عدم تغییر manifest/رسید و موفقیت صرفاً fixture مصنوعی را پوشش می‌دهد. migration تنها روی دیتابیس‌های تست اجرا شد. دیتابیس اصلی، production، backup و سرویس‌های واقعی تغییر نکردند. تصویر نهایی جدید، CI میزبانی‌شده این تغییرات و ربات زنده در این نوبت آزموده نشده‌اند.

### تصمیم مالک: بکاپ ۱۴روزه، رمزنگاری خصوصی و دکمه حذف (۲۷ اکتبر ۲۰۲۶)

- مالک پروژه سقف نگهداری بکاپ را دو هفته تعیین و درج رمزنگاری داده خصوصی و دکمه حذف کامل در PRD را درخواست کرد. [ADR-010](adr/ADR-010-fourteen-day-backups-and-full-erasure.md) تصمیم و اثر آن بر پیشنهاد پیشین را ثبت می‌کند؛ این تأیید نیازمندی است، نه ادعای اجرای فعلی یا تأیید کامل متن حقوقی.
- PRD با FR-084…088، NFR-11…12 و QA-19…21 کامل شد: دکمه در صفحه محدود حریم خصوصی حساب/Telegram Mini App، احراز مجدد و تأیید نهایی، tenant Policy، idempotency و پیگیری، مهار پردازش از T0، پوشش همه داده خصوصی/تاریخچه/کپی‌ها و کلیدها، و آزمون encryption/restore/مهلت. دامنه Web Workspace کامل اضافه نشد.
- سقف بکاپ ۱۴ روز از ایجاد و حذف کامل تا ۱۴ روز از درخواست تأییدشده مالک است؛ هدف حذف زنده ۷ روز در همان بازه است، نه پایان در ۲۱ روز. بکاپ پس از درخواست، انتقال و retry نباید مهلت را تمدید کنند. Runbook، استاندارد مهندسی، راهبرد و برنامه توسعه با این تصمیم هماهنگ شدند.
- بسته تازه `pilot-20261027-v2` برای بررسی انتخاب شد؛ v1 سابقه پیشنهاد تأییدنشده و کنارگذاشته‌شده است. manifest واقعی همچنان pending است؛ زمان سایر گروه‌ها، بازبینی نهایی و شواهد اجرا تأیید نشده‌اند. ثبت این تصمیم هیچ receipt قدیمی را ارتقا نمی‌دهد و policy:check باید همچنان ready=false باشد.
- در این نوبت فقط اسناد، متن و انتخاب نسخه سیاست و ارجاع fixture تست تغییر کردند؛ دکمه، رمزنگاری جامع، purge، lifecycle بکاپ یا restore واقعی پیاده/اجرا نشده‌اند. هیچ migration، تغییر کلید، دست‌کاری داده اصلی یا استقرار انجام نشد. نتایج نوبت پیش، شاهد قابلیت حذف/رمزنگاری جدید نیستند.
- بررسی پس از انتخاب v2: SQLite حافظه‌ای **۷۲ تست پاس / ۵۰۲ assertion، ۹ integration skip**؛ Pint برای سه فایل PHP تغییرکرده و `git diff --check` پاس. `policy:check` نسخه `pilot-20261027-v2` را با `ready=false` و exit code برابر ۱ گزارش کرد؛ این نتیجه درستِ باقی‌بودن تأیید نهایی/شواهد است. PostgreSQL concurrency و رمزنگاری/حذف/بکاپ واقعی در این نوبت دوباره اجرا نشدند؛ معیارهای QA-19…21، تست‌های لازم آینده‌اند و پاس‌شده معرفی نمی‌شوند.

### انتخاب نهایی مالک: حذف کامل حداکثر ۷ روز (۲۷ اکتبر ۲۰۲۶)

- پاسخ صریح مالک به پرسش زمان حذف، «حذف کامل تا ۷ روز؛ طراحی فنی متناسب اصلاح شود» است. [ADR-011](adr/ADR-011-seven-day-complete-erasure.md) مهلت ۱۴روزه نوبت پیش را جایگزین می‌کند؛ سقف عادی عمر بکاپ ۱۴ روز باقی است و داده حساب در انتظار حذف باید زودتر، حداکثر T0+۷ روز غیرقابل بازیابی شود.
- PRD FR-084/087، NFR-12 و QA-21، استاندارد، Runbook، راهبرد و برنامه توسعه و راهنمای سیاست با این تفاوت هماهنگ شدند. طرح شامل کلید مستقل حساب/Workspace با key store جدا، تمام نسل‌ها/recovery کلید، پاک‌سازی نسخه‌های plaintext/متادیتا و بکاپ قدیمی، مهار restore و حفظ بازیابی دیگران است. انتخاب key service/روش عملی هنوز به اثبات حذف قطعی تا ۷ روز و staging نیاز دارد؛ cast و APP_KEY مشترک کافی نیستند.
- بسته جاری `pilot-20261027-v3` با بکاپ ۱۴روزه و حذف کامل ۷روزه انتخاب شد؛ v1/v2 سابقه‌اند. متن نهایی، اعداد دیگر، شواهد رمزنگاری/حذف و فعال‌سازی تأیید نشده‌اند. فقط اسناد، بسته سیاست و ارجاع config/fixture اصلاح شده‌اند؛ عملیات حذف، رمزنگاری جامع، migration، تغییر کلید، backup/restore واقعی یا استقرار انجام نشده است.
- بررسی این بازبینی: SQLite حافظه‌ای **۷۲ تست پاس / ۵۰۲ assertion، ۹ integration skip**؛ Pint برای سه فایل PHP تغییرکرده و `git diff --check` پاس. `policy:check` بسته v3 را عمداً با `ready=false` و exit code برابر ۱ گزارش کرد. این نتایج اتصال متن/نسخه و رفتار gate را می‌سنجند، نه اجرای حذف رمزنگارانه، key service یا QA-19…21 آینده. PostgreSQL concurrency و restore واقعی در این نوبت دوباره اجرا نشدند.

### برش FR-086: فرآیند واحد حذف و مهار از T0 (۲۷ اکتبر ۲۰۲۶)

- `/delete_data` دیگر رسید `service_requests` نمی‌سازد؛ هشدار برگشت‌ناپذیری با دکمه تأیید و انصراف می‌فرستد. داده دکمه `delete:confirm:<زمان>:<HMAC>` به کاربر، workspace و زمان صدور بسته و ده دقیقه معتبر است؛ امضای جعلی، منقضی یا متعلق به کاربر دیگر و انصراف هیچ تغییری نمی‌دهند. کاربر تلگرامِ ثبت‌نشده با این دستور حساب نمی‌گیرد. رسیدهای قدیمی `data_deletion` به فرآیند جدید ارتقا داده نمی‌شوند.
- `RequestAccountDeletionAction` نقطه ورود مشترک کانال‌هاست: Policy مالکیت `requestDeletion`، قفل workspace، یک ردیف یکتا در `account_deletions` با `requested_at` (T0) و `deadline_at` برابر T0+۱۶۸ ساعت طبق ADR-011، تغییر وضعیت workspace به `deletion_pending`، حذف Sanctum tokenها و رکوردهای replay توکن Mini App در همان تراکنش. تکرار یا کلیک هم‌زمان همان رسید را برمی‌گرداند.
- از T0، هر پیام یا callback تلگرام بدون ثبت‌نام، هدیه یا mutation فقط رسید، مهلت و وضعیت «در حال رسیدگی» را برمی‌گرداند. API با token قبلی 401 و صدور token تازه 403 است. `LockActiveWorkspaceAction` وضعیت را زیر قفل ردیف دوباره می‌خواند و در برند، پروژه، onboarding، پشتیبانی و idempotency اجرا می‌شود؛ درخواستی که پیش از T0 مجوز گرفته پس از commit با `ACCOUNT_DELETION_PENDING` رد می‌شود.
- `PATCH /api/v1/brands/current` و `POST /api/v1/projects` بدون پذیرش نسخه و hash دقیق سیاست مصوب برای همان workspace با `403 POLICY_CONSENT_REQUIRED` رد می‌شوند؛ خواندن باز است و replay درخواست موفق قبلی همان snapshot را برمی‌گرداند. پذیرش فعلاً فقط از مسیر تلگرام ثبت می‌شود.
- ساخته نشده: purge داده زنده/فایل/تاریخچه/صف/لاگ و بکاپ و کلید (FR-087، NFR-11)، دکمه Mini App با احراز مجدد (FR-085)، endpoint حذف در API (منتظر احراز مجدد FR-085)، مسیر اپراتور، زمان‌بند و هشدار مهلت (NFR-12)، و مهار jobهای AI/n8n آینده که هنوز وجود ندارند. پیام‌های outbox تلگرامِ ثبت‌شده پیش از T0 هنوز ارسال می‌شوند. rollback migration ردیف‌ها را حذف می‌کند ولی workspaceهای `deletion_pending` را عمداً فعال نمی‌کند.
- شواهد محلی با PHP 8.4: PostgreSQL موقت روی tmpfs **۹۱ تست پاس / ۷۰۸ assertion** شامل ۱۰ integration هم‌زمانی (کلیک هم‌زمان تأیید حذف)؛ SQLite حافظه‌ای **۸۱ پاس / ۶۱۴ assertion و ۱۰ skip**؛ Pint روی ۱۶۴ فایل و PHPStan سطح ۵ بدون خطا؛ rollback و اجرای مجدد migration جدید روی همان PostgreSQL موقت موفق. دیتابیس اصلی، staging، ربات زنده و CI میزبانی‌شده آزموده نشده‌اند.

### بازبینی امنیتی و ساختاری پس از FR-086 (۲۷ اکتبر ۲۰۲۶)

بازبینی مستقل diff از 0d48d54 تا dd614e8 مورد critical نیافت؛ موارد زیر تأیید و اصلاح شدند:

- **صدور token پس از T0 (متوسط):** `IssueTelegramTokenAction` بعد از ثبت‌نام، وضعیت workspace را زیر قفل (به ترتیب user سپس workspace برای جلوگیری از deadlock) دوباره بررسی می‌کند؛ `RegisterTelegramUserAction` پیش از هر نوشتن، workspace موجود را با `LockActiveWorkspaceAction` قفل می‌کند و مسیر Mini App برای حساب در حال حذف `409 ACCOUNT_DELETION_PENDING` می‌گیرد. `LockActiveWorkspaceAction` بدون تراکنش باز خطا می‌دهد و وضعیت غیرفعال غیرحذفی را `WORKSPACE_NOT_ACTIVE` می‌نامد.
- **حلقه 503 تلگرام (متوسط):** خطاهای قطعی (`AuthorizationException`، `DomainException`، HTTP 4xx) پس از rollback با 200 و وضعیت `rejected` پاسخ داده می‌شوند و فقط خطای گذرا 503 می‌گیرد. پذیرش نسخه‌ای که متنش بدون تغییر شماره عوض شده، دیگر exception نمی‌دهد و پیام راهنما برمی‌گرداند. workspace غیرفعال بدون رسید حذف هم پیام «فعال نیست» می‌گیرد.
- **callbackها (کم):** همه callbackها ACK می‌شوند؛ callback ناشناخته، inline، بدون data یا گروهی نادیده گرفته می‌شود و دیگر 422 نمی‌گیرد. الگوی مجاز در سرویس بررسی می‌شود.
- **کلید رسید پشتیبانی (کم):** کلید از chat و `message_id` ساخته می‌شود، نه `update_id` که پس از یک هفته بی‌کاری ممکن است تکرار شود.
- **migration (کم):** ایندکس یکتای `message_key` روی PostgreSQL با `CONCURRENTLY` ساخته و حذف می‌شود؛ rollback چهار migration اخیر و اجرای دوباره روی PostgreSQL موقت موفق است. rollback همچنان داده جدول‌های جدید را حذف می‌کند و فقط با backup مجاز است.
- **ساختار:** منطق امضای دکمه حذف و lookup حساب از `TelegramConversationService` به `TelegramDeletionConfirmation` و Actionهای `Prepare/ConfirmTelegramAccountDeletionAction` و `FindContainedTelegramAccountAction` منتقل شد؛ رضایت در `WorkspaceConsentRepositoryInterface` جدا شد و `isReady` دیگر رکورد نمی‌سازد؛ repositoryها در پوشه مدل خود؛ controller، Request، Resource و middleware ها `final`؛ `strict_types` در دو فایل باقی‌مانده؛ تجزیه initData از controller به FormRequest؛ `ProjectController` خطای دامنه ناشناخته را دیگر «بدون کارمند» برچسب نمی‌زند. `ENGINEERING_STANDARDS.md` به‌عنوان نسخه جاری و فایل v1.0 به‌عنوان بایگانی مشخص شد.
- **باقی‌مانده:** مقادیر `memory_items`/`memory_item_revisions`/`brands` هنوز plaintext‌اند و به طرح کلید NFR-11 (ADR بعدی) وابسته‌اند؛ تراکنش و قاعده grant در `EloquentWalletRepository` با گام ۵ (Reserve/Capture) بازآرایی می‌شود؛ `EnsureBrandAction` در خواندن برند رکورد می‌سازد.
- شواهد: PostgreSQL موقت **۹۵ تست / ۷۳۲ assertion**؛ SQLite **۸۵ پاس / ۶۳۸ assertion و ۱۰ skip**؛ Pint روی ۱۷۱ فایل و PHPStan سطح ۵ بدون خطا.

### برش FR-085: صفحه حریم خصوصی Mini App و دکمه حذف (۲۷ اکتبر ۲۰۲۶)

- `POST /api/v1/account/deletion` (Sanctum + workspace مجاز + throttle پنج در دقیقه) با `RequestAccountDeletionRequest` به `RequestReauthenticatedAccountDeletionAction` و همان `RequestAccountDeletionAction` مشترک FR-086 (کانال `mini_app`) می‌رسد. **احراز مجدد** = initData امضاشده و تازه (حداکثر ۵ دقیقه از باز شدن Mini App) که کاربر تلگرامش باید همان صاحب token باشد؛ **تأیید نهایی** = `confirm: true`. initData کهنه یا جعلی `401 REAUTHENTICATION_REQUIRED`، initData کاربر دیگر `403`، و نبود تأیید `422` می‌گیرد و هیچ حذفی ثبت نمی‌شود. پاسخ `AccountDeletionResource` (رسید، وضعیت، T0 و مهلت) با 201 یا 200 است.
- ورود دوباره به Mini App برای حساب در حال حذف `409 ACCOUNT_DELETION_PENDING` همراه رسید برمی‌گرداند؛ token یا هدیه تازه ساخته نمی‌شود.
- Vue: `/app/privacy` با `PrivacyPage` (RTL، وضعیت‌های loading/خارج از تلگرام/آماده/تأیید/ثبت/حذف‌شده/احراز مجدد/خطا، فوکوس روی عنوان تأیید، تیک برگشت‌ناپذیری اجباری، انصراف بی‌اثر)، `shared/api/client` و `shared/telegram/webApp` فقط initData امضاشده را می‌فرستند. token فقط در حافظه صفحه است. shell در `resources/views/miniapp.blade.php` با SDK رسمی تلگرام.
- «initData تازه ۵ دقیقه‌ای» به‌عنوان احراز مجدد FR-085 توسط مالک پروژه برای Product/Security تأیید و در [ADR-012](adr/ADR-012-mini-app-reauthentication-and-sign-in.md) ثبت شد. ساخت حساب هنگام باز کردن Mini App خطا اعلام شد و اصلاح شد: `/auth/telegram/verify` اکنون فقط برای حساب ساخته‌شده با `/start` ربات token می‌دهد و برای کاربر ناشناس بدون هیچ نوشتنی `404 ACCOUNT_NOT_FOUND` برمی‌گرداند.
- **framing و CSP (بازبینی deployment-readiness):** `deploy/Caddyfile` اکنون فقط برای `/app` و `/app/*` هدر `Content-Security-Policy` با `frame-ancestors https://web.telegram.org`، `script-src 'self' https://telegram.org`، `connect-src 'self'` و `object-src 'none'` می‌فرستد و `X-Frame-Options` ندارد؛ بقیه مسیرها `X-Frame-Options: DENY` و `frame-ancestors 'none'` دارند. `caddy validate` روی `caddy:2.10-alpine` معتبر بود و کانتینر محلی بدون پورت میزبان هدرها را برای `/app/privacy`، `/app/`، `/appendix` (بدون تطبیق) و `/build/*` درست برگرداند. `style-src` به‌خاطر احتمال تزریق استایل توسط SDK تلگرام `'unsafe-inline'` دارد. آزمون نشده: باز شدن واقعی در Telegram Web/Desktop/موبایل، استقرار، TLS و HSTS (Caddy به‌طور پیش‌فرض HSTS نمی‌فرستد)، تنظیم BotFather و URL عمومی.
- شواهد: PostgreSQL موقت **۱۰۰ تست / ۷۶۴ assertion**؛ SQLite **۹۰ پاس / ۶۷۰ assertion و ۱۰ skip**؛ Pint روی ۱۷۷ فایل و PHPStan سطح ۵ بدون خطا. frontend در کانتینر ایزوله: `npm ci`، `npm audit` (صفر آسیب‌پذیری)، `vue-tsc`، ESLint با `--max-warnings=0`، **Vitest ۶ تست پاس** و `vite build` موفق. CI اکنون Vitest را اجرا می‌کند؛ E2E مرورگر/تلگرام واقعی و ربات زنده آزموده نشده‌اند. `@vue/test-utils` و `jsdom` به devDependencies افزوده شدند و `no-undef` برای فایل‌های TS/Vue خاموش شد چون vue-tsc همین را بررسی می‌کند.

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
| Vitest | پس از FR-085: ۶ تست component برای `PrivacyPage` پاس و در CI اجرا می‌شود؛ E2E مرورگر/تلگرام هنوز وجود ندارد |
| Compose config | production reference و dev override با `--quiet` معتبر؛ مقدار secrets نمایش داده نشد |
| Gitleaks 8.24 | snapshot فایل‌های tracked و untracked مجاز اسکن شد؛ صفر leak گزارش‌شده؛ `.env` واقعی، cache و تاریخچه Git اسکن نشدند؛ تضمین عدم نشت در runtime نیست |
| CI | `.github/workflows/foundation-checks.yml` برای PHP/PostgreSQL، frontend و build هر دو image؛ [run 40514840813](https://github.com/reyazat/karmandeman/actions/runs/40514840813) روی dd614e8: backend، frontend-foundation و images موفق، **۹۱ تست / ۷۰۸ assertion** روی PostgreSQL با ۱۰ integration. هشدارهای باقی: actionهای Node 20، نبود تست frontend، مهاجرت ubuntu-latest به Ubuntu 26. همه gateهای release را پوشش نمی‌دهد |
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
