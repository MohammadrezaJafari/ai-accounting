<?php

namespace App\Services\Agents\Delivery;

use Illuminate\Support\Str;

/**
 * Turns a Markdown report into what each channel accepts: Telegram's small HTML subset,
 * plain text (Bale) or full HTML (email). Messenger texts are split under their size limit
 * at paragraph boundaries, so formatting tags stay whole (only a single paragraph longer
 * than a message is cut).
 */
class ReportFormatter
{
    private const MESSAGE_LIMIT = 3800;

    public function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * @return list<string>
     */
    public function telegram(string $title, string $markdown): array
    {
        $html = $this->html($markdown);
        $html = preg_replace('#<h[1-6][^>]*>(.*?)</h[1-6]>#s', "<b>$1</b>\n", $html);
        $html = preg_replace('#<li[^>]*>(.*?)</li>#s', "• $1\n", $html);
        $html = preg_replace('#<(br|hr)\s*/?>#', "\n", $html);
        $html = preg_replace('#</p>|</blockquote>|</ul>|</ol>#', "\n", $html);
        $html = strip_tags($html, '<b><strong><i><em><u><s><a><code><pre>');
        $html = preg_replace('#<a href="([^"]*)"[^>]*>#', '<a href="$1">', $html);
        $html = preg_replace("/\n{3,}/", "\n\n", trim($html));

        return $this->chunks('<b>'.e($title)."</b>\n\n".$html);
    }

    /**
     * @return list<string>
     */
    public function plain(string $title, string $markdown): array
    {
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1: $2', $markdown);
        $text = preg_replace('/^#{1,6}\s*/m', '', $text);
        $text = preg_replace('/^\s*[-*]\s+/m', '• ', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text));

        return $this->chunks($title."\n\n".$text);
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $chunks = [];
        $current = '';

        foreach (preg_split("/\n\n/", $text) as $paragraph) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > self::MESSAGE_LIMIT) {
                $chunks[] = $current;
                $current = '';
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;

            while (mb_strlen($current) > self::MESSAGE_LIMIT) {
                $chunks[] = mb_substr($current, 0, self::MESSAGE_LIMIT);
                $current = mb_substr($current, self::MESSAGE_LIMIT);
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
