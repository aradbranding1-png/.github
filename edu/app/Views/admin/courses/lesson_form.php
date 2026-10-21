<?php
$isEdit = $l !== null;
$v = fn($k, $d = '') => old($k, $l[$k] ?? $d);
$ct = $v('content_type', 'video');
$ctIcons = ['text' => 'file-text', 'video' => 'video', 'audio' => 'music', 'image' => 'image', 'pdf' => 'file-text', 'file' => 'file', 'link' => 'link'];
?>
<div class="crumbs mb-1"><a href="<?= url('/admin/courses') ?>">دوره‌ها</a> / <a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a> / <?= $isEdit ? 'ویرایش درس' : 'درس جدید' ?></div>
<div class="card chero" style="--cc:<?= e(($c['category_color'] ?? '') ?: '#6366f1') ?>">
    <div class="chero-top">
        <div class="chero-cover"><?= icon($isEdit ? ($ctIcons[$ct] ?? 'book-open') : 'plus') ?></div>
        <div class="chero-main">
            <h1><?= $isEdit ? e($l['title']) : 'درس جدید' ?></h1>
            <div class="chero-tags">
                <?php if ($isEdit): ?><?= status_badge($l['status']) ?><?php endif; ?>
                <a class="chero-cat" href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a>
                <?php if ($isEdit && $l['section_title']): ?><span class="chero-meta"><?= icon('layers') ?> <?= e($l['section_title']) ?></span><?php endif; ?>
                <?php if ($isEdit && $l['duration_minutes']): ?><span class="chero-meta"><?= icon('clock') ?> <?= fa($l['duration_minutes']) ?> دقیقه</span><?php endif; ?>
            </div>
        </div>
        <?php if ($isEdit): ?>
        <div class="chero-acts">
            <a class="btn btn-outline" href="<?= url('/learn/lesson/' . $l['id']) ?>" target="_blank"><?= icon('eye') ?> نمایش برای فراگیر</a>
            <?php if (can('reports.report') || can('courses.report')): ?><a class="btn btn-outline" href="<?= url('/admin/reports/lesson/' . $l['id']) ?>"><?= icon('chart-column') ?> گزارش</a><?php endif; ?>
            <a class="btn btn-ghost" href="#lesson-extras"><?= icon('clipboard-check') ?> آزمون و تمرین (<?= fa(count($lessonExams) + count($lessonExercises)) ?>)</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<form method="post" enctype="multipart/form-data" action="<?= url($isEdit ? '/admin/lessons/' . $l['id'] : '/admin/courses/' . $c['id'] . '/lessons') ?>">
    <?= csrf_field() ?>
    <div class="grid g-main">
        <div class="exf">
            <div class="exf-sec">
                <div class="exf-h"><span>۱</span> اطلاعات درس</div>
                <div class="form-grid">
                    <div class="field full"><label>عنوان درس <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required placeholder="مثلاً: شناخت بازار و تأمین‌کنندگان"></div>
                    <div class="field mb-0"><label>سرفصل (بخش)</label><input type="text" name="section_title" value="<?= e($v('section_title')) ?>" list="sections" placeholder="مثلاً: فصل اول — مبانی"><datalist id="sections"><?php foreach ($sections as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
                    <div class="field mb-0"><label>مدت (دقیقه)</label><input type="number" name="duration_minutes" value="<?= e($v('duration_minutes')) ?>" min="0"></div>
                </div>
            </div>

            <div class="exf-sec">
                <div class="exf-h"><span>۲</span> محتوای اصلی درس <small>نوع محتوا را انتخاب کنید</small></div>
                <div class="exf-fmts ctype-pick">
                    <?php foreach (App\Core\Labels::CONTENT_TYPE as $k => $t): ?>
                        <label class="exf-fmt"><input type="radio" name="content_type" value="<?= $k ?>" data-content-type<?= $k === $ct ? ' checked' : '' ?>><span class="exf-card"><span class="exf-ic"><?= icon($ctIcons[$k] ?? 'file') ?></span><b><?= e($t) ?></b><span class="exf-tick"><?= icon('check') ?></span></span></label>
                    <?php endforeach; ?>
                </div>
                <div data-ctype-box="video,audio,image,pdf,file" class="mt-2">
                    <?php if ($isEdit && $l['media_file_id']): ?><div class="lf-current"><?= icon('file') ?><span>فایل فعلی این درس</span><a href="<?= e(file_url($l['media_file_id'])) ?>" target="_blank"><?= icon('eye') ?> مشاهده</a><label class="check"><input type="checkbox" name="remove_media" value="1"> حذف فایل</label></div><?php endif; ?>
                    <div class="form-grid">
                        <div class="field"><label>آپلود فایل <?= $isEdit && $l['media_file_id'] ? 'جایگزین' : '' ?></label><input type="file" name="media"><div class="hint">MP4، MP3، PDF، تصویر، Word، PowerPoint، Excel — حداکثر <?= fa(setting('max_upload_mb')) ?> مگابایت</div></div>
                        <div class="field"><label>یا از کتابخانه محتوا</label><select name="media_file_id"><option value="">—</option><?php foreach ($library as $f): ?><option value="<?= (int)$f['id'] ?>"<?= selected($f['id'], $v('media_file_id')) ?>>[<?= e($f['kind']) ?>] <?= e($f['title'] ?: $f['original_name']) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <label class="check mb-1"><input type="checkbox" name="media_downloadable" value="1"> اجازه دانلود فایل (در غیر این صورت فقط مشاهده آنلاین)</label>
                </div>
                <div data-ctype-box="video,audio,link" class="field mb-0 mt-1">
                    <label><span data-ctype-box="video,audio">یا لینک فایل (به جای آپلود)</span><span data-ctype-box="link">لینک (آپارات، یوتیوب یا هر آدرس معتبر)</span></label>
                    <input type="url" class="ltr" name="link_url" value="<?= e($v('link_url')) ?>" placeholder="https://www.aparat.com/v/…">
                    <details class="lf-help"><summary><?= icon('info') ?> کدام لینک‌ها پشتیبانی می‌شوند؟</summary>
                        <div>لینک صفحه ویدیو در آپارات یا یوتیوب داخل خود درس پخش می‌شود. لینک مستقیم فایل (MP4، M4V، WebM، MP3، M4A، OGG، WAV) با پخش‌کننده سامانه پخش می‌شود و پیشرفت فراگیر ثبت می‌شود. لینک باید با <span class="ltr">https://</span> شروع شود؛ اگر فایل آپلود یا انتخاب کنید، فایل جایگزین لینک می‌شود.</div>
                    </details>
                </div>
            </div>

            <div class="exf-sec">
                <div class="exf-h"><span>۳</span> متن درس</div>
                <div class="field mb-0"><textarea name="body" rows="10" data-rich><?= e($v('body')) ?></textarea><div class="hint">متن ساده، HTML یا خروجی هوش مصنوعی (Markdown): ## تیتر، **بولد** و * بولت خودکار قالب‌بندی می‌شوند.</div></div>
            </div>

            <div class="exf-sec">
                <div class="exf-h"><span>۴</span> فایل‌های پیوست <small>اختیاری</small></div>
                <div class="form-grid">
                    <div class="field mb-0"><label>از کتابخانه</label><select name="attachments[]" multiple size="5"><?php foreach ($library as $f): ?><option value="<?= (int)$f['id'] ?>"<?= selected($f['id'], $attached) ?>>[<?= e($f['kind']) ?>] <?= e($f['title'] ?: $f['original_name']) ?> — <?= human_size((int)$f['size']) ?></option><?php endforeach; ?></select></div>
                    <div class="field mb-0"><label>آپلود پیوست جدید</label><input type="file" name="new_attachments[]" multiple><div class="hint">چند فایل را با هم انتخاب کنید.</div></div>
                </div>
            </div>
        </div>

        <div class="lf-side">
            <div class="card">
                <h3><?= icon('settings') ?> انتشار و دسترسی</h3>
                <div class="field"><label>وضعیت</label><select name="status"><option value="published"<?= selected('published', $v('status', 'published')) ?>>منتشر شده</option><option value="draft"<?= selected('draft', $v('status')) ?>>پیش‌نویس</option></select></div>
                <div class="field"><label>پیش‌نیاز درس</label><select name="prerequisite_lesson_id"><option value="">— بدون پیش‌نیاز —</option><?php foreach ($siblings as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $v('prerequisite_lesson_id')) ?>><?= e($s['title']) ?></option><?php endforeach; ?></select></div>
                <label class="exf-req switch mt-0"><input type="checkbox" name="is_preview" value="1"<?= checked($v('is_preview', 0)) ?>><span><b>پیش‌نمایش رایگان</b><small>بدون ثبت‌نام در دوره قابل مشاهده است.</small></span></label>
            </div>
            <div class="card lf-save">
                <button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> ذخیره درس</button>
                <?php if (!$isEdit): ?><button class="btn btn-outline w-100 mt-1" name="add_another" value="1"><?= icon('plus') ?> ذخیره و درس بعدی</button><?php endif; ?>
                <a class="btn btn-ghost w-100 mt-1" href="<?= url('/admin/courses/' . $c['id']) ?>">انصراف</a>
            </div>
        </div>
    </div>
</form>

<?php if ($isEdit): $lessons = $courseLessons; ?>
<div class="lf-extras-head" id="lesson-extras"><span class="lf-extras-ic"><?= icon('clipboard-check') ?></span><div><h2>آزمون و تمرین این درس</h2><div class="sub">فراگیر بعد از دیدن درس، این‌ها را در ستون کناری همان صفحه می‌بیند.</div></div></div>
<div class="grid g-2">
    <div class="card">
        <div class="card-head"><h3><?= icon('clipboard-check') ?> آزمون‌های درس</h3>
            <?php if (can('exams.create')): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/exams/create', ['course_id' => $c['id'], 'lesson_id' => $l['id']]) ?>"><?= icon('plus') ?> آزمون جدید</a><?php endif; ?></div>
        <div class="exl">
        <?php if (!$lessonExams): ?><div class="empty" style="padding:1.2rem"><?= icon('clipboard-check') ?><div class="small">برای این درس آزمونی تعریف نشده است.</div></div><?php endif; ?>
        <?php foreach ($lessonExams as $x): ?>
            <div class="crow is-link">
                <span class="crow-ic" style="--tc:var(--purple,#8b5cf6)"><?= icon('clipboard-check') ?></span>
                <span class="crow-main">
                    <a class="crow-title stretch" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= e($x['title']) ?></a>
                    <span class="crow-meta">
                        <span class="crow-tag"><?= icon('circle-help') ?> <?= fa((int)($x['random_count'] ?: $x['qn'])) ?> سؤال<?= $x['random_count'] ? ' (تصادفی)' : '' ?></span>
                        <span>قبولی <?= fa((float)$x['pass_score']) ?>٪</span>
                        <?php if ($x['is_required']): ?><span class="crow-req">الزامی</span><?php endif; ?>
                        <?php if (!(int)$x['qn'] && !$x['random_count']): ?><span class="crow-warn"><?= icon('triangle-alert') ?> بدون سؤال</span><?php endif; ?>
                    </span>
                </span>
                <span class="crow-side"><?= status_badge($x['status']) ?><span class="crow-acts"><a class="btn btn-sm btn-outline" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= icon('pencil') ?> ویرایش</a></span></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><?= icon('notebook-pen') ?> تمرین‌های درس</h3><?php if ($lessonExercises): ?><span class="badge badge-gray"><?= fa(count($lessonExercises)) ?> تمرین</span><?php endif; ?></div>
        <?php $fmtNames = ['text' => ['متن', 'message-square'], 'audio' => ['صوت', 'music'], 'file' => ['Word/PDF', 'file-text'], 'image' => ['تصویر', 'image'], 'video' => ['ویدیو', 'video']]; ?>
        <div class="exl">
        <?php foreach ($lessonExercises as $ex): $canEx = can_edit_exercise($ex); $exUrl = $canEx ? url('/admin/exercises/' . $ex['id'] . '/edit', ['from_lesson' => $l['id']]) : url('/learn/exercise/' . $ex['id']); ?>
            <div class="crow is-link" id="ex-<?= (int)$ex['id'] ?>">
                <span class="crow-ic" style="--tc:var(--warning)"><?= icon('notebook-pen') ?></span>
                <span class="crow-main">
                    <a class="crow-title stretch" href="<?= $exUrl ?>"><?= e($ex['title']) ?></a>
                    <span class="crow-meta">
                        <?php foreach (array_intersect_key($fmtNames, array_flip(explode(',', (string)$ex['formats']))) as [$fn, $fi]): ?><span class="crow-tag"><?= icon($fi) ?> <?= e($fn) ?></span><?php endforeach; ?>
                        <span>قبولی <?= fa((float)$ex['pass_score']) ?> از <?= fa((float)$ex['max_score']) ?></span>
                        <?php if ($ex['is_required']): ?><span class="crow-req">الزامی</span><?php endif; ?>
                    </span>
                </span>
                <span class="crow-side">
                    <?= status_badge($ex['status']) ?>
                    <span class="crow-acts"><a class="btn btn-sm btn-outline" href="<?= $exUrl ?>"><?= icon($canEx ? 'pencil' : 'eye') ?> <?= $canEx ? 'ویرایش' : 'مشاهده' ?></a></span>
                </span>
            </div>
        <?php endforeach; ?>
        <?php if (can('lessons.create')): $ex = ['lesson_id' => $l['id'], 'title' => '']; ?>
            <details class="exl-item exl-new"<?= $lessonExercises ? '' : ' open' ?>>
                <summary class="exl-add"><span class="exl-add-ic"><?= icon('plus') ?></span><span><b>تمرین جدید برای این درس</b><small>صوتی، متنی، فایل، تصویر یا ویدیو</small></span></summary>
                <form method="post" action="<?= url('/admin/courses/' . $c['id'] . '/exercises') ?>" class="exl-form"><?= csrf_field() ?><input type="hidden" name="return_lesson" value="<?= (int)$l['id'] ?>">
                    <?php include APP_PATH . '/Views/admin/courses/_exercise_fields.php'; ?>
                    <div class="exl-foot"><button class="btn btn-primary"><?= icon('plus') ?> افزودن تمرین</button></div>
                </form>
            </details>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info mt-2"><?= icon('info') ?><div>بعد از ذخیره درس، در همین صفحه ویرایش می‌توانید آزمون و تمرین مخصوص این درس را اضافه کنید.</div></div>
<?php endif; ?>
