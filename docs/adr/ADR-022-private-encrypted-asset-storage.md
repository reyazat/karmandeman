# ADR-022 — storage خصوصی و رمزشده عکس‌های ورودی Batch

تاریخ: ۲۹ اکتبر ۲۰۲۶. وضعیت: تصمیم فنی برش هفتم گام ۳ (PRD FR-025 و جدول `assets`، [DEVELOPMENT_PLAN](../DEVELOPMENT_PLAN.md) گام ۳: «storage خصوصی» و «دانلود tenant-scoped»). ادامه [ADR-013](ADR-013-openbao-transit-key-service.md) و [ADR-021](ADR-021-record-policies-and-structural-fixes.md). **مجوز intake واقعی، انتخاب provider object storage، انتشار یا پایان گام ۲ نیست.**

## زمینه

گام ۳ «یک محصول ساده از متن/عکس» و «دانلود tenant-scoped» می‌خواهد و storage خصوصی را پیش‌شرط می‌داند. تا این برش هیچ فایلی ذخیره نمی‌شد. ADR-013 می‌گوید Object Storage فقط ciphertext نگه دارد، کلید داده با tenant و رکورد bind شود، و حذف کلید Workspace همه داده آن را غیرقابل خواندن کند.

## تصمیم

۱. **دریافت:** `POST /api/v1/projects/{project}/assets` با فیلد `file` (multipart) و `Idempotency-Key`.
   - فقط پروژه در `collecting_input`، `needs_clarification` یا `review_required`. وضعیت دیگر: `409 PROJECT_NOT_ACCEPTING_INPUT`. رضایت سیاست لازم است (`403 POLICY_CONSENT_REQUIRED`).
   - Policy رکورد (ADR-021): `update` روی پروژه، پس از یافتن آن زیر قفل Workspace.
   - نوع و ابعاد از خود بایت‌ها با `getimagesizefromstring` خوانده می‌شود، نه از نام یا MIME کلاینت. فقط JPEG، PNG و WebP. بقیه `422 ASSET_UNSUPPORTED`. FormRequest هم MIME را محدود می‌کند، ولی تصمیم نهایی با Action است.
   - سقف‌ها: `KM_ASSET_MAX_KILOBYTES` پیش‌فرض ۱۰۲۴۰ (۱۰ مگابایت) و `KM_ASSET_MAX_PIXELS` پیش‌فرض ۴۰ میلیون پیکسل (`422 ASSET_TOO_LARGE`)، و throttle `asset-upload` بیست درخواست در دقیقه برای هر کاربر. فایل در حافظه رمز می‌شود، پس سقف حجم سقف حافظه هر درخواست هم هست.
   - همان بایت‌ها در همان پروژه asset اول را برمی‌گردانند (`200`). کلید تکراری: HMAC کلیددار Workspace روی sha256 بایت‌ها (`content_mac`). hash ساده ذخیره نمی‌شود، چون پس از حذف کلید اثرانگشت عکس می‌ماند. **انحراف ثبت‌شده از PRD** (دریافت عکس و جدول `assets`) که `sha256` می‌خواهد.
   - هر asset تازه `version` پروژه را یکی زیاد می‌کند، چون ورودی Batch تغییر کرده است.

۲. **ذخیره:**
   - `WorkspaceFieldCipher::encryptBytes`: AES-256-GCM با کلید داده Workspace، به‌شکل باینری (`KMB1` + nonce + tag + ciphertext). AAD شامل `public_id` Workspace و `assets.original:{asset public_id}` است. پس جابه‌جایی object بین دو ردیف، حتی در یک Workspace، رمزگشایی نمی‌شود.
   - کلید object: `ws/{workspace public_id}/{uuid تصادفی}`. نام فایل، کاربر، شناسه asset و محتوا در کلید نیست.
   - disk با `KM_ASSET_DISK`. پیش‌فرض development disk محلی `assets` در `storage/app/assets` بدون `serve` است. در production هر disk غیر از driver `s3` رد می‌شود (fail closed): بارگذاری و دانلود خطا می‌دهند و چیزی روی دیسک کانتینر نوشته نمی‌شود. `compose.prod.reference.yaml` پیش‌فرض `KM_ASSET_DISK=s3` را می‌دهد و متغیرهای `AWS_*` از قبل در آن الزامی‌اند. disk `s3` حالا `throw` دارد.
   - PHP در هر دو image `upload_max_filesize=12M` و `post_max_size=13M` می‌گیرد. پیش‌فرض PHP (۲ مگابایت) عکس عادی موبایل را با 422 عمومی رد می‌کرد.
   - ردیف `assets`: شناسه‌ها، `storage_key`، `content_mac`، MIME، حجم، ابعاد، `source` (`api_upload`) و سازنده. `storage_key` و `content_mac` در API نیستند.
   - ترتیب نوشتن: object اول نوشته می‌شود و بعد ردیف. اگر ساخت ردیف خطا بدهد، object همان لحظه پاک می‌شود. خطای این پاک‌سازی گزارش می‌شود ولی خطای اصلی را پنهان نمی‌کند.

۳. **خواندن:**
   - `GET /projects/{project}/assets` فهرست. `GET /assets/{asset}/download` فایل اصلی رمزگشایی‌شده.
   - Policy `view` روی خود asset. Workspace دیگر `404 ASSET_NOT_FOUND` می‌گیرد.
   - پاسخ دانلود: `Content-Disposition: attachment` با نام `{public_id}.{jpg|png|webp}`، `Cache-Control: private, no-store` و `X-Content-Type-Options: nosniff`. هیچ URL مستقیم یا signed URL ساخته نمی‌شود.
   - object گم‌شده، بیرون از prefix Workspace، جابه‌جاشده یا دست‌کاری‌شده: `500 ASSET_INTEGRITY_FAILED` و لاگ critical `asset.integrity_failed` فقط با شناسه asset. قطع یا خطای خواندن storage خطای عمومی `500 REQUEST_FAILED` بدون هشدار یکپارچگی می‌دهد.

۴. **حذف حساب (FR-087):** `PurgeAccountDataAction` پس از نابودی کلید Transit و پیش از حذف ردیف‌ها، کل prefix `ws/{workspace}` را پاک می‌کند، از جمله objectهای یتیم. شکست حذف، تراکنش purge را برمی‌گرداند و pass بعدی تکرار می‌کند. `assets` به فهرست جدول‌های purge اضافه شد.

## محدودیت‌ها و کار باز

- **بررسی نوع فقط در سطح header است.** dev image افزونه GD ندارد و تصویر decode نمی‌شود. فایلی با header معتبر و بدنه خراب پذیرفته می‌شود. هر decode بعدی (thumbnail، AI در گام ۴) باید در worker ایزوله با سقف حافظه انجام شود. fixture آزمون JPEG و WebP فقط header معتبر دارند.
- **polyglot پذیرفته می‌شود.** PNG معتبری که پس از آن HTML آمده با 201 ذخیره می‌شود. دانلود امن است (`attachment`، نام از شناسه عمومی، `nosniff`، بدون SVG). هر مرحله بعدی که فایل را inline نمایش دهد یا decode کند، باید فایل را دوباره بسازد، نه همان بایت‌ها را بفرستد.
- **قفل Workspace در طول نوشتن object:** رمزنگاری تا ۱۰ مگابایت و PUT به S3 زیر قفل ردیف Workspace انجام می‌شود، پس mutationهای دیگر همان Workspace منتظر می‌مانند. purge هم حذف prefix را زیر قفل‌های حذف انجام می‌دهد. نسخه‌های قدیمی bucket با versioning فقط ciphertext زیر کلید نابودشده‌اند و پاک‌سازی آن‌ها gate عملیات است.
- **EXIF و GPS حذف نمی‌شوند.** فایل اصلی رمزشده با metadata کامل نگه داشته می‌شود. نسخه مشتق یا ارسال به n8n باید metadata را حذف کند.
- **object یتیم:** اگر تراکنش پس از نوشتن object و ساخت ردیف rollback شود (مثلاً شکست commit)، object زیر prefix Workspace می‌ماند تا حذف حساب. پاک‌سازی دوره‌ای یتیم‌ها ساخته نشده است. object یتیم بدون کلید Workspace خوانا نیست.
- **streaming ساخته نشده است.** رمزنگاری کامل در حافظه است و سقف ۱۰ مگابایت به همین دلیل است. فایل بزرگ‌تر طراحی streaming با همان مرز کلید می‌خواهد (ADR-013).
- **retention:** دسته نگهداری فایل‌های ورودی در بسته سیاست تعریف نشده است. فایل‌ها تا حذف حساب می‌مانند. versioning و lifecycle bucket و بکاپ آن (۱۴ روز، ADR-010) gate عملیات است و آزموده نشده است.
- **intake تلگرام ساخته نشده است.** دریافت عکس از ربات (`getFile` و دانلود سمت سرور) و اتصال asset به محصول (`image_asset_ids` و پرچم `image_missing`) برش‌های بعدی‌اند.
- provider واقعی S3 و دسترسی آن از میزبان آزموده نشده است. آزمون‌ها disk محلی جعلی دارند.
- endpointهای mutation تا تصویب سیاست در production با `403 POLICY_CONSENT_REQUIRED` بسته می‌مانند.
