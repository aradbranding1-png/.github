<div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(110px,1fr))">
    <div class="field"><label>عنوان</label><input type="text" name="title" value="<?= e($rk['title']) ?>" required maxlength="60"></div>
    <div class="field"><label>از مرحله</label><input type="number" name="from_stage" min="1" value="<?= (int)$rk['from_stage'] ?>"></div>
    <div class="field"><label>تا مرحله</label><input type="number" name="to_stage" min="1" value="<?= (int)$rk['to_stage'] ?>"></div>
    <div class="field"><label>تعداد ستاره</label><input type="number" name="stars" min="1" max="5" value="<?= (int)$rk['stars'] ?>"></div>
    <div class="field"><label>رنگ</label><input type="color" name="color" value="<?= e($rk['color']) ?>" style="height:42px;padding:.2rem"></div>
</div>
<label class="check small mb-2"><input type="checkbox" name="filled" value="1"<?= checked((int)$rk['filled'] === 1) ?>> ستاره توپر (★) — خاموش = توخالی (☆)</label>
