<?php foreach (($flashes ?? []) as $type => $message): ?>
  <div class="alert alert-<?= e($type) ?>" role="status"><?= e($message) ?></div>
<?php endforeach; ?>
