<?php
/**
 * Generic form field.
 * @var string $name @var string $label @var array $old @var array $errors
 * @var string|null $type text|email|password|tel|url|textarea
 * @var string|null $hint @var array|null $attrs extra attributes
 */
$type ??= 'text';
$id = 'f-' . $name;
$value = $old[$name] ?? '';
$error = $errors[$name] ?? null;
$extra = '';
foreach (($attrs ?? []) as $k => $v) {
    $extra .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
}
$describedBy = trim(($hint ?? null ? $id . '-hint ' : '') . ($error ? $id . '-err' : ''));
?>
<div class="field<?= $error ? ' has-error' : '' ?>">
  <label for="<?= e($id) ?>"><?= te($label) ?></label>
  <?php if ($type === 'textarea'): ?>
    <textarea class="textarea" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $describedBy ? ' aria-describedby="' . e($describedBy) . '"' : '' ?><?= $error ? ' aria-invalid="true"' : '' ?><?= $extra ?>><?= e($value) ?></textarea>
  <?php else: ?>
    <input class="input" id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"<?= $type === 'password' ? '' : ' value="' . e($value) . '"' ?><?= $describedBy ? ' aria-describedby="' . e($describedBy) . '"' : '' ?><?= $error ? ' aria-invalid="true"' : '' ?><?= $extra ?>>
  <?php endif; ?>
  <?php if ($hint ?? null): ?><div class="hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error" id="<?= e($id) ?>-err"><?= e($error) ?></div><?php endif; ?>
</div>
