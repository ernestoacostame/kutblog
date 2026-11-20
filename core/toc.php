<?php
// KutBlog - Table of Contents (TOC) Generator

function cms_toc_slugify(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n','ç'=>'c','ý'=>'y','ÿ'=>'y',
    ];
    $text = strtr($text, $map);
    $slug = (string)preg_replace('/[^a-z0-9]+/', '-', $text);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'section';
}

function cms_build_toc(string $html, array $opts = []): array {
    $minHeadings = max(1, (int)($opts['min_headings'] ?? 2));
    $maxLevel    = min(4, max(2, (int)($opts['max_level'] ?? 4)));
    $isOpen      = !empty($opts['open']);
    $title       = (string)($opts['title'] ?? 'Tabla de contenidos');

    if ($html === '' || str_contains($html, 'id="TableOfContents"') || str_contains($html, 'class="toc')) {
        return ['html' => $html, 'toc' => '', 'count' => 0];
    }

    $tagPattern = $maxLevel === 2 ? 'h2' : ($maxLevel === 3 ? 'h[23]' : 'h[2-4]');
    $items = [];
    $usedIds = [];

    $newHtml = preg_replace_callback(
        '/<(' . $tagPattern . ')(\b[^>]*)>(.*?)<\/\1>/is',
        function (array $m) use (&$items, &$usedIds): string {
            $tag   = strtolower($m[1]);
            $attrs = $m[2];
            $inner = $m[3];
            $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $plain = (string)preg_replace('/\s+/', ' ', $plain);
            if ($plain === '') {
                return $m[0];
            }

            if (preg_match('/\bid\s*=\s*(["\'])(.*?)\1/i', $attrs, $idMatch) && trim($idMatch[2]) !== '') {
                $id = trim($idMatch[2]);
            } else {
                $base = cms_toc_slugify($plain);
                $id = $base;
                $n = 2;
                while (isset($usedIds[$id])) {
                    $id = $base . '-' . $n++;
                }
                $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"';
            }
            $usedIds[$id] = true;

            $items[] = [
                'level' => (int)substr($tag, 1),
                'id'    => $id,
                'title' => $plain,
            ];

            return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
        },
        $html
    );

    if ($newHtml === null || count($items) < $minHeadings) {
        return ['html' => $html, 'toc' => '', 'count' => count($items)];
    }

    // Build nested <ul><li> tree normalized to the shallowest heading level found
    $minFound = min(array_column($items, 'level'));
    $navHtml = "<ul>\n";
    $stack = [$minFound];
    $firstItem = true;

    foreach ($items as $item) {
        $lvl = max($minFound, $item['level']);
        $top = end($stack);

        if ($lvl > $top) {
            while (end($stack) < $lvl) {
                $navHtml .= "\n<ul>\n";
                $stack[] = end($stack) + 1;
            }
        } elseif ($lvl < $top) {
            while (count($stack) > 1 && end($stack) > $lvl) {
                $navHtml .= "</li>\n</ul>\n";
                array_pop($stack);
            }
            $navHtml .= "</li>\n";
        } elseif (!$firstItem) {
            $navHtml .= "</li>\n";
        }

        $firstItem = false;
        $href = '#' . htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
        $navHtml .= '<li><a href="' . $href . '">' . $label . '</a>';
    }

    while (count($stack) > 1) {
        $navHtml .= "</li>\n</ul>\n";
        array_pop($stack);
    }
    if (!$firstItem) {
        $navHtml .= "</li>\n";
    }
    $navHtml .= "</ul>";

    $openAttr = $isOpen ? ' open' : '';
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $tocHtml = '<details class="toc kutblog-toc"' . $openAttr . '>' . "\n"
        . '  <summary accesskey="c" title="' . $titleEsc . ' (Alt + C)">' . "\n"
        . '    <span class="toc-chevron" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></span>' . "\n"
        . '    <span class="toc-title">' . $titleEsc . '</span>' . "\n"
        . '  </summary>' . "\n"
        . '  <div class="inner">' . "\n"
        . '    <nav id="TableOfContents" aria-label="' . $titleEsc . '">' . "\n"
        . $navHtml . "\n"
        . '    </nav>' . "\n"
        . '  </div>' . "\n"
        . '</details>' . "\n";

    return [
        'html'  => $newHtml,
        'toc'   => $tocHtml,
        'count' => count($items),
    ];
}

function cms_inject_toc(string $html): string {
    $cfg = cms_config();
    $enabled = $cfg['toc_enabled'] ?? '1';
    if ($enabled === '0' || $enabled === 0 || $enabled === false) {
        return $html;
    }

    $lang = function_exists('site_lang') ? site_lang() : ($cfg['language'] ?? 'es');
    $title = ($lang === 'en') ? 'Table of Contents' : 'Tabla de contenidos';
    $isOpen = ($cfg['toc_open'] ?? '1') !== '0';
    $minHeadings = max(2, (int)($cfg['toc_min_headings'] ?? 2));

    $res = cms_build_toc($html, [
        'min_headings' => $minHeadings,
        'max_level'    => 4,
        'open'         => $isOpen,
        'title'        => $title,
    ]);

    if ($res['toc'] === '') {
        return $html;
    }

    return $res['toc'] . $res['html'];
}

function kutblog_toc_assets(): string {
    return '<style id="kutblog-toc-css">
html{scroll-behavior:smooth}
.prose h2[id],.prose h3[id],.prose h4[id]{scroll-margin-top:2rem}
.kutblog-toc{margin:0 0 1.75rem 0;padding:0.7rem 1.05rem;border:1px solid var(--border,rgba(128,128,128,.24));border-radius:8px;background:var(--surface,var(--card-bg,rgba(128,128,128,.05)));font-size:.93rem;line-height:1.55}
.kutblog-toc summary{display:flex;align-items:center;gap:.45rem;cursor:pointer;font-weight:600;list-style:none;user-select:none;color:inherit}
.kutblog-toc summary::-webkit-details-marker{display:none}
.kutblog-toc .toc-chevron{display:inline-flex;align-items:center;transition:transform .18s ease;opacity:.7}
.kutblog-toc[open] .toc-chevron{transform:rotate(90deg)}
.kutblog-toc .inner{margin-top:.55rem;padding-top:.45rem;border-top:1px solid var(--border,rgba(128,128,128,.16))}
.kutblog-toc nav ul{margin:0;padding-left:1.15rem;list-style:disc}
.kutblog-toc nav>ul{padding-left:1.1rem}
.kutblog-toc nav li{margin:.24rem 0}
.kutblog-toc nav ul ul{margin:.2rem 0 .25rem 0;list-style:circle}
.kutblog-toc nav a{color:inherit;text-decoration:none;opacity:.85;transition:opacity .15s ease,color .15s ease}
.kutblog-toc nav a:hover{opacity:1;text-decoration:underline;text-underline-offset:3px;color:var(--accent,var(--primary,inherit))}
</style>';
}
