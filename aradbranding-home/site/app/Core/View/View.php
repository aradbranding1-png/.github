<?php

declare(strict_types=1);

namespace App\Core\View;

use RuntimeException;
use Throwable;

/**
 * Plain-PHP templates under app/Views. Templates escape every value with e().
 * Inside a template $this is the View, so $this->partial() is available.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private string $base)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->partial($template, $data);
        return $layout === null ? $content : $this->partial($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->base . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }
        $level = ob_get_level();
        ob_start();
        try {
            (function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data + $this->shared);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
