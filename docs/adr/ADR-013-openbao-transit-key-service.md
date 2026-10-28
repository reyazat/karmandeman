# ADR-013 — انتخاب OpenBao Transit برای NFR-11 و FR-087

تاریخ: ۲۷ اکتبر ۲۰۲۶. وضعیت: انتخاب فنی مسیر توسعه با واگذاری تصمیم به عامل توسط مالک پروژه؛ **مجوز انتشار و تأیید حذف ۷روزه نیست**. استفاده عملیاتی به آزمون‌های زیر وابسته است. این تصمیم مهلت‌های ADR-011 یا روش احراز ADR-012 را تغییر نمی‌دهد.

به‌روزرسانی همان روز: مالک پروژه انتخاب OpenBao Transit خودمیزبان، کلید مستقل هر حساب/Workspace، نگهداری کلید جدا از سرور برنامه، بکاپ کلید با سقف طراحی ۴۸ ساعت و حذف قطعی تا روز هفتم را صریحاً تأیید کرد. شواهد آزمون، فهرست پوشش و وضعیت مرحله اول کد در بخش‌های پایانی همین سند آمده‌اند؛ سند موازی `ADR-013-openbao-transit-workspace-keys.md` در این سند ادغام و حذف شد.

## مبنا و انتخاب

در کد فعلی، `RequestAccountDeletionAction` مهلت ۱۶۸ساعته، مهار Workspace و لغو token/replay را اجرا می‌کند؛ حذف واقعی FR-087 را انجام نمی‌دهد. صفحه Mini App و احراز مجدد FR-085 نیز وجود دارند. بخشی از داده‌ها با encrypted cast و صف `ShouldBeEncrypted` زیر APP_KEY مشترک‌اند و برند/حافظه هنوز plaintext دارند؛ تغییر cast به‌تنهایی حذف انتخابی از بکاپ را حل نمی‌کند.

انتخاب: **OpenBao خودمیزبان با Transit secrets engine، پشت قرارداد کوچک مدیریت کلید در Laravel؛ envelope encryption با کلید مستقل تصادفی برای هر مرز حذف حساب/Workspace در پایلوت تک‌مالک.** پیاده‌سازی رمزنگاری اختصاصی یا key store تازه در Laravel ساخته نمی‌شود. OpenBao فقط مرجع عملیات کلید است؛ حساب، مجوز، وضعیت و deadline حذف در Laravel می‌مانند؛ Telegram و n8n دسترسی مستقیم به کلید ندارند.

این انتخاب با PHP/Laravel، HTTP API و Compose فعلی سازگار است و حساب یک سرویس ابری خارجی را پیش‌شرط نمی‌کند؛ در عوض مسئولیت وصله، دسترسی، unseal، backup و پاک‌سازی storage بر عهده تیم است. این هزینه و یک میزبان مجزا باید در برنامه عملیات لحاظ شوند. هیچ ادعای HA، مصونیت از تحریم یا انطباق حقوقی خودکار نمی‌شود.

## قرارداد رمزنگاری و دسترسی

- کلید Transit مستقل با شناسه تصادفی غیرشخصی؛ Telegram ID، ایمیل یا نام مشتری در مسیر کلید نباشد. از یک master key مشترک با context مشتری برای حذف انتخابی استفاده نشود؛ مادامی که master باقی است، مشتق‌ها دوباره قابل تولیدند. با توسعه چندمالک/چند Workspace، مرز کلید هویت حساب و محتوای Workspace باید جداگانه بازبینی شود.
- کلید محافظ Transit از نوع AEAD استاندارد، baseline `aes256-gcm96`؛ `exportable=false`، `allow_plaintext_backup=false` و ایجاد مستقل کلید. مجوز حذف/config فقط برای worker اختصاصی حذف باشد؛ runtime عادی کلید را حذف، export، restore یا خودسرانه ایجاد نکند. `disable_upsert=true` و نداشتن قابلیت create در endpoint encrypt از ساخت ضمنی کلید گمشده جلوگیری کنند. حذف key ring شامل همه نسل‌هاست؛ soft delete یا min_decryption_version حذف قطعی نیست.
- کلید داده برای فیلد/محتوا تولید و با کلید حساب محافظت شود. DB/Object Storage فقط ciphertext، nonce/tag، الگوریتم/نسخه، wrapped DEK و ارجاع opaque نگه دارد؛ plaintext DEK فقط در حافظه کوتاه‌عمر و خارج از log/cache پایدار. کلید داده با کتابخانه معتبر AEAD و binding به tenant، نوع رکورد/فیلد و نسخه استفاده شود تا جابه‌جایی ciphertext میان دو tenant رد شود. فایل بزرگ به طراحی streaming با همان مرز حذف نیاز دارد؛ صرف encrypt یک ستون پوشش فایل نیست.
- APP_KEY برای کاربردهای چارچوب باقی می‌ماند؛ fallback رمزنگاری داده مشتری با APP_KEY ممنوع است. یک قرارداد مانند `KeyServiceInterface` و adapter مانند `OpenBaoTransitKeyService` برای ارتباط خارجی کافی است. نبود کلید یا قطع key service، عملیات حساس را متوقف می‌کند؛ داده بدون رمز نوشته یا از مسیر جایگزین خوانده نمی‌شود.
- payload صف/failed job، replay، تیکت، لاگ و identity lookup نیز در inventory هستند. `ShouldBeEncrypted` مشترک، HMAC عمومی lookup یا نام فایل قابل انتساب، خودکار زیر کلید حساب قرار نمی‌گیرند؛ باید بازطراحی/پاک‌سازی شوند. Job بهتر است به رکورد تحت پوشش همان کلید ارجاع بدهد و وضعیت حذف را دوباره بررسی کند.

## توپولوژی پایلوت

OpenBao روی ماشین/دامنه دسترسی جدا از app، با storage مستقل، ارتباط خصوصی امن و ACL حداقلی مستقر شود؛ پورت مدیریت عمومی، root token در برنامه یا اتصال n8n ممنوع است. container جدا روی همان VPS و همان snapshot، جداسازی کافی برای این تصمیم محسوب نمی‌شود. نسخه و digest بعد از بررسی امنیت و آزمون سازگاری پین شوند؛ این ADR نسخه منتشرنشده یا docs/next را baseline نمی‌کند.

شروع با یک نود اختصاصی برای پایلوت کم‌حجم قابل بررسی است و **HA نیست**؛ اختلال آن رمزگشایی را متوقف می‌کند. هدف RPO/RTO فقط پس از restore drill تأیید شود. سه نود روی یک VPS، حل HA یا جداسازی دامنه شکست نیست. رشد به کلاستر پس از شواهد دسترس‌پذیری انجام شود. دسترسی unseal/recovery خارج از برنامه و با رویه مستند تیم نگهداری شود.

## مهم‌ترین شرط: بکاپ کلید، بکاپ محتوای مشتری نیست

بکاپ عادی محتوای برنامه حداکثر ۱۴ روز باقی می‌ماند. **بکاپ مستقل key store به‌عنوان baseline طراحی حداکثر ۴۸ ساعت** نگهداری شود، با فاصله گرفتن backup حداکثر ۲۴ ساعت برای هدف RPO فعلی. این سقف کوتاه‌تر با حداکثر ۱۴روزه تعارض ندارد و تضمین حذف به‌تنهایی نیست. هدف داخلی حذف key ring زنده حداکثر ۲۴ ساعت از T0 است؛ با اجرای سالم سقف‌های فوق، نسخه‌های پشتیبان قبل از حذف طی حداکثر ۷۲ ساعت از T0 منقضی می‌شوند و برای رسیدگی تا روز هفتم حاشیه باقی می‌ماند. این اعداد، بودجه عملیاتی طراحی‌اند و شواهد SLA اجراشده نیستند.

snapshot خود OpenBao، backup ماشین/دیسک، object version، Raft log/نسخه storage، replica آفلاین، cache و همه کپی‌های کلید باید در inventory باشند؛ snapshot پنهان میزبان یا backup ۱۴روزه key store این طرح را نقض می‌کند. حذف API یا اتمام TTL فایل snapshot، پاک‌سازی بقایای storage را ثابت نمی‌کند؛ روش عملی حذف/جایگزینی امن نسخه‌ها و لایه storage باید قبل از go-live مشخص و تأیید شود. `exportable=false` و منع backup plaintext جلوی snapshot کل backend را نمی‌گیرد.

طبق API رسمی، restore یک Raft snapshot، cluster را به وضعیت همان snapshot برمی‌گرداند؛ بنابراین **حذف کلید از OpenBao زنده، در حضور snapshot قابل بازیابی، حذف کامل نیست**. restore فقط در محیط ایزوله و با مرجع حذف مستقل از snapshot قدیمی انجام شود. تا زمان پاک‌شدن همه کپی‌ها، فهرست موقت و حداقلی حذف مانع بازفعال‌شدن کلید/حساب شود؛ این فهرست و رسید شخصی هم پس از تکمیل نباید باقی بمانند. بازیابی tenant دیگر باید همچنان کار کند.

اگر حذف مطمئن نسخه کلید در یک لایه storage/میزبان ممکن نبود، آن لایه باید تغییر کند یا کپی محتوای مربوط تا روز هفتم پاک/پالایش شود؛ اعلام completed و فعال‌سازی پایلوت تا حل این نقص مجاز نیست. کوتاه‌کردن TTL جای این شواهد را نمی‌گیرد. از دست‌رفتن همه نسخه‌های key store نیز می‌تواند داده همه مشتریان را غیرقابل بازیابی کند؛ حذف backup کلید به‌طور کامل، راه‌حل پیش‌فرض نیست.

## ترتیب اجرا و پذیرش

۱. inventory داده/lookup و کلید فعلی، threat model محدود و طرح پاک‌سازی backend/backup میزبان؛ سپس یک PoC ایزوله با دو حساب A و B. ایجاد/بازیابی داده، چرخش، حذف همه نسل‌های A و تلاش restore از قدیمی‌ترین snapshot و لایه‌های recovery آزمایش شود. در مهلت، A هیچ داده/هویت/کلید/job قابل بازیابی نداشته باشد و B سالم بماند. وجود snapshot قدیمی عمداً باید نشان دهد چرا حذف API به‌تنهایی کافی نیست؛ محدودکردن restore با ACL تنها هم شاهد غیرقابل بازیابی‌بودن نیست.

۲. adapter واقعی و قرارداد پاسخ/خطا، timeout و fail-closed؛ آزمون ciphertext tampering، cross-tenant swap، قطع سرویس، نبود کلید و عدم upsert. نمونه PoC مجوز اتصال production نیست.

۳. پوشش تدریجی هویت/برند/حافظه/تاریخچه، تیکت و replay و صف، با قالب نسخه‌دار و inventory migration. داده و بکاپ قبلیِ plaintext یا زیر APP_KEY مشترک با نابودی کلید تازه پوشش داده نمی‌شوند؛ پاک‌سازی یا جایگزینی آن‌ها، staging و rollback قبل از عرضه لازم است.

۴. اجرای idempotent پاک‌سازی FR-087 روی فرآیند موجود FR-086: مهار، حذف داده زنده، نابودی key ring، رفع تمام کپی‌ها/پردازشگران و رسیدها و سپس تکمیل؛ timer مستقل و هشدار deadline. T0 به‌علت retry/timeout یا انتظار بکاپ تغییر نکند. QA-19…21 و آزمون restore با ساعت کنترل‌شده مبنای پایان‌اند.

تا عبور از این آزمون‌ها، انتخاب provider برای توسعه بسته شده ولی **گیت انتشار NFR-11/FR-087 باز است**. این تغییر فقط سند تصمیم است؛ سرویس، migration، rotation، حذف یا deployment اجرا نشده و تست runtime جدیدی ادعا نمی‌شود.

## شواهد آزموده‌شده (OpenBao 2.4.1، کانتینر محلی، ۲۷ اکتبر ۲۰۲۶)

| رفتار | نتیجه |
|---|---|
| پیش‌فرض کلید تازه `aes256-gcm96` | `exportable=false`، `allow_plaintext_backup=false`، `deletion_allowed=false` |
| `POST transit/keys/:name` دوباره | 200 و همان کلید؛ ساخت idempotent است |
| `POST transit/datakey/plaintext/:name` با `bits=256` | `plaintext` (base64) و `ciphertext` با پیشوند `vault:v1:` |
| `DELETE transit/keys/:name` بدون `deletion_allowed` | رد با «deletion is not allowed for this key» |
| `DELETE transit/keys/:name/soft-delete` | decrypt با «refusing to use soft-deleted key» رد می‌شود؛ `soft-delete-restore` آن را برمی‌گرداند؛ پس soft delete فقط مهار است، نه حذف |
| حذف قطعی پس از `deletion_allowed=true` | کلید از فهرست حذف و ciphertext قبلی رمزگشایی‌ناپذیر می‌شود؛ `soft-delete-restore` آن را برنمی‌گرداند |
| **raft snapshot پیش از حذف، سپس restore** (cluster تک‌نود raft با init/unseal) | **کلید حذف‌شده برمی‌گردد و ciphertext قدیمی دوباره رمزگشایی می‌شود**؛ شرط «بکاپ کلید ۴۸ ساعت» این سند ضروری است |
| هدر احراز | `X-Vault-Token` پذیرفته شد؛ `X-Bao-Token` در این نسخه 403 داد |

این آزمون‌ها روی حالت dev و یک نود raft موقت انجام شدند؛ جای آزمون PoC بند «ترتیب اجرا»، AppRole، TLS، unseal واقعی و snapshot زمان‌بندی‌شده را نمی‌گیرند.

## فهرست پوشش داده خصوصی (وضعیت ۲۷ اکتبر ۲۰۲۶)

| داده | وضعیت فعلی | هدف |
|---|---|---|
| `brands` (نام، دسته، لحن، مخاطب، توضیح، رنگ، تماس، خروجی‌ها) | **کلید Workspace (S2)**؛ واحد پول، `required_product_fields`، نمایش قیمت و نسخه تنظیمات غیرشخصی و plaintext ماندند | کلید حساب/Workspace |
| `memory_items.value_json`، `memory_item_revisions.value_json` | **کلید Workspace (S2)**؛ `key`، منبع و وضعیت plaintext | کلید حساب/Workspace |
| `projects.name` | **کلید Workspace (S2)** | کلید حساب/Workspace |
| `telegram_onboardings.draft` | **کلید Workspace (S2)** | کلید حساب/Workspace |
| `service_requests.details` و `details_hash` | **کلید Workspace (S2)**؛ hash با HMAC کلید مشتق‌شده (HKDF) از DEK همان Workspace | کلید حساب؛ HMAC با کلید همان حساب |
| `api_idempotency_records.request_hash` | از ۲۸ اکتبر HMAC زیر کلید Workspace (قبلاً sha256 بی‌کلید ورودی) | کلید حساب |
| `api_idempotency_records.result_attributes` | snapshot خام مدل؛ از S2 فیلدهای خصوصی برند/پروژه در آن ciphertext کلید Workspace‌اند؛ snapshot قدیمی plaintext مانع migration است | کلید حساب یا عدم نگهداری فیلد خصوصی |
| `users.name` (نام نمایشی تلگرام)، `workspaces.name` | از ADR-015 ذخیره نمی‌شوند؛ migration مقدار موجود را پاک می‌کند | حداقل‌سازی به‌جای رمزنگاری |
| `user_identities.external_id` | plaintext برای lookup؛ تصمیم باز ۱ با گزینه (ج) بسته شد (ADR-014) | بکاپ DB حداکثر ۷ روز و purge زنده |
| `user_identities.meta` (username و زبان تلگرام) | از ADR-015 ذخیره نمی‌شود | حداقل‌سازی |
| `conversation_events.message_key`، `subject_key` | از ADR-014: HMAC با کلید مشتق از APP_KEY؛ `subject_key` برای یافتن رویدادهای حساب در purge؛ رویدادهای قدیمی بدون `subject_key` | retention کوتاه (پیاده‌نشده) |
| `jobs`/`failed_jobs` (chat id و متن پاسخ) | `ShouldBeEncrypted` با APP_KEY؛ از ADR-014 پیام‌های همان چت در purge حذف می‌شوند | retention کوتاه failed_jobs |
| `telegram_auth_requests.encrypted_token`، `personal_access_tokens` | APP_KEY / hash | در T0 حذف می‌شوند (پیاده‌شده در FR-086) |
| `workspace_consents`، `account_deletions`، `credit_ledger`، `wallets`، `employees` | بدون متن آزاد؛ وابسته به حساب | حذف کامل در purge |
| فایل و مشتقات آینده | وجود ندارد | کلید حساب پیش از فعال‌سازی intake، با طراحی streaming |
| لاگ برنامه | فقط نوع exception | بدون PII؛ بازبینی دوره‌ای |

## تصمیم‌های باز

۱. **(بسته‌شده ۲۸ اکتبر ۲۰۲۶ — گزینه ج، [ADR-014](ADR-014-seven-day-database-backups-and-live-purge.md))** **lookup شناسه تلگرام:** پیش از شناختن حساب باید کاربر را از `from.id` پیدا کرد؛ این ستون با کلید همان حساب رمزپذیر نیست. اگر در بکاپ ۱۴روزه بماند، شناسه شخص پس از روز هفتم باقی است. گزینه‌ها: (الف) blind index با HMAC کلید سراسری در OpenBao؛ برای کسی که کلید و بکاپ را دارد هنوز با حدس شناسه قابل اتصال است. (ب) جدول هویت در دیتابیس کوچک جدا با بکاپ حداکثر ۴۸ ساعت مثل key store. (ج) کاهش عمر بکاپ کل دیتابیس به ۷ روز. **پیشنهاد: (ب)**؛ تصمیم با مالک است.
۲. میزبان جدای OpenBao، روش unseal و نگهدارنده کلیدهای unseal/recovery.
۳. فهرست موقت کلیدهای نابودشده برای restore: فقط شناسه تصادفی کلید، حداکثر به اندازه عمر بکاپ key store، بدون tombstone دائمی.

## وضعیت پیاده‌سازی (مرحله اول، ۲۷ اکتبر ۲۰۲۶)

- قرارداد `App\Services\Crypto\WorkspaceKeyManager` و adapter `OpenBaoTransitKeyManager` (پیام خطا بدون token یا payload؛ fail-closed وقتی پیکربندی نیست یا OpenBao رد می‌کند). نام کلید `ws-{workspace.public_id}`؛ UUID تصادفی و بدون شناسه تلگرام، نام یا ایمیل.
- جدول `workspace_data_keys` فقط DEK wrapped و نام کلید را نگه می‌دارد. `WorkspaceFieldCipher` با AES-256-GCM، nonce تصادفی و AAD شامل شناسه عمومی Workspace و context فیلد رمز می‌کند؛ قالب `kmv1:` + base64(nonce، tag، ciphertext). DEK باز فقط در scope یک request/job نگه داشته می‌شود و fallback به APP_KEY ندارد.
- کلید Transit و DEK فقط هنگام اولین رمزنگاری یک Workspace **فعال** و زیر قفل همان Workspace ساخته می‌شوند؛ حساب در حال حذف کلید تازه نمی‌گیرد. adapter از `transit/encrypt` استفاده نمی‌کند، ولی `disable_upsert=true` روی mount و جداکردن policy ساخت/حذف کلید از runtime عادی هنوز در پیکربندی استقرار انجام نشده‌اند.
- **هیچ ستون موجودی هنوز رمز نشده است.** soft delete در T0، purge، حذف قطعی key ring و پایش مهلت پیاده نشده‌اند.
- آزمون: PostgreSQL موقت و OpenBao 2.4.1 در حالت dev، **۱۰۹ تست / ۷۹۹ assertion** شامل ۳ آزمون integration روی Transit واقعی (round trip، idempotent بودن ساخت، soft delete و حذف قطعی بدون اثر بر کلید دیگر، عدم نشت token)؛ آزمون feature با fake برای جابه‌جایی بین tenant/فیلد، دست‌کاری ciphertext و حساب در حال حذف. آزمون OpenBao در CI اجرا نمی‌شود مگر `OPENBAO_TEST_ADDR` و `OPENBAO_TEST_TOKEN` داده شوند.

## وضعیت پیاده‌سازی (مرحله دوم، S2، ۲۷ اکتبر ۲۰۲۶)

- cast `App\Models\Casts\WorkspaceEncrypted` ستون‌های جدول بالا را با `WorkspaceFieldCipher` و context برابر `table.column` رمز می‌کند؛ `:array` برای JSON. خواندن مقدار plaintext قدیمی، ciphertext منتقل‌شده از Workspace/ستون دیگر، کلید soft-delete یا نابودشده خطا می‌دهد و fallback به plaintext یا APP_KEY ندارد. چون Eloquent cast را بدون constructor injection می‌سازد، cipher scoped از container گرفته می‌شود؛ این استثنای ثبت‌شده KM-ENG-001 §۱ است. `workspace_id` باید پیش از فیلد رمزی مقداردهی شود وگرنه `LogicException`.
- **اصلاح cache کلید:** کلید داده‌ای که داخل تراکنش باز دیده شده، پیش از هر استفاده با ردیف `workspace_data_keys` دوباره تطبیق می‌شود؛ قبلاً rollback تراکنش بیرونی کلیدی را در حافظه نگه می‌داشت که ردیفش وجود نداشت و داده بعدی در همان request/job برای همیشه غیرقابل رمزگشایی می‌شد. آزمون rollback روی کد قبلی شکست و روی کد جدید پاس است.
- migration `2026_10_27_000005_encrypt_workspace_private_fields` فقط نوع ستون‌ها را به `text` تغییر می‌دهد و **داده را بازنویسی نمی‌کند**: اگر مقدار plaintext/APP_KEY، snapshot قدیمی `brand.update`/`project.create` یا `details_hash` قدیمی وجود داشته باشد، اجرا متوقف می‌شود. down در حضور ciphertext متوقف می‌شود؛ مسیر بازگشت restore بکاپ است. داده توسعه محلی موجود باید پس از بکاپ پاک یا دوباره وارد شود؛ هیچ داده پایلوت/production وجود ندارد.
- باقی‌مانده S2: `users.name`، `workspaces.name`، `user_identities` (وابسته به تصمیم باز ۱)، `conversation_events.message_key` و payload صف/failed_jobs. **runtime همچنان کلید Transit را هنگام اولین رمزنگاری Workspace فعال می‌سازد** (`ensureKey`)؛ پس token runtime مجوز create روی `transit/keys/*` لازم دارد و این با جمله «runtime عادی کلید را خودسرانه ایجاد نکند» فقط به این شرط سازگار است که ساخت زیر قفل Workspace فعال انجام شود و مجوز config/delete فقط با worker حذف باشد. جداسازی policy در استقرار هنوز انجام نشده است.
- شواهد: PostgreSQL موقت و fake کلید: **۱۱۸ تست / ۸۷۲ assertion** شامل ۱۱ integration هم‌زمانی؛ SQLite **۱۰۴ پاس و ۱۴ skip**؛ Pint و PHPStan بدون خطا؛ rollback و اجرای دوباره migration روی PostgreSQL موقت موفق. OpenBao واقعی در این نوبت فقط با ۳ آزمون adapter سنجیده شد، نه مسیر کامل API.

## گزینه‌های کنارگذاشته‌شده و منابع

- APP_KEY مشترک و encrypted cast فعلی: مرز حذف حساب مستقل ندارند؛ راه نهایی نیستند.
- AWS KMS برای این تعهد انتخاب اول نیست: مستند رسمی حداقل انتظار ۷ روز و امکان تأخیر تا ۲۴ ساعت بیشتر را می‌گوید؛ از این رو حذف قطعی تا T0+۱۶۸ ساعت را بر آن بنا نمی‌کنیم. این تصمیم درباره تناسب زمانی است، نه ادعای ممنوعیت دسترسی همه providerها در ایران.
- key service دست‌ساز: هزینه نگهداری رمزنگاری و recovery را بدون دلیل به محصول اضافه می‌کند. OpenBao هم به‌تنهایی گواهی حذف نیست و نیاز به عملیات درست دارد.

منابع رسمی بررسی‌شده در ۲۷ اکتبر ۲۰۲۶:

- [OpenBao Transit](https://openbao.org/docs/secrets/transit/): عملیات رمزنگاری، کلید داده و نگهداری ciphertext در مخزن برنامه.
- [Transit API](https://openbao.org/docs/api/secret/transit/): AEAD، generate datakey، export/backup flags، delete در مقابل soft delete و disable_upsert؛ ویژگی‌ها باید روی نسخه پین‌شده نیز آزموده شوند.
- [Raft snapshot/restore](https://openbao.org/docs/api/system/storage/raft/): بازگشت وضعیت cluster با snapshot، مبنای ریسک احیای کلید قدیمی.
- [OpenBao audit](https://openbao.org/docs/audit/): اطلاعات request/response و HMAC پیش‌فرض؛ تنظیم و inventory لاگ همچنان لازم است و HMAC به معنی داده ناشناسِ خارج از دامنه حذف نیست.
- [AWS KMS deletion](https://docs.aws.amazon.com/kms/latest/developerguide/deleting-keys.html): انتظار اجباری و تأخیر احتمالی اضافه.

## وضعیت پیاده‌سازی (FR-087 مرحله اول، ۲۸ اکتبر ۲۰۲۶)

تصمیم باز ۱ با گزینه (ج) بسته شد و پاک‌سازی زنده، نابودی key ring، تکمیل مشروط و هشدار مهلت پیاده شدند. جزئیات و محدودیت‌ها در [ADR-014](ADR-014-seven-day-database-backups-and-live-purge.md) است. در بازبینی همان روز:

- `destroy` adapter برای کلید ازپیش‌حذف‌شده no-op شد. OpenBao 2.4.1 برای config/delete کلید ناموجود 400 می‌دهد و retry پاک‌سازی همیشه شکست می‌خورد.
- adapter آدرس غیر HTTPS را خارج از local/testing رد می‌کند.
- DEKها پس از پایان هر job از حافظه worker پاک می‌شوند.
- fingerprint درخواست idempotency به HMAC کلید Workspace تغییر کرد.
