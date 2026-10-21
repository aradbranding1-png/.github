<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Modules\Letters\AnnouncementService;
use App\Modules\Letters\LetterService;

/**
 * /admin/letters — letters from Arad Branding (free).
 *  - "همه کاربران": one announcement row, visible to everyone in the «آراد برندینگ» tab.
 *  - "کاربران منتخب": a real conversation (type official) with each user, who can reply.
 */
final class OfficialLetterController extends Controller
{
    public function index(Request $request, array $errors = [], int $status = 200): Response
    {
        return $this->view($request, 'admin/letters', [
            'title' => 'نامه‌های آراد برندینگ',
            'announcements' => $this->c->get(AnnouncementService::class)->latest(50),
            'old' => $request->all(),
            'errors' => $errors,
        ], 'layouts/app', $status);
    }

    public function store(Request $request): Response
    {
        $admin = $this->user($request);
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        $audience = $request->input('audience') === 'selected' ? 'selected' : 'all';
        $errors = [];
        if ($subject === '' || mb_strlen($subject) > 150) {
            $errors['subject'] = 'موضوع را بنویسید (حداکثر ۱۵۰ نویسه).';
        }
        if ($body === '' || mb_strlen($body) > 20000) {
            $errors['body'] = 'متن نامه را بنویسید.';
        }
        $handles = array_values(array_unique(array_filter(array_map(
            static fn (string $h): string => strtolower(trim(ltrim(trim($h), '@'))),
            preg_split('/[\s,،]+/u', (string) $request->input('handles', '')) ?: []
        ))));
        $recipients = [];
        if ($audience === 'selected') {
            if ($handles === []) {
                $errors['handles'] = 'نشانی صفحه گیرندگان را وارد کنید.';
            } else {
                $recipients = array_map('intval', array_column($this->c->get(Connection::class)->select(
                    'SELECT id FROM users WHERE deleted_at IS NULL AND handle IN (' . implode(',', array_fill(0, min(500, count($handles)), '?')) . ')',
                    array_slice($handles, 0, 500)
                ), 'id'));
                if ($recipients === []) {
                    $errors['handles'] = 'هیچ کاربری با این نشانی‌ها پیدا نشد.';
                }
            }
        }
        if ($errors !== []) {
            return $this->index($request, $errors, 422);
        }

        if ($audience === 'all') {
            $uid = $this->c->get(AnnouncementService::class)->create($admin['id'], $subject, $body);
            $this->c->get(Audit::class)->log('official.announcement', $admin['id'], 'announcement', null, 'success', $request, ['uid' => $uid, 'subject' => $subject]);
            return $this->redirect('/admin/letters', 'نامه برای همه کاربران منتشر شد.');
        }
        $letters = $this->c->get(LetterService::class);
        $db = $this->c->get(Connection::class);
        $sent = 0;
        foreach ($recipients as $rid) {
            if ($rid === (int) $admin['id']) {
                continue;
            }
            $db->transaction(fn () => $letters->openThread(LetterService::T_OFFICIAL, (int) $admin['id'], $rid, $subject, $body, null));
            $sent++;
        }
        $this->c->get(Audit::class)->log('official.letters', $admin['id'], null, null, 'success', $request, ['recipients' => $recipients, 'subject' => $subject]);
        return $this->redirect('/admin/letters', 'نامه برای ' . fa_int($sent) . ' کاربر ارسال شد.');
    }

    public function destroy(Request $request): Response
    {
        $this->c->get(AnnouncementService::class)->delete((string) $request->param('uid'));
        $this->c->get(Audit::class)->log('official.announcement_delete', $this->user($request)['id'], 'announcement', null, 'success', $request);
        return $this->redirect('/admin/letters', 'نامه حذف شد.');
    }
}
