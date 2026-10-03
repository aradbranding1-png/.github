<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Credit;
use App\Services\EventService;

/** Learner side of webinars, online workshops and online meetings */
final class EventsController
{
    private function me(): array
    {
        return Auth::user() ?? throw new HttpException(401);
    }

    private function event(int $id): array
    {
        $e = DB::one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
        if (!EventService::canSee($this->me(), $e)) throw new HttpException(404);
        return $e;
    }

    /** Catalog of one type: upcoming first, then past */
    public function index(string $type): string
    {
        $t = EventService::type($type);
        $u = $this->me();
        [$w, $p] = EventService::visibleSql($u);
        $rows = DB::all("SELECT e.*, r.id AS reg_id, r.joined_at FROM events e
                           LEFT JOIN event_registrations r ON r.event_id = e.id AND r.user_id = ? AND r.status = 'registered'
                          WHERE e.type = ? AND $w ORDER BY e.starts_at IS NULL, e.starts_at ASC LIMIT 300", array_merge([(int)$u['id'], $type], $p));
        $upcoming = []; $past = [];
        foreach ($rows as $r) {
            if (EventService::phase($r) === 'ended') $past[] = $r; else $upcoming[] = $r;
        }
        $past = array_reverse($past);
        return view('learn/events', ['title' => $t['plural'], 'type' => $type, 't' => $t, 'upcoming' => $upcoming, 'past' => array_slice($past, 0, 30), 'bal' => Credit::balances((int)$u['id']), 'staff' => EventService::isStaff($u)]);
    }

    public function show(int $id): string
    {
        $e = $this->event($id);
        $u = $this->me();
        $reg = EventService::registration((int)$u['id'], $id);
        [$may] = EventService::mayJoin($u, $e);
        return view('learn/event', ['title' => $e['title'], 'e' => $e, 't' => EventService::type($e['type']), 'reg' => $reg, 'mayJoin' => $may,
            'bal' => Credit::balances((int)$u['id']), 'cost' => EventService::isStaff($u) ? 0 : EventService::cost($e), 'phase' => EventService::phase($e), 'staff' => EventService::isStaff($u)]);
    }

    public function register(int $id): never
    {
        $e = $this->event($id);
        [$ok, $msg] = EventService::register($this->me(), $e);
        if (!$ok) { flash('danger', $msg . ($e['type'] !== 'meeting' ? ' ' . setting('minutes_charge_text') : '')); redirect('/learn/event/' . $id); }
        if ($msg !== 'already') {
            $cost = EventService::isStaff($this->me()) ? 0 : EventService::cost($e);
            flash('success', 'ثبت‌نام شما انجام شد' . ($cost ? ' و ' . Credit::amount($e['type'], $cost) . ' از اعتبار شما کسر شد' : '') . '. لینک ورود پایین همین صفحه است و در «خدمات و جلسات من» هم ذخیره شد.');
        }
        redirect('/learn/event/' . $id);
    }

    /** Opens the join link (and records attendance) */
    public function join(int $id): never
    {
        $e = $this->event($id);
        $u = $this->me();
        [$ok, $why] = EventService::mayJoin($u, $e);
        if (!$ok) { flash('warning', $why); redirect('/learn/event/' . $id); }
        $url = (string)$e['join_url'];
        if (!preg_match('~^https?://~i', $url)) { flash('info', 'لینک ورود این جلسه هنوز ثبت نشده است؛ کمی قبل از شروع دوباره سر بزنید.'); redirect('/learn/event/' . $id); }
        EventService::recordJoin($u, $e);
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** "خدمات و جلسات من": courses + webinars + workshops + meetings with credit summary */
    public function services(): string
    {
        $u = $this->me();
        $tab = Request::str('tab', 'webinar');
        if (!in_array($tab, ['courses', 'webinar', 'workshop', 'meeting'], true)) $tab = 'webinar';
        $data = ['title' => 'خدمات و جلسات من', 'tab' => $tab, 'bal' => Credit::balances((int)$u['id']), 'u' => $u,
            'counts' => [
                'courses' => (int)DB::value('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', [(int)$u['id']]),
                'webinar' => (int)DB::value("SELECT COUNT(*) FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.status = 'registered' AND e.type = 'webinar' AND e.deleted_at IS NULL", [(int)$u['id']]),
                'workshop' => (int)DB::value("SELECT COUNT(*) FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.status = 'registered' AND e.type = 'workshop' AND e.deleted_at IS NULL", [(int)$u['id']]),
            ],
            'used' => DB::pairs("SELECT credit_type, -SUM(delta) FROM minute_ledger WHERE user_id = ? AND kind = 'consume' GROUP BY credit_type", [(int)$u['id']]),
        ];
        if ($tab === 'courses') {
            $data['rows'] = DB::all("SELECT e.*, c.title, c.summary, c.image_file_id, c.duration_minutes, c.id AS course_id FROM enrollments e JOIN courses c ON c.id = e.course_id
                                      WHERE e.user_id = ? AND c.deleted_at IS NULL ORDER BY e.last_activity_at IS NULL, e.last_activity_at DESC, e.id DESC", [(int)$u['id']]);
        } else {
            $data['rows'] = EventService::mine($u, $tab);
        }
        return view('learn/services', $data);
    }
}
