<?php
declare(strict_types=1);

/**
 * Validator
 * -----------------------------------------------------------
 * পণ্য অ্যাড ফর্মের সব ইনপুট validate ও sanitize করে।
 * মূল Validator.php-র bug fix করা হয়েছে (sanitizeInput-এ return ছিল না)।
 * -----------------------------------------------------------
 */
class Validator
{
    /** Product code format: SKF-01, AB-123 */
    public static function validateProductCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{2,5}-\d{2,5}$/', $code);
    }

    /** মূল্য ০ থেকে ৯৯,৯৯,৯৯৯.৯৯-এর মধ্যে */
    public static function validatePrice(float $price): bool
    {
        return $price >= 0 && $price <= 9999999.99;
    }

    /** base64 ছবি valid কিনা + সাইজ ৬MB-এর মধ্যে */
    public static function validateImage(string $base64): bool
    {
        $raw     = preg_replace('#^data:image/\w+;base64,#i', '', $base64);
        $decoded = base64_decode($raw, true);
        return $decoded !== false && strlen($decoded) <= 6 * 1024 * 1024;
    }

    /** লোকেশন shop বা godown — অন্য কিছু হলে false */
    public static function validateLocation(string $location): bool
    {
        return in_array($location, ['shop', 'godown'], true);
    }

    /** পিস সংখ্যা ১ থেকে ৯৯,৯৯৯-এর মধ্যে */
    public static function validatePieces(int $pieces): bool
    {
        return $pieces >= 1 && $pieces <= 99999;
    }

    /**
     * ইনপুট sanitize করে।
     * Bug fix: আগের ভার্সনে match-এ return ছিল না — এখন ঠিক করা হয়েছে।
     */
    public static function sanitizeInput(string $input, string $type = 'text'): string
    {
        return match ($type) {
            'text'   => trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8')),
            'number' => (string) preg_replace('/[^0-9.]/', '', $input),
            'code'   => strtoupper((string) preg_replace('/[^A-Z0-9-]/i', '', $input)),
            default  => $input,
        };
    }

    /**
     * পণ্য অ্যাড ফর্মের সব ফিল্ড একসাথে validate করে।
     * Error থাকলে Exception ছোড়ে।
     *
     * ব্যবহার:
     *   Validator::validateProductForm($_POST);
     */
    public static function validateProductForm(array $post): void
    {
        if (empty($post['category_id']) || !is_numeric($post['category_id'])) {
            throw new InvalidArgumentException('ক্যাটাগরি সিলেক্ট করুন।');
        }

        if (!empty($post['product_code'])) {
            $code = self::sanitizeInput($post['product_code'], 'code');
            if (!self::validateProductCode($code)) {
                throw new InvalidArgumentException('পণ্য কোড সঠিক নয় (যেমন: SKF-01)।');
            }
        }

        $pieces = (int) ($post['pieces'] ?? 0);
        if (!self::validatePieces($pieces)) {
            throw new InvalidArgumentException('পিস সংখ্যা ১ থেকে ৯৯,৯৯৯-এর মধ্যে হতে হবে।');
        }

        foreach (['buy_price', 'cost', 'cash_sell'] as $field) {
            $val = (float) ($post[$field] ?? -1);
            if (!self::validatePrice($val)) {
                throw new InvalidArgumentException("{$field} মূল্য সঠিক নয়।");
            }
        }

        $loc = trim($post['item_location'] ?? '');
        if (!self::validateLocation($loc)) {
            throw new InvalidArgumentException('দোকান বা গোডাউন সিলেক্ট করুন।');
        }
    }
}
