<?php
/**
 * Shared helpers for rendering generation records in UI views.
 * All functions are idempotent (function_exists guard) for safe re-inclusion.
 */

if (!function_exists('generation_input_image_count')) {
    /**
     * Safely count reference images from a generation record.
     * Returns 0 on any error (invalid JSON, missing field, etc).
     */
    function generation_input_image_count(array $record): int {
        static $IMAGE_JSON_FIELDS = ['input_images_json', 'input_image_urls_json', 'input_images'];
        foreach ($IMAGE_JSON_FIELDS as $field) {
            if (isset($record[$field]) && is_string($record[$field])) {
                $decoded = @json_decode($record[$field], true);
                if (is_array($decoded)) {
                    return count($decoded);
                }
            }
        }
        if (isset($record['input_images']) && is_array($record['input_images'])) {
            return count($record['input_images']);
        }
        return 0;
    }
}

if (!function_exists('generation_record_image_src')) {
    /**
     * Get image result URL from a generation record.
     * Returns empty string if no image result is available.
     * Does NOT return video URLs.
     */
    function generation_record_image_src(array $record): string {
        $mime = (string) ($record['mime_type'] ?? '');
        if (str_starts_with($mime, 'video/')) {
            return '';
        }
        if (!empty($record['output_url'])) {
            $url = (string) $record['output_url'];
            if (str_starts_with(strtolower($url), 'http') || str_starts_with($url, '/')) {
                return $url;
            }
        }
        if (!empty($record['image_url'])) {
            return (string) $record['image_url'];
        }
        if (!empty($record['output_base64']) && !empty($mime)) {
            return 'data:' . $mime . ';base64,' . (string) $record['output_base64'];
        }
        return '';
    }
}

if (!function_exists('generation_record_video_src')) {
    /**
     * Get video result URL from a generation record.
     * Returns empty string if no video result is available.
     * Does NOT return image URLs.
     */
    function generation_record_video_src(array $record): string {
        if (!empty($record['video_url'])) {
            return (string) $record['video_url'];
        }
        $mime = (string) ($record['video_mime_type'] ?? '');
        if (str_starts_with($mime, 'video/') && !empty($record['video_base64'])) {
            return 'data:' . $mime . ';base64,' . (string) $record['video_base64'];
        }
        if (!empty($record['output_url'])) {
            $url = (string) $record['output_url'];
            $outMime = strtolower((string) ($record['mime_type'] ?? ''));
            $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
            if ($outMime === 'video/mp4' || $ext === 'mp4' || $ext === 'webm' || $ext === 'mov') {
                return $url;
            }
        }
        return '';
    }
}

if (!function_exists('generation_record_media_type')) {
    /**
     * Determine whether a record is primarily a video or an image.
     */
    function generation_record_media_type(array $record): string {
        $videoSrc = generation_record_video_src($record);
        if ($videoSrc !== '') {
            return 'video';
        }
        $mime = strtolower((string) ($record['mime_type'] ?? ''));
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        $imageSrc = generation_record_image_src($record);
        if ($imageSrc !== '') {
            return 'image';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        $mode = (string) ($record['mode'] ?? '');
        if ($mode === 'video') {
            return 'video';
        }
        return 'unknown';
    }
}

if (!function_exists('generation_record_mode_label')) {
    /**
     * Get the human-readable mode label for a record.
     */
    function generation_record_mode_label(array $record): string {
        $mode = (string) ($record['mode'] ?? 'draw');
        if ($mode === 'video') {
            $vm = (string) ($record['selected_video_mode'] ?? '');
            static $VLABELS = [
                'text_to_video'      => '文生视频',
                'first_frame'         => '首帧',
                'first_last_frame'    => '首尾帧',
                'multi_reference'     => '多帧参考',
                'video_edit'          => '视频编辑',
            ];
            return $VLABELS[$vm] ?? ($vm !== '' ? $vm : '视频');
        }
        if ($mode === 'edit') {
            return '编辑';
        }
        return '绘画';
    }
}

if (!function_exists('generation_record_param_label')) {
    /**
     * Get the full human-readable parameter summary for a record.
     * e.g. "视频 / 16:9 / 10秒 / 文生视频" or "绘画 / 1:1"
     */
    function generation_record_param_label(array $record): string {
        $mode = generation_record_mode_label($record);
        $size = (string) ($record['size'] ?? 'auto');
        $aspect = trim((string) ($record['selected_aspect'] ?? ''));
        $duration = (int) ($record['selected_duration'] ?? 0);
        $parts = [$mode];
        if ($aspect !== '' && $aspect !== 'auto') {
            $parts[] = $aspect;
        }
        $parts[] = $size;
        if ($duration > 0) {
            $parts[] = $duration . '秒';
        }
        return implode(' / ', $parts);
    }
}

if (!function_exists('safe_record_text')) {
    /**
     * Safely escape any value for HTML output.
     * Uses ENT_QUOTES | ENT_SUBSTITUTE for maximum safety.
     * Returns empty string only if input is null/undefined, never on encoding failure.
     */
    function safe_record_text(mixed $value): string {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
