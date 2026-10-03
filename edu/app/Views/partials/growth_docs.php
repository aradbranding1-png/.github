<?php /** @var array $docs rows from GrowthDocs */ ?>
<?php if ($docs): ?><div class="tg-docs">
<?php foreach ($docs as $dc): $du = file_url($dc["file_id"]); ?>
    <a class="tg-doc" href="<?= e($du) ?>" target="_blank" rel="noopener" title="<?= e(($dc['label'] ?? '') . ' — ' . $dc['original_name']) ?>">
        <?php if ($dc['kind'] === 'image'): ?><img src="<?= e(image_url($dc['file_id'], 320)) ?>" alt="" loading="lazy"><?php else: ?><?= icon(['video' => 'video', 'pdf' => 'file-text', 'audio' => 'music'][$dc['kind']] ?? 'file') ?><?php endif; ?>
        <span><b><?= e($dc['label'] ?: 'مدرک') ?></b><br><span class="faint"><?= e(str_limit($dc['original_name'], 28)) ?> · <?= human_size((int)$dc['size']) ?></span></span>
    </a>
<?php endforeach; ?>
</div><?php else: ?><span class="small faint">مدرکی پیوست نشده</span><?php endif; ?>
