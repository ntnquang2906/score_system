<?php

function initLang()
{
    global $LANG, $TRANSLATIONS;

    $requested = $_GET['lang'] ?? null;

    if ($requested === 'vi' || $requested === 'en') {
        setcookie('site_lang', $requested, time() + 31536000, '/');
        $LANG = $requested;
    } else {
        $LANG = ($_COOKIE['site_lang'] ?? 'vi') === 'en' ? 'en' : 'vi';
    }

    $TRANSLATIONS = require __DIR__ . '/lang/' . $LANG . '.php';
}

function t($key, $vars = [])
{
    global $TRANSLATIONS;

    $text = $TRANSLATIONS[$key] ?? $key;

    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }

    return $text;
}

// Picks the "<field>_en" variant of a criteria.json item when the UI is in
// English and that variant exists, otherwise falls back to the Vietnamese field.
function pickField($item, $field)
{
    global $LANG;

    if ($LANG === 'en' && !empty($item[$field . '_en'])) {
        return $item[$field . '_en'];
    }

    return $item[$field] ?? '';
}

function langSwitchLinks($onDark = false)
{
    global $LANG;

    $viParams = $_GET;
    $viParams['lang'] = 'vi';

    $enParams = $_GET;
    $enParams['lang'] = 'en';

    $viUrl = '?' . http_build_query($viParams);
    $enUrl = '?' . http_build_query($enParams);

    $viClass = $LANG === 'vi' ? 'active' : '';
    $enClass = $LANG === 'en' ? 'active' : '';
    $wrapClass = 'lang-switch' . ($onDark ? ' on-dark' : '');

    return '<div class="' . $wrapClass . '">'
        . '<a href="' . htmlspecialchars($viUrl) . '" class="' . $viClass . '">🇻🇳 VI</a>'
        . '<a href="' . htmlspecialchars($enUrl) . '" class="' . $enClass . '">🇬🇧 EN</a>'
        . '</div>';
}
