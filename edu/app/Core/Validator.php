<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /**
     * Rules: required|string|int|numeric|email|mobile|max:N|min:N|in:a,b|date|url|confirmed|unique:table,column[,ignoreId]
     * @param array<string,string> $rules field => rules
     * @param array<string,string> $labels field => persian label
     */
    public static function check(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleStr) {
            $label = $labels[$field] ?? $field;
            $value = $data[$field] ?? null;
            if (is_string($value)) $value = normalize_input($value);
            $rules = explode('|', $ruleStr);
            $required = in_array('required', $rules, true);
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if ($required) $errors[$field] = "«{$label}» الزامی است.";
                continue;
            }
            foreach ($rules as $r) {
                [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
                $err = match ($name) {
                    'int' => filter_var($value, FILTER_VALIDATE_INT) === false ? "«{$label}» باید عدد صحیح باشد." : null,
                    'numeric' => !is_numeric($value) ? "«{$label}» باید عدد باشد." : null,
                    'email' => !filter_var($value, FILTER_VALIDATE_EMAIL) ? "«{$label}» معتبر نیست." : null,
                    'mobile' => !preg_match('/^(\+?\d{8,15}|09\d{9})$/', (string)$value) ? "«{$label}» معتبر نیست (مثال: 09121234567)." : null,
                    'max' => (is_numeric($value) && !in_array('string', $rules, true) && in_array('int', $rules, true)) ? ((float)$value > (float)$arg ? "«{$label}» حداکثر {$arg} است." : null) : (mb_strlen((string)$value) > (int)$arg ? "«{$label}» حداکثر " . fa($arg) . ' کاراکتر است.' : null),
                    'min' => (is_numeric($value) && in_array('int', $rules, true)) ? ((float)$value < (float)$arg ? "«{$label}» حداقل {$arg} است." : null) : (mb_strlen((string)$value) < (int)$arg ? "«{$label}» حداقل " . fa($arg) . ' کاراکتر است.' : null),
                    'in' => !in_array((string)$value, explode(',', (string)$arg), true) ? "مقدار «{$label}» مجاز نیست." : null,
                    'date' => Jalali::parse((string)$value) === null ? "«{$label}» تاریخ معتبر نیست." : null,
                    'url' => !filter_var($value, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', (string)$value) ? "«{$label}» آدرس معتبر نیست." : null,
                    'confirmed' => ($data[$field . '_confirmation'] ?? null) !== $value ? "تکرار «{$label}» مطابقت ندارد." : null,
                    'unique' => self::unique((string)$value, (string)$arg) ? null : "این «{$label}» قبلاً ثبت شده است.",
                    default => null,
                };
                if ($err) { $errors[$field] = $err; break; }
            }
        }
        return $errors;
    }

    private static function unique(string $value, string $arg): bool
    {
        $p = explode(',', $arg);
        $table = DB::ident($p[0]);
        $col = DB::ident($p[1] ?? 'id');
        $ignore = (int)($p[2] ?? 0);
        $sql = "SELECT COUNT(*) FROM `$table` WHERE `$col` = ?" . ($ignore ? ' AND id <> ' . $ignore : '');
        if (in_array($table, ['users', 'courses', 'files', 'questions'], true)) $sql .= ' AND deleted_at IS NULL';
        return (int)DB::value($sql, [$value]) === 0;
    }

    /** Validate request; on failure flash errors and redirect back with old input. */
    public static function validate(array $rules, array $labels = []): array
    {
        $data = Request::all();
        $errors = self::check($data, $rules, $labels);
        if ($errors) {
            if (Request::isAjax()) json_out(['ok' => false, 'errors' => $errors], 422);
            keep_old($data);
            $_SESSION['_errors'] = $errors;
            flash('danger', implode('<br>', array_map('e', $errors)), true);
            back();
        }
        clear_old();
        return $data;
    }
}
