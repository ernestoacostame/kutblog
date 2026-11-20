<?php
// KutBlog - Theme Engine

function theme_path(string $theme = ''): string {
    return THEMES_PATH . '/' . ($theme ?: active_theme());
}

function available_themes(): array {
    $themes = [];
    foreach (glob(THEMES_PATH . '/*/theme.json') as $file) {
        $info = json_decode(file_get_contents($file), true);
        $name = basename(dirname($file));
        $themes[$name] = array_merge(['name' => $name, 'label' => ucfirst($name)], $info ?? []);
    }
    return $themes;
}

function theme_file(string $filename, string $theme = ''): string {
    $theme = $theme ?: active_theme();
    $file = theme_path($theme) . "/$filename";
    if (file_exists($file)) return $file;
    // Fallback to default theme
    return theme_path('default') . "/$filename";
}

function render_theme(string $template, array $vars = []): void {
    if (cms_plugin_is_active('kutpod') && function_exists('kutpod_config')) {
        $kpCfg = kutpod_config();
        $instBase = rtrim($kpCfg['instance_url'] ?? '', '/');
        if ($instBase) {
            if (!empty($vars['post']['featured_image']) && str_starts_with($vars['post']['featured_image'], '/media/covers/')) {
                $vars['post']['featured_image'] = $instBase . $vars['post']['featured_image'];
            }
            if (isset($vars['posts']) && is_array($vars['posts'])) {
                foreach ($vars['posts'] as &$pItem) {
                    if (!empty($pItem['featured_image']) && str_starts_with($pItem['featured_image'], '/media/covers/')) {
                        $pItem['featured_image'] = $instBase . $pItem['featured_image'];
                    }
                }
                unset($pItem);
            }
        }
    }

    if (isset($vars['post']['content'])) {
        if ($template === 'single' && ($vars['type'] ?? '') !== 'pages') {
            if (($vars['post']['content_format'] ?? '') === 'markdown' && function_exists('flux_markdown')) {
                $vars['post']['content'] = flux_markdown($vars['post']['content']);
                $vars['post']['content_format'] = 'html';
            }
            require_once ROOT_PATH . '/core/toc.php';
            $vars['post']['content'] = cms_inject_toc($vars['post']['content']);
        }
        if (cms_plugin_is_active('kutpod') && function_exists('kutpod_filter_content')) {
            $vars['post']['content'] = kutpod_filter_content($vars['post']['content'], $vars['post']);
        }
        if ($template === 'single' && ($vars['type'] ?? '') !== 'pages' && cms_plugin_is_active('series')) {
            require_once ROOT_PATH . '/plugins/series/series.php';
            if (function_exists('series_filter_content')) {
                $vars['post']['content'] = series_filter_content($vars['post']['content'], $vars['post']);
            }
        }
        if ($template === 'single' && ($vars['type'] ?? '') !== 'pages' && cms_plugin_is_active('newsletter')) {
            require_once ROOT_PATH . '/core/newsletter.php';
            if (function_exists('newsletter_render_box')) {
                $vars['post']['content'] .= newsletter_render_box();
            }
        }
    }
    extract($vars);
    $config = cms_config();
    $site_title = $config['site_title'] ?? CMS_NAME;
    $site_logo = site_logo();
    $site_favicon = site_favicon();
    $favicon_tags = cms_favicon_tags();
    $theme_color = $config['theme_color'] ?? '#6366f1';
    $theme = active_theme();
    $base = base_url();
    
    $file = theme_file("$template.php");
    if (!file_exists($file)) {
        echo "<p>Template '$template' not found.</p>";
        return;
    }
    
    ob_start();
    // Load header
    $header_file = theme_file('header.php');
    if (file_exists($header_file)) include $header_file;
    if (cms_plugin_is_active('kutpod') && function_exists('kutpod_theme_header')) {
        kutpod_theme_header($template, $vars);
    }
    include $file;
    if (cms_plugin_is_active('kutpod') && function_exists('kutpod_theme_footer')) {
        kutpod_theme_footer($template, $vars);
    }
    $footer_file = theme_file('footer.php');
    if (file_exists($footer_file)) include $footer_file;

    // Cookie-free native analytics tracking
    if (file_exists(ROOT_PATH . '/core/analytics.php')) {
        require_once ROOT_PATH . '/core/analytics.php';
        analytics_render_tracker();
    }
    $rendered = ob_get_clean();
    if (function_exists('cms_sanitize_local_file_urls')) {
        $rendered = cms_sanitize_local_file_urls($rendered);
    }
    if (str_contains($rendered, 'kutblog-toc') && function_exists('kutblog_toc_assets')) {
        $tocCss = kutblog_toc_assets();
        if (str_contains($rendered, '</head>')) {
            $rendered = str_replace('</head>', $tocCss . '</head>', $rendered);
        } else {
            $rendered = $tocCss . $rendered;
        }
    }
    if (str_contains($rendered, 'kutblog-gallery') && function_exists('kutblog_gallery_assets')) {
        $galleryAssets = kutblog_gallery_assets();
        if (str_contains($rendered, '</head>')) {
            $rendered = str_replace('</head>', $galleryAssets['css'] . '</head>', $rendered);
        } else {
            $rendered = $galleryAssets['css'] . $rendered;
        }
        if (str_contains($rendered, '</body>')) {
            $rendered = str_replace('</body>', $galleryAssets['js'] . '</body>', $rendered);
        } else {
            $rendered .= $galleryAssets['js'];
        }
    }
    if ($template === 'single' && !empty($vars['post']) && !str_contains($rendered, 'kutblog-admin-edit-btn') && cms_current_user_can_edit_post($vars['post'])) {
        $editUrl = cms_post_edit_url($vars['post'], (string)($vars['type'] ?? 'articles'));
        if ($editUrl !== '') {
            $editLabel = (function_exists('site_lang') && site_lang() === 'en') ? 'Edit' : 'Editar';
            $btnHtml = '<a href="' . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '" class="kutblog-admin-edit-btn" style="position:fixed;right:20px;bottom:20px;z-index:999;display:inline-flex;align-items:center;gap:6px;padding:9px 15px;border-radius:999px;background:var(--accent,var(--primary,#6366f1));color:#fff;font-size:13px;font-weight:600;text-decoration:none;box-shadow:0 6px 20px rgba(0,0,0,.28);">'
                . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>'
                . '<span>' . $editLabel . '</span></a>';
            $rendered = str_contains($rendered, '</body>')
                ? str_replace('</body>', $btnHtml . '</body>', $rendered)
                : ($rendered . $btnHtml);
        }
    }
    echo $rendered;
}

function cms_current_user_can_edit_post(array $post): bool {
    $hasAuthCookie = isset($_COOKIE[session_name()]) || !empty($_COOKIE['kutblog_remember']);
    if (!$hasAuthCookie) {
        return false;
    }
    if (!function_exists('is_logged_in') || !is_logged_in()) {
        return false;
    }
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, ['admin', 'editor'], true);
}

function cms_post_edit_url(array $post, string $type = 'articles'): string {
    $slug = $post['slug'] ?? '';
    if ($slug === '') return '';
    $t = ($type === 'pages') ? 'pages' : 'articles';
    return base_url() . '/admin/editor.php?type=' . urlencode($t) . '&slug=' . urlencode($slug);
}

function theme_asset_url(string $asset): string {
    return base_url() . '/themes/' . active_theme() . '/' . $asset;
}

function get_theme_css_vars(): string {
    $color = theme_color();
    // Ensure valid hex color
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6366f1';
    $r = hexdec(substr($color, 1, 2));
    $g = hexdec(substr($color, 3, 2));
    $b = hexdec(substr($color, 5, 2));
    return ":root {
        --accent: {$color};
        --accent-rgb: {$r},{$g},{$b};
        --accent-light: rgba({$r},{$g},{$b},0.1);
        --accent-medium: rgba({$r},{$g},{$b},0.3);
    }";
}
