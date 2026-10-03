<?php
$isEdit = $l !== null;
$v = fn($k, $d = '') => old($k, $l[$k] ?? $d);
$ct = $v('content_type', 'video');
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= url('/admin/courses') ?>">دوره‌ها</a> / <a href="<?= url('/admin/courses/' . $c['id']) ?>"><?= e($c['title']) ?></a></div><h1><?= $isEdit ? 'ویرایش درس' : 'درس جدید' ?></h1></div></div>
<form method="post" enctype="multipart/form-data" action="<?= url($isEdit ? '/admin/lessons/' . $l['id'] : '/admin/courses/' . $c['id'] . '/lessons') ?>">
    <?= csrf_field() ?>
    <div class="grid g-main">
        <div class="stack">
            <div class="card">
                <div class="form-grid">
                    <div class="field full"><label>عنوان درس <span class="req">*</span></label><input type="text" name="title" value="<?= e($v('title')) ?>" required></div>
                    <div class="field"><label>سرفصل (بخش)</label><input type="text" name="section_title" value="<?= e($v('section_title')) ?>" list="sections" placeholder="مثلاً: فصل اول — مبانی"><datalist id="sections"><?php foreach ($sections as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
                    <div class="field"><label>نوع محتوای اصلی</label><select name="content_type" data-content-type><?php foreach (App\Core\Labels::CONTENT_TYPE as $k => $t): ?><option value="<?= $k ?>"<?= selected($k, $ct) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
                </div>
                <div data-ctype-box="video,audio,image,pdf,file">
                    <fieldset><legend>فایل محتوا</legend>
                        <div class="hint mb-1" data-ctype-box="video,audio">برای ویدیو و صوت می‌توانید به جای فایل، پایین همین کادر لینک بدهید؛ آپلود فایل الزامی نیست.</div>
                        <?php if ($isEdit && $l['media_file_id']): ?><div class="alert alert-info small"><?= icon('file') ?> فایل فعلی: <a href="<?= e(file_url($l['media_file_id'])) ?>" target="_blank">مشاهده</a> — برای جایگزینی، فایل جدید آپلود یا از کتابخانه انتخاب کنید. <label class="check" style="display:inline-flex"><input type="checkbox" name="remove_media" value="1"> حذف فایل</label></div><?php endif; ?>
                        <div class="form-grid">
                            <div class="field"><label>آپلود فایل جدید</label><input type="file" name="media"><div class="hint">MP4، MP3، PDF، JPG/PNG، Word، PowerPoint، Excel — حداکثر <?= fa(setting('max_upload_mb')) ?> مگابایت</div></div>
                            <div class="field"><label>یا انتخاب از کتابخانه محتوا</label><select name="media_file_id"><option value="">—</option><?php foreach ($library as $f): ?><option value="<?= (int)$f['id'] ?>"<?= selected($f['id'], $v('media_file_id')) ?>>[<?= e($f['kind']) ?>] <?= e($f['title'] ?: $f['original_name']) ?></option><?php endforeach; ?></select></div>
                        </div>
                        <label class="check"><input type="checkbox" name="media_downloadable" value="1"> اجازه دانلود فایل آپلودی (در غیر این صورت فقط مشاهده آنلاین)</label>
                    </fieldset>
                </div>
                <div data-ctype-box="video,audio,link" class="field">
                    <label><span data-ctype-box="video,audio">یا لینک فایل (به جای آپلود)</span><span data-ctype-box="link">لینک (آپارات، یوتیوب یا هر آدرس معتبر)</span></label>
                    <input type="url" class="ltr" name="link_url" value="<?= e($v('link_url')) ?>" placeholder="https://www.aparat.com/v/…">
                    <div class="hint">
                        لینک صفحه ویدیو در آپارات یا یوتیوب، داخل خود درس پخش می‌شود.
                        لینک مستقیم فایل ویدیو یا صوت (MP4، M4V، WebM، MP3، M4A، OGG، WAV) با پخش‌کننده سامانه پخش می‌شود و پیشرفت و محل توقف فراگیر هم ثبت می‌شود.
                        لینک باید با <span class="ltr">https://</span> شروع شود. اگر فایل آپلود یا از کتابخانه انتخاب کنید، فایل جایگزین لینک می‌شود.
                    </div>
                </div>
                <div class="field"><label>متن درس / توضیحات</label><textarea name="body" rows="10"><?= e($v('body')) ?></textarea><div class="hint">متن ساده، HTML پایه یا متن خروجی هوش مصنوعی (Markdown). علامت‌های ## تیتر، **بولد** و * بولت خودکار به قالب‌بندی تبدیل می‌شوند.</div></div>
            </div>
            <div class="card">
                <h3><?= icon('file-down') ?> فایل‌های پیوست</h3>
                <div class="field"><label>انتخاب از کتابخانه</label><select name="attachments[]" multiple size="6"><?php foreach ($library as $f): ?><option value="<?= (int)$f['id'] ?>"<?= selected($f['id'], $attached) ?>>[<?= e($f['kind']) ?>] <?= e($f['title'] ?: $f['original_name']) ?> — <?= human_size((int)$f['size']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>آپلود پیوست‌های جدید</label><input type="file" name="new_attachments[]" multiple></div>
            </div>
        </div>
        <div class="stack">
            <div class="card">
                <h3><?= icon('settings') ?> تنظیمات</h3>
                <div class="field"><label>مدت (دقیقه)</label><input type="number" name="duration_minutes" value="<?= e($v('duration_minutes')) ?>"></div>
                <div class="field"><label>پیش‌نیاز درس</label><select name="prerequisite_lesson_id"><option value="">—</option><?php foreach ($siblings as $s): ?><option value="<?= (int)$s['id'] ?>"<?= selected($s['id'], $v('prerequisite_lesson_id')) ?>><?= e($s['title']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>وضعیت</label><select name="status"><option value="published"<?= selected('published', $v('status', 'published')) ?>>منتشر شده</option><option value="draft"<?= selected('draft', $v('status')) ?>>پیش‌نویس</option></select></div>
                <label class="switch"><input type="checkbox" name="is_preview" value="1"<?= checked($v('is_preview', 0)) ?>> پیش‌نمایش رایگان</label>
            </div>
            <div class="card">
                <button class="btn btn-grad btn-lg w-100"><?= icon('save') ?> ذخیره درس</button>
                <?php if (!$isEdit): ?><button class="btn btn-outline w-100 mt-1" name="add_another" value="1"><?= icon('plus') ?> ذخیره و درس بعدی</button><?php endif; ?>
                <div class="hint mt-1">برای افزودن آزمون به این درس، از بخش آزمون‌ها، درس را انتخاب کنید.</div>
            </div>
        </div>
    </div>
</form>

<?php if ($isEdit): $lessons = $courseLessons; ?>
<div class="page-head mt-3" id="lesson-extras"><div><h2 class="mb-0"><?= icon('clipboard-check') ?> آزمون و تمرین این درس</h2><div class="sub">فراگیر بعد از دیدن درس، آزمون و تمرین آن را پایین همان صفحه درس می‌بیند.</div></div></div>
<div class="grid g-2">
    <div class="card">
        <div class="card-head"><h3><?= icon('clipboard-check') ?> آزمون درس</h3>
            <?php if (can('exams.create')): ?><a class="btn btn-primary btn-sm" href="<?= url('/admin/exams/create', ['course_id' => $c['id'], 'lesson_id' => $l['id']]) ?>"><?= icon('plus') ?> آزمون جدید</a><?php endif; ?></div>
        <?php if (!$lessonExams): ?><div class="empty" style="padding:1.2rem"><?= icon('clipboard-check') ?><div class="small">برای این درس آزمونی تعریف نشده است.</div></div><?php endif; ?>
        <?php foreach ($lessonExams as $x): ?>
            <div class="list-item">
                <span class="ico" style="background:var(--purple-soft);color:var(--purple)"><?= icon('clipboard-check') ?></span>
                <div class="grow"><b><?= e($x['title']) ?></b>
                    <div class="small faint"><?= fa((int)$x['qn']) ?> سؤال<?= $x['random_count'] ? ' (' . fa((int)$x['random_count']) . ' سؤال تصادفی)' : '' ?> · قبولی <?= fa((float)$x['pass_score']) ?>٪ · <?= $x['is_required'] ? 'الزامی' : 'اختیاری' ?></div>
                    <?php if (!(int)$x['qn'] && !$x['random_count']): ?><div class="small" style="color:var(--warning)"><?= icon('triangle-alert') ?> هنوز سؤالی اضافه نشده</div><?php endif; ?>
                </div>
                <?= status_badge($x['status']) ?>
                <a class="btn btn-outline btn-sm" href="<?= url('/admin/exams/' . $x['id']) ?>"><?= icon('pencil') ?> سؤالات و تنظیمات</a>
            </div>
        <?php endforeach; ?>
        <div class="hint mt-1">در صفحه آزمون، سؤال‌ها را از بانک سؤال انتخاب کنید یا حالت تصادفی بگذارید.</div>
    </div>

    <div class="card">
        <div class="card-head"><h3><?= icon('notebook-pen') ?> تمرین درس</h3></div>
        <?php foreach ($lessonExercises as $ex): ?>
            <details class="ex-edit">
                <summary class="list-item">
                    <span class="ico" style="background:var(--warning-soft);color:var(--warning)"><?= icon('notebook-pen') ?></span>
                    <div class="grow"><b><?= e($ex['title']) ?></b><div class="small faint">قبولی <?= fa((float)$ex['pass_score']) ?> از <?= fa((float)$ex['max_score']) ?> · <?= $ex['is_required'] ? 'الزامی' : 'اختیاری' ?></div></div>
                    <?= status_badge($ex['status']) ?>
                    <?php if (can('lessons.edit')): ?><span class="btn btn-outline btn-sm"><?= icon('pencil') ?> ویرایش</span><?php endif; ?>
                </summary>
                <?php if (can('lessons.edit')): ?>
                <form method="post" action="<?= url('/admin/exercises/' . $ex['id']) ?>" class="mt-1"><?= csrf_field() ?><input type="hidden" name="return_lesson" value="<?= (int)$l['id'] ?>">
                    <?php include APP_PATH . '/Views/admin/courses/_exercise_fields.php'; ?>
                    <button class="btn btn-primary"><?= icon('save') ?> ذخیره تمرین</button>
                </form>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        <?php if (can('lessons.create')): $ex = ['lesson_id' => $l['id'], 'title' => '']; ?>
            <details class="ex-edit"<?= $lessonExercises ? '' : ' open' ?>>
                <summary class="btn btn-outline btn-sm mt-1"><?= icon('plus') ?> تمرین جدید برای این درس</summary>
                <form method="post" action="<?= url('/admin/courses/' . $c['id'] . '/exercises') ?>" class="mt-1"><?= csrf_field() ?><input type="hidden" name="return_lesson" value="<?= (int)$l['id'] ?>">
                    <?php include APP_PATH . '/Views/admin/courses/_exercise_fields.php'; ?>
                    <button class="btn btn-primary"><?= icon('plus') ?> افزودن تمرین</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info mt-2"><?= icon('info') ?><div>بعد از ذخیره درس، در همین صفحه ویرایش می‌توانید آزمون و تمرین مخصوص این درس را اضافه کنید.</div></div>
<?php endif; ?>
