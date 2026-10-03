<?php
$gNames = array_column($groups, 'name', 'id');
$myGroups = array_filter($groups, fn($g) => in_array((int)$g['id'], $uGroups, true));
$myOrgs = array_filter($orgs, fn($o) => in_array((int)$o['id'], $uOrgs, true));
$myRoles = array_filter($roles, fn($r) => in_array((int)$r['id'], $uRoles, true));
$done = count(array_filter($en, fn($e) => $e['status'] === 'completed'));
$avg = $en ? array_sum(array_column($en, 'progress_pct')) / count($en) : 0;
?>
<div class="crumbs"><a href="<?= url('/admin/users') ?>">کاربران</a></div>
<?php if ($u['status'] === 'pending'): ?>
<div class="alert alert-warning approve-bar"><?= icon('user-check') ?>
    <div class="grow"><b>این حساب در انتظار تأیید است.</b> تا زمان تأیید، کاربر نمی‌تواند وارد سامانه شود و هیچ دوره‌ای برایش فعال نیست.</div>
    <?php if (can('users.approve')): ?>
        <form method="post" action="<?= url('/admin/users/' . $u['id'] . '/approve') ?>" class="flex"><?= csrf_field() ?>
            <button class="btn btn-success btn-sm" name="decision" value="approve"><?= icon('check') ?> تأیید حساب</button>
            <button class="btn btn-outline btn-sm" name="decision" value="reject" data-confirm="درخواست این حساب رد و حساب غیرفعال شود؟"><?= icon('x') ?> رد</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="card mb-3">
    <div class="flex between flex-wrap gap-2">
        <div class="flex gap-2">
            <?= avatar_html($u, 'xl') ?>
            <div>
                <h1 class="mb-0"><?= user_name_html($u) ?></h1>
                <div class="muted small ltr" style="text-align:right"><?= e($u['mobile']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?> · #<?= (int)$u['id'] ?></div>
                <div class="flex flex-wrap mt-1">
                    <?= status_badge($u['status']) ?><span class="badge badge-gray"><?= e(label('segment_one', $u['segment'])) ?></span>
                    <?php if ($u['is_root']): ?><span class="badge badge-root"><?= icon('crown') ?> مدیر کل</span><?php endif; ?>
                    <?php foreach ($myRoles as $r): ?><span class="badge badge-<?= e($r['color']) ?>"><?= e($r['name']) ?></span><?php endforeach; ?>
                    <?php if ($u['my_user_id']): ?><span class="badge badge-success"><?= icon('fingerprint') ?> متصل به my (<?= e($u['my_user_id']) ?>)</span><?php else: ?><span class="badge badge-gray">غیرمتصل به my</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="btn-group">
            <?php if (can_any(['reports.report', 'users.report'])): ?><a class="btn btn-primary" href="<?= url('/admin/reports/user/' . $u['id']) ?>"><?= icon('chart-column') ?> گزارش کامل فعالیت</a><?php endif; ?>
            <?php if (can('growth.view') && App\Services\TraderGrowth::participates($u)): $tgS = App\Services\TraderGrowth::stageByNo((int)($u['tg_stage'] ?? 0)); ?><a class="btn btn-outline" href="<?= url('/admin/growth/user/' . $u['id']) ?>"><?= icon('mountain') ?> نظام رشد<?= $tgS ? ': مرحله ' . fa($tgS['no']) : '' ?></a><?php endif; ?>
            <?php if (can('users.edit') && $canManage): ?><a class="btn btn-outline" href="<?= url('/admin/users/' . $u['id'] . '/edit') ?>"><?= icon('pencil') ?> ویرایش</a><?php endif; ?>
            <?php if (can('roles.view')): ?><a class="btn btn-outline" href="<?= url('/admin/users/' . $u['id'] . '/access') ?>"><?= icon('key-round') ?> دسترسی‌ها</a><?php endif; ?>
            <?php if (is_root() && !$u['is_root'] && $u['status'] === 'active'): ?>
                <form class="inline" method="post" action="<?= url('/admin/users/' . $u['id'] . '/impersonate') ?>" data-confirm="با حساب این کاربر وارد می‌شوید. این اقدام ثبت می‌شود. ادامه؟"><?= csrf_field() ?><button class="btn btn-warning"><?= icon('venetian-mask') ?> ورود به حساب کاربر</button></form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid g-4 mb-3">
    <div class="card stat tone-primary"><div class="bubble"><?= icon('book-open') ?></div><div><div class="v"><?= fa(count($en)) ?></div><div class="l">دوره</div></div></div>
    <div class="card stat tone-success"><div class="bubble"><?= icon('circle-check') ?></div><div><div class="v"><?= fa($done) ?></div><div class="l">تکمیل‌شده</div></div></div>
    <div class="card stat tone-info"><div class="bubble"><?= icon('gauge') ?></div><div><div class="v"><?= fa(round($avg)) ?>٪</div><div class="l">میانگین پیشرفت</div></div></div>
    <div class="card stat tone-warning"><div class="bubble"><?= icon('log-in') ?></div><div><div class="v"><?= nf($u['login_count']) ?></div><div class="l">ورود · آخرین: <?= $u['last_login_at'] ? time_ago($u['last_login_at']) : 'هرگز' ?></div></div></div>
</div>

<div class="grid g-main">
    <div class="stack">
        <?php if (can('credits.view')): $C = App\Services\Credit::class; $B = $C::balances((int)$u['id']); $ledger = $C::ledger((int)$u['id'], 20); ?>
        <div class="card" id="credits">
            <h3><?= icon('zap') ?> اعتبارها و اشتراک‌ها</h3>
            <div class="svc-credits">
                <div class="svc-c tone-primary"><span class="ic"><?= icon('graduation-cap') ?></span><div><span class="l">اعتبار دوره‌ها</span><b><?= $C::format($B['course']) ?></b></div></div>
                <div class="svc-c tone-purple"><span class="ic"><?= icon('video') ?></span><div><span class="l">اعتبار وبینار</span><b><?= $C::format($B['webinar']) ?></b></div></div>
                <div class="svc-c tone-warning"><span class="ic"><?= icon('briefcase') ?></span><div><span class="l">اعتبار کارگاه آنلاین</span><b><?= fa($B['workshop']) ?> عدد</b></div></div>
                <div class="svc-c tone-info"><span class="ic"><?= icon('users') ?></span><div><span class="l">اشتراک میتینگ</span><?php if ($B['meeting_active']): ?><b>تا <?= jdate($B['meeting_until']) ?></b><small><?= fa($B['meeting_days']) ?> روز مانده</small><?php else: ?><b>غیرفعال</b><?php if ($B['meeting_until']): ?><small>پایان: <?= jdate($B['meeting_until']) ?></small><?php endif; ?><?php endif; ?></div></div>
                <?php if ($B['account_until']): ?><div class="svc-c tone-success"><span class="ic"><?= icon('badge-check') ?></span><div><span class="l">اکانت سامانه آموزش</span><?php if ($B['account_active']): ?><b>تا <?= jdate($B['account_until']) ?></b><small><?= fa($B['account_days']) ?> روز مانده</small><?php else: ?><b>منقضی</b><small>پایان: <?= jdate($B['account_until']) ?></small><?php endif; ?></div></div><?php endif; ?>
            </div>
            <?php if (can('credits.edit')): ?>
            <div class="tabs mt-2" data-credit-tabs>
                <a href="#" class="active" data-ct="course"><?= icon('graduation-cap') ?> دوره</a><a href="#" data-ct="webinar"><?= icon('video') ?> وبینار</a><a href="#" data-ct="workshop"><?= icon('briefcase') ?> کارگاه</a><a href="#" data-ct="meeting"><?= icon('users') ?> میتینگ</a>
            </div>
            <?php foreach (['course' => 'اعتبار دوره‌ها (مشاهده درس‌ها)', 'webinar' => 'اعتبار وبینار (ساعت)'] as $ct => $lbl): ?>
            <form method="post" action="<?= url('/admin/users/' . $u['id'] . '/credits') ?>" class="credit-form<?= $ct === 'course' ? '' : ' hide' ?>" data-ct-form="<?= $ct ?>"><?= csrf_field() ?><input type="hidden" name="ctype" value="<?= $ct ?>">
                <div class="small faint mb-1"><?= $lbl ?></div>
                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
                    <div class="field"><label>عملیات</label><select name="op"><option value="add">افزودن (شارژ)</option><option value="deduct">کسر</option></select></div>
                    <div class="field"><label>ساعت</label><input type="number" name="hours" min="0" placeholder="۰"></div>
                    <div class="field"><label>دقیقه</label><input type="number" name="minutes" min="0" placeholder="۰"></div>
                    <div class="field"><label>توضیح (مثلاً شماره فاکتور)</label><input type="text" name="note" maxlength="250"></div>
                </div>
                <button class="btn btn-primary btn-sm"><?= icon('save') ?> ثبت</button>
            </form>
            <?php endforeach; ?>
            <form method="post" action="<?= url('/admin/users/' . $u['id'] . '/credits') ?>" class="credit-form hide" data-ct-form="workshop"><?= csrf_field() ?><input type="hidden" name="ctype" value="workshop">
                <div class="small faint mb-1">اعتبار کارگاه تجاری آنلاین (تعداد)</div>
                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
                    <div class="field"><label>عملیات</label><select name="op"><option value="add">افزودن (شارژ)</option><option value="deduct">کسر</option></select></div>
                    <div class="field"><label>تعداد کارگاه</label><input type="number" name="count" min="0" placeholder="۰"></div>
                    <div class="field"><label>توضیح</label><input type="text" name="note" maxlength="250"></div>
                </div>
                <button class="btn btn-primary btn-sm"><?= icon('save') ?> ثبت</button>
            </form>
            <form method="post" action="<?= url('/admin/users/' . $u['id'] . '/credits') ?>" class="credit-form hide" data-ct-form="meeting"><?= csrf_field() ?><input type="hidden" name="ctype" value="meeting">
                <div class="small faint mb-1">اشتراک میتینگ آنلاین — اگر اشتراک فعال باشد، از تاریخ پایان فعلی تمدید می‌شود.</div>
                <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr))">
                    <div class="field"><label>سال</label><select name="years"><option value="0">—</option><option value="1" selected>۱ ساله</option><option value="2">۲ ساله</option><option value="3">۳ ساله</option></select></div>
                    <div class="field"><label>ماه (دلخواه)</label><input type="number" name="months" min="0" placeholder="۰"></div>
                    <div class="field"><label>روز (دلخواه)</label><input type="number" name="days" min="0" placeholder="۰"></div>
                    <div class="field"><label>شروع از (شمسی)</label><input type="text" class="ltr" name="from" placeholder="<?= e(App\Core\Jalali::format('Y/m/d', time())) ?>"></div>
                    <div class="field"><label>توضیح</label><input type="text" name="note" maxlength="250"></div>
                </div>
                <div class="flex"><button class="btn btn-primary btn-sm" name="op" value="add"><?= icon('save') ?> ثبت / تمدید اشتراک</button>
                <?php if ($B['meeting_active']): ?><button class="btn btn-outline btn-sm" name="op" value="end" data-confirm="اشتراک میتینگ این کاربر همین حالا پایان یابد؟"><?= icon('x') ?> پایان اشتراک</button><?php endif; ?></div>
            </form>
            <?php endif; ?>
            <?php if ($ledger): ?>
            <div class="table-wrap mt-2"><table class="table small">
                <thead><tr><th>تاریخ</th><th>نوع</th><th>شرح</th><th>تغییر</th><th>مانده</th></tr></thead><tbody>
                <?php foreach ($ledger as $r): $ct = $r['credit_type'] ?? 'course'; ?><tr>
                    <td class="nowrap"><?= jdatetime($r['created_at']) ?></td>
                    <td><span class="badge badge-gray"><?= e(['course' => 'دوره', 'webinar' => 'وبینار', 'workshop' => 'کارگاه', 'meeting' => 'میتینگ', 'account' => 'اکانت'][$ct] ?? $ct) ?></span></td>
                    <td><?= $r['kind'] === 'consume' ? ($r['lesson_title'] ? 'درس «' . e($r['lesson_title']) . '»' : 'ثبت‌نام «' . e($r['event_title'] ?? $r['note']) . '»') : (['charge' => 'شارژ', 'deduct' => 'کسر', 'refund' => 'بازگشت'][$r['kind']] ?? $r['kind']) . ($r['note'] ? ' — ' . e($r['note']) : '') . ($r['by_first'] ? ' <span class="faint">(' . e($r['by_first'] . ' ' . $r['by_last']) . ')</span>' : '') ?></td>
                    <td class="num"><span class="badge badge-<?= (int)$r['delta'] > 0 ? 'success' : 'warning' ?>"><?= (int)$r['delta'] > 0 ? '+' : '−' ?><?= e($C::amount($ct, abs((int)$r['delta']))) ?></span></td>
                    <td class="num small"><?= e($C::amount($ct, (int)$r['balance_after'])) ?></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card flush"><div class="card-head"><h3><?= icon('graduation-cap') ?> دوره‌ها</h3></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>دوره</th><th>نوع</th><th>منبع</th><th>پیشرفت</th><th>وضعیت</th><th>مهلت</th></tr></thead><tbody>
            <?php foreach ($en as $e): ?><tr><td><?= e($e['title']) ?></td><td><?= e(label('training_type', $e['training_type'])) ?></td><td class="small"><?= e(['self' => 'ثبت‌نام خود', 'assignment' => 'تخصیص', 'rule' => 'قانون خودکار', 'path' => 'مسیر آموزشی', 'api' => 'API'][$e['source']] ?? $e['source']) ?></td><td style="min-width:120px"><div class="flex"><div class="grow"><?= progress_bar((float)$e['progress_pct']) ?></div><span class="small"><?= fa((int)$e['progress_pct']) ?>٪</span></div></td><td><?= status_badge($e['status']) ?></td><td class="num small"><?= $e['due_at'] ? jdate($e['due_at']) : '—' ?></td></tr><?php endforeach; ?>
            <?php if (!$en): ?><tr><td colspan="6" class="faint text-center">دوره‌ای ندارد</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php $reasons = ['wrong_password' => 'رمز اشتباه', 'not_found' => 'حساب پیدا نشد', 'no_password' => 'رمز تعیین نشده', 'locked' => 'مسدود موقت', 'pending' => 'در انتظار تأیید', 'inactive' => 'غیرفعال']; ?>
        <div class="card" id="login-check"><div class="card-head"><h3><?= icon('key-round') ?> بررسی مشکل ورود</h3></div>
            <?php if ($dupAcc): ?><div class="alert alert-warning"><?= icon('triangle-alert') ?> <div>حساب دیگری با همین موبایل وجود دارد: <a href="<?= url('/admin/users/' . (int)$dupAcc['id']) ?>"><?= e(full_name($dupAcc)) ?> (#<?= (int)$dupAcc['id'] ?>)</a>. اگر رمز را روی یکی عوض کنید و کاربر با دیگری وارد شود، ورود ناموفق می‌شود؛ بهتر است این دو حساب ادغام شوند.</div></div><?php endif; ?>
            <?php if ($locked): ?><div class="alert alert-danger"><?= icon('lock') ?> <div class="grow">ورود این کاربر به خاطر تلاش‌های ناموفق حدود <?= fa($locked) ?> دقیقه مسدود است — حتی با رمز درست.</div>
                <?php if ($canManage): ?><form method="post" action="<?= url('/admin/users/' . $u['id'] . '/unlock') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-danger"><?= icon('unlock') ?> رفع مسدودی ورود</button></form><?php endif; ?></div><?php endif; ?>
            <?php if ($canManage): ?>
            <p class="muted small">شناسه و رمزی را که کاربر می‌گوید وارد می‌کند اینجا بزنید تا دقیقاً مثل صفحه ورود بررسی شود و علت مشکل نمایش داده شود. چیزی تغییر نمی‌کند.</p>
            <form method="post" action="<?= url('/admin/users/' . $u['id'] . '/login-check') ?>" class="form-grid" autocomplete="off"><?= csrf_field() ?>
                <div class="field"><label>موبایل / ایمیل / نام کاربری</label><input class="ltr" type="text" name="identifier" value="<?= e((string)$u['mobile']) ?>" autocomplete="off"></div>
                <div class="field"><label>رمزی که کاربر وارد می‌کند (اختیاری)</label><input class="ltr" type="text" name="password" autocomplete="off" spellcheck="false"></div>
                <div class="field full flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm"><?= icon('shield-check') ?> بررسی ورود</button>
                    <?php if (!$locked): ?><button class="btn btn-outline btn-sm" formaction="<?= url('/admin/users/' . $u['id'] . '/unlock') ?>"><?= icon('unlock') ?> رفع مسدودی ورود</button><?php endif; ?></div>
            </form>
            <?php endif; ?>
        </div>
        <div class="card flush"><div class="card-head"><h3><?= icon('log-in') ?> آخرین ورودها</h3></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>زمان</th><th>روش</th><th>شناسه واردشده</th><th>IP</th><th>نتیجه</th></tr></thead><tbody>
            <?php foreach ($logins as $l): ?><tr><td class="num"><?= jdatetime($l['created_at']) ?></td><td><?= e(['password' => 'رمز عبور', 'sso' => 'SSO (my)', 'register' => 'ثبت‌نام', 'impersonate' => 'مدیر کل'][$l['method']] ?? $l['method']) ?></td><td class="ltr small"><?= e((string)($l['identifier'] ?? '')) ?: '—' ?></td><td class="ltr num small"><?= e($l['ip']) ?></td><td><?= $l['success'] ? status_badge('success') : '<span class="badge badge-danger">' . e($reasons[$l['reason'] ?? ''] ?? 'ناموفق') . '</span>' ?></td></tr><?php endforeach; ?>
            <?php if (!$logins): ?><tr><td colspan="5" class="faint text-center">ورودی ثبت نشده</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <?php if ($imps): ?>
        <div class="card"><h3><?= icon('venetian-mask') ?> سوابق ورود مدیر کل به این حساب</h3>
            <?php foreach ($imps as $i): ?><div class="list-item small"><div class="grow"><?= e(full_name($i)) ?> · <?= jdatetime($i['started_at']) ?></div><span>مدت: <?= $i['duration_sec'] !== null ? fa(round($i['duration_sec'] / 60, 1)) . ' دقیقه' : 'در جریان' ?></span><span class="ltr faint"><?= e($i['ip']) ?></span></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="stack">
        <div class="card">
            <h3><?= icon('info') ?> مشخصات</h3>
            <dl class="kv">
                <dt>عنوان شغلی</dt><dd><?= e($u['job_title'] ?: '—') ?></dd>
                <dt>مسئول آموزش</dt><dd><?= $supervisor ? user_name_html($supervisor) : '—' ?></dd>
                <dt>تاریخ عضویت</dt><dd><?= jdate($u['created_at']) ?></dd>
                <dt>اولین ورود</dt><dd><?= jdatetime($u['first_login_at']) ?></dd>
                <dt>منبع تصویر</dt><dd><?= e(label('avatar_source', $u['avatar_source'])) ?></dd>
            </dl>
        </div>
        <div class="card"><h3><?= icon('layers') ?> گروه‌ها</h3><div class="flex flex-wrap"><?php foreach ($myGroups as $g): ?><span class="badge badge-gray"><?= icon($g['icon']) ?> <?= e($g['name']) ?></span><?php endforeach; ?><?= $myGroups ? '' : '<span class="faint small">—</span>' ?></div>
            <?php if ($uLevels): ?><div class="mt-1 small"><?php foreach ($levels as $l) if (($uLevels[$l['group_id']] ?? null) == $l['id']): ?><span class="badge badge-info"><?= e($l['group_name']) ?>: <?= e($l['name']) ?></span> <?php endif; ?></div><?php endif; ?>
        </div>
        <div class="card"><h3><?= icon('network') ?> ساختار سازمانی</h3><div class="flex flex-wrap"><?php foreach ($myOrgs as $o): ?><span class="badge badge-info"><?= e($o['type_name']) ?>: <?= e($o['name']) ?></span><?php endforeach; ?><?= $myOrgs ? '' : '<span class="faint small">—</span>' ?></div></div>
        <?php if ($canManage && !$u['is_root'] && can('users.edit')): ?>
        <div class="card">
            <h3><?= icon('settings') ?> عملیات</h3>
            <div class="btn-group">
                <form method="post" action="<?= url('/admin/users/' . $u['id'] . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-outline' : 'btn-success' ?>"><?= icon($u['status'] === 'active' ? 'user-x' : 'user-check') ?> <?= $u['status'] === 'active' ? 'غیرفعال‌سازی' : ($u['status'] === 'pending' ? 'تأیید و فعال‌سازی' : 'فعال‌سازی') ?></button></form>
                <?php if ($u['my_user_id']): ?><form method="post" action="<?= url('/admin/users/' . $u['id'] . '/unlink-my') ?>" data-confirm="اتصال این کاربر به my قطع شود؟"><?= csrf_field() ?><button class="btn btn-sm btn-outline"><?= icon('link') ?> قطع اتصال my</button></form><?php endif; ?>
                <?php if (can('users.delete')): ?><form method="post" action="<?= url('/admin/users/' . $u['id'] . '/delete') ?>" data-confirm="کاربر حذف شود؟ سوابق آموزشی برای گزارش حفظ می‌شود."><?= csrf_field() ?><button class="btn btn-sm btn-danger"><?= icon('trash-2') ?> حذف</button></form><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
