<?php
/** Deal form fields. @var ?array $dv existing deal  @var string[] $dealLabels */
$f = fn($k) => old($k, $dv[$k] ?? '');
?>
<div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
    <div class="field"><label>محصول <span class="req">*</span></label><input type="text" name="product" value="<?= e($f('product')) ?>" required maxlength="200" placeholder="مثلاً: زعفران سرگل"></div>
    <div class="field"><label>مشتری <span class="req">*</span></label><input type="text" name="customer" value="<?= e($f('customer')) ?>" required maxlength="200" placeholder="نام شخص یا شرکت خریدار"></div>
    <div class="field"><label>مبلغ (<?= e(currency_label()) ?>)</label><input type="text" inputmode="numeric" class="ltr" name="amount" value="<?= e($dv && $dv['amount'] !== null && old('amount', null) === null ? number_format((float)$dv['amount']) : $f('amount')) ?>" placeholder="250,000,000"></div>
    <div class="field"><label>تاریخ معامله</label><input type="text" class="ltr" name="deal_date" value="<?= e(old('deal_date', $dv && $dv['deal_date'] ? App\Core\Jalali::input($dv['deal_date']) : '')) ?>" placeholder="1405/07/01"></div>
    <div class="field"><label>بازار / مقصد</label><input type="text" name="market" value="<?= e($f('market')) ?>" maxlength="150" placeholder="مثلاً: امارات، تهران…"></div>
</div>
<div class="field"><label>توضیحات</label><textarea name="description" rows="2" maxlength="3000"><?= e($f('description')) ?></textarea></div>
<div class="field"><label><?= $dv ? 'افزودن مدرک' : 'مدارک معامله' ?> <?= $dv ? '' : '<span class="req">*</span>' ?></label>
    <div class="tg-doc-inputs"><?php foreach ($dealLabels as $i => $lbl): ?><div><label class="faint"><?= e($lbl) ?></label><input type="file" name="docs[<?= $i ?>][]" multiple accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx"></div><?php endforeach; ?></div>
    <div class="hint">عکس، فیلم، PDF یا فایل Word/Excel. <?= $dv ? '' : 'حداقل یک مدرک لازم است.' ?></div>
</div>
