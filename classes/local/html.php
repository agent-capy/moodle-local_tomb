<?php
// License: GNU GPL v3 or later.
namespace local_tomb\local;

/** Inert static content. Every original link/resource is resolved through an authorised map. */
final class html {
    public static function article_digest(string $html): string {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $article = $dom->getElementsByTagName('article')->item(0);
        if (!$article) {
            throw new \RuntimeException('Archive page does not contain an article');
        }
        return hash('sha256', $dom->saveHTML($article));
    }

    /** Fail closed on broken or remote references in final generated pages. */
    public static function check_links(array $documents, array $paths): void {
        $known = array_fill_keys($paths, true);
        foreach ($documents as $from => $content) {
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[@href or @src or @poster]') as $node) {
                foreach (['href', 'src', 'poster'] as $attr) {
                    $url = $node->getAttribute($attr);
                    if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'data:image/')) {
                        continue;
                    }
                    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|/)~i', $url)) {
                        throw new \RuntimeException('Non-relative archive reference in ' . $from);
                    }
                    $segments = dirname($from) === '.' ? [] : explode('/', dirname($from));
                    foreach (explode('/', rawurldecode(explode('#', explode('?', $url)[0])[0])) as $segment) {
                        if ($segment === '..') {
                            if (!$segments) {
                                throw new \RuntimeException('Archive reference escapes archive root');
                            }
                            array_pop($segments);
                        } else if ($segment !== '.' && $segment !== '') {
                            $segments[] = $segment;
                        }
                    }
                    if (!isset($known[implode('/', $segments)])) {
                        throw new \RuntimeException('Unresolved archive reference in ' . $from);
                    }
                }
            }
        }
    }

    public static function rewrite(string $fragment, callable $resolve, ?callable $omitted = null): string {
        if (trim($fragment) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        try {
            $dom->loadHTML('<?xml encoding="utf-8" ?><html><body><div id="tomb-fragment">' . $fragment .
                '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $container = $dom->getElementById('tomb-fragment');
        if (!$container) {
            throw new \RuntimeException('Cannot parse learning content');
        }
        $nodes = [];
        foreach ($container->getElementsByTagName('*') as $node) {
            $nodes[] = $node;
        }
        foreach (array_reverse($nodes) as $node) {
            if (!$node->parentNode) {
                continue;
            }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'base', 'link', 'meta'], true)) {
                if ($omitted && in_array($tag, ['iframe', 'object', 'embed'], true)) {
                    $omitted('external_resource');
                }
                $node->parentNode->removeChild($node);
                continue;
            }
            if ($tag === 'input') {
                $type = strtolower($node->getAttribute('type'));
                $text = in_array($type, ['hidden', 'submit', 'button', 'password'], true) ? '' :
                    (in_array($type, ['radio', 'checkbox'], true) ? ($node->hasAttribute('checked') ? '☑ ' : '☐ ') :
                    $node->getAttribute('value'));
                $node->parentNode->replaceChild($dom->createTextNode($text), $node);
                continue;
            }
            if ($tag === 'textarea') {
                $node->parentNode->replaceChild($dom->createTextNode($node->textContent), $node);
                continue;
            }
            if ($tag === 'select') {
                $selected = [];
                foreach ($node->getElementsByTagName('option') as $option) {
                    if ($option->hasAttribute('selected')) {
                        $selected[] = $option->textContent;
                    }
                }
                $node->parentNode->replaceChild($dom->createTextNode(implode(', ', $selected)), $node);
                continue;
            }
            $attrs = [];
            foreach ($node->attributes as $attr) {
                $attrs[] = $attr->name;
            }
            foreach ($attrs as $attr) {
                if (!in_array(strtolower($attr), ['id', 'class', 'title', 'alt', 'href', 'src', 'poster', 'colspan',
                        'rowspan', 'width', 'height', 'controls', 'scope', 'lang', 'dir', 'open', 'selected'], true)) {
                    $node->removeAttribute($attr);
                }
            }
            foreach (['href', 'src', 'poster'] as $attr) {
                if (!$node->hasAttribute($attr)) {
                    continue;
                }
                $url = trim($node->getAttribute($attr));
                if ($attr === 'href' && str_starts_with($url, '#')) {
                    continue;
                }
                if ($tag === 'img' && $attr === 'src' && preg_match('~^data:image/(png|jpeg|gif|webp);base64,[a-zA-Z0-9+/=]+$~D', $url)) {
                    continue;
                }
                $replacement = $resolve($url);
                if ($replacement !== null) {
                    $node->setAttribute($attr, $replacement);
                } else {
                    $node->removeAttribute($attr);
                    if ($tag === 'img' && $attr === 'src') {
                        $node->parentNode->replaceChild($dom->createTextNode($node->getAttribute('alt')), $node);
                        break;
                    }
                }
            }
            if (in_array($tag, ['form', 'button', 'svg', 'math'], true) && $node->parentNode) {
                // Native MathML is retained as inert presentation; SVG can carry active foreign content.
                if ($tag === 'math') {
                    continue;
                }
                if ($tag === 'svg') {
                    $node->parentNode->replaceChild($dom->createTextNode('[image]'), $node);
                    if ($omitted) {
                        $omitted('unsupported_markup');
                    }
                } else {
                    while ($node->firstChild) {
                        $node->parentNode->insertBefore($node->firstChild, $node);
                    }
                    $node->parentNode->removeChild($node);
                }
            }
        }
        $result = '';
        foreach ($container->childNodes as $node) {
            $result .= $dom->saveHTML($node);
        }
        return $result;
    }
}
