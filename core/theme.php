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
        if (cms_plugin_is_active('kutpod') && function_exists('kutpod_filter_content')) {
            $vars['post']['content'] = kutpod_filter_content($vars['post']['content'], $vars['post']);
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
    $theme_color = $config['theme_color'] ?? '#6366f1';
    $theme = active_theme();
    $base = base_url();
    
    $file = theme_file("$template.php");
    if (!file_exists($file)) {
        echo "<p>Template '$template' not found.</p>";
        return;
    }
    
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
