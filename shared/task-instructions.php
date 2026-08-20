<?php

declare(strict_types=1);

/**
 * Canonical rich-text handling for task instructions.
 *
 * Instructions are authored by portal users, so they are never returned as
 * renderable HTML until this allow-list has processed them.  The helper also
 * recognises legacy entity-escaped markup and normalises it once before
 * sanitising, which lets historic tasks render without a data rewrite.
 */
function task_instructions_sanitize_html(string $instructions): string
{
    $instructions = trim($instructions);
    if ($instructions === '') return '';

    // Older task records may contain &lt;p&gt;...&lt;/p&gt;. Decode before the
    // allow-list, never after it, so encoded unsafe markup is still removed.
    if (stripos($instructions, '&lt;') !== false || stripos($instructions, '&gt;') !== false) {
        $instructions = html_entity_decode($instructions, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    if (!class_exists('DOMDocument')) {
        // The production portal has DOMDocument for its policy viewer. Keep a
        // conservative fallback for a misconfigured environment.
        return nl2br(htmlspecialchars(strip_tags($instructions), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="task-instructions-root">' . $instructions . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $document->getElementById('task-instructions-root');
    if (!$root) return nl2br(htmlspecialchars(strip_tags($instructions), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a'];
    $blocked = ['script', 'iframe', 'object', 'embed', 'style', 'form', 'input', 'button', 'video', 'audio'];
    $renderNode = static function (DOMNode $node) use (&$renderNode, $allowed, $blocked): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars((string) $node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) return '';

        $tag = strtolower((string) $node->nodeName);
        if (in_array($tag, $blocked, true)) return '';

        $children = '';
        foreach ($node->childNodes as $child) $children .= $renderNode($child);
        if (!in_array($tag, $allowed, true)) return $children;
        if ($tag === 'br') return '<br>';

        if ($tag === 'a') {
            $hrefNode = $node->attributes ? $node->attributes->getNamedItem('href') : null;
            $href = trim((string) ($hrefNode ? $hrefNode->nodeValue : ''));
            if (!preg_match('#^(?:https?://|mailto:)#i', $href)) return $children;
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $children . '</a>';
        }
        return '<' . $tag . '>' . $children . '</' . $tag . '>';
    };

    $safe = '';
    foreach ($root->childNodes as $child) $safe .= $renderNode($child);
    return trim($safe);
}

function task_instructions_render_html(string $instructions, string $fallback = 'No instructions added.'): string
{
    $safe = task_instructions_sanitize_html($instructions);
    if ($safe !== '') return $safe;
    return '<p>' . htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
}

function task_instructions_plain_text(string $instructions): string
{
    $safe = task_instructions_sanitize_html($instructions);
    if ($safe === '') return '';

    $text = preg_replace('/<br\s*\/?>/i', "\n", $safe) ?? $safe;
    $text = preg_replace('/<\/p>|<\/(?:ul|ol)>/i', "\n", $text) ?? $text;
    $text = preg_replace('/<li>/i', "\n• ", $text) ?? $text;
    $text = preg_replace('/<\/li>/i', '', $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}
