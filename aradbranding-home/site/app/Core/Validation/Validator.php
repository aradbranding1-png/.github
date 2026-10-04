<?php

declare(strict_types=1);

namespace App\Core\Validation;

use App\Core\Support\Str;

/**
 * Rules per field as a list: ['required', 'max:100', 'regex:/^[a-z]+$/'].
 * Returns [cleanData, errors]. Strings are trimmed; Persian digits normalised where numeric.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules
     * @param array<string, string> $labels
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function make(array $input, array $rules, array $labels = []): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $label = t($labels[$field] ?? $field);
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $isEmpty = $value === null || $value === '' || $value === [];

            if (in_array('bool', $fieldRules, true)) {
                $clean[$field] = in_array($value, [1, '1', 'on', 'true', true], true);
                continue;
            }
            if ($isEmpty) {
                if (in_array('required', $fieldRules, true)) {
                    $errors[$field] = t(':label الزامی است.', ['label' => $label]);
                } else {
                    $clean[$field] = null;
                }
                continue;
            }
            if (!is_string($value) && !is_int($value)) {
                $errors[$field] = t(':label معتبر نیست.', ['label' => $label]);
                continue;
            }
            $value = (string) $value;

            foreach ($fieldRules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'required', 'string' => null,
                    'digits' => self::digits($value, $label),
                    'int' => ctype_digit(Str::latinDigits($value)) ? null : t(':label باید عدد باشد.', ['label' => $label]),
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) && strlen($value) <= 191 ? null : t(':label معتبر نیست.', ['label' => $label]),
                    'url' => preg_match('~^https?://[^\s]+$~i', $value) && strlen($value) <= 255 ? null : t(':label باید با http:// یا https:// شروع شود.', ['label' => $label]),
                    'min' => mb_strlen($value) >= (int) $param ? null : t(':label باید حداقل :n نویسه باشد.', ['label' => $label, 'n' => fa_num((int) $param)]),
                    'max' => mb_strlen($value) <= (int) $param ? null : t(':label حداکثر :n نویسه است.', ['label' => $label, 'n' => fa_num((int) $param)]),
                    'in' => in_array($value, explode(',', (string) $param), true) ? null : t(':label معتبر نیست.', ['label' => $label]),
                    'regex' => preg_match((string) $param, $value) ? null : t(':label معتبر نیست.', ['label' => $label]),
                    'same' => ($input[$param] ?? null) === $value ? null : t(':label با تکرار آن یکسان نیست.', ['label' => $label]),
                    default => throw new \InvalidArgumentException("Unknown rule {$name}"),
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $isNumeric = array_intersect(['digits', 'int'], $fieldRules) !== [];
                $clean[$field] = $isNumeric ? Str::latinDigits($value) : $value;
                if (in_array('int', $fieldRules, true)) {
                    $clean[$field] = (int) $clean[$field];
                }
                if (in_array('email', $fieldRules, true)) {
                    $clean[$field] = mb_strtolower($value);
                }
            }
        }
        return [$clean, $errors];
    }

    private static function digits(string $value, string $label): ?string
    {
        return preg_match('/^[0-9]+$/', Str::latinDigits($value)) ? null : t(':label فقط باید عدد باشد.', ['label' => $label]);
    }
}
