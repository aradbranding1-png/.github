<?php
use App\Services\CompletionAudit as CA;

$keep = aud_q() + array_filter(['status' => $status === 'open' ? '' : $status, 'segment' => $segment, 'course' => $_GET['course'] ?? '', 'reason' => $_GET['reason'] ?? '', 'q' => $_GET['q'] ?? ''], fn($v) => $v !== '' && $v !== null);
$link = fn(array $over) => url('/admin/reports/suspicious', array_filter(array_merge($keep, $over), fn($v) => $v !== '' && $v !== null));
$segs = ['merchant' => 'تاجران', 'employee' => 'کارمندان', 'agent' => 'نمایندگان', 'custom' => 'سایر'];
$verb = ['audio' => 'شنیدن', 'video' => 'دیدن', 'text' => 'مطالعه'];
?>
<div class="page-head">
    <div><div class="crumbs"><a href="<?= url('/admin/reports') ?>">گزارش‌ها</a></div>
        <h1><?= icon('triangle-alert') ?> تکمیل مشکوک درس‌ها</h1>
        <div class="sub">کسانی که درس را بدون دیدن / شنیدن / خواندن کامل «تکمیل» کرده‌اند (مثلاً فایل را تا آخر جلو زده‌اند). به خود فرد و به شما هشدار داده می‌شود.</div></div>
    <div class="btn-group">
        <form class="inline" method="post" action="<?= url('/admin/reports/suspicious/scan') ?>"><?= csrf_field() ?><button class="btn btn-outline" title="همه تکمیل‌های درس از ابتدا دوباره بررسی می‌شوند؛ به هر نفر فقط یک بار هشدار داده می‌شود"><?= icon('refresh-cw') ?> بررسی همه تکمیل‌ها</button></form>
        <?php if (can('settings.view') || can('settings.edit')): ?><a class="btn btn-outline" href="<?= url('/admin/settings') ?>#cheat"><?= icon('settings') ?> تنظیمات کشف تقلب</a><?php endif; ?>
        <?php if (can('reports.export')): ?><a class="btn btn-outline" href="<?= $link(['export' => 1]) ?>"><?= icon('file-spreadsheet') ?> Excel</a><?php endif; ?>
    </div>
</div>
<?php $audSegments = false; include APP_PATH . '/Views/partials/report_audience.php'; ?>

<?php if (!empty($unknown)): ?>
<details class="alert alert-warning" style="display:block"><summary style="cursor:pointer"><?= icon('triangle-alert') ?> <b><?= fa(count($unknown)) ?> درس صوتی/ویدیویی بررسی نشد چون مدتش معلوم نیست</b> — کسانی که این درس‌ها را تکمیل کرده‌اند هنوز در این لیست نیستند. برای دیدن فهرست بزنید.</summary>
    <div class="small mt-1">برای هر درس، در فرم ویرایش درس «مدت (دقیقه)» را وارد کنید و دوباره «بررسی همه تکمیل‌ها» را بزنید. (درس‌هایی که دست‌کم دو نفر تا انتهایشان پخش کرده‌اند، خودکار مدت‌شان تشخیص داده می‌شود.)</div>
    <div class="table-wrap mt-1"><table class="table"><thead><tr><th>درس</th><th>دوره</th><th>نوع</th><th>تعداد تکمیل</th><th></th></tr></thead><tbody>
    <?php foreach ($unknown as $l): ?><tr><td class="fw-b"><?= e($l['title']) ?></td><td class="small"><?= e($l['course_title']) ?></td><td class="small"><?= $l['content_type'] === 'audio' ? 'صوتی' : 'ویدیو' ?></td><td class="num"><?= fa($l['completions']) ?></td>
        <td class="actions"><?php if (can('lessons.edit')): ?><a class="btn btn-xs btn-outline" href="<?= url('/admin/lessons/' . $l['id'] . '/edit') ?>"><?= icon('pencil') ?> واردکردن مدت</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</details>
<?php endif; ?>
<?php if (!$lastScan): ?><div class="alert alert-info"><?= icon('info') ?><div>تکمیل‌های قبل از این نسخه هنوز بررسی نشده‌اند. دکمه «بررسی همه تکمیل‌ها» را بزنید تا همه سوابق قبلی هم بررسی شوند (این کار هر شب هم خودکار انجام می‌شود). به هر فرد مشکوک هشدار داده می‌شود و یک خلاصه برای شما می‌آید.</div></div><?php endif; ?>

<div class="pill-nav">
    <a class="<?= $segment === '' ? 'active' : '' ?>" href="<?= $link(['segment' => '', 'page' => '']) ?>">همه گروه‌ها (<?= fa(array_sum($segCounts)) ?>)</a>
    <?php foreach ($segs as $k => $t): if ($k === 'custom' && empty($segCounts[$k])) continue; ?><a class="<?= $segment === $k ? 'active' : '' ?>" href="<?= $link(['segment' => $k, 'page' => '']) ?>"><?= e($t) ?> (<?= fa($segCounts[$k] ?? 0) ?>)</a><?php endforeach; ?>
</div>

<div class="tabs">
    <?php foreach (['open' => 'در انتظار بررسی', 'reset' => 'باید دوباره ببینند', 'rewatched' => 'بعداً کامل دیدند', 'dismissed' => 'تأییدشده', 'all' => 'همه'] as $k => $t): ?>
        <a class="<?= $status === $k ? 'active' : '' ?>" href="<?= $link(['status' => $k === 'open' ? '' : $k, 'page' => '']) ?>"><?= e($t) ?><?= $k !== 'all' ? ' (' . fa($statusCounts[$k] ?? 0) . ')' : '' ?></a>
    <?php endforeach; ?>
</div>

<form class="card filters" method="get" action="<?= url('/admin/reports/suspicious') ?><?= aud_hidden() ?>">
    <?php foreach (['status', 'segment'] as $h): if (isset($keep[$h])): ?><input type="hidden" name="<?= $h ?>" value="<?= e($keep[$h]) ?>"><?php endif; endforeach; ?>
    <div class="field grow"><label>جست‌وجو</label><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="نام، موبایل یا عنوان درس"></div>
    <div class="field"><label>دوره</label><select name="course"><option value="">همه</option><?php foreach ($courses as $k => $t): ?><option value="<?= (int)$k ?>"<?= selected($k, $_GET['course'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>دلیل</label><select name="reason"><option value="">همه</option><?php foreach (CA::REASONS as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $_GET['reason'] ?? '') ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary"><?= icon('filter') ?></button>
</form>

<?php if ($status === 'reset'): ?><div class="alert alert-info"><?= icon('info') ?><div><b>این افراد لغو شده‌اند و پیام لغو به آن‌ها رفته است.</b> تکمیل درس از حسابشان برداشته شده و تا وقتی درس را کامل نبینند/نشنوند در همین تب می‌مانند؛ بعد از پخش کامل، خودکار به تب «بعداً کامل دیدند» منتقل می‌شوند. ستون «وضعیت فعلی» نشان می‌دهد الان چقدر از درس را دیده‌اند.</div></div><?php endif; ?>
<?php if ($status === 'rewatched'): ?><div class="alert alert-success"><?= icon('circle-check') ?><div>این افراد بعد از هشدار یا لغو، درس را واقعاً کامل دیده/شنیده‌اند.</div></div><?php endif; ?>
<form method="post" action="<?= url('/admin/reports/suspicious') ?>" class="card flush">
    <?= csrf_field() ?>
    <div class="flex flex-wrap" style="padding:.8rem 1.25rem;gap:.5rem;border-bottom:1px solid var(--border)">
        <span class="small muted">با انتخاب‌شده‌ها:</span>
        <?php if ($status !== 'reset' && $status !== 'rewatched'): ?><button class="btn btn-sm btn-danger" name="op" value="reset" data-confirm="تکمیل این درس‌ها لغو شود تا فراگیران دوباره ببینند؟"><?= icon('rotate-ccw') ?> لغو تکمیل — باید دوباره ببیند</button><?php endif; ?>
        <button class="btn btn-sm btn-outline" name="op" value="notify"><?= icon('bell') ?> هشدار دوباره به فرد</button>
        <button class="btn btn-sm btn-ghost" name="op" value="dismiss"><?= icon('check') ?> مشکلی نیست (تأیید)</button>
    </div>
    <div class="table-wrap"><table class="table"><thead><tr>
        <th><input type="checkbox" data-check-col="sel" title="انتخاب همه"></th><th>فراگیر</th><th>نوع</th><th>درس</th><th>دلیل</th><th>طول درس</th><th>باز کردن تا تکمیل</th><th>پخش واقعی</th><th>زمان تکمیل (مشکوک)</th><th>وضعیت</th><th>وضعیت فعلی درس</th>
    </tr></thead><tbody>
    <?php foreach ($page['rows'] as $r): ?>
        <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" data-col="sel"></td>
            <td><a class="fw-b" href="<?= url('/admin/reports/user/' . $r['user_id']) ?>"><?= person_name($r, 'user_id') ?></a><div class="small faint ltr"><?= e((string)$r['mobile']) ?></div><?php if ((int)$r['user_open'] > 1): ?><span class="badge badge-danger"><?= fa($r['user_open']) ?> درس مشکوک</span><?php endif; ?></td>
            <td><span class="badge badge-gray"><?= e(label('segment_one', $r['segment'])) ?></span></td>
            <td class="small"><a href="<?= url('/admin/reports/lesson/' . $r['lesson_id']) ?>"><?= e($r['lesson_title']) ?></a><div class="faint"><?= e($r['course_title']) ?></div></td>
            <td class="small"><span class="badge badge-<?= $r['severity'] === 'high' ? 'danger' : 'warning' ?>"><?= $r['severity'] === 'high' ? 'قطعی' : 'احتمالی' ?></span> <?= e(CA::REASONS[$r['reason']] ?? $r['reason']) ?></td>
            <td class="num small"><?= CA::minutes((int)$r['expected_sec']) ?></td>
            <td class="num small<?= $r['reason'] === 'impossible_time' ? ' fw-b' : '' ?>"<?= $r['reason'] === 'impossible_time' ? ' style="color:var(--danger)"' : '' ?>><?= $r['elapsed_sec'] !== null ? CA::minutes((int)$r['elapsed_sec']) : '—' ?></td>
            <td class="num small"><?= (int)$r['watched_sec'] ? CA::minutes((int)$r['watched_sec']) : ($r['content_type'] === 'video' ? 'در صفحه: ' . CA::minutes((int)$r['spent_sec']) : '<span class="faint">ثبت نشده</span>') ?></td>
            <td class="num small"><?= jdatetime($r['completed_at']) ?></td>
            <td class="small"><span class="badge badge-<?= ['open' => 'warning', 'reset' => 'danger', 'rewatched' => 'success', 'dismissed' => 'gray'][$r['status']] ?? 'gray' ?>"><?= e(CA::STATUSES[$r['status']] ?? $r['status']) ?></span><?php if ($r['status'] === 'reset'): ?><div class="faint"><?= icon('bell') ?> پیام لغو رفت — <?= jdatetime($r['reviewed_at']) ?></div><?php elseif ($r['notified_at']): ?><div class="faint"><?= icon('bell') ?> هشدار داده شد</div><?php endif; ?></td>
            <td class="small"><?php if ($r['lp_now'] === 'completed'): ?><span class="badge badge-<?= $r['status'] === 'reset' || $r['status'] === 'open' ? 'warning' : 'success' ?>">تکمیل‌شده</span>
                <?php elseif ($r['lp_now']): ?><span class="badge badge-gray">تکمیل لغو شد</span><div class="faint">دیده‌شده: <?= fa((int)$r['lp_pct']) ?>٪</div>
                <?php else: ?><span class="faint">—</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$page['rows']): ?><tr><td colspan="11"><div class="empty"><?= icon('circle-check') ?><div>موردی نیست</div></div></td></tr><?php endif; ?>
    </tbody></table></div>
</form>
<?= paginate_links($page) ?>
<div class="small muted mt-2">
    <b>چطور تشخیص داده می‌شود؟</b>
    «قطعی»: فایل صوتی/ویدیویی زودتر از نصف طولش (حتی با سرعت ۲×) تکمیل شده، یا پخش واقعی کمتر از <?= fa(CA::minWatchedPct()) ?>٪ فایل بوده است (جلو زدن حساب نمی‌شود).
    «احتمالی»: ویدیو با حضور بسیار کم در صفحه، یا متن طولانی که در کمتر از یک‌پنجم زمان مطالعه‌اش تکمیل شده است.
    درس‌های کوتاه‌تر از ۱٫۵ دقیقه، PDF و لینک‌های آپارات/یوتیوب بررسی نمی‌شوند.
</div>
