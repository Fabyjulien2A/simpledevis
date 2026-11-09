<?php

namespace Tests\Support;

final class InvoiceLogoFixture
{
    public static function bytes(string $format = 'png', int $red = 30): string
    {
        $image = imagecreatetruecolor(12, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 100, 180));
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'jpg' => imagejpeg($image),
            'webp' => imagewebp($image),
        };
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
