# AI Accounting — gateway و حسابداری توکن

یک پلتفرم شبیه GapGPT / OpenRouter برای اپ‌هایی که از هوش مصنوعی استفاده می‌کنند:

- هر **کاربر** چند **اپ** دارد. هر اپ **کیف پول (موجودی دلاری) جدا** دارد.
- هر اپ چند **کلید API** دارد. هر کلید را می‌شود به ارائه‌دهنده‌ها یا مدل‌های خاصی محدود کرد (مثلاً فقط Claude) و برایش سقف هزینه و تاریخ انقضا گذاشت.
- اپ‌ها به‌جای OpenAI / Anthropic / Gemini، این سرویس را صدا می‌زنند (`/v1`). درخواست با **کلید واقعی ما** به ارائه‌دهنده می‌رود. توکن‌های مصرفی خوانده می‌شوند و از موجودی اپ کسر می‌شوند.
- **سود = قیمت فروش − قیمت خرید.** هر درخواست با هزینهٔ واقعی (`cost`) و مبلغ دریافتی (`charge`) در `usage_logs` ثبت می‌شود.
- شارژ موجودی با **بسته‌ها** (مثلاً ۲۰ یا ۱۰۰ دلاری، با امکان اعتبار هدیه) یا با **مبلغ دلخواه** (با حداقل و حداکثر قابل تنظیم) انجام می‌شود.

این مخزن همان الگوی پروژهٔ بیمه را دنبال می‌کند:

| بخش | فناوری | مسیر |
|---|---|---|
| پنل مدیریت | Filament 5 (فارسی، وزیرمتن، تاریخ جلالی، ساعت تهران) | `/admin` |
| API پنل مشتری | Laravel + Sanctum، مصرف‌کننده: [ai-accounting-web-app](https://github.com/MohammadrezaJafari/ai-accounting-web-app) (Quasar) | `/api` |
| Gateway مدل‌ها | سازگار با OpenAI و Anthropic | `/v1` |

## قیمت‌گذاری

قیمت‌ها **دلار به ازای هر ۱ میلیون توکن** هستند و برای چهار دسته جدا تعریف می‌شوند: ورودی، ورودی کش‌شده، نوشتن کش و خروجی.
قیمت خرید هر مدل را همان قیمت رسمی ارائه‌دهنده وارد کنید. قیمت فروش به این ترتیب تعیین می‌شود:

1. **درصد سود اختصاصی اپ** (قرارداد ویژه): قیمت خرید × (۱ + درصد اپ)
2. **قیمت فروش ثابت مدل**، اگر ادمین وارد کرده باشد
3. قیمت خرید × (۱ + درصد سود **مدل** ← **ارائه‌دهنده** ← **پیش‌فرض عمومی** در «تنظیمات مالی»)

مبالغ در دیتابیس به‌صورت عدد صحیح **nano-USD** ذخیره می‌شوند (۱ دلار = ۱۰^۹)، تا قیمت هر توکن دقیق بماند. مبلغ دریافتی رو به بالا گرد می‌شود.

> قیمت‌های `CatalogSeeder` قیمت‌های اعلام‌شده در زمان نوشتن هستند. قبل از استفادهٔ واقعی آن‌ها را در پنل بررسی کنید.

## استفاده از Gateway در اپ‌ها

```python
# OpenAI SDK — برای همهٔ مدل‌ها (GPT، Claude، Gemini، DeepSeek، Grok)
from openai import OpenAI
client = OpenAI(base_url="https://YOUR-HOST/v1", api_key="sk-aia-...")
client.chat.completions.create(model="claude-sonnet-4-5", messages=[{"role": "user", "content": "سلام"}])
```

```python
# Anthropic SDK — API اختصاصی Messages برای مدل‌های Claude
import anthropic
client = anthropic.Anthropic(base_url="https://YOUR-HOST", api_key="sk-aia-...")
client.messages.create(model="claude-sonnet-4-5", max_tokens=1024, messages=[{"role": "user", "content": "سلام"}])
```

| Endpoint | توضیح |
|---|---|
| `GET /v1/models` | مدل‌هایی که این کلید اجازهٔ استفاده از آن‌ها را دارد |
| `POST /v1/chat/completions` | قالب OpenAI، با پشتیبانی از stream. برای Anthropic و Gemini از endpoint سازگار با OpenAI خودشان استفاده می‌شود |
| `POST /v1/messages` | قالب Anthropic، با پشتیبانی از stream (فقط ارائه‌دهنده‌ای که `native_format = anthropic` دارد) |

- اگر موجودی اپ صفر یا کمتر از حداقل باشد، خطای `402` برمی‌گردد.
- در حالت stream، گزینهٔ `stream_options.include_usage` خودکار فعال می‌شود تا مصرف قابل محاسبه باشد.
- اگر ارائه‌دهنده گزارش مصرف نفرستد (مثلاً چون کلاینت وسط کار قطع شده)، مصرف تخمین زده می‌شود (حدود ۴ کاراکتر برای هر توکن) و در لاگ علامت می‌خورد.

## API پنل مشتری (`/api`)

| متد | مسیر | توضیح |
|---|---|---|
| POST | `auth/register`, `auth/login` | برمی‌گرداند: `{ token, user }` (Sanctum bearer) |
| GET/POST | `auth/me`, `auth/logout` | |
| GET | `dashboard?days=30` | موجودی کل، جمع مصرف، مصرف روزانه، مصرف به تفکیک مدل و اپ |
| GET | `catalog/models?app_id=` | مدل‌ها با قیمت فروش (قیمت خرید هیچ‌وقت نمایش داده نمی‌شود) |
| GET | `catalog/packages` | بسته‌ها و تنظیمات شارژ دلخواه |
| CRUD | `apps` | |
| GET/POST/PATCH/DELETE | `apps/{app}/keys[/{key}]` | متن کامل کلید فقط یک بار، در پاسخ ساخت (`plain_key`)، برگردانده می‌شود |
| GET | `apps/{app}/transactions` | تراکنش‌های کیف پول |
| GET | `usage?app_id=&model=&from=&to=&errors_only=` | لاگ درخواست‌ها |
| GET/POST | `orders`, `orders/{order}/cancel` | ثبت سفارش: `{app_id, package_id}` یا `{app_id, amount}` |

همهٔ مبالغ در API رشتهٔ دلاری‌اند (مثل `"12.50"`) و درصدها عدد هستند.

## پرداخت

درگاه با `BILLING_PAYMENT_GATEWAY` انتخاب می‌شود:

- `manual`: سفارش در وضعیت «در انتظار» می‌ماند تا ادمین در پنل (سفارش‌ها ← «تأیید پرداخت») تأییدش کند.
- `fake`: سفارش فوراً پرداخت‌شده ثبت می‌شود. فقط برای توسعه.

برای اضافه‌کردن درگاه واقعی (زرین‌پال، Stripe، کریپتو و …)، `App\Services\Payments\PaymentGateway` را پیاده‌سازی کنید و آن را در `PaymentManager` ثبت کنید. callback درگاه باید در نهایت `OrderService::markPaid()` را صدا بزند. این متد idempotent است.

## راه‌اندازی

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed        # ادمین: admin@example.com / password (ADMIN_EMAIL / ADMIN_PASSWORD)
php artisan serve
```

بعد از راه‌اندازی:

1. وارد `/admin` شوید.
2. در بخش «ارائه‌دهنده‌ها»، کلیدهای واقعی OpenAI / Anthropic / Google را اضافه کنید.
3. قیمت‌ها و درصد سود را بررسی کنید.

تست‌ها:

```bash
php artisan test
```
