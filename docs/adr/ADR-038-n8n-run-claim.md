# ADR-038 — claim هر run توسط فقط یک اجرای n8n

تاریخ: ۱ نوامبر ۲۰۲۶. وضعیت: تصمیم فنی برش هفتم گام ۴ [DEVELOPMENT_PLAN](../DEVELOPMENT_PLAN.md). مورد باز «dedupe شروع تکراری در n8n» از [ADR-036](ADR-036-n8n-extraction-workflow-fixture.md) و [ADR-037](ADR-037-gemini-extraction-provider.md) را می‌بندد. وابسته به [ADR-032](ADR-032-n8n-extraction-run-contract.md) (قرارداد run) و PRD §۱۱ (سقف هزینه هر run). Engineering Standards §۳ («Laravel مالک وضعیت است») و §۸ (آزمون `run_id` تکراری).

## مسئله

پس از ADR-037 هر اجرای workflow یک درخواست پولی Gemini می‌فرستد. شروع یک run ممکن است بیش از یک بار به n8n برسد:
- worker پس از پاسخ ۲۰۲ و پیش از ثبت `dispatched` بمیرد و job دوباره اجرا شود؛
- صف یک job را دو بار تحویل دهد؛
- پاسخ شروع گم شود به شکلی که `WorkflowStartUncertain` نشناسد (مثلاً proxy پس از پذیرش n8n خطای ۵۰۲ بدهد).

هر شروع تکراری یک بار دیگر مدل را صدا می‌زند و هزینه را دو برابر می‌کند. یک callback پذیرفته می‌شود، ولی هزینه هر دو پرداخت شده است.

## گزینه ردشده: گره Remove Duplicates خود n8n

گره `removeDuplicates` نسخه ۲ با «Remove Items Processed in Previous Executions» در n8n 2.44.6 بررسی شد:
- همه کلیدها را در یک ردیف JSON نگه می‌دارد و هر اجرا آن را می‌خواند و دوباره می‌نویسد. دو شروع هم‌زمان هر دو «تازه» دیده می‌شوند.
- helper تاریخچه را به `historySize` محدود می‌کند، ولی خود گره وقتی `تعداد ذخیره‌شده + تعداد ورودی > historySize` باشد خطا می‌دهد. پس پس از ۱۰٬۰۰۰ run (پیش‌فرض) **هر** شروع شکست می‌خورد.
- وضعیت run در n8n می‌ماند، نه در Laravel.

## تصمیم

۱. **Laravel run را به یک اجرا می‌دهد.** n8n پس از پاسخ ۲۰۲ و پیش از خواندن هر عکس یا صدا زدن مدل، `POST /api/webhooks/internal/n8n/runs/claim` می‌فرستد. بدنه دقیقاً `product-extraction.claim.v1` است (`n8n/schemas/product-extraction.claim.v1.json`): `schema_version`، `run_id` و `execution_id` (شناسه اجرای n8n). امضا مثل callback است: HMAC بدنه خام با callback secret و timestamp تازه (middleware `n8n.callback`). نشانی از `callback_url` بررسی‌شده در «Check envelope» ساخته می‌شود، پس قرارداد `input.v1` تغییر نمی‌کند.

۲. **`ClaimAIWorkflowRunAction`** با قفل Workspace و سپس قفل run (همان ترتیب purge):
   - هر claim، حتی تکرار همان اجرا، همه بررسی‌های زیر را دوباره اجرا می‌کند.
   - اولین اجرا: `claimed_at` و `claimed_by_execution` ثبت می‌شوند. run صف‌شده `dispatched` می‌شود، پس job تکراری چیزی نمی‌فرستد. پاسخ `200 {"claimed": true}`.
   - همان اجرا دوباره: ۲۰۰. پس n8n می‌تواند claimی را که پاسخش گم شد تکرار کند.
   - اجرای دیگر: `409 RUN_ALREADY_CLAIMED`.
   - run بسته، کمتر از ۳۰۰ ثانیه تا مهلت (دانلود، دو تلاش ۱۲۰ ثانیه‌ای Gemini و callback)، Batch خارج از `extracting` یا حساب در حال حذف: `409 RUN_CLOSED`. «Check envelope» در n8n هم حالا همین ۳۰۰ ثانیه را می‌خواهد.
   - استخراج خاموش یا سیاستی که دیگر Google را نام نمی‌برد: run با `EXTRACTION_DISABLED` بسته و Batch همان لحظه باز می‌شود، پیش از خروج عکس. پاسخ `409 RUN_CLOSED`.
   - فروشنده سیاست جاری را نپذیرفته (مثلاً بسته تازه‌ای که هنوز Google را نام می‌برد): run با `POLICY_CONSENT_REQUIRED` بسته می‌شود. claim آخرین دروازه پیش از خروج عکس است.
   - ردها برگردانده می‌شوند، نه پرتاب، تا این بستن‌ها commit شوند.
   - بدنه نامعتبر `422 CLAIM_INVALID` و run ناشناخته `404 RUN_NOT_FOUND`.

۳. **workflow:** `Accept → Build claim → Sign claim → Claim run → Claimed? → List photos`. `Sign claim` همان credential و پارامترهای `Sign callback` را دارد. `Claim run` مثل `Send callback` فرستاده می‌شود، با `onError: continueRegularOutput` تا پاسخ رد هم خوانده شود. `Claimed?` فقط با `data.claimed === true` ادامه می‌دهد. خروجی دیگر به جایی نمی‌رود: نه عکس، نه مدل، نه callback.

۴. **claim در Laravel اجبار می‌شود، نه فقط در workflow:**
   - لینک عکس run (ADR-033) فقط پس از claim کار می‌کند. workflowی که claim را دور بزند (نسخه قدیمی یا دوم روی همان webhook) عکسی نمی‌گیرد و چیزی برای مدل ندارد.
   - claim و callback secret مشترک دارند. پس callback هر بدنه‌ای که `schema_version` آن `product-extraction.output.v1` نباشد را با ۴۲۲ رد می‌کند **بدون بستن run**. claim امضاشده‌ای که به callback replay شود run را نمی‌کشد. برعکس، callback replayشده به claim با `CLAIM_INVALID` رد می‌شود.
   - claim سقف نرخ جدا دارد (`n8n-claim`)، تا retry آن callback پولی را به ۴۲۹ نیندازد.
   - چون شروع تکراری دیگر هزینه دوباره ندارد، `WorkflowStartUncertain` نسخه پیش‌نویس ADR-037 حذف شد. هر شروع ناموفق، حتی با پاسخ گم‌شده، retry می‌شود و فروشنده ۱۵ دقیقه منتظر نمی‌ماند.

۵. **مایگریشن** فقط دو ستون nullable به `ai_workflow_runs` اضافه می‌کند و `down` آن‌ها را حذف می‌کند. شناسه اجرای n8n داده شخصی یا محتوا نیست. ردیف با بقیه run در حذف حساب پاک می‌شود.

## شواهد

- **PHPUnit روی PostgreSQL موقت:**
  - `AIExtractionRunTest`:
    - claim اول، تکرار همان اجرا، رد اجرای دیگر، job تکراری بدون ارسال، رد پس از نتیجه.
    - امضای جعلی، start token یا timestamp کهنه ۴۰۱. هفت بدنه نامعتبر ۴۲۲. run ناشناخته ۴۰۴.
    - claim یا output.v2 امضاشده روی callback ۴۲۲ می‌گیرد و run باز می‌ماند.
    - run لغوشده، چهار دقیقه مانده، مهلت‌گذشته و حساب در حال حذف ۴۰۹.
    - استخراج خاموش، سیاستی بدون Google و بسته تازه پذیرفته‌نشده run را می‌بندند و Batch را باز می‌کنند، حتی برای اجرایی که claim را داشت.
    - لینک عکس پیش از claim ۴۰۳ و پس از آن ۲۰۰.
    - چهار نوع شکست شروع، شامل پاسخ گم‌شده، retry می‌شوند و اجرای دوم ۴۰۹ می‌گیرد.
  - `FoundationConcurrencyTest`: شش claim هم‌زمان با شش اجرای متفاوت دقیقاً یک ۲۰۰ و پنج `RUN_ALREADY_CLAIMED` می‌دهند.
  - `N8nExtractionWorkflowTest`: نقشه دقیق اتصال‌ها با شاخه رد بی‌خروجی، credential و پارامترهای claim برابر callback، و نشانی route.
- **vitest:** بدنه claim دقیقاً کلیدهای schema را دارد، به نشانی بررسی‌شده می‌رود و چیزی درباره عکس یا فروشنده ندارد.
- **اجرای سرتاسری روی n8n 2.44.6 موقت محلی** در شبکه داخلی بدون اینترنت، با credentialهای واقعی و stub به‌جای Laravel که HMAC را بررسی می‌کند. یک شروع امضاشده دو بار فرستاده شد. هر دو ۲۰۲ گرفتند. اجرای ۱ claim کرد، عکس را خواست و callback امضاشده فرستاد. اجرای ۲ پاسخ ۴۰۹ گرفت، هیچ عکسی نخواست و callback نفرستاد.

## پیامد و محدودیت

- **یک درخواست اضافه** به Laravel برای هر run.
- **۴۰۹ سه بار تکرار می‌شود.** retry گره HTTP در n8n هر خطایی را تکرار می‌کند. اجرای ردشده حدود چهار ثانیه بیشتر می‌ماند، بدون هزینه مدل.
- **حداقل ۳۰۰ ثانیه.** اگر `KM_AI_RUN_TIMEOUT_MINUTES` کمتر از ۶ باشد، run عملاً هرگز claim نمی‌شود. دانلود ۲۰ عکس کند (هر کدام تا ۱۵ ثانیه) به‌اضافه دو تلاش Gemini در بدترین حالت از ۳۰۰ ثانیه بیشتر است. «Build Gemini request» پس از مهلت مدل را صدا نمی‌زند و Laravel callback دیررس را کنار می‌گذارد.
- **claim شکست‌خورده run را تا مهلت باز نگه می‌دارد.** اگر Laravel در دسترس نباشد، n8n کاری نمی‌کند و `ai:expire-runs` run را در مهلت می‌بندد. این شکست بسته است: هرگز هزینه دوباره نمی‌دهد.
- **callback شناسه اجرا را حمل نمی‌کند.** callback امضاشده run باز بدون claim هم پذیرفته می‌شود، تا قرارداد `output.v1` عوض نشود. ولی بدون claim عکسی خوانده نمی‌شود، پس چنین callbackی نتیجه مدل ندارد.
- **retry خود Gemini** (دو تلاش در `Call Gemini`) داخل همان اجراست و claim آن را نمی‌بیند. محدودیت ADR-037 درباره شمارش آن باقی است.
- **بازبینی مستقل** دو P2 یافت: replay claim امضاشده روی callback که run را می‌بست، و اجبار claim فقط در workflow. شش P3 هم یافت: نبود بررسی رضایت در claim، claim تکراری بدون بررسی، claim با زمان ناکافی، هزینه UX «شروع نامطمئن»، سقف نرخ مشترک و آزمون‌های منفی. همه در بالا با آزمون اصلاح شدند.
- روی instance واقعی `ai.smartystudio.co.uk` و با Laravel واقعی اجرا نشده است. workflow آنجا غیرفعال و بدون credential است.
