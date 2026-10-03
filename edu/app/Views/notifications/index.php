<div class="page-head"><div><h1>اعلان‌ها</h1><div class="sub">آموزش‌های جدید، مهلت‌ها، نتایج آزمون و بازخورد تمرین‌ها</div></div>
<form method="post" action="<?= url('/notifications/read-all') ?>"><?= csrf_field() ?><button class="btn btn-outline btn-sm"><?= icon('check') ?> علامت‌گذاری همه به‌عنوان خوانده‌شده</button></form></div>
<div class="card">
    <?php if (!$page['rows']): ?><div class="empty"><?= icon('bell') ?><h3>اعلانی ندارید</h3></div><?php endif; ?>
    <?php foreach ($page['rows'] as $n): $t = App\Core\Notify::TYPES[$n['type']] ?? App\Core\Notify::TYPES['system']; ?>
        <div class="list-item" style="<?= $n['read_at'] ? '' : 'background:var(--primary-soft);border-radius:12px;padding-inline:.6rem' ?>">
            <span class="ico" style="background:var(--<?= $t[2] === 'gray' ? 'gray' : $t[2] ?>-soft);color:var(--<?= $t[2] === 'gray' ? 'muted' : $t[2] ?>)"><?= icon($t[1]) ?></span>
            <div class="grow">
                <div class="flex between"><b><?= e($n['title']) ?></b><span class="small faint"><?= time_ago($n['created_at']) ?></span></div>
                <?php if ($n['body']): ?><div class="small muted"><?= nl2br(e($n['body'])) ?></div><?php endif; ?>
                <span class="badge badge-<?= $t[2] ?>"><?= e($t[0]) ?></span>
            </div>
            <?php if ($n['link']): ?><a class="btn btn-sm btn-outline" href="<?= e($n['link']) ?>">مشاهده</a><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?= paginate_links($page) ?>
</div>
