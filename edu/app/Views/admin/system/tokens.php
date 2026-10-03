<div class="page-head"><div><h1>کلیدهای API</h1><div class="sub">اتصال سامانه‌های دیگر (CRM، منابع انسانی، اپلیکیشن موبایل، my) به API نسخه ۱ — مستندات: docs/API.md</div></div></div>
<?php if ($new): ?><div class="alert alert-success"><?= icon('key-round') ?><div><b>کلید جدید ساخته شد. این کلید فقط همین یک بار نمایش داده می‌شود:</b><div class="flex mt-1"><code class="ltr grow" style="background:#fff;padding:.4rem .6rem;border-radius:8px;color:#065f46"><?= e($new) ?></code><button class="btn btn-sm btn-outline" data-copy="<?= e($new) ?>"><?= icon('copy') ?></button></div></div></div><?php endif; ?>
<div class="grid g-main">
    <div class="card flush"><div class="table-wrap"><table class="table"><thead><tr><th>نام</th><th>کلید</th><th>دسترسی‌ها</th><th>آخرین استفاده</th><th>انقضا</th><th>وضعیت</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $t): $active = !$t['revoked_at'] && (!$t['expires_at'] || $t['expires_at'] > now()); ?><tr style="<?= $active ? '' : 'opacity:.5' ?>"><td class="fw-b"><?= e($t['name']) ?><div class="small faint"><?= e(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''))) ?> · <?= jdate($t['created_at']) ?></div></td><td class="ltr small">ae_…<?= e($t['token_hint']) ?></td>
    <td class="small"><?php foreach (array_filter(explode(',', $t['abilities'])) as $a): ?><span class="badge badge-gray"><?= e($abilities[$a] ?? $a) ?></span> <?php endforeach; ?></td>
    <td class="small"><?= $t['last_used_at'] ? time_ago($t['last_used_at']) : '—' ?></td><td class="small"><?= $t['expires_at'] ? jdate($t['expires_at']) : 'بدون انقضا' ?></td><td><?= $active ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>' ?></td>
    <td class="actions"><?php if ($active && can('api_tokens.delete')): ?><form class="inline" method="post" action="<?= url('/admin/api-tokens/' . $t['id'] . '/revoke') ?>" data-confirm="کلید ابطال شود؟"><?= csrf_field() ?><button class="btn btn-xs btn-ghost" style="color:var(--danger)"><?= icon('x') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="empty"><?= icon('key-round') ?><div>کلیدی ساخته نشده</div></div></td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php if (can('api_tokens.create')): ?>
    <form class="card" method="post" action="<?= url('/admin/api-tokens') ?>"><?= csrf_field() ?>
        <h3><?= icon('plus') ?> کلید جدید</h3>
        <div class="field"><label>نام (سامانه مصرف‌کننده)</label><input type="text" name="name" required placeholder="مثلاً: CRM آراد"></div>
        <div class="field"><label>دسترسی‌ها</label><?php foreach ($abilities as $k => $t): ?><label class="check mb-1"><input type="checkbox" name="abilities[]" value="<?= $k ?>"> <?= e($t) ?> <code class="ltr small faint"><?= $k ?></code></label><?php endforeach; ?></div>
        <div class="field"><label>اعتبار (روز، ۰ = بدون انقضا)</label><input type="number" name="days" value="365"></div>
        <button class="btn btn-primary w-100"><?= icon('key-round') ?> ساخت کلید</button>
    </form>
    <?php endif; ?>
</div>
