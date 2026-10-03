<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Jalali;
use App\Core\Request;
use App\Core\Upload;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Services\EventService;

/** Admin: webinars, online workshops and online meetings */
final class EventController
{
    private function load(int $id): array
    {
        return DB::one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new HttpException(404);
    }

    public function index(string $type): string
    {
        $t = EventService::type($type);
        $q = Request::str('q');
        $w = 'e.type = ? AND e.deleted_at IS NULL'; $p = [$type];
        if ($q !== '') { $w .= ' AND e.title LIKE ?'; $p[] = "%$q%"; }
        $when = Request::str('when', 'upcoming');
        $now = date('Y-m-d H:i:s');
        if ($when === 'upcoming') { $w .= ' AND (e.starts_at IS NULL OR e.starts_at >= ?)'; $p[] = date('Y-m-d H:i:s', time() - 6 * 3600); }
        elseif ($when === 'past') { $w .= ' AND e.starts_at < ?'; $p[] = $now; }
        $page = DB::paginate("SELECT e.*, g.name AS group_name,
                                     (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'registered') regs,
                                     (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.joined_at IS NOT NULL) joined
                                FROM events e LEFT JOIN `groups` g ON g.id = e.group_id WHERE $w
                               ORDER BY " . ($when === 'past' ? 'e.starts_at DESC' : 'e.starts_at IS NULL, e.starts_at ASC'), $p, 20);
        return view('admin/events/index', ['title' => 'مدیریت ' . $t['plural'], 'type' => $type, 't' => $t, 'page' => $page, 'q' => $q, 'when' => $when]);
    }

    public function create(string $type): string
    {
        $t = EventService::type($type);
        $copy = Request::int('copy') ? DB::one('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [Request::int('copy')]) : null;
        if ($copy) { $copy['starts_at'] = $copy['starts_at'] ? date('Y-m-d H:i:s', strtotime($copy['starts_at'] . ' +1 day')) : null; unset($copy['id']); }
        return view('admin/events/form', ['title' => $t['label'] . ' جدید', 'type' => $type, 't' => $t, 'e' => $copy, 'isEdit' => false, 'groups' => $this->groups()]);
    }

    public function store(string $type): never
    {
        EventService::type($type);
        $row = $this->row($type, null) + ['type' => $type, 'created_by' => Auth::id(), 'created_at' => now()];
        $id = DB::insert('events', $row);
        Audit::log('events.create', 'event', $id, 'success', ['type' => $type]);
        if (\App\Services\TraderGrowth::eventTypeUsed($type)) \App\Services\TraderGrowth::markAllDirty();
        $sent = EventService::announce($id);
        flash('success', EventService::TYPES[$type]['label'] . ' ایجاد شد.' . ($sent ? ' اعلان آن برای ' . fa($sent) . ' کاربر ارسال شد.' : ''));
        redirect('/admin/events/' . $type);
    }

    public function edit(int $id): string
    {
        $e = $this->load($id);
        $t = EventService::type($e['type']);
        return view('admin/events/form', ['title' => 'ویرایش ' . $e['title'], 'type' => $e['type'], 't' => $t, 'e' => $e, 'isEdit' => true, 'groups' => $this->groups()]);
    }

    public function update(int $id): never
    {
        $e = $this->load($id);
        DB::update('events', $this->row($e['type'], $e) + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('events.update', 'event', $id);
        if (\App\Services\TraderGrowth::eventTypeUsed($e['type'])) \App\Services\TraderGrowth::markAllDirty();
        $sent = EventService::announce($id); // e.g. just published
        flash('success', 'تغییرات ذخیره شد.' . ($sent ? ' اعلان آن برای ' . fa($sent) . ' کاربر ارسال شد.' : ''));
        redirect('/admin/events/' . $e['type']);
    }

    /** Quick link change (daily meetings) */
    public function link(int $id): never
    {
        $e = $this->load($id);
        $url = trim(Request::str('join_url'));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) { flash('danger', 'لینک باید با http:// یا https:// شروع شود.'); back(); }
        DB::update('events', ['join_url' => $url ?: null, 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('events.link', 'event', $id);
        flash('success', 'لینک «' . $e['title'] . '» به‌روزرسانی شد.');
        back();
    }

    public function destroy(int $id): never
    {
        $e = $this->load($id);
        $regs = (int)DB::value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'registered' AND cost > 0", [$id]);
        if ($regs && Request::bool('refund')) {
            foreach (DB::column("SELECT id FROM event_registrations WHERE event_id = ? AND status = 'registered'", [$id]) as $rid) EventService::cancel((int)$rid, true, (int)Auth::id());
        }
        DB::update('events', ['deleted_at' => now(), 'status' => 'inactive'], 'id = ?', [$id]);
        Audit::log('events.delete', 'event', $id, 'success', ['refund' => Request::bool('refund')]);
        \App\Services\TraderGrowth::markAllDirty();
        flash('success', '«' . $e['title'] . '» حذف شد' . ($regs && Request::bool('refund') ? ' و اعتبار ثبت‌نام‌کنندگان برگشت داده شد.' : '.'));
        redirect('/admin/events/' . $e['type']);
    }

    public function registrations(int $id): string
    {
        $e = $this->load($id);
        $rows = DB::all("SELECT r.*, u.first_name, u.last_name, u.mobile, u.segment, u.avatar_path, u.is_root, u.id AS uid FROM event_registrations r JOIN users u ON u.id = r.user_id WHERE r.event_id = ? ORDER BY r.status, r.id DESC", [$id]);
        if (Request::str('export') === '1') {
            Xlsx::download('event-' . $id, ['نام', 'نام خانوادگی', 'موبایل', 'نوع کاربر', 'تاریخ ثبت‌نام', 'اعتبار کسرشده', 'ورود به جلسه', 'دفعات ورود', 'وضعیت'],
                array_map(fn($r) => [$r['first_name'], $r['last_name'], $r['mobile'], label('segment_one', $r['segment']), jdatetime($r['created_at']), (int)$r['cost'], $r['joined_at'] ? jdatetime($r['joined_at']) : '—', (int)$r['join_count'], $r['status'] === 'registered' ? 'ثبت‌نام شده' : 'لغو شده'], $rows),
                'ثبت‌نام‌ها');
        }
        return view('admin/events/registrations', ['title' => 'ثبت‌نام‌های ' . $e['title'], 'e' => $e, 't' => EventService::type($e['type']), 'rows' => $rows]);
    }

    public function cancelRegistration(int $id, int $rid): never
    {
        $this->load($id);
        EventService::cancel($rid, Request::bool('refund'), (int)Auth::id());
        flash('success', 'ثبت‌نام لغو شد' . (Request::bool('refund') ? ' و اعتبار برگشت داده شد.' : '.'));
        back();
    }

    private function groups(): array
    {
        return DB::pairs('SELECT id, name FROM `groups` ORDER BY sort, name');
    }

    private function row(string $type, ?array $old): array
    {
        $d = Validator::validate(['title' => 'required|max:200', 'summary' => 'max:500', 'join_url' => 'url|max:500', 'date' => 'required', 'time' => 'required', 'host_name' => 'max:150'],
            ['title' => 'عنوان', 'summary' => 'خلاصه', 'join_url' => 'لینک ورود', 'date' => 'تاریخ برگزاری', 'time' => 'ساعت شروع', 'host_name' => 'ارائه‌دهنده']);
        $time = normalize_input((string)$d['time']);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $tm) || (int)$tm[1] > 23 || (int)$tm[2] > 59) { keep_old($_POST); flash('danger', 'ساعت شروع را به شکل ۱۸:۰۰ وارد کنید.'); back(); }
        $date = Jalali::parse((string)$d['date']);
        if (!$date) { keep_old($_POST); flash('danger', 'تاریخ برگزاری را به شکل ۱۴۰۵/۰۷/۱۰ وارد کنید.'); back(); }
        $hours = (float)str_replace(['٫', '/'], '.', normalize_input(Request::str('duration_hours')));
        $minutes = $type === 'webinar' ? (int)round($hours * 60) : max(0, Request::int('duration_minutes', 60));
        if ($minutes <= 0) { keep_old($_POST); flash('danger', 'مدت زمان را وارد کنید.'); back(); }
        $segs = array_values(array_intersect(Request::arr('segments'), ['merchant', 'agent', 'employee']));
        $row = [
            'title' => $d['title'], 'summary' => ($d['summary'] ?? '') !== '' ? $d['summary'] : null,
            'description' => CourseController::richText($_POST['description'] ?? ''), 'join_url' => ($d['join_url'] ?? '') !== '' ? $d['join_url'] : null,
            'starts_at' => sprintf('%s %02d:%02d:00', $date, (int)$tm[1], (int)$tm[2]), 'duration_minutes' => $minutes,
            'segments' => $segs && count($segs) < 3 ? implode(',', $segs) : null, 'group_id' => Request::intOrNull('group_id'),
            'host_name' => ($d['host_name'] ?? '') !== '' ? $d['host_name'] : null,
            'status' => Request::str('status') === 'inactive' ? 'inactive' : 'active',
        ];
        foreach (['image' => 'image_file_id', 'banner' => 'banner_file_id'] as $field => $col) {
            if ($f = Request::file($field)) {
                $file = Upload::store($f, ['image'], ['folder' => 'events', 'max_mb' => 8, 'library' => 0]);
                $row[$col] = (int)$file['id'];
            } elseif ($old && !Request::bool('remove_' . $field)) {
                $row[$col] = $old[$col];
            } elseif (!$old && ($keep = Request::intOrNull('keep_' . $field)) && DB::value("SELECT 1 FROM files WHERE id = ? AND kind = 'image' AND deleted_at IS NULL", [$keep])) {
                $row[$col] = $keep; // duplicated event keeps the images
            } else {
                $row[$col] = null;
            }
        }
        return $row;
    }
}
