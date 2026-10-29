# ADR-019 — بازکردن پروژه تأییدشده، لغو پروژه و امضای تأیید محصول

تاریخ: ۲۸ اکتبر ۲۰۲۶. وضعیت: تصمیم فنی برش چهارم گام ۳، ادامه [ADR-018](ADR-018-batch-finish-input-and-approved-snapshot.md) (PRD §۶، FR-041، FR-043، FR-063). **مجوز intake واقعی، انتشار یا پایان گام ۲ نیست.**

## زمینه

ADR-018 سه کار باز بدون وابستگی به intake یا AI گذاشت:

- بازگشت از `product_approved` برای اصلاح پس از تأیید پروژه.
- لغو پروژه (PRD §۶: «From eligible states -> CANCELLED»، FR-063).
- `/products/{id}/approved` امضای محتوا را بررسی نمی‌کرد.

مشکل سوم عمیق‌تر است. `payload_mac` فقط محتوا را امضا می‌کند و به ردیف بسته نیست. پس جابه‌جایی جفت `(payload, payload_mac)` بین دو نسخه یک Workspace، یا تنظیم دستی `approved_at`، در خواندن تک‌محصول کشف نمی‌شود. snapshot همین نقص را هنگام ساخت به ارث می‌برد: نسخه‌ای که `approved_at` آن در DB جعل شده، داخل snapshot امضاشده منجمد می‌شود.

## تصمیم

۱. **امضای تأیید (`approval_mac`):**
   - Gate A یک HMAC با کلید Workspace روی `(product_id, شناسه عمومی محصول, project_id, revision, payload_mac, ثانیه approved_at)` در ستون تازه `product_revisions.approval_mac` می‌نویسد (context `product_revisions.approval`). پس جابه‌جایی محصول تأییدشده به Batch دیگر در DB و تغییر زمان تأیید نمایش‌داده‌شده هم کشف می‌شوند. زمان تأیید به ثانیه کامل گرد می‌شود، چون ستون کسر ثانیه نگه نمی‌دارد.
   - `ApprovedRevisionVerifier` نسخه تأییدشده را بررسی می‌کند: تعلق نسخه به محصول، وجود `approved_at` و `approval_mac`، برابری `approval_mac`، و برابری MAC محتوای رمزگشایی‌شده با `payload_mac`. ciphertextی که رمزگشایی نشود شکست یکپارچگی است.
   - این بررسی در سه جا اجرا می‌شود:
     - `GET /products/{id}/approved`: شکست = `500 PRODUCT_INTEGRITY_FAILED` و لاگ critical `product.integrity_failed` فقط با شناسه‌ها.
     - `approve-products` پیش از ساخت snapshot: نسخه جعلی منجمد نمی‌شود. شکست = همان `500 PRODUCT_INTEGRITY_FAILED` و هیچ snapshotی ساخته نمی‌شود. محصول `approved` که `approved_at` نسخه آن پاک شده هم شکست یکپارچگی است، نه «تأییدنشده».
     - `GET /projects/{id}/snapshot`: شکست = `500 SNAPSHOT_INTEGRITY_FAILED` مانند ADR-018.
   - **بدون backfill:** نسخه‌هایی که پیش از این migration تأیید شده‌اند `approval_mac` ندارند و شکست یکپارچگی می‌گیرند. جدول‌های ADR-016 در production اجرا نشده‌اند. اگر داده واقعی پیش از این migration ساخته شود، تأیید دوباره یا backfill PHP لازم است.

۲. **`POST /api/v1/projects/{project}/reopen`** با `version` و `Idempotency-Key`:
   - فقط از `product_approved` به `review_required` و `version` یکی زیاد می‌شود. از وضعیت‌های بعدی (Quote و اعتبار) تا تعریف ابطال Quote در گام ۵ مجاز نیست: `409 PROJECT_STATE_CONFLICT`.
   - snapshotها حذف یا تغییر نمی‌شوند و سابقه می‌مانند. تأیید دوباره snapshot با شماره بعدی می‌سازد.
   - پس از reopen، `GET /snapshot` پاسخ `409 PROJECT_NOT_APPROVED` می‌دهد. snapshot فقط وقتی معتبر است که پروژه در `product_approved` یا وضعیت‌های پس از آن باشد. پس خروجی از Batchی که کاربر در حال اصلاح آن است ساخته نمی‌شود (PRD §۶: «Approval کاربر نسبت به version قبلی پس از تغییر رکورد معتبر نیست»).
   - افزودن محصول در `review_required` مانند قبل مجاز است.

۳. **`POST /api/v1/projects/{project}/cancel`** با `version` و `Idempotency-Key`:
   - وضعیت‌های مجاز: `new`، `collecting_input`، `needs_clarification`، `review_required` و `product_approved`. هیچ رزرو اعتباری در این وضعیت‌ها وجود ندارد، پس FR-063 چیزی برای آزادسازی ندارد.
   - `extracting` و `generating` مجاز نیستند تا گام ۴ مهار callback دیررس را تعریف کند. `quoted`، `expired` و وضعیت‌های اعتبار تا گام ۵ مجاز نیستند. وضعیت غیرمجاز یا پروژه لغوشده: `409 PROJECT_STATE_CONFLICT`.
   - `cancelled` پایانی است. snapshot پروژه لغوشده خروجی نمی‌دهد (`409 PROJECT_NOT_APPROVED`).
   - **محصولات تغییر نمی‌کنند.** PRD E06-الف می‌گوید هر کالای تأییدشده رکوردی مستقل از Batch دارد. پس `/products/{id}/approved` همچنان کار می‌کند.
   - در پروژه لغوشده، افزودن محصول `409 PROJECT_NOT_ACCEPTING_PRODUCTS` و اصلاح یا Gate A `409 PROJECT_CANCELLED` می‌گیرد. رد (archive) مجاز می‌ماند تا فروشنده SKU را آزاد کند. SKU محصولات پروژه لغوشده تا رد آن‌ها رزرو می‌ماند.

۴. **هم‌زمانی:** هر دو mutation زیر قفل ردیف Workspace و با بررسی `version` اجرا می‌شوند. cancel هم‌زمان با approve-products یکی را با `409` رد می‌کند.

## محدودیت‌ها و کار باز

- **بازگشت به نسخه قدیمی کشف نمی‌شود.** کسی که به DB دسترسی نوشتن دارد می‌تواند `approved_revision`/`current_revision` را به نسخه تأییدشده قبلی برگرداند یا ردیف snapshot جدیدتر را حذف کند. نسخه قدیمی MAC معتبر دارد. جلوگیری از آن اشاره‌گر امضاشده بیرون از DB می‌خواهد و در این برش ساخته نمی‌شود. MACها جعل، جابه‌جایی و ویرایش را کشف می‌کنند، نه rollback.
- **تعارض ثبت‌شده با PRD §۶:** طبق ADR-018 (تصمیم ۳)، اصلاح و Gate A محصول در پروژه `product_approved` بدون reopen مجاز است. snapshot قبلی همان خروجی FR-043 می‌ماند، ولی `/products/{id}/approved` نسخه تازه را نشان می‌دهد. PRD می‌گوید «Approval کاربر نسبت به version قبلی پس از تغییر رکورد معتبر نیست». این برش آن رفتار را تغییر نمی‌دهد. پیش از Quote در گام ۵، مالک محصول باید تصمیم بگیرد که اصلاح محصول در پروژه تأییدشده reopen را اجباری کند یا نه. **به‌روزرسانی ۲۹ اکتبر ۲۰۲۶:** مالک reopen اجباری را انتخاب کرد ([ADR-029](ADR-029-bot-product-edit-and-reopen-required.md)).

- ویرایش رکورد مستقل محصول بیرون از Batch (پس از لغو پروژه) ساخته نشده است.
- لغو از `quoted`/`credit_reserved` با آزادسازی رزرو به گام ۵، و لغو `extracting`/`generating` به گام ۴ وابسته است.
- intake متن/عکس تلگرام، storage خصوصی و AI ساخته نشده‌اند.
- endpointهای mutation تا تصویب سیاست در production با `403 POLICY_CONSENT_REQUIRED` بسته می‌مانند.
