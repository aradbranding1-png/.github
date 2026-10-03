<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (session_status() === PHP_SESSION_ACTIVE): ?><meta name="csrf-token" content="<?= e(csrf_token()) ?>"><?php endif; ?>
<meta name="robots" content="noindex">
<title><?= e($title ?? 'سامانه آموزش آراد برندینگ') ?></title>
<?php include APP_PATH . '/Views/partials/pwa_head.php'; ?>
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<?php if (session_status() === PHP_SESSION_ACTIVE) foreach (flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" style="margin:1rem auto;max-width:720px"><?= $f['msg'] ?></div>
<?php endforeach; ?>
<?= $content ?>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
