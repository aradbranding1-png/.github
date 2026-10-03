<?php

declare(strict_types=1);

namespace App\Modules\Trust;

use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Modules\Users\ValidationFailed;

/** Member side of Trust & Safety: report something, block/unblock a trader, and «حریم و امنیت» (blocked list + own reports). */
final class SafetyController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $trust = $this->c->get(TrustService::class);
        return $this->view($request, 'account/safety', [
            'title' => 'حریم و امنیت',
            'blocked' => $trust->blockedList((int) $user['id']),
            'reports' => $trust->mine((int) $user['id']),
        ]);
    }

    /** POST /reports — type, id, reason, details, optional block=1, back. */
    public function report(Request $request): Response
    {
        $user = $this->user($request);
        $back = self::back($request);
        $type = TrustService::TARGET_KEYS[(string) $request->input('type', '')] ?? 0;
        $id = (int) $request->input('id', 0);
        $trust = $this->c->get(TrustService::class);
        try {
            $new = $trust->report((int) $user['id'], $type, $id, (string) $request->input('reason', ''), (string) $request->input('details', ''));
        } catch (ValidationFailed $e) {
            return $this->redirect($back, (string) reset($e->errors), 'error');
        }
        $msg = $new ? 'گزارش شما ثبت شد. تیم آراد برندینگ آن را بررسی می‌کند و نتیجه را به شما خبر می‌دهد.' : 'گزارش قبلی شما به‌روز شد.';
        if ((string) $request->input('block', '') === '1') {
            $target = $trust->target((int) $user['id'], $type, $id);
            if ($target !== null && $trust->block((int) $user['id'], $target['user_id'])) {
                $msg .= ' این تاجر مسدود شد.';
            }
        }
        $this->c->get(Audit::class)->log('trust.report', (int) $user['id'], 'report', $id, 'success', $request, ['type' => $type, 'new' => $new]);
        return $this->redirect($back, $msg);
    }

    /** POST /blocks/{id} */
    public function block(Request $request): Response
    {
        $user = $this->user($request);
        $other = (int) $request->param('id');
        $exists = $this->c->get(\App\Core\Db\Connection::class)->scalar('SELECT 1 FROM users WHERE id = ? AND deleted_at IS NULL', [$other]);
        if ($exists === null || $other === (int) $user['id']) {
            return $this->redirect(self::back($request), 'این تاجر پیدا نشد.', 'error');
        }
        $this->c->get(TrustService::class)->block((int) $user['id'], $other);
        return $this->redirect(self::back($request), 'مسدود شد. دیگر نمی‌توانید با این تاجر نامه یا پیشنهاد ردوبدل کنید و نامه‌های عمومی‌اش به شما نمی‌رسد.');
    }

    /** POST /blocks/{id}/delete */
    public function unblock(Request $request): Response
    {
        $user = $this->user($request);
        $this->c->get(TrustService::class)->unblock((int) $user['id'], (int) $request->param('id'));
        return $this->redirect(self::back($request), 'رفع مسدودی انجام شد.');
    }

    /** Same-site path only. */
    private static function back(Request $request): string
    {
        $back = (string) $request->input('back', '/account/safety');
        return preg_match('~^/(?!/)[^\s\\\\]*$~', $back) ? $back : '/account/safety';
    }
}
