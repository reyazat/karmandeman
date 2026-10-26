# استاندارد مهندسی و قرارداد معماری کارمند من

> **شناسه:** KM-ENG-001  
> **نسخه:** 1.0 — ۲۶ اکتبر ۲۰۲۶  
> **وضعیت:** **Normative / Mandatory** برای کد جدید و بازآرایی کد قبلی  
> **محدوده:** Laravel API و Backend، Vue 3 + TypeScript، ادغام n8n، تست، بازبینی و تحویل کد  
> **وابسته به:** `Karmandeman_Telegram_MVP_PRD_v1.3.md`، `Karmandeman_Deployment_Runbook_v1.1.md`  
> **توجه:** این سند یک قرارداد طراحی است؛ وجود کلاس‌ها/تست‌های ذکرشده، به معنای پیاده‌سازی یا تست شدن آن‌ها نیست.

## ۱. اصول الزام‌آور

**Clean Code، SOLID، DRY با حفظ خوانایی، KISS، Separation of Concerns، Explicitness over Magic و Consistency** مبنای تصمیم هستند. پیچیدگی اضافی برای پیروی صوری از الگوها ممنوع است: یک Action ساده نباید به Service، چند Helper و سلسله‌مراتب ارث‌بری غیرضروری تبدیل شود.

- **Thin Controller:** فقط دریافت `FormRequest`، فراخوانی Action/Service و بازگرداندن `JsonResource`/پاسخ خطای استاندارد؛ بدون query یا محاسبات دامنه.
- **Single-purpose Action:** هر فعل تجاری در `app/Actions/{Feature}/...Action.php`؛ یک مسئولیت با transaction boundary صریح در صورت نیاز.
- **Form Request:** validation/authorization در `app/Http/Requests/{Feature}/...Request.php`؛ Policy برای مجوزهای دامنه‌ای.
- **API Resource:** تمام endpointهای عمومی با `app/Http/Resources/{Feature}/...Resource.php` یا `ResourceCollection`، هرگز خروجی raw Eloquent model.
- **Repository Contract + Eloquent Implementation:** قرارداد در `app/Repositories/Contracts/` و پیاده‌سازی مختص موجودیت در `app/Repositories/Eloquents/{Model}/`؛ منطق persistence/query در این لایه، نه قواعد کسب‌وکار.
- **Lean Model:** فقط casts، fillable صریح، relations، query scopes و accessor/mutator ساده؛ orchestration، پرداخت، workflow و HTTP call در Model ممنوع.
- **Service فقط در صورت نیاز:** هماهنگی چند Action/Repository، API خارجی یا orchestration قابل استفاده مجدد؛ از GodService/ManagerService جلوگیری شود.
- **Providers:** interface bindings با constructor injection؛ Container resolve دستی در منطق تجاری ممنوع مگر دلیل فنی ثبت‌شده.
- **Scopes:** `app/Scopes` برای Eloquent global reusable scopes؛ authorization و tenant isolation علاوه بر Scope باید در Policy، repository filters و آزمون‌های امنیتی تضمین شوند.
- **Helpers:** فقط توابع pure و مستقل از DB/I/O/Service Container؛ هیچ state mutation یا business logic.

## ۲. ساختار Canonical مخزن

```text
karmandeman/
├── app/
│   ├── Actions/
│   │   ├── Identity/
│   │   ├── Workspace/
│   │   ├── Brand/
│   │   ├── Memory/
│   │   ├── Product/
│   │   ├── Project/
│   │   ├── Approval/
│   │   ├── Catalog/
│   │   ├── Wallet/
│   │   └── Employee/
│   ├── Http/
│   │   ├── Controllers/API/V1/{Identity,Brand,Memory,Product,Project,Catalog,Wallet}/
│   │   ├── Controllers/Webhooks/{Telegram,Internal,Payment}/
│   │   ├── Middleware/
│   │   ├── Requests/{Identity,Brand,Memory,Product,Project,Catalog,Wallet}/
│   │   └── Resources/{Brand,Memory,Product,Project,Catalog,Wallet}/
│   ├── Models/
│   ├── Policies/
│   ├── Repositories/
│   │   ├── Contracts/
│   │   └── Eloquents/{Brand,MemoryItem,Product,Project,Artifact,WalletLedger}/
│   ├── Services/{AI,Telegram,Memory,ProductIntake,Export,Storage,Billing}/
│   ├── Jobs/{AI,Project,Export}/
│   ├── Events/
│   ├── Listeners/
│   ├── Enums/
│   ├── Data/                     # typed input/output DTO or Value Objects only when justified
│   ├── Scopes/
│   ├── Helpers/                  # pure utilities only
│   └── Providers/
├── bootstrap/providers.php      # ثبت RepositoryServiceProvider و service bindings
├── database/{migrations,seeders,factories}/
├── routes/{api.php,web.php}/     # api/v1 prefix؛ webhook routeها با middleware جدا
├── resources/js/src/
│   ├── modules/{admin,auth,brand,memory,product,project,catalog,wallet,employee}/
│   ├── shared/{components,composables,types,utils,api}/
│   ├── stores/
│   └── router/
├── tests/{Unit,Feature,Integration,Contracts}/
├── n8n/workflows/                 # JSON versioned; no credentials
├── deploy/ + compose.prod.reference.yaml, .env.production.example, .dockerignore  # infra reference در ریشه
├── docs/
├── AGENTS.md
└── .cursor/rules/                # اختیاری؛ فقط برای IDEهای پشتیبان
```

**توضیح:** مسیرهای `Controllers/Webhooks` و `Jobs`, `Policies`, `Events`, `Data` مکمل ساختار الزامی هستند، نه جایگزین آن. در Laravel 13، Middleware و routing در `bootstrap/app.php` نیز تنظیم می‌شود. ماژول‌های frontend مانند `cart` در پروژه‌ای که هنوز فروشگاه برای خریدار نهایی ندارد به‌صورت مصنوعی ساخته نشوند.

## ۳. نقش لایه‌ها و قاعده عبور داده

```text
HTTP/Telegram Webhook
  -> Middleware (authentication, webhook signing, workspace context)
  -> FormRequest (validation / authorization) یا Verified Webhook DTO
  -> Thin Controller
  -> Action (single use case)
  -> Repository Contract -> Eloquent Repository -> Model -> PostgreSQL
  -> Resource / normalized error response

Multi-step orchestration:
  Action -> Service (coordinator / external integration) -> contract/API
  Async tasks: Action -> durable state + after-commit Job -> n8n -> validated callback -> Action
```

- API Controller نباید `Model::where()`، `DB::table()`، `Http::post()` یا محاسبه مبلغ تراکنش داشته باشد.
- **Actions** قواعد دامنه را اجرا می‌کنند؛ queries سنگین و storage در Repository؛ `DB::transaction()` در Action/Service owner of use case (هرگز در چند لایه تو در تو بدون علت).
- **Services** منطق مشترک چند use case، adapterها و n8n/Telegram/Storage integration را مدیریت می‌کنند؛ فقط به interface یا typed clients وابسته باشند.
- **Repository** صرفاً create/read/update/query و persistence atomic primitives دارد؛ تصمیم اینکه «کاربر حق انتشار دارد» در Policy/Action است.
- **Polymorphism و abstraction** فقط برای چند implementation واقعی یا مرز خارجی که تغییرش محتمل است؛ برای هر متد ساده interface خیالی اضافه نشود، مگر استاندارد Repository که در این پروژه الزام دارد.
- **Cross-workspace security:** دریافت `workspace_id` از Body برای انتخاب مالکیت ممنوع؛ Workspace از authenticated principal/verified context استخراج و در Query محدود شود؛ `Policy` مجوز را مستقل بررسی کند؛ global scope صرفاً دفاع اضافی است.
- عملیات خارجی یا صف بعد از commit (`afterCommit`) و با idempotency انجام شود؛ جلوگیری از side effect پنهان، double charge و race condition ضروری است.

## ۴. نگاشت قابلیت‌های واقعی MVP به فایل‌ها

| Use case | Action(s) | Request / Resource | Repository / Service |
|---|---|---|---|
| آغاز ثبت‌نام تلگرام | `Identity/RegisterTelegramUserAction` | `Identity/RegisterTelegramUserRequest` یا verified webhook DTO / `UserResource` | `UserRepositoryInterface`, `TelegramIdentityService` |
| شناخت و اصلاح برند | `Brand/CreateBrandAction`, `Brand/UpdateBrandProfileAction` | `Brand/UpdateBrandProfileRequest`, `BrandResource` | `BrandRepositoryInterface`, `BrandOnboardingService` |
| ثبت حافظه تأییدشده | `Memory/ApproveMemoryCandidateAction`, `Memory/RevokeMemoryItemAction` | `Memory/ApproveMemoryCandidateRequest`, `MemoryItemResource` | `MemoryItemRepositoryInterface`, `MemoryService` |
| ایجاد پروژه و دریافت داده خام | `Project/CreateProjectAction`, `Project/AttachProjectAssetAction`, `Project/FinishProjectInputAction` | `Project/CreateProjectRequest`, `Project/AttachProjectAssetRequest`, `ProjectResource` | `ProjectRepositoryInterface`, `AssetStorageService` |
| استخراج محصول | `Product/RecordProductCandidatesAction`, `Product/ConfirmProductAttributesAction` | callback DTO + `Product/ConfirmProductAttributesRequest`, `ProductResource` | `ProductRepositoryInterface`, `ProductIntakeService`, `AIWorkflowService` |
| تأیید خروجی | `Approval/ApproveProductBatchAction` | `Project/ApproveProductBatchRequest`, `ProjectResource` | repositories مربوط؛ Policy |
| صدور خروجی و کاتالوگ | `Catalog/RequestCatalogExportAction`, `Catalog/FinalizeCatalogExportAction` | `Catalog/CreateCatalogExportRequest`, `CatalogResource` | `ArtifactRepositoryInterface`, `CatalogExportService` |
| کیف پول و اعتبار | `Wallet/ReserveCreditsAction`, `Wallet/CaptureCreditsAction`, `Wallet/ReleaseCreditsAction` | `WalletResource`, `WalletLedgerResource` | `WalletLedgerRepositoryInterface`, `BillingService` |
| دریافت callback n8n | `Project/CompleteAIWorkflowRunAction` | internal verified DTO؛ endpoint غیرعمومی | `AIWorkflowService`, `ProjectRepositoryInterface` |

**نمونه API mapping:** `POST /api/v1/projects` → `CreateProjectRequest` → `ProjectController::store` → `CreateProjectAction` → `ProjectRepositoryInterface` → `ProjectResource`. نمونه endpoint async: `POST /api/v1/projects/{project}/finish-input` → Action ثبت وضعیت و dispatch job after commit → پاسخ Resource با وضعیت `queued` و `run_id`، نه نگه‌داشتن درخواست HTTP تا پایان مدل AI.

## ۵. قرارداد پاسخ API

**پاسخ موفقِ single resource:**

```json
{
  "data": { "id": "uuid", "status": "awaiting_review" },
  "meta": { "request_id": "uuid", "api_version": "v1" }
}
```

**پاسخ خطا:**

```json
{
  "error": {
    "code": "PRODUCT_ATTRIBUTE_REQUIRES_CONFIRMATION",
    "message": "برخی مشخصات نیازمند تأیید هستند.",
    "details": { "fields": ["material"] }
  },
  "meta": { "request_id": "uuid", "api_version": "v1" }
}
```

- `JsonResource`/`ResourceCollection` + response envelope مشترک؛ `meta` ثابت؛ Pagination با `links/meta` سازگار. خطا از exception handler مرکزی و HTTP status صحیح برگردد؛ خروجی مستقیم Model/Exception stack trace ممنوع.
- `422` برای validation؛ `401` بدون هویت؛ `403` بدون مجوز؛ `404` برای resource خارج workspace در صورت نیاز به جلوگیری از enumeration؛ `409` optimistic concurrency؛ `429` rate-limit؛ `202` برای accepted async.
- هیچ فیلد API شامل رمز، access token، callback signature، payload حساس n8n یا internal storage path نباشد. `id`های عمومی opaque و versioned هستند.
- URLهای `/api/v1` در `routes/api.php` با یک strategy واحد prefix و version تنظیم شوند؛ از prefix دوتایی تصادفی جلوگیری شود. routes webhook مستقل از کاربر و با signature/secret middleware پیاده شوند.

## ۶. Laravel code conventions

- PHP 8.4، Laravel 13، PSR-12 / Laravel Pint؛ `declare(strict_types=1)` در کلاس‌های جدید مگر تصمیم ثبت‌شده ناسازگاری؛ typed ctor parameters, typed returns و DTO به جای arrayهای بدون قرارداد در لبه‌های حساس.
- کلاس‌ها `final` اگر inheritance لازم نیست؛ Actionها با `__invoke()` یا متد `execute()` طبق یک انتخاب پروژه **یکنواخت**. تصمیم این پروژه: **`execute()`** برای Actions، با return type مشخص.
- نام‌های دقیق فعلی کارمند من: `CreateProjectAction`, `ConfirmProductAttributesAction`, `ReserveCreditsAction`؛ اسم‌های عمومی `ProcessAction`, `CommonService`, `ManagerService` ممنوع.
- Eloquent Models: `protected $fillable` صریح؛ `casts()` برای money/currency/enums/dates؛ قیمت در integer minor units همراه currency (`IRT`/`IRR`)، نه float؛ casts مقادیر پول باید تست شوند.
- Repository: قرارداد `ProductRepositoryInterface`، اجرا `Eloquents/Product/EloquentProductRepository`. Provider binding در `RepositoryServiceProvider` و ثبت Provider در `bootstrap/providers.php`.
- Query filtering/pagination در Repositories با constraints whitelist شده؛ global scope در `app/Scopes/` فقط اگر واقعاً reuse و security analysis داشته باشد؛ قیود repository و Policy همچنان الزامی‌اند.
- `DB::transaction()` برای wallet، transitions و persist چند جدول؛ `lockForUpdate` و unique business key برای atomic ledger؛ ارسال ایمیل/تلگرام/Job پس از commit؛ money transfer یا credit charge بدون idempotency key ممنوع.
- Jobs با timeout، retries/backoff و permanent failure handling؛ Logs structured و بدون PII/raw image/voice/credentials؛ correlation_id و workspace ID با redaction.
- n8n input/output JSON Schema نسخه‌بندی‌شده؛ output همیشه untrusted، validation در Laravel، n8n هیچ دسترسی مستقیم به DB محصول/ledger نداشته باشد.
- `Helpers` فقط pure transformations مثل normalize public SKU/strings؛ محاسبه اعتبار، conversion مقررات‌دار قیمت یا status transition در Helpers ممنوع.

## ۷. Vue 3 + TypeScript rules

- **Vue 3 + TypeScript + Vite**، برای state مشترک **Pinia**، برای routing وب **Vue Router**؛ ساختار feature-first در `resources/js/src/modules/{feature}` با `pages/`, `components/`, `composables/`, `api/`, `types/` در صورت نیاز.
- Component تک‌مسئولیتی؛ presentation از API calls، domain computation و state mutation جدا؛ composable برای reusable UI behavior؛ Pinia store فقط state و orchestration سبک؛ business truth همچنان Backend.
- API client مشترک `shared/api`; DTOهای TS با response Resource و schema مستند یکسان؛ UI حق ندارد body آزاد مدل AI را مستقیماً به HTML ناامن تبدیل کند.
- Route guards و پنهان‌کردن دکمه جایگزین Backend Policy نیست؛ Workspace access server-side enforce شود.
- موبایل و RTL/Persian برای Mini Review/Web Workspace از ابتدا تست شود؛ accessibility keyboard/focus، وضعیت loading/error/empty و خطای شبکه الزامی است.
- ابزار quality: `vue-tsc --noEmit`، ESLint، Prettier، Vitest برای unit/component و Playwright برای E2E کلیدی؛ اعمال در CI پس از تثبیت lockfile.

## ۸. تست و Quality Gates اجباری

| لایه | حداقل تستِ قابل پذیرش |
|---|---|
| Action unit/feature | state transition، validation rules، negative paths، همکاری با repository mock فقط در unit لازم |
| Repository integration | PostgreSQL واقعی در CI/test container؛ `workspace_id` filtering، pagination و concurrency |
| API feature | Form Request/Policy، Resource response schema، tenant isolation، HTTP status و rate limit |
| Wallet | balance invariants، atomic reservation/capture/release، retry/idempotence و double callback |
| AI/n8n contract | valid/invalid JSON، unknown fields، schema evolution، timeout، callback signature، duplicate `run_id` |
| Export | WooCommerce CSV roundtrip fixture، تومان/ریال، variable products، PDF RTL و artifact expiry |
| Telegram | webhook secret، dedupe update_id، multi-message grouping، long-running job/retry |
| Vue | component behavior، Pinia state، route guards، RTL/mobile، error/loading و browser E2E |
| Infra | `docker compose config`، build از clean clone، backup/restore drill، no secret in image layers |

**CI merge blockers:** `composer validate`, `composer audit` (یا معادل بررسی وابستگی), `vendor/bin/pint --test`, PHPStan+Larastan سطح baseline مستند و برنامه افزایش، `php artisan test`، tests قرارداد، frontend `npm ci`/`vue-tsc`/ESLint/Vitest، build Docker، image + secret scan، dependency license/security review. به جای هدف‌گذاری صرفاً درصد coverage کلی، پوشش تمام **financial/security invariants** شرط قطعی Merge باشد.

**PR checklist:** فایل‌ها در لایه درست، Request/Resource، Action تک‌مسئولیتی، Repository contract/binding، Query scoping، test positive/negative، اجرای بعد از commit، idempotency، بدون secrets، migration back-compat، اثر بر n8n/Frontend و مستندات API بررسی شود. هر تغییر بیرون قرارداد این سند ADR کوتاه با علت و پیامد داشته باشد.

## ۹. نظم تغییر و خروجی ابزارهای AI برای کدنویسی

قبل از کدنویسی: (۱) Feature مشخص، (۲) لایه صحیح، (۳) اجتناب از تداخل مسئولیت‌ها، (۴) استفاده از abstraction موجود؛ از «کد موقتِ رهاشده» پرهیز شود.

هنگام تولید یا اصلاح کد، ابتدا فایل‌های افزوده/تغییریافته و نقش هرکدام مختصر اعلام شود؛ سپس implementation و tests ارائه گردد؛ ریسک معماری یا تعارض با SOLID قبل از اعمال تغییر مطرح شود. هنگام ویرایش کد موجود، conventions اطراف تا جای سازگار با این سند حفظ شوند. dead code، unused imports، commented-out implementation و side effect مخفی ممنوع.

## ۱۰. دامنه اجرایی و مواردی که این سند جایگزین آن‌ها نیست

- این سند جایگزین Design/PRD، threat model، قرارداد Legal نهاد پرداخت یا آزمون کاربر نیست.
- **پایداری SOLID/DRY با رفتار واقعی سنجیده شود**: واحدهای کوچک و مرزهای مشخص بله؛ ده‌ها interface/Service پیش از کاربرد واقعی خیر.
- این فایل تنها قرارداد ساختار و قواعد است؛ **کلاس‌ها و تست‌ها هنوز باید در مخزن واقعی برنامه ساخته و اجرا شوند**.
