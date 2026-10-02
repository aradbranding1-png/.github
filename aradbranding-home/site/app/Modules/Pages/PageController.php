<?php

declare(strict_types=1);

namespace App\Modules\Pages;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\ContactGuard;
use App\Core\Validation\Validator;
use App\Modules\Reference\ReferenceData;
use App\Modules\Users\ValidationFailed;

/** Owner-side: list, create, edit, delete pages and manage routing rules. */
final class PageController extends Controller
{
    private const LABELS = [
        'language_id' => 'زبان صفحه', 'title' => 'عنوان صفحه', 'company_name' => 'نام شرکت', 'teaser' => 'معرفی کوتاه',
        'about' => 'درباره ما', 'products' => 'محصولات', 'services' => 'خدمات', 'markets' => 'بازارهای هدف',
        'handle' => 'نشانی صفحه',
    ];

    public function index(Request $request, array $errors = [], int $status = 200): Response
    {
        $user = $this->user($request);
        $service = $this->c->get(PageService::class);
        $ref = $this->c->get(ReferenceData::class);
        return $this->view($request, 'pages/index', [
            'title' => 'پیج‌های من',
            'pages' => $service->listForOwner($user['id']),
            'rules' => $service->rules($user['id']),
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'errors' => $errors,
        ], 'layouts/app', $status);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        $used = array_column($this->c->get(PageService::class)->listForOwner($user['id']), 'language_id');
        $lang = (int) $request->query('lang', (string) $user['language_id']);
        return $this->form($request, null, ['language_id' => in_array($lang, array_map('intval', $used), true) ? '' : $lang], [], $used);
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);
        $service = $this->c->get(PageService::class);
        [$d, $errors] = $this->validate($request, true);

        if (!isset($errors['language_id']) && $this->c->get(ReferenceData::class)->language($d['language_id']) === null) {
            $errors['language_id'] = 'زبان را از فهرست انتخاب کنید.';
        }
        if ($user['handle'] === null && $errors === []) {
            try {
                $service->setHandle($user['id'], (string) $request->input('handle', ''));
            } catch (ValidationFailed $e) {
                $errors += $e->errors;
            }
        }
        if ($errors === []) {
            try {
                $service->create($user, $d, ['cover' => $request->file('cover'), 'avatar' => $request->file('avatar')]);
                return $this->redirect('/pages', $d['publish'] ? 'صفحه منتشر شد.' : 'پیش‌نویس صفحه ذخیره شد.');
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }
        $used = array_column($service->listForOwner($user['id']), 'language_id');
        return $this->form($request, null, $request->all(), $errors, $used, 422);
    }

    public function edit(Request $request): Response
    {
        $page = $this->owned($request);
        $content = $page['content'];
        $old = $page + [
            'products' => implode("\n", $content['products'] ?? []),
            'services' => implode("\n", $content['services'] ?? []),
            'markets' => implode("\n", $content['markets'] ?? []),
        ];
        $old['publish'] = (int) $page['status'] === PageRouter::STATUS_PUBLISHED;
        return $this->form($request, $page, $old, []);
    }

    public function update(Request $request): Response
    {
        $page = $this->owned($request);
        [$d, $errors] = $this->validate($request, false);
        if ($errors === []) {
            try {
                $this->c->get(PageService::class)->update($page, $d, ['cover' => $request->file('cover'), 'avatar' => $request->file('avatar')]);
                return $this->redirect('/pages/' . $page['uid'] . '/edit', 'تغییرات ذخیره شد.');
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }
        return $this->form($request, $page, $request->all() + $page, $errors, [], 422);
    }

    public function destroy(Request $request): Response
    {
        $page = $this->owned($request);
        $this->c->get(PageService::class)->delete($page);
        return $this->redirect('/pages', 'صفحه حذف شد.');
    }

    public function makeDefault(Request $request): Response
    {
        $page = $this->owned($request);
        $this->c->get(PageService::class)->setDefault($page);
        return $this->redirect('/pages', 'صفحه پیش‌فرض تغییر کرد.');
    }

    public function addRule(Request $request): Response
    {
        $user = $this->user($request);
        $service = $this->c->get(PageService::class);
        $ref = $this->c->get(ReferenceData::class);

        $country = (int) $request->input('country_id', 0) ?: null;
        $language = (int) $request->input('language_id', 0) ?: null;
        $target = (string) $request->input('target', '');
        $page = $service->findOwned($target, $user['id']);

        $errors = [];
        if ($page === null) {
            $errors['rule'] = 'صفحه مقصد را انتخاب کنید.';
        } elseif (($country !== null && $ref->country($country) === null) || ($language !== null && $ref->language($language) === null)) {
            $errors['rule'] = 'کشور یا زبان معتبر نیست.';
        } else {
            try {
                $service->addRule($user['id'], $country, $language, (int) $page['id']);
                return $this->redirect('/pages#routing', 'قانون نمایش ذخیره شد.');
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }
        return $this->index($request, $errors, 422);
    }

    public function deleteRule(Request $request): Response
    {
        $user = $this->user($request);
        $this->c->get(PageService::class)->deleteRule($user['id'], (int) $request->param('rule'));
        return $this->redirect('/pages#routing', 'قانون حذف شد.');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(Request $request, bool $creating): array
    {
        $rules = [
            'title' => ['required', 'max:200'],
            'company_name' => ['max:200'],
            'teaser' => ['required', 'max:500'],
            'about' => ['max:5000'],
            'products' => ['max:5000'],
            'services' => ['max:5000'],
            'markets' => ['max:5000'],
            'publish' => ['bool'],
            'remove_cover' => ['bool'],
            'remove_avatar' => ['bool'],
        ];
        if ($creating) {
            $rules = ['language_id' => ['required', 'int']] + $rules;
        }
        [$clean, $errors] = Validator::make($request->all(), $rules, self::LABELS);

        // No part of a page may carry off-platform contact details; traders talk inside the platform.
        foreach (['title', 'company_name', 'teaser', 'about', 'products', 'services', 'markets'] as $field) {
            if (!isset($errors[$field]) && is_string($clean[$field] ?? null) && ContactGuard::contains($clean[$field])) {
                $errors[$field] = ContactGuard::message();
            }
        }
        return [$clean, $errors];
    }

    private function owned(Request $request): array
    {
        $page = $this->c->get(PageService::class)->findOwned((string) $request->param('uid'), $this->user($request)['id']);
        if ($page === null) {
            throw new HttpException(404);
        }
        return $page;
    }

    private function form(Request $request, ?array $page, array $old, array $errors, array $usedLanguages = [], int $status = 200): Response
    {
        return $this->view($request, 'pages/form', [
            'title' => $page === null ? 'ساخت صفحه جدید' : 'ویرایش صفحه',
            'page' => $page,
            'old' => $old,
            'errors' => $errors,
            'languages' => $this->c->get(ReferenceData::class)->languages(),
            'usedLanguages' => array_map('intval', $usedLanguages),
        ], 'layouts/app', $status);
    }
}
