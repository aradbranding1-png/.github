<?php /** @var int $migrations @var int $partitions @var string $health_token @var string $ops_token @var callable $e */ ?>
<div class="card">
  <h2 class="ok">نصب با موفقیت انجام شد</h2>
  <p><?= $e($migrations) ?> Migration اجرا و <?= $e($partitions) ?> Partition ساخته شد. نصب‌کننده اکنون برای همیشه غیرفعال است.</p>

  <h2>کارهای باقی‌مانده در DirectAdmin</h2>
  <p>در بخش Cron Jobs این دو خط را اضافه کنید:</p>
  <p class="hint">هر خط یک Cron جداگانه است. زمان‌بندی را در فیلدهای بالای فرم و فقط دستور را در فیلد Command وارد کنید. مسیر PHP را از پشتیبانی هاست بپرسید.</p>
  <p>هر دقیقه: <code><?= $e(PHP_BINDIR . '/php ' . BASE_PATH . '/bin/console queue:work --max-time=55 >/dev/null 2>&1') ?></code></p>
  <p>هر ۱۵ دقیقه: <code><?= $e(PHP_BINDIR . '/php ' . BASE_PATH . '/cron.php >/dev/null 2>&1') ?></code></p>

  <h2>توکن Health Check</h2>
  <p class="hint">این توکن برای مانیتورینگ آدرس <code>/health</code> با هدر <code>X-Health-Token</code> لازم است و در فایل .env هم ذخیره شده است.</p>
  <p><code><?= $e($health_token) ?></code></p>

  <h2>توکن عملیات (OPS_TOKEN)</h2>
  <p class="hint">فقط برای مسیرهای مدیریتی <code>/ops/*</code> با هدر <code>X-Ops-Token</code>. این توکن را هرگز در سرویس مانیتورینگ نگذارید. اگر به آن نیاز ندارید، مقدار <code>OPS_TOKEN</code> را در .env خالی کنید تا این مسیرها کاملاً خاموش شوند.</p>
  <p><code><?= $e($ops_token) ?></code></p>

  <p class="hint">برای مدیر شدن: در /register عضو شوید و سپس نقش Super Admin را با دستور <code>php bin/console user:promote-admin EMAIL</code> یا درخواست POST به <code>/ops/promote-admin</code> بگیرید.</p>
</div>
