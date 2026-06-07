<?php

declare(strict_types=1);

function normalize_prompt_text(string $text): string
{
    if (function_exists('mb_convert_kana')) {
        $text = mb_convert_kana($text, 'asKV', 'UTF-8');
    }
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    $normalized = preg_replace('/[\s\p{P}\p{S}_]+/u', '', $text);
    return is_string($normalized) ? $normalized : $text;
}

function blocked_prompt_categories(string $prompt): array
{
    $dictFile = dirname(__DIR__) . '/config/blocked_words.php';
    $rules = is_file($dictFile) ? require $dictFile : [];
    if (!is_array($rules)) {
        return [];
    }

    $normalizedPrompt = normalize_prompt_text($prompt);
    $blockedCategories = [];

    foreach ($rules as $category => $words) {
        if (!is_array($words)) {
            continue;
        }

        foreach ($words as $word) {
            $normalizedWord = normalize_prompt_text((string) $word);
            if ($normalizedWord === '') {
                continue;
            }
            if (strpos($normalizedPrompt, $normalizedWord) !== false) {
                $blockedCategories[] = (string) $category;
                break;
            }
        }
    }

    return array_values(array_unique($blockedCategories));
}

function prompt_violation_message(array $categories): string
{
    if (!$categories) {
        return '';
    }

    return '提示词包含不合规内容（' . implode('、', $categories) . '），请修改后再生成。';
}
