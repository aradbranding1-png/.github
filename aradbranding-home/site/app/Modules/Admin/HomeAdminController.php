<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Cache\Cache;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Settings\Settings;
use App\Core\Storage\ImageUploader;
use App\Core\Storage\UploadException;
use App\Modules\System\HomeContent;

/** /admin/home — every section and item of the public home page. Requires settings.manage. Every change is audited. */
final class HomeAdminController extends Controller
{
    /** Lists whose rows can carry an uploaded image => ImageUploader preset. */
    private const IMAGES = ['markets' => 'thumb', 'opps' => 'thumb'];

    public function show(Request $request, array $errors = [], ?array $content = null, int $status = 200): Response
    {
        return $this->view($request, 'admin/home', [
            'title' => 'صفحه اصلی سایت',
            'home' => $content ?? $this->content()->get(),
            'errors' => $errors,
            'maxKb' => $this->c->get(ImageUploader::class)->maxKb(),
        ], 'layouts/app', $status);
    }

    public function update(Request $request): Response
    {
        $actor = (int) $this->user($request)['id'];
        $service = $this->content();
        $current = $service->get();
        $input = $request->input('home');
        $content = HomeContent::sanitize(is_array($input) ? $input : [], $current);

        // Images: new uploads replace, "remove" clears; the stored path is re-validated by sanitize().
        $images = $this->c->get(ImageUploader::class);
        $errors = [];
        $old = [];
        $new = [];
        foreach (self::IMAGES as $list => $preset) {
            foreach (array_keys($content[$list]) as $i) {
                // Rows keep their form index in "_src" so an upload follows its row after re-ordering.
                $src = $this->rowSource($input, $list, $content[$list][$i]);
                if ($src === null) {
                    continue;
                }
                if (!empty($input[$list][$src]['_remove_image'])) {
                    $old[] = $content[$list][$i]['image'];
                    $content[$list][$i]['image'] = '';
                }
                $file = $request->file("img_{$list}_{$src}");
                if ($file !== null) {
                    try {
                        $old[] = $content[$list][$i]['image'];
                        $content[$list][$i]['image'] = $new[] = $images->store($file, $preset);
                    } catch (UploadException $e) {
                        $errors["img_{$list}_{$src}"] = $e->getMessage();
                    }
                }
            }
        }
        if (!empty($input['banner']['_remove_image'])) {
            $old[] = $content['banner']['image'];
            $content['banner']['image'] = '';
        }
        if (($file = $request->file('img_banner')) !== null) {
            try {
                $old[] = $content['banner']['image'];
                $content['banner']['image'] = $new[] = $images->store($file, 'cover');
            } catch (UploadException $e) {
                $errors['img_banner'] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            foreach ($new as $path) {
                $images->delete($path);
            }
            return $this->show($request, $errors, null, 422);
        }

        $service->save($content, $actor);
        // Delete replaced images that are no longer referenced anywhere in the content.
        $used = json_encode($content);
        foreach (array_unique(array_filter($old)) as $path) {
            if (!str_contains((string) $used, $path)) {
                $images->delete($path);
            }
        }
        $this->c->get(Cache::class)->bump('landing');
        $this->c->get(Audit::class)->log('home.update', $actor, 'settings', null, 'success', $request, ['sections' => $content['show']]);
        return $this->redirect('/admin/home', 'صفحه اصلی سایت ذخیره شد. تغییرات حداکثر تا یک دقیقه دیگر برای همه نمایش داده می‌شود.');
    }

    public function reset(Request $request): Response
    {
        $actor = (int) $this->user($request)['id'];
        $this->content()->reset($actor);
        $this->c->get(Cache::class)->bump('landing');
        $this->c->get(Audit::class)->log('home.reset', $actor, 'settings', null, 'success', $request);
        return $this->redirect('/admin/home', 'محتوای صفحه اصلی به حالت پیش‌فرض برگشت.');
    }

    private function content(): HomeContent
    {
        return new HomeContent($this->c->get(Settings::class));
    }

    /** Finds which submitted row (by its form key) produced this cleaned row. */
    private function rowSource(mixed $input, string $list, array $row): ?string
    {
        if (!is_array($input) || !is_array($input[$list] ?? null)) {
            return null;
        }
        static $claimed = [];
        foreach ($input[$list] as $key => $raw) {
            $id = $list . ':' . $key;
            if (!is_array($raw) || !empty($raw['_delete']) || isset($claimed[$id])) {
                continue;
            }
            $match = $list === 'markets'
                ? strtoupper(trim((string) ($raw['code'] ?? ''))) === $row['code'] && trim((string) ($raw['name'] ?? '')) === $row['name']
                : trim((string) ($raw['product'] ?? '')) === $row['product'] && trim((string) ($raw['market'] ?? '')) === $row['market'];
            if ($match) {
                $claimed[$id] = true;
                return (string) $key;
            }
        }
        return null;
    }
}
