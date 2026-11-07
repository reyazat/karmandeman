# ADR-057 — دسترسی تسترهای دعوت‌شده و استخراج زنده برای آن‌ها

تاریخ: ۵ نوامبر ۲۰۲۶. وضعیت: تصمیم مالک («دسترسی اپ را باز کن تا بتوانیم پروسه را تست کنیم»، و انتخاب‌های: فهرست تستر، import و فعال‌سازی و آزمون workflow، دسترسی SSH). این ADR بخشی از [ADR-032](ADR-032-n8n-extraction-run-contract.md) («استخراج در production همیشه خاموش») و gate سیاست [ADR-009](ADR-009-pilot-policy-and-erasure.md) را **فقط برای تسترهای فهرست‌شده** تغییر می‌دهد. سیاست برای عموم تأیید نشده است و این ADR آن را تأیید نمی‌کند.

## زمینه

ربات زنده (ADR-054) به همه پیام «نسخه شرایط و سیاست داده هنوز برای استفاده عمومی تأیید نشده است» می‌دهد، چون هیچ بسته سیاستی تأیید نشده و کد تأیید ساختگی را نمی‌پذیرد. استخراج هم در production خاموش است. پس مالک نمی‌تواند مسیر عکس و متن تا کاتالوگ را روی ربات واقعی تمرین کند (PZ-07). پیش‌نویس v5 ([ADR-056](ADR-056-verification-fixes-photo-orientation-policy-v5.md)) آنچه کد انجام می‌دهد و OpenAI را می‌گوید.

## تصمیم

۱. **فهرست تستر:** `KM_PILOT_TESTER_TELEGRAM_IDS` (شناسه‌های عددی تلگرام، جداشده با کاما) و `KM_PILOT_TEST_POLICY` (نام پوشه یک بسته در `resources/policies`، مثلاً `pilot-2026-11-05-v5`). اگر یکی خالی یا بدشکل باشد، هیچ تستری وجود ندارد.

۲. **بسته آزمایشی فقط برای تسترها:** `PilotPolicyService::forTelegramId` و `forWorkspace` برای تستر بسته آزمایشی و برای بقیه همان بسته منتشرشده را برمی‌گردانند. در بسته آزمایشی فقط مانع‌های تأیید و شواهد (وضعیت draft، تأییدکننده، تاریخ، hash تأیید و چهار شاهد انتشار) نادیده گرفته می‌شوند. بسته خراب، نسخه یا scope نامعتبر، پردازشگر نامعتبر یا جدول خالی همچنان مسدود می‌کند. نسخه آن `<version>-test` و digest آن `sha256("test:" + digest)` است، پس رضایت تستر هرگز رضایت به بسته منتشرشده حساب نمی‌شود و اگر همان متن بعداً تأیید شود، پذیرش تازه لازم است. متن شرایط با «نسخه آزمایشی … فقط برای آزمون‌کنندگان دعوت‌شده» شروع می‌شود. حذف شناسه از فهرست، دسترسی پروژه را می‌بندد.

۳. **استخراج در production فقط برای workspace تستر:** `AIWorkflowService::extractionEnabled(Workspace)` در production فقط وقتی true است که مالک workspace تستر باشد؛ بقیه شرط‌ها مثل قبل‌اند: `KM_AI_EXTRACTION_ENABLED`، provider OpenAI، `KM_AI_WORKFLOW_VERSION=product-extraction.openai.v2`، مسیر webhook همان export، و بسته‌ای که OpenAI را نام می‌برد. `canProcessRun` همان را برای workspace همان run بررسی می‌کند، پس claim، لینک عکس و callback هم بسته می‌شوند. `workflow_version` اکنون از env می‌آید (پیش‌فرض v1 که هرگز از gate نمی‌گذرد).

۴. **Compose:** `KM_AI_EXTRACTION_ENABLED` پیش‌تر در فهرست env برنامه نبود و به کانتینر نمی‌رسید. این کلید و `KM_AI_WORKFLOW_VERSION`، `N8N_EXTRACTION_WEBHOOK_PATH`، `KM_PILOT_TESTER_TELEGRAM_IDS` و `KM_PILOT_TEST_POLICY` اضافه شدند.

۵. **workflow در n8n:** export `product-extraction.openai.v2` اکنون credential استاندارد OpenAI در n8n (`openAiApi`) را به کار می‌برد، نه Header Auth دستی؛ کلید همچنان فقط در credential رمزشده n8n است. در n8n روی `ai.smartystudio.co.uk` (همان سرور) workflow `km-product-extraction-openai-v2` import و فعال شد، با دو credential تازه HMAC («KM extraction start token» با همان `N8N_WORKFLOW_AUTH_TOKEN` برنامه، و «KM extraction callback secret» با `N8N_CALLBACK_SECRET` تازه که روی سرور ساخته شد و از سرور بیرون نیامد) و credential موجود «OpenAi». درخواست بدون امضا 401 می‌گیرد.

۶. **تنظیم سرور (۵ نوامبر):** پس از بکاپ `.env.production`، تستر `79355662` (تنها حساب تلگرامی که تا آن زمان به ربات زنده پیام داده بود)، بسته `pilot-2026-11-05-v5`، استخراج روشن، نسخه و مسیر workflow OpenAI و secret callback تنظیم شدند.

۷. **اصلاح پس از اولین اجرای زنده (۵ نوامبر، ۱۷:۲۵):** هنگام import ثابت `BASE` در گره «Check envelope» روی مقدار جانگهدار `__KM_APP_BASE_URL__` مانده بود. به همین دلیل n8n هر شروعی را با 401 رد کرد و اولین اجرای تستر پس از سه تلاش `DISPATCH_FAILED` شد. hold دو اعتباری آزاد شد و کسری انجام نشد. مقدار `https://karmandeman.ir` با API n8n روی workflow زنده نوشته شد. آزمون بدون هزینه: شروع امضاشده با شناسه run ناموجود از n8n پاسخ 202 گرفت. claim هم به Laravel رسید و 404 گرفت. پس امضای شروع، secret callback و مسیر n8n به Laravel از راه Cloudflare کار می‌کنند. گام import در `deploy/server/README.md` ثبت شد.

## پیامدها

- تستر داده واقعی خود را زیر متنی می‌فرستد که تأیید عمومی ندارد؛ متن آزمایشی این را صریح می‌گوید. داده تستر همان رمزنگاری و حذف بقیه را دارد.
- هزینه OpenAI برای اجرای تستر واقعی است و با پیش‌فاکتور و سقف همان قاعده ADR-039/042 کنترل می‌شود.
- gateهای عمومی انتشار (تصویب سیاست، restore، editor n8n، SSH و نقش Postgres n8n در ADR-056) باز می‌مانند؛ این ADR آن‌ها را نمی‌بندد.

## شواهد

- `PilotTesterAccessTest`: تستر بسته آزمایشی را با نسخه `-test` می‌پذیرد، آشنایی را تمام و پروژه می‌سازد و متن آزمایشی را می‌بیند؛ حساب دیگر پیام بسته را می‌گیرد و رضایت یا پروژه‌ای نمی‌سازد؛ digest آزمایشی با digest همان متن منتشرشده فرق دارد؛ حذف از فهرست ساخت پروژه را می‌بندد؛ استخراج در production فقط برای workspace تستر و فقط با workflow OpenAI روشن است؛ فهرست یا پوشه نامعتبر هیچ تستری نمی‌سازد.
- آزمون‌های workflow: export OpenAI credential `openAiApi` را با `predefinedCredentialType` دارد.
