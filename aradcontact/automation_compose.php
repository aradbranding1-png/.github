<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/automation_functions.php';
$user = require_login();
$pdo  = db();

if (!automation_ready($pdo)) {
    $pageTitle = 'اتوماسیون';
    require_once __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning">ماژولِ اتوماسیون هنوز روی این سایت نصب نشده.</div>';
    require_once __DIR__ . '/includes/layout_bottom.php';
    exit;
}
automation_require_permission($pdo, $user, 'letter_create');

$myPos = automation_user_position($pdo, (int) $user['id']);
if (!$myPos) {
    $pageTitle = 'نامه جدید';
    require_once __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning">برای شما هنوز جایگاهِ سازمانی تعریف نشده — از ادمین بخواهید جایگاهِ شما را در «ساختار سازمانی» ثبت کند تا بتوانید نامه ارسال کنید.</div>';
    require_once __DIR__ . '/includes/layout_bottom.php';
    exit;
}

// کسی که در چند واحد عضو است انتخاب می‌کند نامه «از سوی» کدام واحد/سمت ارسال شود
$myPositions = automation_user_positions_all($pdo, (int) $user['id']);

$replyToId = (int) ($_GET['reply_to'] ?? 0);
$replyTo = null;
if ($replyToId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM letters WHERE id = ? LIMIT 1');
    $stmt->execute([$replyToId]);
    $replyTo = $stmt->fetch() ?: null;
}

$errors = [];
$UPLOAD_DIR = __DIR__ . '/storage/automation_attachments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } else {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $letterType = trim((string) ($_POST['letter_type'] ?? '')) ?: null;
        $priority = $_POST['priority'] ?? 'عادی';
        $confidentiality = $_POST['confidentiality'] ?? 'عادی';
        $deadlineJ = trim((string) ($_POST['deadline_at'] ?? ''));
        $deadlineG = $deadlineJ !== '' ? to_gregorian($deadlineJ) : null;
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;
        $isDraft = isset($_POST['save_draft']);
        $senderPosId = (int) ($_POST['sender_position_id'] ?? 0);
        $senderPos = automation_sender_position($pdo, (int) $user['id'], $senderPosId ?: null);
        if (count($myPositions) > 1 && $senderPosId > 0 && (int) ($senderPos['id'] ?? 0) !== $senderPosId) {
            $errors[] = 'واحدِ فرستنده‌ی انتخاب‌شده معتبر نیست.';
        }
        $letterNumber = trim((string) ($_POST['letter_number'] ?? ''));
        if ($letterNumber === '') {
            $letterNumber = 'L-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        $payload = json_decode((string) ($_POST['recipients_payload'] ?? '[]'), true);
        if (!is_array($payload)) { $payload = []; }
        $recipients = [];
        foreach ($payload as $r) {
            // گیرنده می‌تواند فرد، «واحدِ سازمانی» (فقط اعضای مستقیمِ همان واحد، نه زیرمجموعه‌ها) یا «رده‌ی سازمانی»
            // (اعضای همه‌ی واحدهای آن رده، نه زیرمجموعه‌ها) باشد — چند انتخابی.
            if (!is_array($r) || empty($r['kind']) || empty($r['id'])) { continue; }
            if (!in_array($r['kind'], ['user', 'unit', 'level'], true)) { continue; }

            $recipients[] = [
                'role'     => ($r['role'] ?? 'to') === 'cc' ? 'cc' : 'to',
                'kind'     => $r['kind'],
                'id'       => (int) $r['id'],
                'scope'    => $r['kind'] === 'unit' ? 'all_members' : null,
                'selected' => [],
            ];
        }

        if ($subject === '') {
            $errors[] = 'موضوعِ نامه الزامی است.';
        }
        if (!$isDraft && !$recipients) {
            $errors[] = 'حداقل یک گیرنده باید انتخاب شود.';
        }

        if (!$errors) {
            if ($isDraft) {
                $ins = $pdo->prepare("INSERT INTO letters (letter_number, subject, body, letter_type, priority, confidentiality, status, sender_user_id, sender_unit_id, sender_position_title, deadline_at, description, parent_letter_id, root_letter_id)
                    VALUES (?,?,?,?,?,?,'پیش‌نویس',?,?,?,?,?,?,?)");
                $ins->execute([
                    $letterNumber, $subject, $body, $letterType, $priority, $confidentiality,
                    (int) $user['id'], $senderPos['unit_id'] ?? $myPos['unit_id'], $senderPos['position_title'] ?? $myPos['position_title'],
                    $deadlineG, $description, $replyToId ?: null, $replyTo['root_letter_id'] ?? ($replyToId ?: null),
                ]);
                $letterId = (int) $pdo->lastInsertId();
                if (!$replyToId) {
                    $pdo->prepare('UPDATE letters SET root_letter_id = ? WHERE id = ?')->execute([$letterId, $letterId]);
                }
                automation_log_history($pdo, $letterId, (int) $user['id'], 'created', 'نامه به‌صورت پیش‌نویس ذخیره شد.');
                flash_set('success', 'پیش‌نویس ذخیره شد.');
            } else {
                try {
                    $letterId = automation_send_letter($pdo, [
                        'letter_number' => $letterNumber,
                        'subject' => $subject,
                        'body' => $body,
                        'letter_type' => $letterType,
                        'priority' => $priority,
                        'confidentiality' => $confidentiality,
                        'deadline_at' => $deadlineG,
                        'description' => $description,
                        'parent_letter_id' => $replyToId ?: null,
                        'root_letter_id' => $replyTo['root_letter_id'] ?? ($replyToId ?: null),
                        'sender_position_id' => (int) ($senderPos['id'] ?? 0) ?: null,
                    ], $recipients, (int) $user['id']);
                    if ($replyToId) {
                        automation_log_history($pdo, $replyToId, (int) $user['id'], 'replied', 'به این نامه پاسخ داده شد.');
                    }
                } catch (Throwable $e) {
                    $errors[] = 'خطا در ارسالِ نامه. دوباره تلاش کنید.';
                    $letterId = null;
                }
                if (!$errors && $letterId) {
                    flash_set('success', 'نامه با موفقیت ارسال شد.');
                    // نامه به دبیرخانه ریاست ← بعد از ارسال، پاپ‌آپِ اطلاع‌رسانی در صفحه‌ی نامه
                    if (automation_recipients_include_secretariat($pdo, $recipients)) {
                        $_SESSION['automation_secretariat_notice'] = (int) $letterId;
                    }
                }
            }

            if (!$errors && $letterId && !empty($_FILES['attachments']['name'][0] ?? '')) {
                if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0755, true); }
                $letterDir = $UPLOAD_DIR . '/' . $letterId;
                if (!is_dir($letterDir)) { @mkdir($letterDir, 0755, true); }
                $count = count($_FILES['attachments']['name']);
                $attStmt = $pdo->prepare('INSERT INTO letter_attachments (letter_id, file_name, stored_path, file_size, mime_type, uploaded_by) VALUES (?,?,?,?,?,?)');
                for ($i = 0; $i < $count; $i++) {
                    if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
                    $origName = $_FILES['attachments']['name'][$i];
                    $safeName = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $origName);
                    $storedName = uniqid('att_', true) . '_' . $safeName;
                    $dest = $letterDir . '/' . $storedName;
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dest)) {
                        $attStmt->execute([$letterId, $origName, $letterId . '/' . $storedName, $_FILES['attachments']['size'][$i] ?? null, $_FILES['attachments']['type'][$i] ?? null, (int) $user['id']]);
                    }
                }
            }

            if (!$errors && $letterId) {
                redirect('automation_letter_view.php?id=' . $letterId);
            }
        }
    }
}

$pageTitle = $replyTo ? 'پاسخ به نامه' : 'نامه جدید';
require_once __DIR__ . '/includes/layout_top.php';
?>
<style>
.acp-page{--acp-line:#e7e2d3;--acp-ink:#1c1917;--acp-muted:#78716c;--acp-gold:#c9a24b;--acp-gold-2:#f1dfa8;}
.acp-page .card{border:1px solid var(--acp-line);border-radius:18px;box-shadow:0 4px 20px -16px rgba(28,25,23,.3)}
.acp-page .form-label{font-size:.78rem;font-weight:700;color:#57534e}
.acp-page .form-control,.acp-page .form-select{border:1px solid var(--acp-line);border-radius:10px}
.acp-page .form-control:focus,.acp-page .form-select:focus{border-color:var(--acp-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15)}
.acp-page .btn-primary{border:none;border-radius:12px;font-weight:700;background:linear-gradient(135deg,var(--acp-gold-2),var(--acp-gold));color:#241d0a}
.acp-page .acp-back{border-radius:10px;font-weight:700}
.acp-page #recipientResults{position:absolute;z-index:20;background:#fff;border:1px solid var(--acp-line);border-radius:10px;width:100%;max-height:260px;overflow:auto;box-shadow:0 10px 24px -12px rgba(0,0,0,.25)}
.acp-page #recipientResults .rec-item{padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #f3f1ea}
.acp-page #recipientResults .rec-item:hover{background:#faf8f2}

/* پنجره انتخاب رونوشت */
.acp-page #ccResults{
  position:absolute;
  z-index:30;
  top:100%;
  right:0;
  left:0;
  margin-top:8px;
  background:linear-gradient(180deg,#fff 0%,#fcfaf5 100%);
  border:1px solid #d9c58f;
  border-radius:15px;
  width:100%;
  max-height:285px;
  overflow:auto;
  box-shadow:0 14px 35px -12px rgba(91,67,18,.28),0 3px 10px rgba(0,0,0,.06);
  padding:7px;
}
.acp-page #ccResults::before{
  content:'انتخاب مخاطب برای رونوشت';
  display:block;
  padding:7px 10px 9px;
  margin:0 2px 5px;
  color:#8a6a20;
  font-size:.75rem;
  font-weight:800;
  border-bottom:1px solid #eee5cf;
}
.acp-page #ccResults .rec-item{
  display:flex;
  align-items:center;
  min-height:44px;
  padding:.62rem .75rem;
  margin:2px 0;
  cursor:pointer;
  border:1px solid transparent;
  border-radius:10px;
  transition:all .15s ease;
}
.acp-page #ccResults .rec-item:hover{
  background:linear-gradient(135deg,#fffaf0,#f7edcf);
  border-color:#e2cb91;
  transform:translateY(-1px);
}
.acp-page #ccResults .rec-item:last-child{border-bottom:0}
.acp-page #ccResults .rec-item strong{font-size:.82rem;color:#29251c}
.acp-page #ccResults .rec-item .text-muted{font-size:.7rem}
.acp-page .recipient-chip{display:inline-flex;align-items:center;gap:.4rem;background:#faf9f5;border:1px solid var(--acp-line);border-radius:999px;padding:.3rem .7rem;font-size:.78rem;margin:.2rem}
.acp-page .recipient-chip .remove-chip{cursor:pointer;color:#b91c1c}
.acp-page .recipient-chip.cc-chip{background:#eff6ff;border-color:#bfdbfe}
.acp-page .verified-gold{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:18px;
  height:18px;
  margin-right:5px;
  border-radius:50%;
  flex:0 0 18px;
  font-size:10px;
  font-weight:900;
  line-height:18px;
  color:#fff;
  background:linear-gradient(135deg,#f8df83 0%,#d4aa45 48%,#b88620 100%);
  border:1px solid #c79a35;
  box-shadow:0 2px 5px rgba(154,112,24,.35),inset 0 1px 1px rgba(255,255,255,.55);
  vertical-align:middle;
}
.acp-page .rec-item .verified-gold{vertical-align:middle}
</style>
<div class="acp-page">
<a href="automation_dashboard.php" class="btn btn-sm btn-outline-secondary mb-3 acp-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به اتوماسیون</a>

<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endforeach; ?>

<div class="card p-4">
  <h6 class="mb-3"><i class="fa-solid fa-pen-to-square"></i> <?= $replyTo ? 'پاسخ به نامه: ' . e($replyTo['subject']) : 'نامه جدید' ?></h6>
  <form method="post" enctype="multipart/form-data" id="letterForm">
    <?= csrf_field() ?>
    <div class="row g-3">
      <?php if (count($myPositions) > 1):
        $__selPos = (int) ($_POST['sender_position_id'] ?? ($myPositions[0]['id'] ?? 0)); ?>
      <div class="col-12">
        <label class="form-label">ارسال از سوی <span class="text-danger">*</span></label>
        <select name="sender_position_id" class="form-select" required>
          <?php foreach ($myPositions as $__p): ?>
            <option value="<?= (int) $__p['id'] ?>" <?= (int) $__p['id'] === $__selPos ? 'selected' : '' ?>><?= e(trim((string) $__p['unit_title']) . (trim((string) $__p['position_title']) !== '' ? ' — ' . trim((string) $__p['position_title']) : '') . (!empty($__p['is_primary']) ? ' (واحدِ اصلی)' : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">شما در چند واحد عضو هستید؛ نامه با نام و سمتِ همین واحد ارسال و چاپ می‌شود.</div>
      </div>
      <?php endif; ?>
      <div class="col-md-4">
        <label class="form-label">شماره نامه (خالی=خودکار)</label>
        <input type="text" name="letter_number" class="form-control" dir="ltr">
      </div>
      <div class="col-md-4">
        <label class="form-label">نوع نامه</label>
        <input type="text" name="letter_type" class="form-control" placeholder="مثلا: داخلی، بخشنامه">
      </div>
      <div class="col-md-4">
        <label class="form-label">مهلت اقدام</label>
        <input type="text" name="deadline_at" class="form-control jalali-date" dir="ltr" autocomplete="off" placeholder="۱۴۰۵/۰۷/۱۰">
      </div>
      <div class="col-12">
        <label class="form-label">موضوع <span class="text-danger">*</span></label>
        <input type="text" name="subject" class="form-control" required value="<?= $replyTo ? e('پاسخ: ' . $replyTo['subject']) : '' ?>">
      </div>
      <div class="col-12">
        <label class="form-label">متن نامه</label>
        <textarea name="body" class="form-control" rows="6"></textarea>
      </div>
      <div class="col-md-6">
        <label class="form-label">اولویت</label>
        <select name="priority" class="form-select">
          <?php foreach (['عادی','مهم','فوری','خیلی فوری'] as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">سطح محرمانگی</label>
        <select name="confidentiality" class="form-select">
          <?php foreach (['عادی','داخلی','محرمانه','خیلی محرمانه'] as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 position-relative">
        <label class="form-label">گیرنده (شخص، واحد یا رده‌ی سازمانی — چند انتخابی) <span class="text-danger">*</span></label>
        <input type="text" id="recipientSearch" class="form-control" placeholder="نامِ شخص، موبایل، نامِ واحد یا رده (مثلاً معاونت)…" autocomplete="off">
        <div id="recipientResults" class="d-none"></div>
        <div id="recipientChips" class="mt-2"></div>
      </div>
      <div class="col-12 position-relative">
        <label class="form-label">رونوشت</label>
        <input type="text" id="ccSearch" class="form-control" placeholder="شخص، واحد یا رده برای رونوشت…" autocomplete="off">
        <div id="ccResults" class="d-none"></div>
        <div id="ccChips" class="mt-2"></div>
      </div>

      <div class="col-12">
        <label class="form-label">پیوست</label>
        <input type="file" name="attachments[]" class="form-control" multiple>
      </div>
      <div class="col-12">
        <label class="form-label">توضیحات</label>
        <textarea name="description" class="form-control" rows="2"></textarea>
      </div>
    </div>

    <input type="hidden" name="recipients_payload" id="recipientsPayload" value="[]">
    <div class="d-flex gap-2 mt-4">
      <button type="submit" name="send_now" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> ارسال نامه</button>
      <button type="submit" name="save_draft" class="btn btn-outline-secondary"><i class="fa-solid fa-floppy-disk"></i> ذخیره پیش‌نویس</button>
    </div>
  </form>
</div>
</div>

<script>
(function () {
  var recipients = []; // {role, kind, id, label, sub, scope, selected}

  function normalizeMobile(value) {
    return String(value || '').replace(/[^0-9]/g, '').replace(/^98/, '0');
  }

  function recipientDisplayLabel(item) {
    // نام نمایشی اختصاصی برای سه کاربر مشخص؛ جستجو همچنان با نام/موبایل اصلی انجام می‌شود.
    var specialNames = {
      'امیرعلی قربانی': 'مهندس قربانی',
      'تالیا براری': 'مهندس براری',
      'علیرضا شعبانی': 'مهندس شعبانی'
    };
    var specialMobiles = {
      '09121513972': 'مهندس قربانی',
      '09121960242': 'مهندس براری',
      '09121537239': 'مهندس شعبانی'
    };

    var rawLabel = String(item.label || '').trim();
    var label = specialNames[rawLabel];
    if (label) return label;

    var mobileCandidates = [
      item.phone, item.mobile, item.mobile_number, item.phone_number,
      item.sub
    ];

    for (var i = 0; i < mobileCandidates.length; i++) {
      var mobile = normalizeMobile(mobileCandidates[i]);
      if (specialMobiles[mobile]) return specialMobiles[mobile];
    }

    return rawLabel;
  }

  function isSpecialRecipient(item) {
    var specialLabels = {
      'مهندس قربانی': true,
      'مهندس براری': true,
      'مهندس شعبانی': true
    };
    if (specialLabels[String(item.displayLabel || '').trim()]) return true;

    var mobiles = [
      item.phone, item.mobile, item.mobile_number, item.phone_number, item.sub
    ];
    var specialMobiles = {
      '09121513972': true,
      '09121960242': true,
      '09121537239': true
    };
    for (var i = 0; i < mobiles.length; i++) {
      if (normalizeMobile(mobiles[i]) in specialMobiles) return true;
    }
    return false;
  }

  function makeSearch(inputId, resultsId, role) {
    var input = document.getElementById(inputId);
    var results = document.getElementById(resultsId);
    var timer = null;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 2) { results.classList.add('d-none'); results.innerHTML = ''; return; }
      timer = setTimeout(function () {
        fetch('automation_search_recipients.php?q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok || !data.results.length) { results.classList.add('d-none'); results.innerHTML = ''; return; }
            results.innerHTML = '';
            var visibleResults = role === 'cc' ? data.results.slice(0, 5) : data.results;
            visibleResults.forEach(function (item) {
              // فرد، واحدِ سازمانی یا رده‌ی سازمانی
              if (['user', 'unit', 'level'].indexOf(item.kind) === -1) return;

              var div = document.createElement('div');
              div.className = 'rec-item';
              var displayLabel = recipientDisplayLabel(item);
              div.innerHTML = '<strong>' + displayLabel + '</strong>' +
                (item.mobile ? ' <span dir="ltr" class="text-muted">(' + String(item.mobile).replace(/[^0-9+]/g, '') + ')</span>' : '') +
                (isSpecialRecipient(item) ? ' <span class="verified-gold" title="تایید شده" aria-label="تایید شده"><i class="fa-solid fa-check"></i></span>' : '') +
                (item.sub ? ' <span class="text-muted small">&nbsp;(' + item.sub + ')</span>' : '');
              item.displayLabel = displayLabel;
              div.addEventListener('click', function () {
                addRecipient(role, item);
                input.value = '';
                results.classList.add('d-none');
              });
              results.appendChild(div);
            });
            results.classList.remove('d-none');
          }).catch(function () {});
      }, 250);
    });
  }

  function addRecipient(role, item) {
    for (var i = 0; i < recipients.length; i++) {
      if (recipients[i].kind === item.kind && String(recipients[i].id) === String(item.id) && recipients[i].role === role) { return; }
    }
    recipients.push({
      role: role,
      kind: item.kind,
      id: item.id,
      label: item.displayLabel || recipientDisplayLabel(item),
      sub: item.sub,
      scope: 'all_members',
      selected: []
    });
    renderChips();
  }

  function removeRecipient(idx) {
    recipients.splice(idx, 1);
    renderChips();
  }

  function renderChips() {
    var toBox = document.getElementById('recipientChips');
    var ccBox = document.getElementById('ccChips');
    toBox.innerHTML = '';
    ccBox.innerHTML = '';
    recipients.forEach(function (r, idx) {
      var chip = document.createElement('span');
      chip.className = 'recipient-chip' + (r.role === 'cc' ? ' cc-chip' : '');
      var scopeSelect = '';
      chip.innerHTML = '<i class="fa-solid ' + (r.kind === 'unit' ? 'fa-building' : (r.kind === 'level' ? 'fa-layer-group' : 'fa-user')) + '"></i> ' +
        (r.kind === 'unit' ? 'واحد: ' : (r.kind === 'level' ? 'رده: ' : '')) + r.label +
        (isSpecialRecipient(r) ? ' <span class="verified-gold" title="تایید شده" aria-label="تایید شده"><i class="fa-solid fa-check"></i></span>' : '') +
        scopeSelect + ' <span class="remove-chip" data-idx="' + idx + '">×</span>';
      (r.role === 'cc' ? ccBox : toBox).appendChild(chip);
    });
    toBox.querySelectorAll('.remove-chip').forEach(function (el) { el.addEventListener('click', function () { removeRecipient(+el.getAttribute('data-idx')); }); });
    ccBox.querySelectorAll('.remove-chip').forEach(function (el) { el.addEventListener('click', function () { removeRecipient(+el.getAttribute('data-idx')); }); });
    toBox.querySelectorAll('.scope-select').forEach(function (el) { el.addEventListener('change', function () { recipients[+el.getAttribute('data-idx')].scope = el.value; }); });
    ccBox.querySelectorAll('.scope-select').forEach(function (el) { el.addEventListener('change', function () { recipients[+el.getAttribute('data-idx')].scope = el.value; }); });
    document.getElementById('recipientsPayload').value = JSON.stringify(recipients);
  }

  makeSearch('recipientSearch', 'recipientResults', 'to');
  makeSearch('ccSearch', 'ccResults', 'cc');

  document.getElementById('letterForm').addEventListener('submit', function () {
    document.getElementById('recipientsPayload').value = JSON.stringify(recipients);
  });
})();
</script>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
