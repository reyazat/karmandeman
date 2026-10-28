# کارمند من — Deployment Architecture & Operations Runbook

> **شناسه:** KM-OPS-001  
> **نسخه:** 1.1 — ۲۶ اکتبر ۲۰۲۶ (قرارداد ساختار Laravel/Vue و CI)  
> **الحاق نهایی سیاست:** ۲۷ اکتبر ۲۰۲۶ — ADR-010/011؛ سقف عادی بکاپ ۱۴ روز و پایان حذف ۷ روز در §۱۲؛ نیازمند پیاده‌سازی و آزمون واقعی.
> **وابسته به:** `Karmandeman_Telegram_MVP_PRD_v1.3.md` و `Karmandeman_Product_Strategy_and_Roadmap_v1.3.md`  
> **مخاطب:** Tech Lead، Backend، Automation Engineer، DevOps، Security، QA  
> **وضعیت:** سند تصمیم و نمونه پیکربندی؛ پیش از go-live باید روی repository واقعی اجرا و راستی‌آزمایی شود. این سند ادعا نمی‌کند سرور یا ربات اکنون استقرار یافته است.

---

## ۱. دامنه و اصول استقرار

این Runbook برای **پایلوت تلگرامی اولین کارمند** نوشته شده؛ مقصد اصلی همچنان پلتفرم مستقل وب و چندکارمندی است. **Laravel System of Record** و مالک حساب/برند/داده/اعتبار/مجوز است. n8n به‌عنوان AI Orchestrator داخلی عمل می‌کند. **Docker Compose** برای شروع کافی است؛ Kubernetes و میکروسرویس توجیه نشده‌اند.

| اصل | الزام عملیاتی |
|---|---|
| Channel-agnostic | Telegram فقط adapter؛ API و داده به chat ID وابسته نشوند |
| Data ownership | داده مشتری، محصول و ledger در DB Laravel؛ فایل‌ها در Object Storage؛ n8n فقط اجرای موقت |
| Least privilege | اجرای n8n بدون دسترسی مستقیم به DB اصلی؛ API داخلی مجوزدار و scope شده |
| Reproducible release | همه package lock و digest تصویر ثبت شود؛ deploy از registry/Git tag مشخص |
| No silent charging | انجام AI هیچ عمل کیف پول را مستقیماً صدا نزند؛ رزرو و تسویه در Laravel |
| Observability | `correlation_id`, latency, error rate, accepted-output cost، queue lag و storage usage |
| Pilot discipline | توسعه قابلیت‌های HA/scale فقط با داده فشار واقعی و تعهد SLA |

## ۲. نسخه‌ها و سیاست سازگاری — Decision Baseline

| جزء | نسخه منتخب | علت و محدودیت |
|---|---|---|
| Laravel | `^13.0` + `composer.lock` | نسخه فعال و دارای وصله امنیتی؛ نیاز PHP ≥8.3؛ security fixes تا 17 مارس 2028 |
| PHP runtime | 8.4 FPM Alpine، patch vetted | سازگار با Laravel13؛ PhpRedis، pdo_pgsql، mbstring، intl، GD، zip، bcmath، opcache، pcntl لازم/پیشنهادی؛ بررسی پس از build |
| Composer | 2.x، نسخه نهایی پین در Dockerfile | install با `--no-dev --prefer-dist --no-interaction` و lockfile |
| PostgreSQL | `17.11` | release پشتیبانی‌شده تا 2029؛ DB مستقل برای Laravel و n8n (دو DB/role یا دو کانتینر برای پایلوت) |
| Redis | `7.2.x` | queue/cache/rate limit؛ Redis Laravel Horizon؛ تنظیم persistence و memory cap |
| n8n | `2.44.6` stable baseline مورخ 22 اکتبر 2026 | فقط orchestration، بدون نصب بتا یا `latest` در prod؛ `n8n` single instance در pilot |
| Docker | Engine از official apt، Compose plugin v2 | نصب رسمی؛ ثبت نسخه واقعی `docker version` و `docker compose version` در manifest |
| Ubuntu | `24.04 LTS` amd64 | انتخاب محافظه‌کارانه عملیاتی؛ وصله امنیتی، SSH key، firewall |
| Edge web | Caddy 2.10 baseline + TLS | خودکارسازی گواهی، پروکسی به PHP-FPM، static assets از image هم‌نسخه |
| Node build | Node 24 LTS | فقط برای frontend build و renderer، نه runtime هسته Laravel |
| PDF engine | Playwright + Chromium pinned | Chromium مجزا برای RTL؛ asset-font قانونی؛ ثبت نسخه مرورگر/Playwright در lockfile |
| Object storage | S3-compatible private bucket | versioning, lifecycle, limited credentials؛ provider باید از منطقه میزبان reachable باشد |
| pgvector | نصب نمی‌شود در P0 | حافظه ساختاریافته کم‌حجم است؛ در صورت نیاز P2 با migration و ارزیابی نسخه |

**قاعده پین نسخه:** Tag بالای جدول، **نام نسخه مبنا**ست؛ در production فایل image manifest باید `image@sha256:...` از registry واقعی را نگه دارد. تغییر digest یا dependency lock فقط با PR، تست و برنامه rollback. به‌طور خاص n8n beta/latest و Alpine floating tags نباید مستقیم روی production deploy شوند. نسخه‌های پیشنهادی بدون آزمایش end-to-end در زیرساخت واقعی، ضمانت سازگاری نیستند.

**مراجع:** Laravel https://laravel.com/docs/13.x/releases و https://laravel.com/docs/13.x/deployment ؛ PostgreSQL https://www.postgresql.org/support/versioning/ ؛ n8n https://github.com/n8n-io/n8n/releases ؛ Docker https://docs.docker.com/engine/install/ubuntu/ .

## ۳. پیش‌نیازهای زیرساخت و ظرفیت اولیه

**محیط‌ها:** developer workstation (Docker Compose dev)، staging با داده آزمایشی/ماسک‌شده، production pilot با داده واقعی؛ هر کدام Bot Token، سرویس AI، storage، DB، NextPay/Stars credentials (در صورت قانونی بودن) و secrets متفاوت. از داده مشتری واقعی در dev استفاده نشود.

**VPS پیشنهادی پایلوت:** 4 vCPU، 8 GiB RAM، 80–120 GiB SSD/NVMe، Ubuntu 24.04 amd64، IPv4 عمومی؛ ورودی 80/443؛ SSH محدود. ظرفیت با فرض سقف هم‌زمانی کوچک (مثلاً حداکثر ۲ اجرای AI/media سنگین) و ذخیره رسانه در Object Storage است. PDF Chromium یا مدل‌های محلی می‌توانند ظرفیت را افزایش دهند؛ AI inference با API خارجی نه GPU سرور محلی. ارقام Benchmark یا تضمین عملکرد نیستند.

**وابستگی خارجی ضروری:** نام دامنه و DNS، Object Storage با lifecycle، دسترسی قابل قبول به API Telegram برای وبهوک و ارسال فایل، providerهای AI با شرایط مجاز/پایدار، کانال امن اعلان هشدار، offsite backup مقصد جدا. مسیرها باید **از شبکه کاربران داخل ایران و از سرور** جداگانه تست شوند؛ صرف انتخاب اروپا کافی نیست.

**DNS پیشنهادی:** `api.karmandeman.ir` برای Telegram webhook/API/کاتالوگ اولیه؛ `karmandeman.ir` برای landing؛ `app.karmandeman.ir` بعداً web workspace؛ `ops` عمومی منتشر نشود. Certificate با Caddy/ACME؛ DNS پیش از اولین deploy فعال باشد. روی CDN/Proxy از صحت `X-Forwarded-*` و IP spoof prevention اطمینان حاصل شود.

**DNS/TLS preflight:** DNS A/AAAA صحیح، پورت 80/443 reachable، valid CA certificate، domain callback تلگرام با HTTPS، هیچ TLS bypass یا گواهی خودامضای غیرمجاز. برای optional CDN، webhook secret حتی پشت CDN بررسی شود.

## ۴. Topology و مرز شبکه

```text
Internet / Iran mobile users  --443/TLS--> Caddy web edge
                                            |
                                 Laravel 13 API (PHP-FPM)
                                   |         |          \
                       PostgreSQL App       Redis       Object Storage (S3 private)
                                   |         |                   |
                             Horizon/Scheduler           Asset URLs short-lived
                                   |
                          n8n internal webhook (no public port)
                                   |
                        n8n PostgreSQL (separate)
                                   |
                      External AI APIs (STT/Vision/Text)
                                   |
                    versioned JSON callback -> Laravel
```

- **Public**: only Caddy `80/443`; Telegram webhook route within Laravel, never direct n8n webhook.
- **Private Docker network**: Laravel/PHP-FPM, Redis, Postgres, n8n, renderer. Containers may need outbound internet to provider APIs; do not accidentally mark entire Docker network `internal: true` without egress design.
- **n8n editor**: no published port in reference compose. For setup use temporary local tunnel/VPN with authentication and TLS; disable access after configuration. Do not expose 5678/5432/6379 directly.
- **Admin/Horizon**: protected application admin route, explicit roles/MFA/IP restrictions; no public unauthenticated queue dashboard.

## ۵. سرویس‌های Docker Compose — وظیفه و محدوده

| سرویس | تصویر/Build | نقش | Data persistence | پروتکل |
|---|---|---|---|---|
| `web` | Caddy 2.10 build target `web` | edge TLS, assets, PHP FastCGI | cert volumes | public 80/443 |
| `app` | Laravel PHP 8.4 build target `app` | API + Bot Webhook | stateless؛ asset در S3 | internal 9000 |
| `horizon` | همان App image | queue workers, retry | Redis/DB | internal |
| `scheduler` | همان App image | scheduled jobs | DB | internal |
| `postgres_app` | PostgreSQL 17.11 | System of Record | encrypted backup volume | internal 5432 |
| `postgres_n8n` | PostgreSQL 17.11 | workflow DB/credentials metadata | encrypted backup volume | internal 5432 |
| `redis` | Redis 7.2 | cache, queue, rate limit | AOF در pilot | internal 6379 |
| `n8n` | 2.44.6 stable | AI workflows | DB + encryption key | internal 5678 |
| `pdf-renderer` | Node 24 + Playwright (بعد از ساخت) | HTML to PDF و تصویر ثابت | temp only | internal HTTP |

**توضیح DB:** reference Compose دو Postgres container با کاربر و volume جدا ارائه می‌کند، چون Setup ساده و ایزولاسیون داده واضح است. `postgres_n8n` و `n8n` پشت profile `bundled-n8n` هستند و در استقرار فعلی که n8n کانتینر مستقل روی همان سرور است (`ai.smartystudio.co.uk`) بالا نمی‌آیند. **توجه:** کاربران `POSTGRES_USER` در تصویر رسمی، در همان کانتینر نقش ادمین DB دارند؛ برای production واقعی با PostgreSQL مدیریت‌شده یا مشترک، نقش bootstrap DBA از نقش کم‌دسترسی app/n8n جدا شود. در محیط بالغ‌تر می‌توان یک سرور PostgreSQL با دو database/user جدا ساخت. این انتخاب به معنی نیاز ذاتی محصول به دو VPS نیست.

**فایل‌های نمونه:** `compose.prod.reference.yaml`, `compose.dev.yaml` (override توسعه لوکال؛ پورت‌های Postgres/Redis فقط روی loopback), `deploy/Dockerfile`, `deploy/Caddyfile`, `.env.production.example`, `.dockerignore` (همه در ریشه/`deploy/` مخزن). این فایل‌ها Template هستند و به Laravel source/app و PDF Renderer هنوز توسعه‌نیافته وابسته‌اند؛ **به‌عنوان Stack تست‌شده production معرفی نشوند.**

## ۶. Dockerfile، Image Build و وابستگی Laravel

**الزام image:** PHP 8.4 FPM با extensionهای `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo_pgsql`, `session`, `tokenizer`, `xml`; برای پروژه `intl`, `gd`, `zip`, `bcmath`, `exif`, `pcntl`, `redis`, `opcache`. بخشی built-in و بخشی build-time نصب می‌شوند. در CI با `php -m` و `composer check-platform-reqs` verify شوند.

**Build:** multi-stage شامل PHP 8.4 و Node 24 برای Vue 3/TypeScript/Vite (`npm ci && npm run build` با `package-lock.json`)، composer.lock اجباری، `.dockerignore` **در ریشه واقعی مخزن** برای `.env*`, `.git`, `node_modules`, فایل‌های حساس و کاربران؛ `.dockerignore` فعال در ریشه مخزن قرار دارد و پیش از اولین build باید مجدداً بازبینی شود. **در غیر این صورت `COPY . .` می‌تواند کلیدهای production را در image جاسازی کند**؛ تصویر جدا `web` شامل public assets همان commit. `APP_KEY` و provider secrets هرگز در Dockerfile، Docker image layer یا CI log قرار نگیرند. برنامه باید با readonly source در production کار کند، write فقط در `storage`, cache و temp مجاز باشد.

**Laravel packages تصمیم:** `laravel/horizon` برای Redis queue؛ `league/flysystem-aws-s3-v3` برای درایور S3 در Laravel؛ `guzzlehttp/guzzle` فقط اگر نیاز؛ `spatie/laravel-permission` اختیاری P2 (P0 policy/gate کافی)؛ `laravel/sanctum` تنها برای login/API بعد از تصمیم معماری auth؛ یک library Telegram Bot API با maintenance فعال یا direct typed HTTP client در P0. نصب وابستگی بدون ضرورت ممنوع. `phpredis` ترجیح داده شده است.

**Runtime .env (نمونه):** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.karmandeman.ir`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `DB_CONNECTION=pgsql`, `DB_HOST=postgres_app`, `REDIS_HOST=redis`, `FILESYSTEM_DISK=s3`, `N8N_INTERNAL_URL=http://n8n:5678`, `N8N_API_URL` (n8n به‌صورت کانتینر مستقل روی همان سرور اجرا می‌شود؛ Laravel می‌تواند هم از آدرس عمومی `https://ai.smartystudio.co.uk` و هم — اگر شبکه docker مشترک با کانتینر n8n برقرار شود — از `http://<n8n-container>:5678` استفاده کند), `N8N_API_KEY` (JWT عمومی n8n برای مدیریت/اجرای workflow از سمت Laravel، بدون قرارگیری در image یا لاگ), `TELEGRAM_WEBHOOK_SECRET` و کلیدهای S3. درگاه پرداخت تا تأیید GO قرار نگیرد. برای DB دو رمز مستقل و برای n8n `N8N_ENCRYPTION_KEY` طولانی و پایدار ایجاد شود.

**نکته PHP-FPM:** process اصلی می‌تواند با Root master و pool `www-data` اجرا شود؛ user و capabilities با image hardening بررسی شود. Laravel دارای cron OS host نیست؛ سرویس scheduler در Compose پایدار است؛ از اجرای هم‌زمان دو scheduler در مقیاس آینده جلوگیری شود. `APP_DEBUG=false` حتی در بروز خطا تغییر نکند.

## ۷. n8n Execution Engine — سیاست دقیق

### ۷.۱ Workflowهای قابل نگهداری

- `brand_onboarding_v1`: پیشنهاد پروفایل برند از گفت‌وگو؛ فقط «کاندید حافظه» نه آپدیت مستقیم حافظه پایدار.
- `product_intake_v1`: transcribe/OCR/vision، نام و ویژگی‌ها همراه confidence/evidence refs و nullable fields.
- `clarification_v1`: پرسش درباره داده‌های مانع خروجی، با بودجه حداکثر پرسش/turn.
- `product_copy_v1`: عنوان/توضیحات بر اساس Product snapshot + brand snapshot تأییدشده.
- `ai_quality_review_v1`: یافتن تناقض‌های متنی؛ جایگزین checks deterministic نیست.

### ۷.۲ قرارداد داخلی نمونه

```json
{
  "schema_version":"1.0",
  "workflow":"product_intake_v1",
  "run_id":"uuid",
  "workspace_id":"uuid",
  "project_id":"uuid",
  "asset_ids":["uuid"],
  "brand_snapshot_revision":7,
  "limits":{"max_cost_units":10,"timeout_seconds":180}
}
```

**خروجی:** JSON طبق schema با item IDs/attribute evidence، `unknown` برای اطلاعات نامشخص، model/provider IDs، latency، usage و cost estimate. حتی اگر n8n schema validator خروجی را قبول کرد، **Laravel دوباره** نوع داده، مالکیت Workspace، قالب و policy را validate کند.

**شروع و callback:** یک `run_id` از Laravel، ارسال asynchronous به n8n webhook داخلی با header auth، response `202` فقط نشانه قبول صف نیست مگر n8n run نیز ثبت شود. نتیجه از طریق internal API مجوزدار یا callback HMAC و idempotent برگردد. Timeout، failover provider و exponential backoff در backend کنترل؛ تکرار run نباید دوباره ledger charge کند.

**عدم انتظار تعاملی:** n8n برای پاسخ چندساعته مشتری pause نشود؛ پروژه در DB حالت `needs_clarification` می‌گیرد؛ پاسخ جدید کاربر workflow جدید را شروع می‌کند.

**اطلاعات حساس و Data retention:** بر اساس policy، execution payload با داده کامل مشتری در تاریخچه n8n ذخیره نشود؛ metadata لازم برای monitoring در Laravel ثبت شود. `EXECUTIONS_DATA_PRUNE=true` و retention کوتاه (مثلاً ۱۶۸ ساعت) صرفاً safety net. بکاپ کلید رمزگذاری n8n حیاتی است؛ بدون آن credentials قابل بازیابی نیستند.

**Binary limits:** روی n8n native external S3 storage برای binary execution پیش‌فرض حساب نکنید؛ مستند n8n، این قابلیت را Enterprise-tier معرفی می‌کند. Object Storage اصلی توسط Laravel مدیریت شود؛ payload n8n شامل شناسه فایل/metadata/URL دسترسی کوتاه‌مدت باشد؛ فایل حجیم از execution JSON عبور نکند. <https://docs.n8n.io/hosting/scaling/external-storage/>.

**Scale:** n8n در P1 single instance، workflow concurrency cap. در صورت فشار واقعی، n8n queue mode با Redis و workerهای مجزا، با مرور نسخه/سازگاری binary و مجوز؛ **از روز اول لازم نیست**.

**Security:** admin URL غیرعمومی، 2FA در صورت امکان، minimal credentials، disabled risky/community nodes مگر با بررسی؛ کنترل SSRF و IP egress/allowlist سرویس‌های AI؛ security audit n8n قبل release و ماهانه. هیچ مشتری end-user وارد editor n8n نشود. جریان‌های API به سرویس ناآشنا/تحریم‌شده یا منع‌شده ارسال نکنیم.

**Licensing Gate:** اتصال n8n به workflowهای backoffice که end-user فقط نتیجه می‌بیند، باید با شرایط استفاده رسمی تطبیق داده شود. امکان استناد قطعی به مجوز بر اساس برداشت شفاهی نداریم؛ در صورت نیاز تأیید کتبی فروشنده پیش از pilot پولی.

## ۸. مدیریت فایل‌ها، Storage، PDF و رسانه

**Bucketها:** `km-originals-private`, `km-artifacts-private` و (در صورت نیاز) `km-catalog-public-versions` با policy محدود. اگر یک bucket باشد prefix/ACL و presigned URLs اجباری. `workspace_id/project_id/asset_id` به‌صورت شناسه ساختاری نگه داشته شود؛ نام مشتری در Object Key یا URL ثبت نشود.

**Lifecycle پیشنهادی قابل تنظیم با قرارداد/رضایت:** فایل اصلی ۹۰ روز پس از آخرین استفاده یا مطابق نیاز، artifacts حداقل ۹۰ روز در beta، نسخه catalogue public تا revoke/expiry؛ این زمان‌ها پیشنهادند و به تجربه کاربر/هزینه بستگی دارند. اصل حذف باید نسخه‌ها، cache، embedding آینده و بکاپ‌های در گردش را پوشش دهد؛ retention بکاپ از قبل اعلام شود.

**Signed URLs:** با TTL کوتاه (مثلاً ۱۰–۳۰ دقیقه) و مجوز Workspace؛ لینک public catalog جدا از pre-signed download. تاریخ انقضا و revocation باید توسط Backend اعمال شود؛ لینک S3 دائمی عمومی ممنوع.

**PDF Persian:** HTML templates کنترل‌شده RTL با فونت دارای مجوز و صفحه‌بندی ثابت؛ Chromium/Playwright worker isolated (Node 24 LTS) با timeout، memory limits، غیرفعال‌سازی شبکه غیرضروری، sanitization محتوای متنی و عدم اجرای JS تولیدشده توسط مشتری. تست «ی/ک»، نیم‌فاصله، راست‌به‌چپ، چاپ، اعداد تومان/ریال، محصولات طولانی و لوگوی کوچک. برای فاز اول rendering را محدود به دو قالب موردپذیرش کنید.

**WooCommerce CSV:** بر اساس sample importer واقعی و golden fixtures؛ UTF-8 BOM فقط در صورت نیاز importer test؛ SKU و نوع قیمت deterministic، null price غیرقابل تبدیل به صفر؛ Variationها P1. فایل CSV حاوی روابط تصویر باید از لینک‌های پایدارِ مجاز برای import پشتیبانی کند و مسیر ZIP/بارگذاری دستی fallback داشته باشد.

## ۹. Telegram Integration — runtime و امنیت

- Bot token مستقل staging/production؛ Bot username بخشی از identity داخلی نیست.
- SetWebhook با `https://api.karmandeman.ir/hooks/telegram/{opaque}` و `secret_token`; Laravel فوراً validate/ack و سپس enqueue. `X-Telegram-Bot-Api-Secret-Token` را در constant-time compare با secret کند.
- Dedup `update_id` در DB با unique index و TTL policy، dedup `callback_query.id` و internal command id؛ retry تلگرام نباید token/cost تکراری بسازد.
- `getWebhookInfo` و backlog/error پس از هر deploy بررسی شود؛ حد `max_connections` محافظه‌کارانه در pilot تنظیم شود.
- فایل‌ها ابتدا با server-side download و checksum در storage خصوصی ثبت شوند؛ Bot API cloud سقف دانلود دارد، پس سقف UI/آپلود MVP را **پس از تست API رسمی** تعیین کنید؛ برای عکس اصلی ارسال به‌عنوان Document توصیه می‌شود.
- در صورت قطعی Telegram، backend jobها و داده محصول محفوظ بمانند؛ اعلان ناقص را پس از بازیابی retry کنید.

## ۱۰. امنیت سرور و شبکه

1. Host OS: sudo user مجزا، SSH key only، root login off، password auth off، UFW/firewall فقط SSH محدود + 80/443؛ time sync + unattended security updates، audit login.
2. Docker: نسخه امن، حداقل privileged capability، عدم mount `/var/run/docker.sock` به n8n یا app، containers بدون bind socket/host network، volume permissions و image scanning.
3. Secrets: `.env.production` خارج Git، فایل permission 0600، محرمانه‌سازی CI variables؛ گزینه برتر Docker Secrets/managed secret store و rotation test. نشت token/APP_KEY/N8N_ENCRYPTION_KEY release blocker.
4. Endpoints: TLS، `APP_DEBUG=false`، deny dashboardهای admin بدون auth؛ API rate limits و cross-tenant authorization tests، request size/quota.
5. File: MIME sniffing، ویروس scan در صورت امکان، metadata stripping متناسب با حفظ عکس مرجع، جلوگیری از ZIP bombs و SSRF؛ منع HTML/SVG کاربر در PDF بدون sanitize.
6. Internal service: signed requests + short TTL + scope; egress restrictions برای n8n و renderer؛ نیازی به اجازه اینترنت عمومی برای هر node نیست.
7. AI: third-party provider approval، data policy، جلوگیری از prompt injection و data exfiltration؛ هیچ خروجی مدل به SQL/code execution تبدیل نشود.
8. Incident: token rotation، revoke download links، isolate worker، audit export، اطلاع‌رسانی طبق policy/قانون قابل اعمال.

## ۱۱. CI/CD و Release Management

**Repo تصمیم (الزام‌آور):** Monorepo برای Laravel و فایل‌های مرجع infra در ریشه و `deploy/`، Vue 3 + TypeScript و Workflow JSON exports. ساختار feature-first دقیقاً `app/Actions/{Feature}/`, `app/Http/Controllers/API/V1/`, `app/Http/Requests/{Feature}/`, `app/Http/Resources/{Feature}/`, `app/Repositories/Contracts/`, `app/Repositories/Eloquents/{Model}/`, `app/Services/`, `app/Models/`, `app/Scopes/`, `app/Helpers/` و `app/Providers/` است. `app/Domain/` جایگزین این قرارداد نیست. `resources/js/src/modules/{feature}/`, `shared/`, `stores/`, `router/` برای Vue الزامی. جزئیات در `Karmandeman_Engineering_Standards_v1.0.md`; migrations زیر کنترل source.

**Branching:** main protected، PR review، `v0.x.y` release tags، conventional changelog؛ staging از merge main، production فقط from tested release artifact.

**CI required checks:** `composer validate`, `composer audit`, Laravel Pint `--test`, PHPStan+Larastan (level baseline مستند)، `php artisan test` (PHPUnit یا Pest)، JSON Schema contract tests، authorization/tenant isolation، credit idempotency، CSV roundtrip fixture، PDF RTL snapshot، `npm ci`, `vue-tsc --noEmit`, ESLint, Vitest, Vue/Vite build و Playwright E2E منتخب؛ secret scanning، image vulnerability scan/SBOM؛ ساخت همه artifacts از lockfile. Code review باید رعایت Action/Request/Resource/Repository/Service، DI و API response shape را پیش از merge کنترل کند.

**Release pipeline:** ① Staging backup + test migration ② build PHP+web images + security scan ③ resolve image digests + Release Manifest ④ deploy staging ⑤ E2E bot + n8n + PDF + wallet ⑥ production DB backup ⑦ deploy app/web/horizon/scheduler tagged images ⑧ `php artisan migrate --force` تنها migrations expand/backward-compatible ⑨ `php artisan optimize` ⑩ `php artisan horizon:terminate` ⑪ smoke /up+Telegram+workflow+csv/pdf ⑫ monitor initial 30–60min.

**Rollback:** code/images/workflows rollback only if DB schema backward compatible. Destructive migrations require expand-and-contract separate release; در ناسازگاری، forward-fix یا restore هماهنگ داده/فایل/ledger با هماهنگی Product/Finance. پس از پرداخت، restore کورکورانه DB بدون reconciliation تراکنش‌ها ممنوع.

## ۱۲. بکاپ، Recovery و آزمون Drill

**دارایی‌ها:** PostgreSQL app، PostgreSQL n8n، Object Storage نسخه‌دار، `N8N_ENCRYPTION_KEY`, `APP_KEY`, credential inventory، workflow version JSON، پیکربندی release/DNS، کلید عمومی TLS در صورت نیاز (Caddy می‌تواند مجدداً certificate بگیرد). Redis queue state حیاتی است، ولی System of Record وظیفه را در PostgreSQL نگاه می‌دارد تا replay کنترل‌شده ممکن باشد.

**Policy تصمیم‌گرفته‌شده مالک محصول — ۲۷ اکتبر ۲۰۲۶:** encrypted daily DB snapshots/pg_dump و نسخه‌های خارج از VPS، با سقف عمر **۱۴ روز / ۳۳۶ ساعت برای همه نسخه‌ها** از زمان ایجاد؛ نسخه هفتگی/ماهانه عمر بلندتر ندارد. این تصمیم [ADR-010](adr/ADR-010-fourteen-day-backups-and-full-erasure.md) جای پیشنهاد پیشین ۷ روز روزانه/۴ هفته هفتگی/۳ ماه ماهانه را می‌گیرد. Object Storage versioning/lifecycle، offsite، snapshot، WAL/PITR، زنجیره incremental، فایل موقت restore و نسخه کلید قابل بازیابی نیز مشمول‌اند؛ انتقال یا کپی، سن داده را صفر نمی‌کند. بکاپ plain در همان VPS **DR نیست**. اجرا و اثبات lifecycle هنوز gate انتشار است.

**به‌روزرسانی ۲۸ اکتبر ۲۰۲۶ ([ADR-014](adr/ADR-014-seven-day-database-backups-and-live-purge.md)):** همه بکاپ‌های **دیتابیس برنامه** شامل pg_dump، snapshot، WAL/PITR و نسخه offsite حداکثر **۷ روز** از ایجاد نگهداری شوند. مقدار عملیاتی ۱۴۴ ساعت است تا پاک‌سازی زنده ≤۲۴ ساعت از T0 داخل مهلت ۱۶۸ ساعت بماند. بکاپ key store حداکثر ۴۸ ساعت است. سقف ۱۴ روز فقط برای بکاپ‌های بدون داده plaintext حساب می‌ماند. سرویس `scheduler` دستور `privacy:process-deletions` را هر ۵ دقیقه اجرا می‌کند. `KM_ERASURE_BACKUP_LIFECYCLE_VERIFIED=true` فقط پس از شواهد اجرای واقعی هر دو سقف تنظیم شود.

**مهلت نهایی حذف کامل:** طبق [ADR-011](adr/ADR-011-seven-day-complete-erasure.md)، T0 ثبت درخواست تأییدشده مالک پس از احراز هویت مجدد است؛ پایان حذف همه داده/تاریخچه و کپی‌های تحت کنترل حداکثر **T0+۷ روز / ۱۶۸ ساعت** است. از T0 پردازش تازه/دسترسی لغو و callback/job دیررس مهار شود. مهلت هر کپی حامل داده `min(پایان عمر عادی ۱۴روزه نسخه، T0 + ۷ روز)` است. expire=14d به‌تنهایی کافی نیست و گردش بکاپ به مهلت اضافه نمی‌شود. بکاپ مشترک یا زنجیره بازیابی باید بدون نقض بازیابی دیگران پاک/جایگزین شود؛ حذف رمزنگارانه فقط با اثبات نبود همه کپی‌های قابل بازیابی کلید/داده معتبر است. پس از تکمیل، هیچ داده شخصی یا تاریخچه درخواست/رسید تحت کنترل باقی نماند؛ نگه‌داشتن shadow account ممنوع است.

**طرح کلید و گذار لازم:** کلید مستقل حذف‌پذیر حساب/Workspace و envelope encryption، key store جدا از بکاپ معمول، پوشش تمام نسل‌ها و recovery/escrow/replica و حذف قطعی آن‌ها تا روز ۷ لازم است؛ disable یا scheduled deletion طولانی‌تر کافی نیست. کلید مشترک APP_KEY، plaintext/متادیتای خوانا و بکاپ قدیمی با حذف کلید حساب پوشش داده نمی‌شوند؛ آن نسخه‌ها تا روز ۷ باید پاک/پالایش شوند. پیش از عرضه، مهاجرت داده و پاک‌سازی/جایگزینی بکاپ قدیمی با backup، rehearsal و rollback آزموده انجام شود. این طرح در نسخه فعلی پیاده نشده است.

**رمزنگاری خصوصی و کلید:** پیش از عرضه، فهرست محل‌های واقعی داده خصوصی (DB، حافظه و revisions، فایل/نسخه، replay، توکن/session/cache، صف، تیکت/لاگ و بکاپ) و روش پوشش آن‌ها تهیه شود. کانال انتقال امن، رمزنگاری احرازشده در ذخیره، جداسازی کلید، دسترسی محدود و rotation/recovery آزموده الزامی است؛ secrets در source/log/image نباشد. APP_KEY مشترک به‌تنهایی طراحی حذف انتخابی کاربر نیست. تنظیم این سیاست یا وجود encrypted cast اثبات رمزنگاری همه داده‌ها نیست.

**اهداف:** RPO 24h و RTO 8h صرفاً اهداف داخلی اولیه. برای کسب‌وکار پولی با حجم بالاتر باید Point-in-Time Recovery (WAL/archive) و RPO کوتاه‌تر بررسی شود.

**Restore drill:** هر ماه در staging ایزوله، restore هر دو DB و کاتالوگ نمونه/خروجی + key n8n، بررسی gateway/webhook inactive، verify checksum و authorization، ثبت زمان و gap. داده مشتری را برای drill در محیط عمومی قرار ندهید. مرجع حذف و key service نباید از بکاپ عقب‌گرد کنند؛ پیش از بازگشایی webhook، عدم بازگشت داده/هویت/کلید/job حساب حذف‌شده اثبات شود؛ فهرست موقت جلوگیری از بازگشت هم مشمول مهلت حذف است. job queue dedup/ledger reconcile انجام شود. با ساعت کنترل‌شده، قبل/مرز/بعد ۷ روز برای حذف و ۱۴ روز برای عمر بکاپ، بکاپ قبل/پس از T0، recovery کلید قدیمی و قطعی پاک‌سازی تست شود (PRD QA-19…21). سلامت بازیابی tenant دیگر و سن نسخه‌ها/هشدار نقض ثبت شود. تا شواهد واقعی، دکمه فقط مجاز به نمایش «در حال رسیدگی» است.

**مسئول:** Tech Lead owner backup freshness، عملیات DB و restore؛ Founder/Product owner سیاست نگه‌داری و اطلاع‌رسانی.

## ۱۳. Monitoring/Alerting و SLO

| شاخص | هدف اولیه / هشدار |
|---|---|
| Backend uptime `/up` | هدف آزمایشی ≥99% ساعات pilot، ثبت incident؛ NFR رسمی پس از اندازه‌گیری |
| Telegram webhook receive errors | افزایش 5xx/401/duplicate، pending update count و last_error |
| Laravel queue wait/failed jobs | alert ماندن job >5min برای interactive، failure rate و retries |
| n8n workflow | failed/timeouts، provider 4xx/5xx، execution count، latency p95 |
| PDF/CSV | error count، render timeout، invalid importer fixture |
| PostgreSQL | free disk, connections, replication/backup freshness, vacuum/lock, slow queries |
| Memory/CPU/disk | هشدار 75/85%، OOM/restart count، image/storage growth |
| Credits & payments | رزروهای مانده، ledger mismatch، duplicate capture، reconciliation |
| Unit economics | AI cost per accepted product، cost per approved output، human support minutes |

Logs JSON دارای `correlation_id`, `workspace_id` pseudonymized و trace identifiers باشد؛ بدون raw voice transcription/photo/financial details یا credentials. Laravel Horizon dashboard با access control، n8n audit/security audit، Sentry یا معادل self-hosted/managed مجاز پس از ارزیابی انتقال داده.

## ۱۴. نگهداری، patch management و چرخه رشد

- هفتگی: بررسی CVE، Docker image و n8n releases، audit failures، API model costs، هشدار backup.
- ماهانه: minor/patch upgrade در staging، security audit n8n، restore drill، credential inventory و dependency report.
- سه‌ماهه: مرور حدود هزینه/Quota، policy retention و storage، مجوز providerها و ریسک دسترسی ایران.
- شرط scale خارج Compose: utilization پایدار >70% یا queue backlog persistent، رشد هم‌زمانی، نیاز availability واقعی؛ گزینه اول split n8n worker/PDF worker/DB و Object Storage بیرونی، نه فوراً Kubernetes.

## ۱۵. تحویل‌های دقیق مورد انتظار از DevOps / تیم توسعه

| Deliverable | معیار پذیرش |
|---|---|
| `Dockerfile` app/web | build from clean clone، no secrets in layer، locked deps |
| `compose.yaml` per env | valid compose config، فقط 80/443 public، persistent volumes |
| `.env.example` + secret inventory | no real tokens، deployment guide، rotation owners |
| `n8n_workflows/*.json` | versioned, no credentials, stable schema contract |
| DB migrations | tenant-scoped schema، credit ledger atomicity، unique idempotency |
| `backup/restore` scripts | encrypted off-host backups و restore drill موفق |
| `CI/CD` pipeline | automated tests, image scan, release manifest, staging approval |
| `/up` & deep health/admin diagnostics | public shallow probe، authenticated internal dependency probes |
| `runbook incidents` | steps برای outage AI، Telegram، DB، payment، object storage |
| `cost reports` | token usage، retries، PDF/image cost، financial forecast feedback |
| `architecture diagram` | updated component/data flow including n8n and renderer |

## ۱۶. Go-Live Checklist قابل امضا

- [ ] DNS/HTTPS برای `api.karmandeman.ir` معتبر، Telegram webhook با secret و dedup فعال.
- [ ] Laravel 13/PHP 8.4 production image build/test و همه PHP extensions verify شده‌اند.
- [ ] n8n 2.44.6 baseline و PostgreSQL 17+ / Redis 7.2 interoperability در staging تست شده‌اند.
- [ ] همه imageها به digest پین، release manifest و rollback tag ثبت شده‌اند.
- [ ] DB و Redis و n8n editor از اینترنت قابل دسترسی نیستند.
- [ ] Object Storage private، signed URL، versioning و expiry/revoke تست شده است.
- [ ] حداقل دو Tenant آزمایشی و تست ممنوعیت دسترسی متقابل پاس شده‌اند.
- [ ] Telegram duplicated update، AI retry، HMAC callback و replay بدون ledger duplicate پاس شده‌اند.
- [ ] WooCommerce CSV sample import و PDF فارسی RTL/logo در تست واقعی تأیید شده‌اند.
- [ ] restore هر دو DB و بخشی از Storage انجام و زمان RPO/RTO اندازه‌گیری شده است.
- [ ] monitoring، failed-job alert، n8n fail alert و گزارش هزینه AI فعال‌اند.
- [ ] محدودیت هدیه، quote/capture/refund، abuse quota مشخص و تست شده‌اند.
- [ ] Telegram Stars / NextPay channels و پذیرندگی در صورت فروش پولی تأیید شده‌اند.
- [ ] n8n license و دسترسی provider AI از نظر حقوقی و فنی بررسی و ثبت شده‌اند.
- [ ] مسیر دریافت درخواست حذف اطلاعات، retention و اعلام پردازش AI مستند شده‌اند.

## ۱۷. منابع رسمی و ملاحظات سند

- Laravel 13 release/security: https://laravel.com/docs/13.x/releases
- Laravel Deployment: https://laravel.com/docs/13.x/deployment
- Laravel Horizon: https://laravel.com/docs/13.x/horizon
- Laravel Redis: https://laravel.com/docs/13.x/redis
- n8n documentation: https://docs.n8n.io/
- n8n GitHub releases: https://github.com/n8n-io/n8n/releases
- n8n Official hosting examples: https://github.com/n8n-io/n8n-hosting
- n8n Enterprise binary S3 limitations: https://docs.n8n.io/hosting/scaling/external-storage/
- PostgreSQL 17 life cycle: https://www.postgresql.org/support/versioning/
- Docker official Ubuntu installation: https://docs.docker.com/engine/install/ubuntu/
- Telegram Bot API: https://core.telegram.org/bots/api
- Telegram digital payments: https://core.telegram.org/bots/payments-stars
- NextPay API: https://nextpay.org/nx/docs

**Scope disclaimer:** این فایل تصمیم‌های مهندسی و معیارهای پذیرش را تعیین می‌کند، نه deploy واقعی یا تضمین حقوقی، throughput، هزینه و uptime. هر تغییر نسخه و هر عملیات production مستلزم آزمون staging، بررسی شرایط سرویس و ثبت evidence است.


---

## ۱۸. Engineering Architecture Contract و قواعد اجرای آن — نسخه ۱.۱

این فصل `Karmandeman_Engineering_Standards_v1.0.md` را الزام‌آور می‌کند و از اختلاف ساختار PRD/Runbook/سورس جلوگیری می‌کند. **Source of Truth برای conventions سند استاندارد مهندسی است.**

### ۱۸.۱ Reusable Template و جایگاه کد

- `app/Actions/{Feature}/*Action.php` با متد `execute` و مسئولیت یکتا؛ `Controllers/API/V1` نازک، `FormRequest` validate/authorize، `JsonResource` output.
- `app/Repositories/Contracts/*RepositoryInterface.php` و `app/Repositories/Eloquents/{Model}/Eloquent*Repository.php`؛ bind در `app/Providers/RepositoryServiceProvider.php` و register در `bootstrap/providers.php`.
- `app/Models` بدون business orchestration؛ `app/Scopes` فقط scopes؛ `app/Helpers` فقط pure utilities؛ Serviceهای integration مثل `AIWorkflowService` و `TelegramMessagingService` در `Services/`.
- `resources/js/src` برای **Vue 3 + TypeScript + Pinia + Vue Router** با پوشه‌های `modules`، `shared`، `stores`، `router`; componentهای بزرگ و state نامتمرکز ممنوع.
- `app/Jobs` و `Policies`, `Events`, `Enums`, `Data` افزونه‌های مجاز Laravel هستند؛ قواعد لایه‌بندی به این معنی نیست که باید تمام منطق async را به Actions sync محدود کرد.

### ۱۸.۲ Build و Docker / CI تطبیق داده‌شده

1. Laravel/PHP app image و Vite assets از همان commit و lockfile ساخته شوند. Runtime نباید Node/Vite development server داشته باشد؛ PHP-FPM و Caddy فایل‌های `public/build/` هم‌نسخه را سرو کنند.
2. نمونه Dockerfile در بسته زیرساخت **Node build stage** دارد؛ پیش از build واقعی وجود `package.json`, `package-lock.json`, `vite.config.ts` یا معادل و دستور `build` الزامی است.
3. تست Laravel `php artisan test` با پایگاه PostgreSQL جدا در CI و Redis در integration؛ SQLite جایگزین کافی برای تست tenant/currency/wallet نیست.
4. `vue-tsc --noEmit`, ESLint, Vitest و build Vite؛ browser E2E برای Review View/RTL و هماهنگی API Resource/TS DTO لازم است.
5. Merge gate: `FormRequest`/Policy، contract Resource، query scoping، Action/Service boundaries، typed DTO و Repo binding، idempotency wallet/Telegram/n8n بررسی شوند.
6. پکیج‌های tooling موردنظر بعد از ایجاد مخزن در `composer.json` dev و `package.json` devDependencies اضافه، نسخه‌های آن‌ها در lockfile پین و license/CVE در CI کنترل شود. ابزار بدون config و دستور قابل اجرا در CI «اعمال‌شده» محسوب نمی‌شود.

### ۱۸.۳ فهرست بازبینی Pull Request

- [ ] Feature و use case مشخص شده؛ فایل‌ها در layer و مسیر مصوب هستند.
- [ ] هیچ SQL/Query/Rule/Service orchestration در API Controller قرار ندارد.
- [ ] Model فقط cast/relationship/scope دارد؛ `fillable` explicit و policy/tenant isolation تست شده‌اند.
- [ ] API خروجی normalized Resource + error envelope دارد؛ FormRequest validation و permissions درست است.
- [ ] Action تک‌مسئولیتی، constructor injection و bindingها معتبرند؛ Repo persistence-only است.
- [ ] همه side effectها آشکارند، queue after-commit و callback idempotent هستند.
- [ ] wallet و state transition با DB transaction و race-condition tests پوشش داده شده‌اند.
- [ ] Laravel Pint، static analysis، tests، Frontend typecheck/lint/build، secret scan و Docker build pass هستند.
- [ ] migration backward-compatible یا برنامه expand/contract مستند است.

### ۱۸.۴ وضعیت پیاده‌سازی

این Runbook و فایل‌های مرجع infra (`compose.prod.reference.yaml`، `deploy/`، `.env.production.example`، `.dockerignore`) **template/reference** هستند. فولدرهای `app/Actions/` یا `resources/js/src/` باید هنگام bootstrap پروژه Laravel/Vue ایجاد شوند؛ وجود نامشان در فایل استاندارد به معنی ایجاد سورس قابل اجرا یا گذراندن تست نیست. فایل `AGENTS.md` و در صورت استفاده از Cursor، `.cursor/rules/*.mdc` در بسته نمونه موجودند تا ابزارهای کدنویسی نیز قواعد یکسان را اعمال کنند.
