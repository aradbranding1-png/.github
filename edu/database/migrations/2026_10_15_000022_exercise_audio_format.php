<?php
/**
 * Exercise answer formats: «صوت» is now its own format, separate from «فایل Word / PDF».
 * Exercises that allowed «file» used to accept audio too, so they get «audio» as well (nothing stops working).
 */
return [
    'description' => 'جدا شدن فرمت «صوت» از «فایل Word / PDF» در پاسخ تمرین‌ها',
    'up' => function (PDO $db): void {
        $db->exec("UPDATE exercises SET formats = CONCAT(formats, ',audio') WHERE FIND_IN_SET('file', formats) > 0 AND FIND_IN_SET('audio', formats) = 0");
        $db->exec("ALTER TABLE exercises MODIFY formats VARCHAR(100) NOT NULL DEFAULT 'text,audio,file,image,video'");
    },
];
