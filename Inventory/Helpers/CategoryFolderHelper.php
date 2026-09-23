<?php
declare(strict_types=1);

/**
 * CategoryFolderHelper
 * -----------------------------------------------------------
 * ক্যাটাগরি DB-তে যোগ হওয়ার সাথে সাথে
 * Inventory/uploads/{ক্যাটাগরি নাম}/ ফোল্ডার অটো তৈরি করে।
 *
 * ব্যবহার (category_mange.php বা inventory.php-তে):
 *   require_once __DIR__ . '/Helpers/CategoryFolderHelper.php';
 *
 *   // ক্যাটাগরি DB-তে INSERT করার পরে:
 *   CategoryFolderHelper::create($categoryName);
 *
 *   // ক্যাটাগরি নাম বদলালে (পুরনো ফোল্ডার rename):
 *   CategoryFolderHelper::rename($oldName, $newName);
 *
 *   // ক্যাটাগরি active/inactive — ফোল্ডার ছোঁয়া লাগে না, তাই কোনো কল নেই।
 * -----------------------------------------------------------
 */
class CategoryFolderHelper
{
    /** Inventory/uploads/ এর absolute path */
    private static function uploadsBase(): string
    {
        return __DIR__ . '/../uploads/';
    }

    /** ফোল্ডার নামের জন্য ক্যাটাগরি নাম safe করে */
    private static function safe(string $name): string
    {
        $name = trim($name);
        if ($name === '') return 'Uncategorized';
        return str_replace(
            ['/', '\\', ':', '*', '?', '"', '<', '>', '|'],
            '-',
            $name
        );
    }

    // ─────────────────────────────────────────────────────

    /**
     * নতুন ক্যাটাগরি তৈরির সময় ফোল্ডার বানায়।
     * আগে থেকে থাকলে কিছু করে না (নিরাপদ)।
     *
     * @return bool ফোল্ডার তৈরি হলে true, আগে থেকে থাকলেও true, error হলে false
     */
    public static function create(string $categoryName): bool
    {
        $path = self::uploadsBase() . self::safe($categoryName) . '/';
        if (is_dir($path)) return true;
        return mkdir($path, 0755, true);
    }

    /**
     * ক্যাটাগরির নাম বদলালে ফোল্ডার rename করে।
     * পুরনো ফোল্ডার না থাকলে নতুনটা বানায়।
     * ছবির path DB-তে আপডেট করতে হবে আলাদাভাবে
     * (category_mange.php-র edit_category handler-এ)।
     *
     * @return bool সফল হলে true
     */
    public static function rename(string $oldName, string $newName): bool
    {
        $base    = self::uploadsBase();
        $oldPath = $base . self::safe($oldName) . '/';
        $newPath = $base . self::safe($newName) . '/';

        if ($oldPath === $newPath) return true; // নাম একই

        if (!is_dir($oldPath)) {
            // পুরনো ফোল্ডার নেই — নতুনটা বানিয়ে দাও
            return self::create($newName);
        }

        if (is_dir($newPath)) {
            // নতুন নামে আগে থেকে ফোল্ডার আছে — merge করা রিস্কি, তাই skip
            return true;
        }

        return @rename($oldPath, $newPath);
    }

    /**
     * ফোল্ডার আছে কিনা চেক করে (debugging-এর জন্য)।
     */
    public static function exists(string $categoryName): bool
    {
        return is_dir(self::uploadsBase() . self::safe($categoryName) . '/');
    }
}
