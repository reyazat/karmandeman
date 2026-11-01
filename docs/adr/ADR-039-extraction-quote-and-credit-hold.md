# ADR-039 — پیش‌فاکتور استخراج و رزرو، کسر و آزادسازی اعتبار

تاریخ: ۱ نوامبر ۲۰۲۶. وضعیت: تصمیم مالک و برش اول گام ۵ [DEVELOPMENT_PLAN](../DEVELOPMENT_PLAN.md). وابسته به PRD E08 (FR-070…073)، §۶ (`CREDIT_RESERVED` بدون Quote معتبر و موجودی کافی ممنوع)، QA-07/08/09، [ADR-032](ADR-032-n8n-extraction-run-contract.md) و [ADR-038](ADR-038-n8n-run-claim.md). Engineering Standards §۳ و §۶ (قفل، کلید یکتا، idempotency).

## مسئله

پس از ADR-037 هر run استخراج یک درخواست پولی Gemini است. ADR-032 روشن‌کردن استخراج را به «رزرو اعتبار گام ۵ پیش از run» مشروط کرده بود. Ledger فقط `grant` داشت و `appendEntry` بقیه نوع‌ها را رد می‌کرد. هیچ پیش‌فاکتوری به فروشنده نشان داده نمی‌شد (FR-072).

## تصمیم مالک (۱ نوامبر ۲۰۲۶)

- استخراج هزینه دارد: در `/done` رزرو و فقط پس از استخراج موفق کسر می‌شود. شکست، timeout و لغو اعتبار را کامل برمی‌گردانند.
- فروشنده پیش از رزرو پیش‌فاکتور را با دکمه تأیید می‌کند. پیش‌فاکتور با تغییر Batch باطل است.

## تصمیم فنی

۱. **دو cache، یک ledger.** `wallets.reserved_cached` و `credit_ledger.held_amount` اضافه شدند. هر ردیف ledger اثر خود را روی هر دو صریح دارد:

   | نوع | `amount` (موجودی) | `held_amount` (رزرو) |
   |---|---|---|
   | `grant` | +X | 0 |
   | `reserve` | 0 | +X |
   | `capture` | −X | −X |
   | `release` | 0 | −X |

   `balance_cached = Σ amount` و `reserved_cached = Σ held_amount`. قابل‌خرج = موجودی − رزرو. در PostgreSQL قید `wallets_credit_not_overdrawn` (`reserved_cached >= 0 AND balance_cached >= reserved_cached`) آخرین دفاع است. `release` هرگز اعتبار اضافه نمی‌کند. کسر واقعی ردیف `capture` است و به run وصل است (FR-073).

۲. **Actionهای کیف پول** (نام‌های Standards §۴): `ReserveCreditsAction`، `CaptureCreditsAction`، `ReleaseCreditsAction`. هر سه داخل تراکنش فراخواننده و پس از قفل Workspace، قفل ردیف wallet را می‌گیرند (ترتیب: Workspace، run، wallet). رزرو کلید idempotency یکتا دارد و فقط از موجودی آزاد. capture و release هر hold یک کلید مشترک `credit:settle:{id}` دارند. پس هر hold دقیقاً یک بار تسویه می‌شود و تسویه دوم، از هر نوع، همان اولی را برمی‌گرداند. `appendEntry` همچنان فقط `grant` می‌پذیرد.

۳. **پیش‌فاکتور** (`credit_quotes`، PRD §۷.۱): نوع `product_extraction`، نسخه تعرفه، نسخه Batch، شناسه عمومی عکس‌ها، خطوط (`product_extraction_photo` × تعداد × اعتبار هر عکس)، جمع، `increase_cap_credits = 0` و انقضا. هیچ متن محصول یا محتوای فایل ندارد. `CreateExtractionQuoteAction` با `Idempotency-Key`، رضایت، Policy و قفل Workspace ساخته می‌شود و چیزی رزرو نمی‌کند. `POST /api/v1/projects/{project}/extraction-quotes` آن را با اعتبار قابل‌خرج فعلی برمی‌گرداند. بدون عکس تازه یا با استخراج خاموش `409 EXTRACTION_NOT_NEEDED`.

۴. **شروع run فقط با پیش‌فاکتور پذیرفته.** `finish-input` وقتی عکس تازه به run می‌رود `quote_id` می‌خواهد (`409 EXTRACTION_QUOTE_REQUIRED`). زیر همان قفل بررسی می‌شود: همان Workspace و پروژه (`404 QUOTE_NOT_FOUND`)، استفاده‌نشده (`QUOTE_ALREADY_USED`)، منقضی‌نشده (`QUOTE_EXPIRED`)، و همان نسخه Batch و دقیقاً همان عکس‌ها (`QUOTE_STALE`، QA-07). سپس run ساخته، جمع پیش‌فاکتور رزرو (`INSUFFICIENT_CREDITS` کل تراکنش را، run و job شروع را هم، برمی‌گرداند)، hold به run وصل و پیش‌فاکتور مصرف‌شده علامت می‌خورد. قیمت پیش‌فاکتور الزام‌آور است. تغییر تعرفه پس از آن اثری ندارد.

۵. **یک راه بستن run.** `CloseAIWorkflowRunAction` تنها مسیر بستن run است: callback موفق، شکست گزارش‌شده یا خروجی نامعتبر، `USAGE_OVER_CAP`، timeout (sweep و callback دیررس)، لغو پروژه، claim ردشده و شروع ناموفق. موفق capture می‌کند و بقیه release. هر دو در همان تراکنش بستن run هستند. پیام ربات مقدار کسر یا برگشت را می‌گوید. حذف حساب wallet و ledger را با همه ردیف‌ها پاک می‌کند (FR-087)، پس hold باز چیزی را نگه نمی‌دارد.

۶. **ربات.** `/done` در `collecting_input` با عکس تازه، به‌جای شروع، پیش‌فاکتور می‌فرستد: تعداد عکس، اعتبار لازم، موجودی قابل‌خرج، انقضا و اینکه کسر فقط پس از موفقیت است (PRD §۲۱). دکمه `quote:accept:<uuid>` فقط وقتی اعتبار کافی است نشان داده می‌شود. فشار آن همان `finish-input` را با کلید ساخته‌شده از callback id اجرا می‌کند، پس فشار تکراری replay است. `/balance` رزرو باز را هم می‌گوید.

۷. **تعرفه پیکربندی است، نه قیمت نهایی.** `KM_CREDITS_EXTRACTION_PER_PHOTO` (پیش‌فرض و حداقل ۱) و `KM_CREDIT_QUOTE_TTL_MINUTES` (پیش‌فرض ۱۵). نسخه تعرفه `extraction-v1` در هر پیش‌فاکتور ثبت می‌شود. قیمت واقعی هنوز تصمیم باز PRD §۲۰ است.

۸. **پیش‌فاکتوری که دیگر لازم یا مجاز نیست پذیرفته نمی‌شود.** اگر بین پیش‌فاکتور و تأیید استخراج خاموش شود، سیاست provider را نام نبرد یا عکس تازه‌ای نماند، `finish-input` با `quote_id` پاسخ `409 QUOTE_STALE` می‌دهد و ربات می‌گوید `/done` را دوباره بفرست. پس هرگز «رزرو شد» گفته نمی‌شود وقتی چیزی رزرو نشده است. `quote_id` فقط وقتی فرستاده شود در fingerprint idempotency می‌آید، تا retry کلیدهای ثبت‌شده پیش از این تغییر replay شوند. replay کلید پیش‌فاکتور وضعیت فعلی آن (`accepted`) را برمی‌گرداند، نه snapshot لحظه ساخت.

۹. **production همچنان خاموش است.** این برش شرط «رزرو اعتبار پیش از run» ADR-032 را برآورده می‌کند، ولی روشن‌کردن در production هنوز ADR تازه می‌خواهد: بسته سیاست مصوب با نام Google، کلید paid tier و آزمون کیفیت.

## گزینه‌های ردشده

- **کسر مستقیم در شروع و refund در شکست:** شکست سیستم کیف پول را موقتاً کم می‌کند و refund نوع جدا برای خطای ما می‌شد. hold همان حسابداری را بدون جابه‌جایی واقعی دارد.
- **فقط یک cache با `amount` منفی برای رزرو:** capture آنگاه ردیف صفر می‌شد و کسر واقعی در ledger دیده نمی‌شد.
- **جدول جدای رزرو:** کلید تسویه مشترک همان تضمین «یک بار» را با یکتایی موجود ledger می‌دهد.
- **رزرو خودکار بدون دکمه:** مالک رد کرد (FR-072).

## بازبینی مستقل

بازبین مستقل P1 نیافت: هر مسیر بستن run hold را یک بار تسویه می‌کند، ترتیب قفل یکسان است، موجودی منفی نمی‌شود و purge ترتیب FKها را رعایت می‌کند. یافته‌ها و نتیجه:
- **(P2، اصلاح شد)** استخراج خاموش‌شده بین پیش‌فاکتور و تأیید: مسیر دستی `quote_id` را نادیده می‌گرفت. ربات «رزرو شد» می‌گفت یا با `PROJECT_HAS_NO_PRODUCTS` «دوباره بزن» تکرار می‌کرد. اکنون `QUOTE_STALE` (بند ۸).
- **(P3، اصلاح شد)** replay پیش‌فاکتور پس از پذیرش `accepted: false` می‌داد و ربات دکمه نشان می‌داد.
- **(P3، اصلاح شد)** `quote: null` در fingerprint، retry کلیدهای قدیمی را به `IDEMPOTENCY_KEY_CONFLICT` می‌انداخت.
- **(P3، اصلاح شد)** حذف همه `\p{Cf}` در نرمال‌ساز soft hyphen، برچسب emoji و نشانه‌های عددی عربی را هم پاک می‌کرد. فهرست به کنترل‌های bidi، zero-width، word joiner و BOM محدود شد.
- **(P3، ثبت‌شده)** FR-072 «عدم تأیید بدون کسر»: نپذیرفتن پیش‌فاکتور هزینه ندارد، ولی تا عکس بی‌محصولی در Batch هست راه بررسی فقط ثبت دستی محصول (`/product` یا API با `image_asset_ids`) است.
- آزمون‌های منفی پیشنهادی (OUTPUT_INVALID، USAGE_OVER_CAP، callback دیررس، `DISPATCH_FAILED`، رد claim، purge با hold باز) اضافه شدند.

## شواهد

- PostgreSQL موقت با OpenBao 2.4.1: **۳۴۶ تست / ۴۷۲۴ assertion** در دو اجرای پیاپی، بدون skip.
  - `ExtractionCreditTest` (۱۰ مورد): پنج راه دیگر پایان run همه release، پیش‌فاکتور پس از خاموشی استخراج در ربات و API رد و replay با وضعیت پذیرش، purge با hold باز و پیش‌فاکتور، و قیمت و replay پیش‌فاکتور بدون رزرو، `EXTRACTION_QUOTE_REQUIRED`، بدون عکس تازه یا استخراج خاموش، رزرو و کسر یک‌باره با callback تکراری، release در شکست، timeout با callback دیررس و لغو، کمبود اعتبار بدون run، پیش‌فاکتور بیگانه، ناموجود، منقضی، کهنه و مصرف‌شده، و ربات بدون دکمه با اعتبار ناکافی، replay فشار دکمه و `/balance`. هر مورد برابری cacheها با جمع ledger را بررسی می‌کند.
  - `WalletLedgerTest`: idempotency رزرو، فقط از موجودی آزاد، تسویه یک‌باره capture/release در هر دو ترتیب، و رد wallet بیش‌برداشت‌شده توسط قید PostgreSQL.
  - `FoundationConcurrencyTest`: شش پذیرش هم‌زمان دو پیش‌فاکتور با اعتبار فقط برای یکی: دقیقاً یک run و یک رزرو، موجودی ۳ و رزرو ۳ (FR-070).
- SQLite: **۳۱۹ پاس / ۲۷ skip**. Pint و PHPStan بدون خطا. vitest **۵۳ پاس**.
- rollback و اجرای دوباره migration `add_credit_quotes_and_reservations` روی PostgreSQL موقت، دو بار، موفق.

## محدودیت‌ها و باز

- تا عکس بی‌محصولی در Batch هست، رسیدن به بررسی بدون پذیرش پیش‌فاکتور فقط با ثبت دستی محصول ممکن است.
- capture همیشه کل پیش‌فاکتور است. capture جزئی (مثلاً به نسبت محصول تأییدشده) ساخته نشده است.
- Quote خروجی‌ها (Gate B، گام ۶)، refund پس از capture، adjustment اپراتور با دلیل و reconciliation دوره‌ای ساخته نشده‌اند.
- retention پیش‌فاکتورهای پذیرفته‌نشده تعریف نشده است. فقط شمارش و شناسه عمومی عکس دارند و با حذف حساب پاک می‌شوند.
- داشبورد مصرف (FR-073) فقط `type`، `amount`، `held_amount` و `reason` را نشان می‌دهد؛ شناسه عمومی run در خروجی API نیست.
- ربات زنده، n8n واقعی و Gemini آزموده نشده‌اند.
