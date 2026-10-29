# ADR-018 — بستن ورودی Batch، تأیید پروژه و snapshot تأییدشده

تاریخ: ۲۸ اکتبر ۲۰۲۶. وضعیت: تصمیم فنی برش سوم گام ۳، ادامه [ADR-016](ADR-016-product-record-and-manual-draft-slice.md) و [ADR-017](ADR-017-product-normalisation-sku-and-review-queue.md) (PRD §۶، §۸.۲، FR-043). **مجوز intake واقعی، انتشار یا پایان گام ۲ نیست.**

## زمینه

ADR-016 تأیید هر محصول را ساخت، ولی وضعیت پروژه را تغییر نداد. PRD §۶ می‌گوید `PRODUCT_APPROVED` باید snapshot/version مشخص داشته باشد. FR-043 می‌گوید خروجی از همان version hash ساخته شود، نه از تغییرات هم‌زمان کاربر. Quote و خروجی‌های گام ۵ و ۶ به این snapshot وابسته‌اند.

## تصمیم

۱. **`POST /api/v1/projects/{project}/finish-input`** با `version` و `Idempotency-Key`:
   - فقط از `collecting_input` به `review_required` می‌رود و `version` یکی زیاد می‌شود.
   - مسیر دستی مرحله `extracting` ندارد. گام ۴ استخراج AI را بین این دو وضعیت قرار می‌دهد.
   - پروژه بدون محصول بایگانی‌نشده: `409 PROJECT_HAS_NO_PRODUCTS`.
   - وضعیت دیگر: `409 PROJECT_STATE_CONFLICT`. `version` قدیمی: `409 PROJECT_VERSION_CONFLICT`.
   - **`version` مجموعه محصولات را هم پوشش می‌دهد:** ایجاد، اصلاح، Gate A و رد هر محصول `version` پروژه آن را یکی زیاد می‌کند. پس تأیید Batch با نمایی که پس از آن محصولی تغییر کرده، 409 می‌گیرد. کلاینت پس از هر تغییر محصول `version` تازه را از `GET /projects/{id}` می‌خواند.

۲. **`POST /api/v1/projects/{project}/approve-products`** با `version` و `Idempotency-Key`:
   - فقط از `review_required`. همه محصولات بایگانی‌نشده باید `approved` باشند، یعنی نسخه جاری هر کدام Gate A را گذرانده باشد. وگرنه `422 PROJECT_PRODUCTS_NOT_APPROVED` با شناسه عمومی محصولات باقی‌مانده.
   - این endpoint محصولی را خودکار تأیید نمی‌کند. تأیید هر قلم همان Gate A ADR-016 است (FR-041). این انحراف از PRD §۸.۲ آگاهانه است: تأیید جمعی بدون دیدن هر نسخه، Gate A را دور می‌زند.
   - یک ردیف immutable در `project_snapshots` ساخته می‌شود: شماره ترتیبی، فهرست `(product_id, شناسه عمومی محصول, revision, payload_mac)` و `snapshot_mac`. شناسه عمومی هم امضا می‌شود تا جابه‌جایی `public_id` در DB برچسب خروجی را عوض نکند. ستون `json` است، نه `jsonb`، چون MAC روی همین ترتیب کلیدهاست.
   - `snapshot_mac` یک HMAC با کلید Workspace روی پروژه، شماره و فهرست است. فهرست محتوای فروشنده ندارد. محتوا همان نسخه‌های رمزشده محصول است.
   - پروژه `product_approved` می‌شود و `version` یکی زیاد می‌شود. افزودن محصول تازه به پروژه تأییدشده `409 PROJECT_NOT_ACCEPTING_PRODUCTS` می‌گیرد.

۳. **`GET /api/v1/projects/{project}/snapshot`**: آخرین snapshot با رکورد schema 1.0 هر محصول (اولین خروجی JSON سطح Batch).
   - شناسه عمومی snapshot همان «version» در FR-043 است. MACها نمایش داده نمی‌شوند.
   - اصلاح یا رد محصول پس از تأیید پروژه snapshot را تغییر نمی‌دهد. خروجی همان نسخه‌های تأییدشده را برمی‌گرداند. **جایگزین‌شده:** از [ADR-029](ADR-029-bot-product-edit-and-reopen-required.md) اصلاح یا رد پس از تأیید پروژه فقط پس از reopen ممکن است.
   - **بررسی یکپارچگی هنگام خواندن:** `snapshot_mac` دوباره محاسبه می‌شود. برای هر قلم، نسخه باید وجود داشته باشد و تأییدشده باشد. `payload_mac` ذخیره‌شده باید با فهرست و با MAC payload رمزگشایی‌شده برابر باشد. شناسه عمومی محصول باید با فهرست برابر باشد. ciphertextی که رمزگشایی نشود (`UndecryptableValue`) هم شکست یکپارچگی است. هر عدم تطابق `500 SNAPSHOT_INTEGRITY_FAILED` و لاگ critical بدون محتوا می‌دهد. خطای key store همچنان خطای عمومی 500 است. پس جابه‌جایی payload دو نسخه از یک Workspace (محدودیت AAD در ADR-014 و ADR-016) در خروجی snapshot کشف می‌شود.
   - پروژه بدون snapshot: `409 PROJECT_NOT_APPROVED`.

۴. **هم‌زمانی:** همه این mutationها زیر قفل ردیف Workspace اجرا می‌شوند. ایندکس یکتای `(project_id, number)` حفاظ دوم است.

۵. **حذف:** `project_snapshots` در فهرست صریح purge (ADR-014) پیش از `projects` است.

## محدودیت‌ها و کار باز

- بازگشت از `product_approved` به `review_required` (اصلاح پس از تأیید پروژه) و لغو پروژه ساخته نشده‌اند.
- intake متن/عکس تلگرام، storage خصوصی، استخراج AI و وضعیت‌های `extracting` و `needs_clarification` ساخته نشده‌اند.
- خواندن مستقیم `/products/{id}/approved` هنوز MAC را بررسی نمی‌کند. خروجی‌های گام ۶ باید از snapshot بخوانند.
- endpointهای mutation تا تصویب سیاست در production با `403 POLICY_CONSENT_REQUIRED` بسته می‌مانند.
