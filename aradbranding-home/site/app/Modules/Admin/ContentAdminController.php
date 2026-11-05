<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Support\Ulid;
use App\Modules\Proposals\ProposalService;

/** Moderation of business pages and proposals: hide, restore, reject (doc §54). */
final class ContentAdminController extends AdminController
{
    public function index(Request $request): Response
    {
        $tab = $request->query('tab') === 'pages' ? 'pages' : 'proposals';
        $status = $request->query('status');
        $before = (int) $request->query('before', '0');
        $db = $this->c->get(Connection::class);

        if ($tab === 'pages') {
            if (!$this->allows($request, 'pages.view') && !$this->allows($request, 'pages.approve')) {
                throw new HttpException(403);
            }
            $where = ['p.deleted_at IS NULL'];
            $bind = [];
            if (is_string($status) && ctype_digit($status)) {
                $where[] = 'p.status = ?';
                $bind[] = (int) $status;
            }
            if ($before > 0) {
                $where[] = 'p.id < ?';
                $bind[] = $before;
            }
            $rows = $db->select(
                'SELECT p.id, p.public_id, p.title, p.teaser, p.status, p.created_at, l.code AS lang, u.first_name, u.last_name, u.handle, u.id AS user_id
                 FROM pages p JOIN languages l ON l.id = p.language_id JOIN users u ON u.id = p.user_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 31',
                $bind
            );
        } else {
            if (!$this->allows($request, 'proposals.view') && !$this->allows($request, 'proposals.moderate')) {
                throw new HttpException(403);
            }
            $where = ['p.deleted_at IS NULL'];
            $bind = [];
            if (is_string($status) && ctype_digit($status)) {
                $where[] = 'p.status = ?';
                $bind[] = (int) $status;
            }
            if ($before > 0) {
                $where[] = 'p.id < ?';
                $bind[] = $before;
            }
            $rows = $db->select(
                'SELECT p.id, p.public_id, p.title, p.summary, p.status, p.reject_reason, p.thumb_path, p.created_at, u.first_name, u.last_name, u.handle, u.id AS user_id
                 FROM proposals p JOIN users u ON u.id = p.user_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 31',
                $bind
            );
        }
        $next = null;
        if (count($rows) > 30) {
            array_pop($rows);
            $next = (int) end($rows)['id'];
        }
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        return $this->view($request, 'admin/content', [
            'title' => 'محتوا و بررسی',
            'tab' => $tab,
            'rows' => $rows,
            'next' => $next,
            'status' => is_string($status) ? $status : '',
            'pendingCount' => $tab === 'proposals' ? (int) $db->scalar('SELECT COUNT(*) FROM proposals WHERE status = 1 AND deleted_at IS NULL') : 0,
        ]);
    }

    public function page(Request $request): Response
    {
        if (!$this->allows($request, 'pages.approve')) {
            throw new HttpException(403);
        }
        $db = $this->c->get(Connection::class);
        $p = $db->first('SELECT id, user_id, status FROM pages WHERE id = ? AND deleted_at IS NULL', [(int) $request->param('id')]);
        if ($p === null) {
            throw new HttpException(404);
        }
        if ($request->input('action') === 'delete') {
            // Only administrators delete pages; owners can just switch them off.
            $page = $db->first('SELECT * FROM pages WHERE id = ?', [$p['id']]);
            $this->c->get(\App\Modules\Pages\PageService::class)->delete($page);
            $this->c->get(Audit::class)->log('pages.delete', (int) $this->user($request)['id'], 'page', (int) $p['id'], 'success', $request);
            return $this->redirect('/admin/content?tab=pages', 'صفحه حذف شد.');
        }
        $to = match ($request->input('action')) {
            'hide' => 3,
            'reject' => 4,
            'restore' => 2,
            default => throw new HttpException(400),
        };
        $db->exec('UPDATE pages SET status = ?, version = version + 1, updated_at = NOW(3) WHERE id = ?', [$to, $p['id']]);
        $this->c->get(Cache::class)->bump('owner:' . $p['user_id']);
        $this->c->get(Audit::class)->log('pages.moderate', (int) $this->user($request)['id'], 'page', (int) $p['id'], 'success', $request, ['status' => $to]);
        return $this->redirect('/admin/content?tab=pages', 'وضعیت صفحه تغییر کرد.');
    }

    public function proposal(Request $request): Response
    {
        if (!$this->allows($request, 'proposals.moderate')) {
            throw new HttpException(403);
        }
        $db = $this->c->get(Connection::class);
        $p = $db->first('SELECT id, user_id, status FROM proposals WHERE id = ? AND deleted_at IS NULL', [(int) $request->param('id')]);
        if ($p === null) {
            throw new HttpException(404);
        }
        $to = match ($request->input('action')) {
            'hide' => ProposalService::HIDDEN,
            'reject' => ProposalService::REJECTED,
            'restore' => ProposalService::PUBLISHED,
            default => throw new HttpException(400),
        };
        $reason = null;
        if ($to === ProposalService::REJECTED) {
            $reason = trim((string) $request->input('reason', ''));
            if ($reason === '') {
                return $this->redirect('/admin/content', 'برای رد پیشنهاد، دلیل را بنویسید تا صاحب آن بتواند اصلاح کند.', 'error');
            }
            $reason = mb_substr($reason, 0, 500);
        }
        $service = $this->c->get(ProposalService::class);
        $db->transaction(function () use ($db, $service, $p, $to): void {
            if ($to === ProposalService::PUBLISHED) {
                // A draft was never published: it has no published_at, which the feed row requires.
                $days = max(1, (int) $this->c->get(\App\Core\Settings\Settings::class)->get('proposal.lifetime_days', 90));
                $db->exec(
                    'UPDATE proposals SET status = ?, reject_reason = NULL, published_at = COALESCE(published_at, NOW(3)),
                            expires_at = IF(expires_at IS NULL OR expires_at < NOW(3), NOW(3) + INTERVAL ? DAY, expires_at),
                            updated_at = NOW(3) WHERE id = ?',
                    [$to, $days, $p['id']]
                );
            } else {
                $db->exec('UPDATE proposals SET status = ?, reject_reason = ?, updated_at = NOW(3) WHERE id = ?', [$to, $reason, $p['id']]);
            }
            $service->syncFeed((int) $p['id']);
        });
        if ($reason !== null) {
            $uid = strtolower(Ulid::toString($db->scalar('SELECT public_id FROM proposals WHERE id = ?', [$p['id']])));
            $this->c->get(\App\Modules\Notifications\NotificationService::class)->notify(
                [(int) $p['user_id']], 'proposal_rejected', (int) $this->user($request)['id'], '/proposals/' . $uid . '/edit', ['subject' => $reason]
            );
        }
        $this->c->get(Audit::class)->log('proposals.moderate', (int) $this->user($request)['id'], 'proposal', (int) $p['id'], 'success', $request, ['status' => $to, 'reason' => $reason]);
        return $this->redirect('/admin/content', 'وضعیت پیشنهاد تغییر کرد.');
    }
}
