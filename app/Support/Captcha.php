<?php

namespace App\Support;

/**
 * Graphic captcha renderer.
 *
 * The original ships its captcha as a PNG fetched with XHR and turned into a
 * `data:` URL (`GET /verify?name=<type>`), which the templates then place in an
 * `<img>`. GD is used here rather than a third-party package so no dependency
 * is added; the generated code is stored in the session under the same `name`.
 */
class Captcha
{
    /** Characters that stay legible at small sizes. */
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Generate a code of the configured length.
     */
    public static function code(int $length = 4): string
    {
        $length = max(3, min(8, $length));
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * Render a code as PNG bytes.
     *
     * Falls back to an SVG data URI when GD is unavailable, so the pages still
     * work on a stripped-down PHP build.
     */
    public static function png(string $code, int $width = 120, int $height = 40): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return self::svg($code, $width, $height);
        }

        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 246, 248, 252);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        // Light speckle so the code is not trivially machine-readable.
        for ($i = 0; $i < 120; $i++) {
            $shade = imagecolorallocate($image, random_int(180, 230), random_int(180, 230), random_int(180, 230));
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $shade);
        }

        $length = strlen($code);
        $step = (int) floor(($width - 16) / max(1, $length));

        for ($i = 0; $i < $length; $i++) {
            $ink = imagecolorallocate($image, random_int(20, 120), random_int(20, 120), random_int(60, 160));
            $x = 8 + ($i * $step);
            $y = random_int(14, 24);
            imagestring($image, 5, $x, $y, $code[$i], $ink);
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Vector fallback, returned as raw SVG bytes.
     */
    protected static function svg(string $code, int $width, int $height): string
    {
        $letters = '';
        $step = (int) floor(($width - 16) / max(1, strlen($code)));

        for ($i = 0; $i < strlen($code); $i++) {
            $letters .= sprintf(
                '<text x="%d" y="%d" font-family="monospace" font-size="22" fill="#1e293b">%s</text>',
                8 + ($i * $step),
                (int) ($height / 2) + 8,
                htmlspecialchars($code[$i], ENT_QUOTES)
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d"><rect width="100%%" height="100%%" fill="#f6f8fc"/>%s</svg>',
            $width,
            $height,
            $letters
        );
    }

    /**
     * Content type matching whichever renderer produced the bytes.
     */
    public static function contentType(string $bytes): string
    {
        return str_starts_with($bytes, '<svg') ? 'image/svg+xml' : 'image/png';
    }
}
