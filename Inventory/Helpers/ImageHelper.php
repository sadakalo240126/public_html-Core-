<?php
declare(strict_types=1);

/**
 * ImageHelper
 * -----------------------------------------------------------
 * যেকোনো ছবি আপলোড হলে সেটা ছোট করে (max 900px) সেভ করে।
 *
 * ব্যবহার (inventory.php-তে):
 *   require_once __DIR__ . '/Helpers/ImageHelper.php';
 *
 *   // আগে যা ছিল:
 *   $imagePath = inv_resize_save_image($imgData, $productCode);
 *
 *   // এখন যা হবে:
 *   $imagePath = ImageHelper::save($imgData, $productCode, $categoryName);
 *
 * রিটার্ন করে: uploads/Shirt/SKF-451.jpg
 * -----------------------------------------------------------
 */
class ImageHelper
{
    // ছবির সর্বোচ্চ সাইজ (px) — এর বড় হলে ছোট করে দেবে
    private const MAX_PX       = 900;

    // JPEG কোয়ালিটি (০-১০০) — ৮২ মানে ভালো কোয়ালিটি + ছোট ফাইল সাইজ
    private const JPEG_QUALITY = 82;

    /**
     * ছবি ছোট করে ক্যাটাগরি ফোল্ডারে সেভ করে।
     *
     * @param string $imgData      raw binary (base64_decode করার পরে)
     * @param string $productCode  যেমন SKF-451
     * @param string $categoryName যেমন Shirt — এই নামে সাবফোল্ডার হবে
     * @return string              যেমন uploads/Shirt/SKF-451.jpg
     */
    public static function save(
        string $imgData,
        string $productCode,
        string $categoryName
    ): string {

        // ক্যাটাগরি ফোল্ডার — না থাকলে অটো তৈরি হবে
        $folder = self::makeFolder($categoryName);

        // ফাইলনেম — product code থেকে (SKF-451 → SKF-451.jpg)
        $name = self::safeName($productCode);

        // GD না থাকলে রিসাইজ ছাড়াই সরাসরি সেভ
        if (!function_exists('imagecreatefromstring')) {
            return self::writeRaw($imgData, $folder, $name, $categoryName);
        }

        // GD দিয়ে ছবি লোড
        $img = @imagecreatefromstring($imgData);
        if ($img === false) {
            return self::writeRaw($imgData, $folder, $name, $categoryName);
        }

        // ছবি ৯০০px-এর বেশি হলে ছোট করো
        $img = self::resize($img);

        // ফাইল সেভ
        $fileName = self::nextName($folder, $name);
        imagejpeg($img, $folder . $fileName, self::JPEG_QUALITY);
        imagedestroy($img);

        return 'uploads/' . self::folderName($categoryName) . '/' . $fileName;
    }

    // ─── Private ────────────────────────────────────────────

    /** ছবি resize করে — MAX_PX-এর মধ্যে রাখে */
    private static function resize(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);

        if ($w <= self::MAX_PX && $h <= self::MAX_PX) {
            return $src; // ছোট ছবি — কিছু করার নেই
        }

        $ratio = min(self::MAX_PX / $w, self::MAX_PX / $h);
        $nw    = max(1, (int) round($w * $ratio));
        $nh    = max(1, (int) round($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return $src;
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        return $dst;
    }

    /** GD ছাড়া raw বাইট সেভ */
    private static function writeRaw(
        string $data,
        string $folder,
        string $name,
        string $categoryName
    ): string {
        $fileName = self::nextName($folder, $name);
        file_put_contents($folder . $fileName, $data);
        return 'uploads/' . self::folderName($categoryName) . '/' . $fileName;
    }

    /** ক্যাটাগরি ফোল্ডার তৈরি করে, absolute path রিটার্ন করে */
    private static function makeFolder(string $categoryName): string
    {
        $cat  = self::folderName($categoryName);
        $path = __DIR__ . '/../uploads/' . $cat . '/';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * ডুপ্লিকেট নাম এড়াতে পরের available নাম দেয়।
     * SKF-451.jpg আগে থাকলে → SKF-451_2.jpg, তারপর SKF-451_3.jpg ...
     */
    private static function nextName(string $folder, string $name): string
    {
        $file = $name . '.jpg';
        $i    = 2;

        while (file_exists($folder . $file)) {
            $file = $name . '_' . $i . '.jpg';
            $i++;
        }

        return $file;
    }

    /** product code থেকে সেফ ফাইলনেম */
    private static function safeName(string $code): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $code);
        return ($safe !== null && $safe !== '') ? $safe : 'product_' . time();
    }

    /** ক্যাটাগরির নাম থেকে ফোল্ডার নেম */
    private static function folderName(string $categoryName): string
    {
        $name = trim($categoryName);
        if ($name === '') return 'Uncategorized';

        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);
        return trim($name) ?: 'Uncategorized';
    }
}
