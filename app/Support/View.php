<?php

declare(strict_types=1);

namespace GermanPath\Support;

use GermanPath\Config\Config;

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderLayout(Config $config, string $title, string $content): string
{
    $siteName = e((string) $config->get('site_name', 'GermanPath'));
    $pageTitle = e($title . ' · ' . $siteName);

    return <<<HTML
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="GermanPath — eine moderne Plattform zum Deutschlernen.">
    <title>{$pageTitle}</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/" aria-label="GermanPath Startseite">
            <span class="brand-mark" aria-hidden="true">G</span>
            <span>{$siteName}</span>
        </a>
        <nav aria-label="Hauptnavigation">
            <a href="/">Start</a>
            <a href="/courses">Kurse</a>
            <a href="/free">Kostenlos lernen</a>
        </nav>
    </header>
    <main class="page-shell">{$content}</main>
    <footer class="site-footer">
        <span>GermanPath</span>
        <span>Deutsch lernen. Schritt für Schritt.</span>
    </footer>
</body>
</html>
HTML;
}
