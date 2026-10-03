<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Auth\Auth;
use App\Core\Auth\Gate;
use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Support\Ulid;
use App\Modules\Notifications\NotificationService;
use App\Modules\Proposals\ProposalService;
use App\Modules\Trust\TrustService;

/**
 * «گزارش‌های تخلف»: the moderation queue for abuse reports (pages, proposals, letters, traders).
 * Anyone with a moderation permission sees the queue; each outcome needs its own permission:
 * hiding a letter → letters.moderate (only they see letter text), a page → pages.approve, a proposal →
 * proposals.moderate; restricting/suspending/banning → users.edit in scope (suspend/ban also need the password).
 * The reporter is told the report was reviewed; the reported member is told about warnings and hidden content.
 */
final class TrustAdminController extends AdminController
{
    private const VIEW_PERMS = ['letters.moderate', 'proposals.moderate', 'pages.approve', 'users.edit'];

    public function index(Request $request, array $errors = [], int $status = 200): Response
    {
        $this->guard($request);
        $db = $this->c->get(Connection::class);
        $tab = (int) $request->query('status', (string) TrustService::OPEN);
        $tab = isset(TrustService::STATUS[$tab]) ? $tab : TrustService::OPEN;
        $typeKey = (string) $request->query('type', '');
        $type = TrustService::TARGET_KEYS[$typeKey] ?? 0;
        $userFilter = (int) $request->query('user', '0');
        $before = (int) $request->query('before', '0');

        $where = ['r.status = ?'];
        $bind = [$tab];
        if ($type > 0) {
            $where[] = 'r.target_type = ?';
            $bind[] = $type;
        }
        if ($userFilter > 0) {
            $where = ['r.target_user_id = ?'];
            $bind = [$userFilter];
        }
        if ($before > 0) {
            $where[] = 'r.id < ?';
            $bind[] = $before;
        }
        $rows = $db->select(
            'SELECT r.*, rp.first_name AS rp_first, rp.last_name AS rp_last, rp.handle AS rp_handle,
                    t.first_name AS t_first, t.last_name AS t_last, t.handle AS t_handle, t.status AS t_status, t.country_id AS t_country,
                    tc.code AS t_cc, tp.company_name AS t_company, h.first_name AS h_first, h.last_name AS h_last,
                    (SELECT COUNT(*) FROM abuse_reports x WHERE x.target_user_id = r.target_user_id) AS t_reports,
                    (SELECT COUNT(*) FROM abuse_reports y WHERE y.target_type = r.target_type AND y.target_id = r.target_id) AS same_target
               FROM abuse_reports r
               JOIN users rp ON rp.id = r.reporter_id
               JOIN users t ON t.id = r.target_user_id JOIN countries tc ON tc.id = t.country_id
               LEFT JOIN user_profiles tp ON tp.user_id = t.id
               LEFT JOIN users h ON h.id = r.handled_by
              WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 26',
            $bind
        );
        $next = null;
        if (count($rows) > 25) {
            array_pop($rows);
            $next = (int) end($rows)['id'];
        }
        $canReadLetters = $this->allows($request, 'letters.moderate');
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
            $r['preview'] = $this->preview((int) $r['target_type'], (int) $r['target_id'], $r['target_at'], $canReadLetters);
        }
        unset($r);
        $counts = [];
        foreach ($db->select('SELECT status, COUNT(*) AS n FROM abuse_reports GROUP BY status') as $c) {
            $counts[(int) $c['status']] = (int) $c['n'];
        }
        return $this->view($request, 'admin/trust', [
            'title' => 'گزارش‌های تخلف',
            'rows' => $rows,
            'next' => $next,
            'tab' => $tab,
            'typeKey' => $type > 0 ? $typeKey : '',
            'userFilter' => $userFilter,
            'counts' => $counts,
            'can' => [
                'letters' => $canReadLetters,
                'pages' => $this->allows($request, 'pages.approve'),
                'proposals' => $this->allows($request, 'proposals.moderate'),
                'users' => $this->allows($request, 'users.edit'),
            ],
            'errors' => $errors,
        ], 'layouts/app', $status);
    }

    /** POST /admin/trust/{id} — action, note, (password for suspend/ban), days, all=1 to close every open report on the same item. */
    public function resolve(Request $request): Response
    {
        $this->guard($request);
        $db = $this->c->get(Connection::class);
        $report = $db->first('SELECT * FROM abuse_reports WHERE id = ?', [(int) $request->param('id')]);
        if ($report === null) {
            throw new HttpException(404);
        }
        $action = (string) $request->input('action', '');
        $note = mb_substr(trim((string) $request->input('note', '')), 0, 500);
        $back = '/admin/trust' . ((int) $report['status'] === TrustService::OPEN ? '' : '?status=' . (int) $report['status']);
        if (!isset(TrustService::ACTIONS[$action])) {
            return $this->index($request, ['r' . $report['id'] => 'نتیجه بررسی را انتخاب کنید.'], 422);
        }
        if ($action !== 'dismiss' && mb_strlen($note) < 3) {
            return $this->index($request, ['r' . $report['id'] => 'توضیح را بنویسید؛ برای اخطار و پنهان‌کردن، کاربر آن را می‌بیند.'], 422);
        }
        $type = (int) $report['target_type'];
        $targetId = (int) $report['target_id'];
        $target = $db->first('SELECT u.id, u.status, u.handle, u.country_id FROM users u WHERE u.id = ?', [$report['target_user_id']]);
        if ($target === null) {
            throw new HttpException(404);
        }
        $staff = (int) $this->user($request)['id'];
        $gate = $this->c->get(Gate::class);
        $notifications = $this->c->get(NotificationService::class);

        // Apply the outcome.
        switch ($action) {
            case 'hide':
                $perm = [TrustService::T_MESSAGE => 'letters.moderate', TrustService::T_PAGE => 'pages.approve', TrustService::T_PROPOSAL => 'proposals.moderate'][$type] ?? null;
                if ($perm === null || !$this->allows($request, $perm)) {
                    return $this->index($request, ['r' . $report['id'] => 'برای پنهان‌کردن این مورد دسترسی ندارید.'], 403);
                }
                $this->hide($type, $targetId, $report['target_at'], (int) $target['id'], $staff);
                $notifications->notify([(int) $target['id']], 'trust_hidden', null, '/account/safety', ['subject' => $note]);
                break;
            case 'warn':
                $notifications->notify([(int) $target['id']], 'trust_warning', null, '/account/safety', ['subject' => $note]);
                break;
            case 'restrict':
            case 'suspend':
            case 'ban':
                if (!$this->inScope($request, 'users.edit', (int) $target['country_id'])) {
                    return $this->index($request, ['r' . $report['id'] => 'برای تغییر وضعیت این حساب دسترسی ندارید.'], 403);
                }
                if ($gate->hasRole((int) $target['id'], 'super_admin')) {
                    return $this->index($request, ['r' . $report['id'] => 'حساب مدیر کل را نمی‌توان محدود یا مسدود کرد.'], 422);
                }
                if ($action !== 'restrict' && !$this->reauth($request)) {
                    return $this->index($request, ['r' . $report['id'] => 'برای تعلیق یا مسدودکردن، رمز عبور خود را درست وارد کنید.'], 422);
                }
                $status = ['restrict' => Auth::STATUS_RESTRICTED, 'suspend' => Auth::STATUS_SUSPENDED, 'ban' => Auth::STATUS_BANNED][$action];
                $days = (int) $request->input('days', 7);
                $until = $action === 'suspend' && in_array($days, UserAdminController::SUSPEND_DAYS, true) ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
                $this->c->get(TrustService::class)->setStatus($target, $status, $until, $note);
                if ($action === 'restrict') {
                    $notifications->notify([(int) $target['id']], 'trust_restricted', null, '/account/safety', ['subject' => $note]);
                }
                break;
        }

        // Close this report (and, by default, every other open report on the same item).
        $outcome = $action === 'dismiss' ? TrustService::DISMISSED : TrustService::ACTIONED;
        $sameItem = (string) $request->input('all', '0') === '1';
        $closed = $db->select(
            'SELECT id, reporter_id FROM abuse_reports WHERE status = ? AND ' . ($sameItem ? 'target_type = ? AND target_id = ?' : 'id = ?'),
            $sameItem ? [TrustService::OPEN, $type, $targetId] : [TrustService::OPEN, $report['id']]
        );
        $ids = array_map('intval', array_column($closed, 'id'));
        if ((int) $report['status'] !== TrustService::OPEN) {
            $ids[] = (int) $report['id']; // re-deciding an already closed report
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            $db->exec(
                'UPDATE abuse_reports SET status = ?, action = ?, resolution = ?, handled_by = ?, handled_at = NOW(3) WHERE id IN (' . implode(',', $ids) . ')',
                [$outcome, $action, $note !== '' ? $note : null, $staff]
            );
        }
        $reporters = array_map('intval', array_column($closed, 'reporter_id'));
        if ($reporters !== []) {
            $name = (string) $db->scalar("SELECT COALESCE(NULLIF(p.company_name, ''), CONCAT(u.first_name, ' ', u.last_name)) FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?", [$target['id']]);
            $notifications->notify($reporters, 'report_resolved', null, '/account/safety', [
                'name' => $name,
                'subject' => $outcome === TrustService::DISMISSED ? 'تخلفی دیده نشد. از همراهی شما سپاسگزاریم.' : 'اقدام لازم انجام شد. از همراهی شما سپاسگزاریم.',
            ]);
        }
        $this->c->get(Audit::class)->log('trust.resolve', $staff, 'user', (int) $target['id'], 'success', $request,
            ['report' => (int) $report['id'], 'action' => $action, 'note' => $note, 'closed' => count($ids), 'target_type' => $type, 'target_id' => $targetId],
            $request->attribute('impersonator_id'));
        return $this->redirect($back, 'گزارش بررسی شد: ' . TrustService::ACTIONS[$action] . '. ' . fa_int(count($ids)) . ' گزارش بسته شد.');
    }

    private function guard(Request $request): void
    {
        foreach (self::VIEW_PERMS as $p) {
            if ($this->allows($request, $p)) {
                return;
            }
        }
        throw new HttpException(403);
    }

    private function hide(int $type, int $id, ?string $at, int $ownerId, int $staff): void
    {
        $db = $this->c->get(Connection::class);
        if ($type === TrustService::T_MESSAGE) {
            $db->exec('UPDATE letter_messages SET hidden_at = NOW(3), hidden_by = ? WHERE id = ?' . ($at !== null ? ' AND created_at = ?' : ''),
                $at !== null ? [$staff, $id, $at] : [$staff, $id]);
            // The inbox preview of that thread must not keep showing the hidden text.
            $thread = $db->scalar('SELECT thread_id FROM letter_messages WHERE id = ? LIMIT 1', [$id]);
            if ($thread !== null) {
                $db->exec("UPDATE thread_participants SET preview = 'این پیام توسط تیم بررسی پنهان شد.'
                           WHERE thread_id = ? AND ? = (SELECT MAX(m.id) FROM letter_messages m WHERE m.thread_id = ?)", [$thread, $id, $thread]);
            }
        } elseif ($type === TrustService::T_PAGE) {
            $db->exec('UPDATE pages SET status = 3, version = version + 1, updated_at = NOW(3) WHERE id = ?', [$id]);
            $this->c->get(Cache::class)->bump('owner:' . $ownerId);
        } elseif ($type === TrustService::T_PROPOSAL) {
            $db->exec('UPDATE proposals SET status = ?, updated_at = NOW(3) WHERE id = ?', [ProposalService::HIDDEN, $id]);
            $this->c->get(ProposalService::class)->syncFeed($id);
        }
    }

    /** What was reported, for the reviewer. Letter text only for letters.moderate. @return array<string, mixed> */
    private function preview(int $type, int $id, ?string $at, bool $canReadLetters): array
    {
        $db = $this->c->get(Connection::class);
        switch ($type) {
            case TrustService::T_PAGE:
                $p = $db->first('SELECT p.title, p.teaser, p.status, l.code, u.handle FROM pages p JOIN languages l ON l.id = p.language_id JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$id]);
                return $p === null ? ['gone' => true] : ['title' => $p['title'], 'text' => (string) $p['teaser'], 'link' => $p['handle'] ? '/p/' . $p['handle'] . '/' . $p['code'] : null, 'hidden' => (int) $p['status'] === 3];
            case TrustService::T_PROPOSAL:
                $p = $db->first('SELECT public_id, title, summary, status FROM proposals WHERE id = ?', [$id]);
                return $p === null ? ['gone' => true] : ['title' => $p['title'], 'text' => (string) $p['summary'], 'link' => '/proposals/' . strtolower(Ulid::toString($p['public_id'])), 'hidden' => (int) $p['status'] === ProposalService::HIDDEN];
            case TrustService::T_MESSAGE:
                $m = $db->first(
                    'SELECT COALESCE(m.body, c.body) AS body, m.hidden_at, t.subject, t.type FROM letter_messages m
                     JOIN letter_threads t ON t.id = m.thread_id LEFT JOIN letter_campaigns c ON c.id = m.campaign_id
                     WHERE m.id = ?' . ($at !== null ? ' AND m.created_at = ?' : '') . ' LIMIT 1',
                    $at !== null ? [$id, $at] : [$id]
                );
                if ($m === null) {
                    return ['gone' => true];
                }
                return ['title' => $m['subject'], 'text' => $canReadLetters ? (string) $m['body'] : null, 'link' => null, 'hidden' => $m['hidden_at'] !== null];
            default:
                return ['title' => null, 'text' => null, 'link' => null, 'hidden' => false];
        }
    }
}
