# ADR-054 — استقرار تک‌سرور کنار n8n با deploy از GitHub

تاریخ: ۵ نوامبر ۲۰۲۶. وضعیت: تصمیم مالک برای سرور پایلوت `5.78.96.78` و دامنه `karmandeman.ir`. Runbook نسخه ۱.۱ و gateهای بخش ۱۶ آن همچنان برقرارند؛ این ADR فقط توپولوژی همین سرور را عوض می‌کند. جزئیات اجرایی در [deploy/server/README.md](../../deploy/server/README.md).

## زمینه

مالک دامنه را از طریق Cloudflare به سرور `5.78.96.78` وصل کرد و خواست بک‌اند به‌صورت Docker کنار n8n موجود (`/root/n8n`) اجرا شود، از طریق شبکه داخلی به دیتابیس همان سرور وصل شود و deploy از GitHub انجام شود. روی این سرور ISPConfig و Apache پورت‌های ۸۰ و ۴۴۳ را در اختیار دارند و Postgres فقط داخل شبکه Docker n8n است. توپولوژی مرجع (`compose.prod.reference.yaml`) Postgres جدا، Caddy روی ۸۰/۴۴۳ و OpenBao روی میزبان جدا فرض کرده بود.

## تصمیم

۱. **دیتابیس:** نقش و دیتابیس `km_app` روی همان کانتینر Postgres n8n (PostgreSQL 15) ساخته شد، از `template0` و بدون دسترسی superuser. برنامه فقط از شبکه داخلی `karmandeman_shared_db` (`--internal`) به آن می‌رسد و تنها کانتینر Postgres n8n به این شبکه وصل است؛ پس n8n به کانتینرهای برنامه دسترسی شبکه ندارد. `CONNECT` روی دیتابیس‌های `n8n` و `postgres` از `PUBLIC` گرفته شد. فایل compose n8n این شبکه را اعلام می‌کند تا ساخت دوباره کانتینر آن را حفظ کند. پیش از تغییر، `pg_dumpall` کامل گرفته شد.

۲. **ورودی وب:** Apache گواهی Let's Encrypt را نگه می‌دارد و TLS را پایان می‌دهد و به Caddy روی `127.0.0.1:8090` می‌فرستد؛ Caddy با `PUBLIC_DOMAIN=":80"` فقط HTTP دارد. Caddy و Laravel سرصفحه‌های `X-Forwarded-*` را فقط از نشانی‌های خصوصی می‌پذیرند؛ نشانی عمومی نمی‌تواند خود را HTTPS یا نشانی دیگری معرفی کند (آزمون `TrustedProxyTest`).

۳. **OpenBao روی همین سرور:** سرور production (نه dev) با ذخیره فایل در volume، یک کلید unseal و unseal خودکار پس از هر ری‌استارت با سرویس `openbao_unsealer` از فایلی که فقط کاربر `deploy` می‌خواند (انتخاب مالک: دسترس‌پذیری بر unseal دستی). توکن برنامه policy `karmandeman-app` دارد و فقط به کلیدهای transit با پیشوند `ws-` دسترسی دارد؛ توکن root پس از راه‌اندازی باطل شد. شنونده OpenBao روی شبکه داخلی هم TLS دارد، با CA خصوصی؛ برنامه در production همچنان HTTP ساده را رد می‌کند و CA را با `OPENBAO_CA_CERT` فقط برای همین کانال می‌پذیرد (آزمون `OpenBaoClientTlsTest`).

۴. **فضای فایل:** Garage (S3-compatible، تک‌گره، شبکه داخلی بدون پورت منتشرشده) با باکت خصوصی `karmandeman-private`، چون ADR-022 در production فقط دیسک S3 را می‌پذیرد (انتخاب مالک).

۵. **Deploy:** workflow `Deploy` پس از سبز شدن Foundation checks روی push به `main` اجرا می‌شود (انتخاب مالک) و با SSH به کاربر `deploy` وصل می‌شود. کلید GitHub فقط forced command `/opt/karmandeman/bin/deploy` را اجرا می‌کند که فقط `deploy <sha>`، `rollback <sha>` و `status` را برای commitهای روی `origin/main` می‌پذیرد. image روی خود سرور ساخته می‌شود (انتخاب مالک) و با commit برچسب می‌خورد. هر release پیش از migration یک `pg_dump` از `km_app` می‌گیرد، سپس migrate، اجرای stack و آزمون `/up` و نبود migration معلق. اجرای دیرتر یک commit قدیمی‌تر از release جاری نادیده گرفته می‌شود؛ بازگشت فقط با `rollback` و فقط وقتی schema سازگار باشد.

## پیامدها و موارد باز

- یک سرور، بدون HA؛ Postgres با n8n مشترک است، پس بار یا خرابی یکی بر دیگری اثر دارد.
- بکاپ‌ها (dump دیتابیس، volume Garage و OpenBao و کلید unseal) هنوز فقط روی همین سرورند؛ کپی خارج از سرور و آزمون restore (Runbook بخش ۱۲) باز است. تا آن زمان `KM_ERASURE_BACKUP_LIFECYCLE_VERIFIED=false` می‌ماند.
- پشت Cloudflare، نشانی کاربر نشانی لبه Cloudflare است تا Apache `mod_remoteip` را با بازه‌های Cloudflare بگیرد.
- توکن ربات تلگرام production و ثبت webhook با مالک است.
- یافته‌های امنیتی سرور خارج از این تغییر: ورود SSH با root و گذرواژه فعال است، فایروال خاموش است، و editor n8n (`5678`)، نمونه dev (`5679`) و ytdlp (`8003`، `8004`) روی همه نشانی‌ها منتشر شده‌اند؛ گذرواژه Postgres n8n ضعیف است.
