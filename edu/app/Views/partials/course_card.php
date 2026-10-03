<?php
/** @var array $c course row (+ optional enrollment fields: status, progress_pct, due_at, training_type) */
$cover = image_url($c['image_file_id'] ?? null, 640);
$color = $c['cat_color'] ?? $c['category_color'] ?? '#6366f1';
$ic = $c['cat_icon'] ?? $c['category_icon'] ?? 'book-open';
$cid = (int)($c['course_id'] ?? $c['id']);
$hasEn = isset($c['progress_pct']) && isset($c['status']) && isset($c['course_id']);
?>
<div class="card course-card">
    <a class="course-cover" href="<?= url('/learn/course/' . $cid) ?>" style="background:linear-gradient(135deg, <?= e($color) ?>, color-mix(in srgb, <?= e($color) ?> 55%, #0f172a))">
        <?php if ($cover): ?><img class="cc-bg" src="<?= e($cover) ?>" alt="" aria-hidden="true" loading="lazy"><img class="cc-fg" src="<?= e($cover) ?>" alt="<?= e($c['title']) ?>" loading="lazy"><?php else: ?><?= icon($ic) ?><?php endif; ?>
    </a>
    <div class="course-body">
        <div class="course-tags">
            <?php if (!empty($c['cat_name'] ?? $c['category_name'] ?? '')): ?><span class="small grow" style="color:<?= e($color) ?>;font-weight:700"><?= e($c['cat_name'] ?? $c['category_name']) ?></span><?php else: ?><span class="grow"></span><?php endif; ?>
            <?php if (!empty($c['last_lesson_at']) && strtotime((string)$c['last_lesson_at']) >= time() - 14 * 86400): ?><span class="badge badge-success" title="درس جدید در <?= e(jdate($c['last_lesson_at'])) ?>"><?= icon('sparkles') ?> درس جدید</span><?php endif; ?>
            <?php if (!empty($c['training_type'])): ?><span class="badge badge-<?= $c['training_type'] === 'mandatory' ? 'danger' : 'gray' ?>"><?= e(label('training_type', $c['training_type'])) ?></span><?php endif; ?>
            <?php if ($hasEn): ?><?= status_badge($c['status']) ?><?php endif; ?>
        </div>
        <h3><a href="<?= url('/learn/course/' . $cid) ?>"><?= e($c['title']) ?></a></h3>
        <?php if (!empty($c['summary'])): ?><p class="small muted mb-0"><?= e(str_limit($c['summary'], 90)) ?></p><?php endif; ?>
        <?php if (!empty($c['_why'])): ?><span class="badge badge-purple"><?= icon('sparkles') ?> <?= e($c['_why']) ?></span><?php endif; ?>
        <div class="course-meta">
            <?php if (!empty($c['duration_minutes'])): ?><span><?= icon('clock') ?> <?= fa($c['duration_minutes']) ?> دقیقه</span><?php endif; ?>
            <?php if (!empty($c['due_at'])): ?><span class="<?= $c['due_at'] < now() && ($c['status'] ?? '') !== 'completed' ? 'fw-b' : '' ?>" style="<?= $c['due_at'] < now() && ($c['status'] ?? '') !== 'completed' ? 'color:var(--danger)' : '' ?>"><?= icon('calendar') ?> مهلت <?= jdate($c['due_at']) ?></span><?php endif; ?>
        </div>
        <?php if ($hasEn): ?>
            <div class="course-foot"><div class="grow"><?= progress_bar((float)$c['progress_pct']) ?></div><b class="small"><?= fa((int)$c['progress_pct']) ?>٪</b></div>
        <?php endif; ?>
    </div>
</div>
