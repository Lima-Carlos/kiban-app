<?php

namespace App\Support;

/**
 * A short video kept in public/ (e.g. public/videos/conexoes/punch_reminder-pt.mp4), in the person's language:
 * shown only when the file exists, so screens work the same before the clips are recorded.
 * Names: <base>-<lang>.mp4, lang = pt, en or es; optional cover image <base>-<lang>.jpg (poster).
 */
class LocalVideo
{
    /**
     * @return array{src: string, poster: ?string}|null
     */
    public static function find(string $base, ?string $locale = null): ?array
    {
        $file = trim($base, '/').'-'.static::lang($locale ?? app()->getLocale());

        if (! is_file(public_path($file.'.mp4'))) {
            return null;
        }

        return [
            'src' => asset($file.'.mp4').'?v='.filemtime(public_path($file.'.mp4')),
            'poster' => is_file(public_path($file.'.jpg')) ? asset($file.'.jpg') : null,
        ];
    }

    /** pt_BR → pt (the file names use the short language). */
    public static function lang(string $locale): string
    {
        return strtolower(substr($locale, 0, 2));
    }
}
