<?php
// KutBlog - Markdown Parser (pure PHP, no dependencies)
// Supports: headings, bold, italic, code, links, images, lists, blockquotes,
//           horizontal rules, tables, strikethrough, inline HTML passthrough

function flux_markdown(string $md): string {
    // Normalize line endings
    $md = str_replace(["\r\n", "\r"], "\n", $md);

    // Protect inline HTML blocks (pass through unchanged)
    $html_blocks = [];
    $md = preg_replace_callback(
        '/^(<(?P<tag>div|section|article|aside|header|footer|nav|figure|details|summary|table|ul|ol|pre|blockquote|script|style)[^>]*>.*?<\/(?P=tag)>)\s*$/ims',
        function($m) use (&$html_blocks) {
            $key = "\x02HTML" . count($html_blocks) . "\x03";
            $html_blocks[$key] = $m[0];
            return $key;
        },
        $md
    );

    $lines = explode("\n", $md);
    $output = '';
    $i = 0;
    $total = count($lines);

    while ($i < $total) {
        $line = $lines[$i];

        // Blank line
        if (trim($line) === '') { $i++; continue; }

        // Fenced code block ```
        if (preg_match('/^(`{3,}|~{3,})(\w*)/', $line, $m)) {
            $fence = $m[1]; $lang = htmlspecialchars($m[2]);
            $code = '';
            $i++;
            while ($i < $total && !str_starts_with(trim($lines[$i]), $fence)) {
                $code .= htmlspecialchars($lines[$i]) . "\n";
                $i++;
            }
            $lang_class = $lang ? " class=\"language-{$lang}\"" : '';
            $output .= "<pre><code{$lang_class}>{$code}</code></pre>\n";
            $i++;
            continue;
        }

        // Heading
        if (preg_match('/^(#{1,6})\s+(.+)/', $line, $m)) {
            $level = strlen($m[1]);
            $text = md_inline($m[2]);
            $id = preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($text)));
            $output .= "<h{$level} id=\"{$id}\">{$text}</h{$level}>\n";
            $i++;
            continue;
        }

        // Horizontal rule
        if (preg_match('/^(\*{3,}|-{3,}|_{3,})\s*$/', $line)) {
            $output .= "<hr>\n";
            $i++;
            continue;
        }

        // Blockquote
        if (str_starts_with($line, '> ') || $line === '>') {
            $bq = '';
            while ($i < $total && (str_starts_with($lines[$i], '> ') || $lines[$i] === '>')) {
                $bq .= substr($lines[$i], str_starts_with($lines[$i], '> ') ? 2 : 1) . "\n";
                $i++;
            }
            $output .= '<blockquote>' . flux_markdown(rtrim($bq)) . "</blockquote>\n";
            continue;
        }

        // Unordered list
        if (preg_match('/^(\s*)([-*+])\s+/', $line, $m)) {
            $output .= "<ul>\n";
            while ($i < $total && preg_match('/^(\s*)([-*+])\s+(.*)/', $lines[$i], $m)) {
                $output .= '<li>' . md_inline($m[3]) . "</li>\n";
                $i++;
            }
            $output .= "</ul>\n";
            continue;
        }

        // Ordered list
        if (preg_match('/^\d+\.\s+/', $line)) {
            $output .= "<ol>\n";
            while ($i < $total && preg_match('/^\d+\.\s+(.*)/', $lines[$i], $m)) {
                $output .= '<li>' . md_inline($m[1]) . "</li>\n";
                $i++;
            }
            $output .= "</ol>\n";
            continue;
        }

        // Table (requires header | --- | row pattern)
        if ($i + 1 < $total && preg_match('/^\|.+\|$/', $line) && preg_match('/^\|[\s|:-]+\|$/', $lines[$i + 1])) {
            $headers = array_map('trim', explode('|', trim($line, '|')));
            $sep_line = $lines[$i + 1];
            $aligns = [];
            foreach (array_map('trim', explode('|', trim($sep_line, '|'))) as $s) {
                if (str_starts_with($s, ':') && str_ends_with($s, ':')) $aligns[] = 'center';
                elseif (str_ends_with($s, ':')) $aligns[] = 'right';
                else $aligns[] = 'left';
            }
            $output .= "<table>\n<thead>\n<tr>\n";
            foreach ($headers as $k => $h) {
                $a = $aligns[$k] ?? 'left';
                $output .= "<th style=\"text-align:{$a}\">" . md_inline($h) . "</th>\n";
            }
            $output .= "</tr>\n</thead>\n<tbody>\n";
            $i += 2;
            while ($i < $total && preg_match('/^\|.+\|$/', $lines[$i])) {
                $cells = array_map('trim', explode('|', trim($lines[$i], '|')));
                $output .= "<tr>\n";
                foreach ($cells as $k => $c) {
                    $a = $aligns[$k] ?? 'left';
                    $output .= "<td style=\"text-align:{$a}\">" . md_inline($c) . "</td>\n";
                }
                $output .= "</tr>\n";
                $i++;
            }
            $output .= "</tbody>\n</table>\n";
            continue;
        }

        // HTML block passthrough placeholder
        if (isset($html_blocks[$line])) {
            $output .= $html_blocks[$line] . "\n";
            $i++;
            continue;
        }

        // Paragraph — collect consecutive non-blank, non-special lines
        $para = '';
        while ($i < $total) {
            $l = $lines[$i];
            if (trim($l) === '') break;
            if (preg_match('/^(#{1,6}\s|`{3,}|~{3,}|> |\d+\.\s|[-*+]\s|(\*{3,}|-{3,}|_{3,})\s*$)/', $l)) break;
            $para .= ($para ? ' ' : '') . trim($l);
            $i++;
        }
        if ($para !== '') {
            $output .= '<p>' . md_inline($para) . "</p>\n";
        }
    }

    // Restore HTML blocks
    foreach ($html_blocks as $key => $val) {
        $output = str_replace($key, $val, $output);
    }

    return $output;
}

function md_inline(string $text): string {
    // Escape HTML (except for allowed inline HTML)
    // First protect existing HTML tags
    $tags = [];
    $text = preg_replace_callback('/<[a-zA-Z\/][^>]*>/', function($m) use (&$tags) {
        $k = "\x02T" . count($tags) . "\x03";
        $tags[$k] = $m[0];
        return $k;
    }, $text);

    // Inline code (protect first)
    $codes = [];
    $text = preg_replace_callback('/`([^`]+)`/', function($m) use (&$codes) {
        $k = "\x02C" . count($codes) . "\x03";
        $codes[$k] = '<code>' . htmlspecialchars($m[1]) . '</code>';
        return $k;
    }, $text);

    // Images before links
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($m) {
        $src_raw = trim($m[2]);
        if (preg_match('/^file:\/+/i', $src_raw)) {
            return '';
        }
        $alt = htmlspecialchars($m[1]);
        $src = htmlspecialchars($src_raw);
        $extra = '';
        if (function_exists('media_get_meta')) {
            $meta = media_get_meta($m[2]);
            if ($alt === '' && !empty($meta['alt'])) {
                $alt = htmlspecialchars($meta['alt']);
            }
            if (!empty($meta['title'])) {
                $extra .= ' title="' . htmlspecialchars($meta['title']) . '"';
            }
        }
        return "<img src=\"{$src}\" alt=\"{$alt}\"{$extra}>";
    }, $text);

    // Links
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
        $label = $m[1];
        $url = trim($m[2]);
        if (preg_match('/^file:\/+/i', $url)) {
            return "<code>{$label}</code>";
        }
        $href = htmlspecialchars($url);
        $ext = preg_match('/^https?:\/\//', $url) ? ' target="_blank" rel="noopener"' : '';
        return "<a href=\"{$href}\"{$ext}>{$label}</a>";
    }, $text);

    // Bold+italic
    $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/___(.+?)___/', '<strong><em>$1</em></strong>', $text);
    // Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
    // Italic
    $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);
    // Strikethrough
    $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);
    // Line break
    $text = preg_replace('/  \n/', '<br>', $text);

    // Restore codes and tags
    foreach ($codes as $k => $v) $text = str_replace($k, $v, $text);
    foreach ($tags as $k => $v) $text = str_replace($k, $v, $text);

    return $text;
}
