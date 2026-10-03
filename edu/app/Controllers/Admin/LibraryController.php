<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Upload;

/** Content library: videos, audio, PDFs, images and documents with per-file view/download policy */
final class LibraryController
{
    public function index(): string
    {
        $w = 'f.deleted_at IS NULL AND f.is_library = 1';
        $p = [];
        if (($k = Request::str('kind')) && isset(Upload::kinds()[$k])) { $w .= ' AND f.kind = ?'; $p[] = $k; }
        if (($q = Request::str('q')) !== '') { $w .= ' AND (f.title LIKE ? OR f.original_name LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if (($fo = Request::str('folder')) !== '') { $w .= ' AND f.folder = ?'; $p[] = $fo; }
        $page = DB::paginate("SELECT f.*, u.first_name, u.last_name,
                                (SELECT COUNT(*) FROM lessons l WHERE l.media_file_id = f.id AND l.deleted_at IS NULL) + (SELECT COUNT(*) FROM lesson_files lf WHERE lf.file_id = f.id) AS used
                                FROM files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE $w ORDER BY f.id DESC", $p, 24);
        $stats = DB::all('SELECT kind, COUNT(*) n, SUM(size) s FROM files WHERE deleted_at IS NULL AND is_library = 1 GROUP BY kind');
        $folders = DB::column('SELECT DISTINCT folder FROM files WHERE deleted_at IS NULL AND folder IS NOT NULL ORDER BY folder');
        return view('admin/library/index', ['title' => 'کتابخانه محتوا', 'page' => $page, 'stats' => $stats, 'folders' => $folders]);
    }

    public function upload(): never
    {
        $n = 0; $errs = [];
        $files = $_FILES['files'] ?? null;
        if (!$files || !is_array($files['name'])) { flash('danger', 'فایلی انتخاب نشده است.'); redirect('/admin/library'); }
        foreach ($files['name'] as $i => $name) {
            $one = ['name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            if ($one['error'] === UPLOAD_ERR_NO_FILE) continue;
            try {
                $row = Upload::store($one, array_keys(Upload::kinds()), ['folder' => Request::str('folder') ?: null, 'viewable' => Request::bool('viewable') ? 1 : 0, 'downloadable' => Request::bool('downloadable') ? 1 : 0]);
                Audit::log('library.upload', 'file', (int)$row['id'], 'success', ['name' => $row['original_name'], 'size' => $row['size']]);
                $n++;
            } catch (HttpException $e) {
                $errs[] = e($name) . ': ' . e($e->getMessage());
            }
        }
        if ($n) flash('success', fa($n) . ' فایل به کتابخانه اضافه شد.');
        if ($errs) flash('danger', implode('<br>', $errs), true);
        redirect('/admin/library');
    }

    public function update(int $id): never
    {
        DB::find('files', $id) ?? throw new HttpException(404);
        DB::update('files', [
            'title' => mb_substr(Request::str('title'), 0, 200) ?: null, 'folder' => mb_substr(Request::str('folder'), 0, 100) ?: null,
            'viewable' => Request::bool('viewable') ? 1 : 0, 'downloadable' => Request::bool('downloadable') ? 1 : 0,
        ], 'id = ?', [$id]);
        Audit::log('library.update', 'file', $id);
        flash('success', 'تنظیمات فایل ذخیره شد.');
        back();
    }

    public function destroy(int $id): never
    {
        $f = DB::find('files', $id) ?? throw new HttpException(404);
        DB::update('files', ['deleted_at' => now()], 'id = ?', [$id]);
        Audit::log('library.delete', 'file', $id, 'success', ['name' => $f['original_name']]);
        flash('success', 'فایل از کتابخانه حذف شد.');
        back();
    }
}
