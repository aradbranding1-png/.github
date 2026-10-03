<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = [];
    private static array $sections = [];

    public static function share(string $k, mixed $v): void
    {
        self::$shared[$k] = $v;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) return $content;
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = APP_PATH . '/Views/' . str_replace('..', '', $template) . '.php';
        if (!is_file($file)) throw new \RuntimeException('View not found: ' . $template);
        extract(array_merge(self::$shared, $data), EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }

    public static function section(string $name, ?string $value = null): ?string
    {
        if ($value !== null) { self::$sections[$name] = $value; return null; }
        return self::$sections[$name] ?? null;
    }
}
