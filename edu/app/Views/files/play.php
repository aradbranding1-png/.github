<?php $src = url('/file/' . $f['uuid']); ?>
<main class="play-page">
    <div class="play-head">
        <span class="play-ico"><?= icon($isVideo ? 'video' : 'music') ?></span>
        <div class="grow"><h1><?= e($f['original_name']) ?></h1><div class="small faint"><?= $isVideo ? 'ویدیو' : 'فایل صوتی' ?><?= !empty($f['size']) ? ' · ' . e(human_size((int)$f['size'])) : '' ?></div></div>
        <?php if ($canDownload): ?><a class="btn btn-outline" href="<?= url('/file/' . $f['uuid'], ['dl' => 1]) ?>"><?= icon('download') ?> دانلود</a><?php endif; ?>
    </div>
    <?php if ($isVideo): ?>
        <div class="media-box"><video controls autoplay playsinline preload="metadata" src="<?= e($src) ?>"<?= $canDownload ? '' : ' controlsList="nodownload"' ?>></video></div>
    <?php else: ?>
        <div class="card"><audio data-player data-inline preload="metadata" data-title="<?= e($f['original_name']) ?>"<?= $staff ? ' data-speeds="1,1.5,2,2.5,3" data-speed-key="ap-speed-review"' : '' ?> src="<?= e($src) ?>"></audio></div>
    <?php endif; ?>
</main>
