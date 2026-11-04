# ADR-046 — export workflow n8n استخراج عکس با OpenAI

تاریخ: ۳ نوامبر ۲۰۲۶. وضعیت: تصمیم فنی برش بعدی گام ۴ [DEVELOPMENT_PLAN](../DEVELOPMENT_PLAN.md) و اجرای بند ۲ [ADR-045](ADR-045-openai-voice-and-text-processing.md). قرارداد Laravel همان [ADR-032](ADR-032-n8n-extraction-run-contract.md)، [ADR-033](ADR-033-n8n-run-photo-links.md) و [ADR-038](ADR-038-n8n-run-claim.md) است. **هیچ درخواست واقعی به OpenAI، import در n8n، انتخاب این نسخه در پیکربندی یا مجوز فرستادن عکس فروشنده با این ADR داده نمی‌شود.**

## زمینه

ADR-045 مالک را به OpenAI رساند و export Gemini را بازنشسته کرد. Laravel از همان زمان فقط برای `product-extraction.openai.v1` و webhook `/webhook/km-product-extraction-openai-v1` run می‌سازد و claim می‌کند، اما export متناظر وجود نداشت. پس مسیر استخراج حتی با بسته سیاست مصوب هم کاری نمی‌توانست بکند.

## تصمیم

۱. **فایل:** `n8n/workflows/product-extraction.openai.v1.json` با نام `km-product-extraction-openai-v1` و مسیر webhook `km-product-extraction-openai-v1`. همان ۲۰ گره و همان ترتیب export قدیمی را دارد: بررسی امضا روی بدنه خام، پاسخ ۲۰۲، claim انحصاری، دانلود عکس‌ها از لینک‌های run، یک درخواست مدل و callback امضاشده. شناسه گره‌ها و webhook جدا هستند، پس هر دو export در یک n8n جا می‌شوند. قراردادهای `input.v1`، `claim.v1` و `output.v1` تغییر نکرده‌اند.

۲. **جداسازی نسخه:** «Check envelope» فقط `workflow_version = product-extraction.openai.v1` را می‌پذیرد و export قدیمی فقط `product-extraction.v1` را. شروعی که برای workflow دیگر امضا شده است در هیچ‌کدام کار نمی‌کند.

۳. **درخواست مدل:** یک `POST https://api.openai.com/v1/responses` با همه عکس‌های run به ترتیب، هر کدام با شماره («عکس ۱:») و data URL. پاسخ با `text.format` از نوع `json_schema` و `strict: true` محدود است، `store: false` پاسخ را از responseهای ذخیره‌شده OpenAI بیرون نگه می‌دارد، و `max_output_tokens` از limit همان run می‌آید. شناسه عکس، run، پروژه و لینک امضاشده به مدل نمی‌رسد؛ عکس با شماره ارجاع می‌شود. نشانی OpenAI در گره ثابت است و کلید فقط در credential رمز‌شده `kmOpenAiApiKey` (Header Auth با `Authorization: Bearer ...`) است، نه در URL یا export.

۴. **schema سخت‌گیر:** هر object بسته است و همه propertyها required هستند. مقدار خالی با `anyOf` و `null` است. کلیدواژه‌های طول، تعداد و بازه (`maxLength`، `maxItems`، `minimum` و مانند آن) در schema نیستند تا حالت strict درخواست را رد نکند. این حدها در دستور مدل گفته می‌شوند، در «Read OpenAI answer» اعمال می‌شوند و Laravel دوباره همه را بررسی می‌کند. مقدار بیرون از حد به `null` یا کوتاه‌شدن فهرست تبدیل می‌شود، نه شکست کل run. رکوردی که به هیچ عکس همان run ارجاع ندهد حذف می‌شود (FR-030).

۵. **مصرف و شکست:** `usage.input_tokens` و `usage.output_tokens` پاسخ گزارش می‌شوند. token استدلال داخل `output_tokens` است و دوباره جمع زده نمی‌شود. provider همیشه `openai` است؛ Laravel callback با provider دیگر را بدون محصول و کسر رد می‌کند (ADR-045). خطای HTTP یا `status` غیر `completed` به `MODEL_REQUEST_FAILED`، `incomplete` با `max_output_tokens` به `MODEL_OUTPUT_TRUNCATED`، `content_filter` یا `refusal` به `MODEL_BLOCKED` و JSON نامعتبر به `MODEL_OUTPUT_INVALID` تبدیل می‌شوند. همه با usage خوانده‌شده گزارش می‌شوند تا هزینه ثبت شود (ADR-042).

۶. **مدل موقت:** `gpt-5-mini` با `reasoning.effort = low` و `detail = high` برای خواندن برچسب و قیمت. این انتخاب نهایی نیست. طبق ADR-045 §۴ مدل، effort، detail و نرخ پس از آزمون API واقعی روی نمونه‌های با رضایت و مقایسه کیفیت و هزینه ثابت می‌شوند.

## شواهد

- PHP: آزمون قرارداد export به `N8nExtractionWorkflowTestCase` مشترک منتقل شد و برای هر دو export اجرا می‌شود (مسیر webhook، ترتیب گره‌ها، credentialها، نبود `$env`، HTTP فقط به لینک‌های بررسی‌شده و نشانی ثابت مدل، retry، بدون تاریخچه اجرا). `N8nOpenAiExtractionWorkflowTest` نسخه، provider، نبود نام Gemini/Google و جدا بودن شناسه‌ها را نیز می‌سنجد.
- vitest: کد گره‌ها همان‌طور که در n8n اجرا می‌شود با `n8n/tests/harness.ts` مشترک. `product-extraction.openai.workflow.spec.ts` بدنه دقیق درخواست، strict بودن schema، `store: false`، حدها، شکست‌ها و usage را می‌سنجد. spec قدیمی Gemini بدون تغییر رفتار پاس است.
- هیچ درخواست شبکه به OpenAI در این آزمون‌ها نیست؛ پاسخ‌ها نمونه ساختگی با شکل مستند Responses API هستند.
- **اجرای سرتاسری (۳ نوامبر ۲۰۲۶)** روی n8n 2.44.6 موقت در شبکه Docker داخلی بدون اینترنت. export با `import:workflow` و سه credential با `import:credentials` وارد و منتشر شد. یک stub نقش Laravel را داشت و HMAC را بررسی می‌کرد. stub دیگری با گواهی TLS آزمایشی نقش `api.openai.com` را داشت (`NODE_EXTRA_CA_CERTS` فقط در همان کانتینر).
  - شروع امضاشده ۲۰۲ گرفت. اجرا claim کرد، دو عکس را از لینک run خواند و یک `POST /v1/responses` با `Authorization: Bearer` از credential فرستاد. بدنه همان کلیدهای مورد انتظار را داشت، با `store: false`، schema سخت‌گیر و بایت‌های دقیق عکس. هیچ شناسه run، پروژه، عکس یا لینک امضاشده در آن نبود.
  - callback امضاشده با امضای معتبر رسید. `ProductExtractionOutputValidator` خود Laravel آن را پذیرفت: یک کاندید «پیشنهادی»، ارجاع به هر دو عکس و usage با provider `openai`.
  - شروع دوباره همان run ۲۰۲ گرفت ولی claim آن ۴۰۹ شد: نه عکس خواند، نه مدل، نه callback. شروعی با `workflow_version` قدیمی ۴۰۱ گرفت.
  - پاسخ ۴۰۰ از OpenAI به callback `failed` با `MODEL_REQUEST_FAILED` و token صفر تبدیل شد.
  - کانتینرها، volume و شبکه پس از آزمون پاک شدند. این اجرا با API واقعی OpenAI یا Laravel واقعی نیست.

## گیت‌های باز

> **به‌روزرسانی ۴ نوامبر ۲۰۲۶:** export `openai.v1` با `openai.v2` و `input.v2` جایگزین و حذف شد ([ADR-050](ADR-050-seller-text-in-extraction-run.md)). قواعد این ADR برای v2 برقرارند.

- export روی `ai.smartystudio.co.uk` import نشده است. فقط روی n8n موقت محلی با stub اجرا شده است.
- `karmandeman.ai.workflow_version` هنوز `product-extraction.v1` است، پس `extractionEnabled()` false می‌ماند. تغییر آن فقط پس از آزمون API واقعی، credential در n8n، بسته سیاست مصوب نام‌برنده OpenAI و رضایت تازه فروشنده انجام می‌شود.
- متن بسته سیاست باید نگهداری داده نزد OpenAI را بگوید: `store: false` نگهداری برای پایش سوءاستفاده را حذف نمی‌کند، مگر توافق Zero Data Retention جدا. این باید با QA-19…21 سازگار شود.
- گره «Call OpenAI» در نسخه اول مثل export قدیمی دو بار تلاش می‌کرد، حتی برای خطای قطعی ۴۰۰ (در اجرای سرتاسری دیده شد). پس از بازبینی ۳ نوامبر retry آن برداشته شد: تلاش دوم ممکن بود درخواستی را که OpenAI یک بار حساب کرده دوباره بخرد. شکست اکنون run را می‌بندد، رزرو آزاد می‌شود و فروشنده `/done` را دوباره می‌فرستد. timeout پس از پردازش در OpenAI همچنان usage گزارش‌شده ندارد و باید در تطبیق با صورت‌حساب provider دیده شود (ADR-045 §۴). `Claim run` پاسخ ۴۰۹ را سه بار تلاش می‌کند؛ بی‌خطر است چون هر claim همه بررسی‌ها را دوباره اجرا می‌کند.
- Laravel پس از همان بازبینی usage خروجی ردشده و callback دیررس run بسته‌شده را هم ثبت می‌کند، بدون کسر. بازبینی دوم (۳ نوامبر) دو نقص آن را بست: callback دیررسی که خروجی‌اش رد می‌شد usage را از دست می‌داد، و usage دیررس پیش از بررسی provider ذخیره می‌شد. اکنون یک قاعده در `FailAIWorkflowRunAction::keepLateUsage` هر دو مسیر را می‌پوشاند: فقط نخستین usage با provider `openai` برای run بسته‌ای که موفق نبوده ثبت می‌شود. وضعیت و کد خطای run تغییر نمی‌کند.
- ESLint، Prettier و `vue-tsc` پروژه فقط `resources/js` را می‌پوشانند؛ `n8n/tests` از پیش بیرون از این دامنه است.
- ویس و متن آزاد همچنان workflow، quote و نرخ ندارند. طبق [ADR-047](ADR-047-voice-in-extraction-run.md) export بعدی `openai.v2` ویس را به همین run اضافه می‌کند و جای این نسخه را می‌گیرد.
