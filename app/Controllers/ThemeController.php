<?php

declare(strict_types=1);

final class ThemeController extends BaseController
{
    public function set(): void
    {
        $theme = $_POST['theme'] ?? $_GET['theme'] ?? '';
        // Migrazione slug obsoleti — definita in config.php (QK_THEME_LEGACY)
        if (isset(QK_THEME_LEGACY[$theme])) {
            $theme = QK_THEME_LEGACY[$theme];
        }

        if (in_array($theme, array_column(QK_THEMES, 'slug'), true)) {
            $_SESSION['qk_theme'] = $theme;
        }

        // 204 per chiamate fetch, redirect per GET
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $back = $_SERVER['HTTP_REFERER'] ?? url('dashboard');
            header('Location: ' . $back);
            exit;
        }

        http_response_code(204);
    }
}

