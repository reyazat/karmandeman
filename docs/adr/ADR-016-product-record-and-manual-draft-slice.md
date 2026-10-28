# ADR-016 — قرارداد رکورد محصول و برش دستی گام ۳

تاریخ: ۲۸ اکتبر ۲۰۲۶. وضعیت: تصمیم فنی برای شروع گام ۳ [DEVELOPMENT_PLAN](../DEVELOPMENT_PLAN.md) (FR-030، FR-033، FR-035، FR-041، FR-043 و PRD §۶–۷). **مجوز intake واقعی، انتشار یا پایان گام ۲ نیست.**

## زمینه

گام ۳ یک برش عمودی کوچک می‌خواهد: ورودی → پیش‌نویس محصول → تأیید انسانی → JSON. شرط شروع آن پایان گام ۲ است. گام ۲ هنوز باز است: بسته سیاست تصویب نشده و شواهد staging و QA-19…21 وجود ندارند. طبق ADR-009، تا تصویب سیاست هیچ متن یا فایل محصول پذیرفته نمی‌شود.

DEVELOPMENT_PLAN اجازه می‌دهد گام ۳ «بدون AI یا با fixture» شروع شود. پس این برش فقط قرارداد داده و Gate A را می‌سازد. ورودی آن فرم دستی فروشنده است، نه فایل یا پیام تلگرام.

## تصمیم

۱. **دو جدول:**
   - `products`: رکورد Product Repository، مستقل از Batch (PRD E06-الف). وضعیت‌ها همان PRD §۶ هستند: `draft_extracted`، `needs_review`، `approved`، `archived`. `current_revision` و `approved_revision` جدا نگه داشته می‌شوند.
   - `product_revisions`: هر اصلاح یک نسخه تازه و immutable است. فقط مهر تأیید (`approved_at`، `approved_by`) یک بار روی همان نسخه نوشته می‌شود.

۲. **رمزنگاری:** `payload` کامل نسخه با کلید Workspace رمز می‌شود (NFR-11، ADR-013). `payload_mac` یک HMAC با همان کلید است، نه SHA-256 ساده. پس با نابودی کلید، digest قابل حدس باقی نمی‌ماند. MAC در API نمایش داده نمی‌شود. گام Quote بعدی تأیید را به همین MAC می‌بندد (FR-043).

۳. **رکورد schema 1.0** (PRD §7.2) از `ProductRecordResource` برمی‌گردد:
   - هر فیلد `value` و `status` دارد: `confirmed`، `suggested` یا `unknown`. مقدار null یعنی `unknown` و برعکس. ورودی ناسازگار 422 می‌گیرد.
   - قیمت عدد صحیح ریال است (`regular_price_irr`). ورودی تومان دقیقاً ×۱۰ می‌شود. قیمت بدون واحد، منفی یا صفر رد می‌شود. قیمت نامعلوم null است، نه ۰.
   - منشأ (`evidence`، `pricing.source`) در سرور تعیین می‌شود. فرم دستی فقط `manual_override` می‌سازد. کلید `source` از کلاینت با 422 رد می‌شود و `evidence` کلاینت نادیده گرفته می‌شود (FR-030، FR-033).
   - `workspace_id` و شناسه‌های داخلی در رکورد نیستند. فقط UUID عمومی محصول می‌آید.

۴. **Gate A برای هر محصول** (FR-041): کاربر شماره نسخه‌ای را که دیده است می‌فرستد.
   - نسخه قدیمی‌تر از نسخه جاری: `409 PRODUCT_REVISION_CONFLICT`.
   - فیلد `suggested` یا نام خالی: `422 PRODUCT_ATTRIBUTE_REQUIRES_CONFIRMATION` با فهرست مسیر فیلدها.
   - کلیک دوباره با کلید تازه، مهر تأیید تازه نمی‌سازد.
   - اصلاح پس از تأیید، وضعیت را به `needs_review` برمی‌گرداند. نسخه تأییدشده قبلی بدون تغییر از `/approved` خوانده می‌شود.

۵. **API** (همه mutationها با `Idempotency-Key`، رضایت نسخه مصوب و قفل Workspace برای مهار T0):

| مسیر | کار |
|---|---|
| `GET /api/v1/projects/{project}/products` | فهرست محصول‌های یک پروژه |
| `POST /api/v1/projects/{project}/products` | پیش‌نویس دستی، نسخه ۱ |
| `GET /api/v1/products/{product}` | وضعیت و رکورد نسخه جاری |
| `PATCH /api/v1/products/{product}/draft` | نسخه تازه با `expected_revision` |
| `POST /api/v1/products/{product}/approve` | Gate A با `revision` |
| `GET /api/v1/products/{product}/approved` | رکورد JSON نسخه تأییدشده (اولین خروجی گام ۳) |

۶. **حذف:** `products` و `product_revisions` در فهرست صریح purge (ADR-014) هستند. آزمون schema-wide حذف را بررسی می‌کند.

## انحراف ثبت‌شده از PRD §8.2

- PRD تأیید را در سطح پروژه (`POST /projects/{id}/approve-products`) پیشنهاد می‌کند. این برش تأیید هر محصول را می‌سازد، چون FR-041 تأیید تک‌قلم را می‌خواهد و وضعیت محصول مستقل از پروژه است. endpoint پروژه‌ای می‌تواند بعداً روی همین Action ساخته شود.
- ایجاد دستی (`POST /projects/{id}/products`) و `GET /products/{id}/approved` در فهرست PRD نیستند. این دو جای مسیر AI و artifact را در برش fixture می‌گیرند.
- وضعیت پروژه در این برش تغییر نمی‌کند. انتقال `COLLECTING_INPUT → … → PRODUCT_APPROVED` به `finish-input` و استخراج وابسته است.

## محدودیت‌ها و کار باز

- intake تلگرام، Asset، storage خصوصی، `finish-input` و AI ساخته نشده‌اند. `image_asset_ids` همیشه خالی است.
- یکتایی SKU در Workspace هنوز اعمال نمی‌شود. SKU رمزشده است و یکتایی به ستون MAC جدا نیاز دارد.
- نرمال‌سازی فارسی/عربی، اعداد و نیم‌فاصله (PRD E04) انجام نمی‌شود. متن همان‌طور که وارد شده ذخیره می‌شود.
- صف بررسی (FR-031)، خلاصه Batch (FR-040)، بایگانی محصول و هشدار شبه‌تکراری (FR-034) ساخته نشده‌اند.
- بسته سیاست v3 دسته نگهداری جدا برای Product Repository ندارد. نزدیک‌ترین دسته `account_brand_memory` است. تعیین آن تصمیم مالک محصول است.
- AAD رمزنگاری ردیف را bind نمی‌کند (محدودیت ADR-014). جابه‌جایی payload دو نسخه از یک Workspace با دسترسی نوشتن DB رمزگشایی می‌شود. `payload_mac` آن ناسازگار می‌شود، ولی خواندن هنوز MAC را بررسی نمی‌کند.
- این endpointها تا تصویب سیاست در production با `403 POLICY_CONSENT_REQUIRED` بسته می‌مانند. آزمون‌ها با بسته سیاست مصنوعی اجرا می‌شوند.
