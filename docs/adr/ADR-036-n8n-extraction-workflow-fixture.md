# ADR-036 — workflow n8n استخراج محصول با مرحله مدل fixture

تاریخ: ۳۱ اکتبر ۲۰۲۶. وضعیت: تصمیم فنی برش پنجم گام ۴ [DEVELOPMENT_PLAN](../DEVELOPMENT_PLAN.md)، سمت n8n قرارداد [ADR-032](ADR-032-n8n-extraction-run-contract.md) و [ADR-033](ADR-033-n8n-run-photo-links.md). Runbook §۷ (n8n فقط orchestration، بدون تاریخچه payload، credential حداقلی، کنترل SSRF و egress). **هیچ provider AI انتخاب نشده است. این ADR مجوز ارسال عکس به هیچ سرویس ثالثی یا روشن‌کردن استخراج در production نیست.**

## زمینه

ADR-032 و ADR-033 سمت Laravel قرارداد را ساختند: شروع امضاشده، لینک امضاشده هر عکس و callback امضاشده. workflow n8n وجود نداشت. انتخاب provider تصمیم مالک است و هنوز گرفته نشده است. مالک در ۳۱ اکتبر خواست workflow کامل با مرحله مدل خنثی (fixture) ساخته شود و غیرفعال روی n8n مستقل (`ai.smartystudio.co.uk`) import شود.

## تصمیم

۱. **فایل:** `n8n/workflows/product-extraction.v1.json` (نام `km-product-extraction-v1`، مسیر webhook `km-product-extraction-v1`، برابر `N8N_EXTRACTION_WEBHOOK_PATH`). فایل repo منبع است؛ تغییر در editor باید دوباره export و بازبینی شود.

۲. **جریان:**
   - `Start run`: webhook با `rawBody`، پاسخ فقط از گره پاسخ.
   - `Read request` و `Sign request`: HMAC-SHA256 روی `"{timestamp}.{بایت خام بدنه}"` با credential «KM extraction start token».
   - `Check envelope`:
     - پنجره ۳۰۰ ثانیه و مقایسه constant-time امضا.
     - مجموعه دقیق کلیدها و نوع هر مقدار در `product-extraction.input.v1`، `locale` برابر `fa-IR`.
     - دست‌کم دو دقیقه تا مهلت run.
     - `callback_url` باید دقیقاً `{BASE}/api/webhooks/internal/n8n/runs` باشد و هر `download_url` با `{BASE}/api/webhooks/internal/n8n/runs/{run}/assets/{asset}?` شروع شود. پس شروع امضاشده هم n8n را به نشانی دیگری نمی‌فرستد (SSRF).
     - `BASE` نشانی‌ای است که n8n با آن به Laravel می‌رسد (`N8N_APP_URL`). در زمان import جای `__KM_APP_BASE_URL__` نوشته می‌شود، چون secret نیست. فقط https، یا http به میزبان داخلی بی‌نقطه (نام سرویس Docker) پذیرفته می‌شود. تا تنظیم نشده، هر شروع رد می‌شود.
   - `Valid start?`: درخواست درست `202 {"accepted":true}`، هر شکست `401 {"accepted":false}` بدون دلیل. دلیل فقط در n8n می‌ماند.
   - `Download photo`: یک درخواست برای هر عکس، بدون redirect، timeout پانزده ثانیه و **بدون retry**. retry در n8n با «ادامه با خطا» همه عکس‌ها را دوباره می‌گیرد، پس شکست به Laravel گزارش می‌شود.
   - `Model step (fixture)`: **هیچ مدلی صدا زده نمی‌شود و عکس به جایی نمی‌رود.** پس از مهلت run `DEADLINE_PASSED` می‌دهد. اگر همه عکس‌ها با mime درست رسیدند، برای هر عکس یک کاندید با نام آشکارا آزمایشی «نمونه آزمایشی N»، بدون قیمت و با ارجاع به همان عکس می‌دهد. usage صفر است و provider `fixture`. وگرنه `failed` با `ASSET_FETCH_FAILED`. این تنها گره‌ای است که با تصویب provider عوض می‌شود و قرارداد ورودی و خروجی‌اش در خود گره نوشته شده است.
   - `Build callback`: بدنه `product-extraction.output.v1` را یک بار به رشته تبدیل می‌کند.
     - خروجی بدشکل مرحله مدل `failed` با `MODEL_OUTPUT_INVALID` می‌شود، ولی تعداد tokenها گرد شده می‌ماند تا هزینه ثبت شود.
     - provider و model با همان الگوی Laravel بررسی می‌شوند.
     - سقف‌ها را Laravel اعمال می‌کند.
   - `Sign callback` و `Send callback`: امضا با credential «KM extraction callback secret» روی همان رشته، ارسال همان رشته، سه تلاش.

۳. **secretها در credential store رمزشده n8n:** دو credential نوع `crypto` (فیلد `hmacSecret`) در Crypto node v2. مقدار اولی برابر `N8N_WORKFLOW_AUTH_TOKEN` و دومی برابر `N8N_CALLBACK_SECRET` در Laravel است.
   - فایل export فقط نام و شناسه آن‌ها را دارد و هیچ secretی ندارد.
   - **دسترسی env لازم نیست.** پیش‌نویس اول این ADR secretها را از `$env` می‌خواند. بازبین نشان داد `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` کل `process.env` را باز می‌کند، شامل `N8N_ENCRYPTION_KEY` و رمز DB، برای همه workflowهای آن instance. پس کنار گذاشته شد.
   - credential خالی یا ناموجود گره را پیش از پاسخ متوقف می‌کند. n8n پاسخ `500` می‌دهد، Laravel retry می‌کند و run با `DISPATCH_FAILED` بسته می‌شود.

۴. **تنظیمات workflow:**
   - `saveDataErrorExecution` و `saveDataSuccessExecution` برابر `none`، بدون ذخیره اجرای دستی و پیشرفت. پس payload، لینک عکس و بایت عکس پس از اجرا در تاریخچه n8n نمی‌ماند (Runbook §۷).
   - `executionTimeout` برابر ۸۴۰ ثانیه، کمتر از مهلت ۱۵ دقیقه‌ای run.

۵. **import:** با API n8n روی `ai.smartystudio.co.uk` به‌صورت **غیرفعال** ساخته و به‌روز شد (id `wORnzRbqwm2ITeVg`).
   - `BASE` همان placeholder ماند و دو credential ساخته نشدند، چون ساختن آن‌ها یعنی فرستادن secretهای Laravel به آن سرویس.
   - پس آن workflow تا تنظیم دستی هر شروع را رد می‌کند.

## شواهد

- **اجرای سرتاسری** روی n8n 2.44.6 موقت محلی در شبکه داخلی بدون اینترنت، با credential واقعی و یک stub به‌جای Laravel:
  - شروع درست: `202`، هر دو عکس از لینک‌ها گرفته شد و callback امضاشده با دو کاندید رسید.
  - شروع با timestamp کهنه، secret غلط و `callback_url` بیگانه: `401`، بدون هیچ درخواست بیرونی.
  - شکست دانلود یک عکس: `202` و callback امضاشده `failed` با `ASSET_FETCH_FAILED`.
  - هر دو callback با `WorkflowSignature::verify` و `ProductExtractionOutputValidator` خود Laravel پذیرفته شدند.
- **`n8n/tests/product-extraction.workflow.spec.ts`** (vitest، ۳۲ مورد): JavaScript همان گره‌ها را اجرا می‌کند. موارد: شروع درست؛ timestamp قدیمی و آینده؛ secret دیگر؛ بدون امضا؛ نشانی تنظیم‌نشده، http عمومی و نشانی دارای user؛ callback و لینک بیگانه؛ لینک run دیگر؛ عکس تکراری؛ شناسه آرایه‌ای؛ کلید ناشناخته؛ مهلت کم؛ بدنه غیرشیء و `__proto__`؛ fixture؛ دانلود ناموفق یا ناقص یا اضافه؛ گذشتن مهلت؛ خروجی بدشکل، provider نامعتبر و کاندید زیاد.
- **`N8nExtractionWorkflowTest`** (PHPUnit): مسیر webhook برابر config؛ rawBody؛ نقشه دقیق اتصال‌ها (هیچ مسیری بدون بررسی و 202 به عکس، مدل یا callback نمی‌رسد)؛ credential فقط روی دو گره امضا؛ مقدار امضاشده؛ بدنه ارسالی همان رشته امضاشده؛ Code nodeها بدون `helpers` (جز خواندن بدنه)، `fetch`، `require`، `process` و `$env`؛ placeholder نشانی؛ نبود کلید در export؛ تنظیمات بدون تاریخچه.

## بازبینی مستقل پیش از commit

بازبین راهی برای دور زدن امضا یا SSRF از `download_url` و `callback_url` نیافت. هر اجرا دقیقاً یک callback می‌فرستد. یافته‌ها:
- **(P1)** `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` کل محیط n8n را به هر workflow می‌دهد. اصلاح شد: credential رمزشده و نشانی ثابت در زمان import.
- **(P2)** تست ساختاری چهار جهش خطرناک را نمی‌گرفت. اصلاح شد: نقشه دقیق اتصال، پارامترهای امضا و ارسال، منع دسترسی Code nodeها و تست رفتاری vitest.
- **(P3)** retry دانلود کار نمی‌کرد و همه را دوباره می‌گرفت؛ usage بدشکل صفر می‌شد؛ provider با الگوی Laravel بررسی نمی‌شد؛ مهلت فقط در شروع بررسی می‌شد؛ مقایسه constant-time نبود؛ نوع شناسه‌ها بررسی نمی‌شد؛ mime با charset رد می‌شد؛ تعداد اضافه گره مدل را می‌شکست. همه اصلاح و آزموده شد.
- **(P3، ثبت‌شده)** شروع تکراری، هشدار، بار ناشناس و تاریخچه در crash: بخش بعد.

## پیامد و محدودیت

- **پیش از فعال‌کردن روی n8n مستقل:**
  - دو credential ساخته شوند.
  - `__KM_APP_BASE_URL__` با `N8N_APP_URL` واقعی (https) جایگزین شود.
  - `N8N_CALLBACK_URL` در Laravel خالی بماند یا دقیقاً برابر `{N8N_APP_URL}/api/webhooks/internal/n8n/runs` باشد.
  - secretها دست‌کم ۳۲ نویسه تصادفی باشند.
  - n8n باید به آن نشانی برسد و Laravel به webhook n8n (`N8N_INTERNAL_URL`).
  - استخراج در Laravel پیش‌فرض خاموش و در production همیشه خاموش است (ADR-032).
- **شروع تکراری دوباره کار می‌کند.** شروع امضاشده در ۳۰۰ ثانیه قابل تکرار است و retry Laravel پس از گم‌شدن ۲۰۲ هم اجرای دوم می‌سازد. هر اجرا عکس‌ها را دوباره می‌گیرد. با fixture بی‌هزینه است و Laravel callback دوم را `already_recorded` یا `409` می‌کند. پیش از provider، dedupe روی `run_id` لازم است.
- **هشدار و سقف بار ندارد.** پس از ۲۰۲، خطای هر گره یا سه شکست callback فقط با انقضای ۱۵ دقیقه‌ای Laravel (`ai:expire-runs`) بسته می‌شود. webhook روی instance عمومی برای هر POST، Code node اجرا می‌کند. rate limit در proxy و error workflow تصمیم عملیات است.
- **«بدون تاریخچه» بهترین تلاش است.** بدنه خام (با لینک‌های امضاشده) و بایت عکس‌ها در طول اجرا نگه داشته می‌شوند. در crash ممکن است تا prune ۱۶۸ ساعته بمانند. لینک‌ها با پایان run بی‌اثرند (ADR-033).
- **fixture محصول آزمایشی می‌سازد.** اجرای آن روی Batch واقعی کاندیدهای «نمونه آزمایشی» می‌سازد که فروشنده باید رد کند. فقط برای staging و آزمون سرتاسری است.
- **workflow روی instance واقعی اجرا نشده است.** فقط روی نسخه محلی همان image اجرا شد. ربات زنده، Laravel واقعی و provider هنوز آزموده نشده‌اند.
- آزمون vitest در CI با `npm run test:unit` اجرا می‌شود. روی این ماشین در کانتینر node:24 اجرا شد؛ CI میزبانی‌شده هنوز آن را اجرا نکرده است.
