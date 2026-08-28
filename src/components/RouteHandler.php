<?php

namespace justinholtweb\archie\components;

use Craft;
use justinholtweb\archie\models\ApplyContext;
use justinholtweb\archie\models\Blueprint;
use justinholtweb\archie\models\LintReport;

/**
 * URL routes.
 *
 * Craft stores a route as a list of literal segments and named subpatterns; a blueprint
 * writes the URI the way the CP shows it — `blog/{slug}` — and this handler compiles it.
 */
class RouteHandler extends BaseComponentHandler
{
    /** What `{slug}` means when the blueprint does not say. Matches Craft's own default. */
    private const DEFAULT_PATTERN = '[^\/]+';

    public static function type(): string
    {
        return 'routes';
    }

    public static function label(): string
    {
        return 'Routes';
    }

    public static function stage(): int
    {
        return 130;
    }

    public static function icon(): string
    {
        return 'routes';
    }

    public function all(): array
    {
        $routes = [];

        foreach (Craft::$app->getRoutes()->getProjectConfigRoutes() as $uid => $route) {
            $routes[] = (object)($route + ['uid' => $uid]);
        }

        return $routes;
    }

    public function handleOf(object $component): ?string
    {
        return $this->uriOf($component);
    }

    public function nameOf(object $component): string
    {
        return (string)$this->uriOf($component);
    }

    public function uidOf(object $component): ?string
    {
        return $component->uid ?? null;
    }

    /** Renders a stored route's parts back into the `blog/{slug}` form. */
    private function uriOf(object $route): string
    {
        $uri = '';

        foreach (($route->uriParts ?? []) as $part) {
            if (is_string($part)) {
                $uri .= $part;
            } elseif (is_array($part)) {
                $uri .= $part[1] === self::DEFAULT_PATTERN
                    ? '{' . $part[0] . '}'
                    : '{' . $part[0] . ':' . $part[1] . '}';
            }
        }

        return $uri;
    }

    public function dump(object $component): array
    {
        $siteUid = $component->siteUid ?? null;
        $site = null;

        if ($siteUid !== null) {
            foreach (Craft::$app->getSites()->getAllSites(true) as $candidate) {
                if ($candidate->uid === $siteUid) {
                    $site = $candidate;
                    break;
                }
            }
        }

        return [
            'handle' => $this->uriOf($component),
            'uri' => $this->uriOf($component),
            'template' => (string)($component->template ?? ''),
            'site' => $site?->handle,
        ];
    }

    public function normalize(array $spec): array
    {
        // Routes are keyed by their URI, and a mapping form (`"blog/{slug}": tmpl`) lands
        // the template in `value`.
        $uri = (string)($spec['uri'] ?? $spec['uriPattern'] ?? $spec['handle'] ?? '');
        $template = (string)($spec['template'] ?? $spec['value'] ?? '');

        return [
            'handle' => $uri,
            'name' => $uri,
            'uri' => $uri,
            'template' => $template,
            'site' => $spec['site'] ?? null,
        ];
    }

    public function lint(array $spec, LintReport $report, Blueprint $blueprint): void
    {
        $where = 'route “' . ($spec['uri'] ?: '?') . '”';

        if (($spec['uri'] ?? '') === '') {
            $report->error('A route needs a URI.', 'route');
        }

        if (($spec['template'] ?? '') === '') {
            $report->error('A route needs a template.', $where);
        }

        if (($spec['site'] ?? null) !== null && Craft::$app->getSites()->getSiteByHandle((string)$spec['site'], true) === null) {
            $report->error(sprintf('The site “%s” does not exist.', $spec['site']), $where);
        }
    }

    public function apply(array $spec, ?object $existing, ApplyContext $context): ?object
    {
        $siteUid = null;
        if (($spec['site'] ?? null) !== null) {
            $siteUid = Craft::$app->getSites()->getSiteByHandle((string)$spec['site'], true)?->uid;
        }

        Craft::$app->getRoutes()->saveRoute(
            self::parseUri((string)$spec['uri']),
            (string)$spec['template'],
            $siteUid,
            $existing->uid ?? null
        );

        return $this->find((string)$spec['uri']);
    }

    /**
     * Splits `blog/{slug}` and `news/{year:\d{4}}` into the literal-and-subpattern list
     * Craft stores.
     *
     * @return array<int, string|array{0: string, 1: string}>
     */
    public static function parseUri(string $uri): array
    {
        $parts = [];
        $offset = 0;

        // The name is deliberately non-greedy up to the first colon so that a pattern
        // containing braces of its own — `{year:\d{4}}` — still splits correctly.
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)(?::(.*?))?\}(?![^{]*\})/', $uri, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $literal = substr($uri, $offset, $match[1] - $offset);
            if ($literal !== '') {
                $parts[] = $literal;
            }

            $pattern = $matches[2][$i][0] ?? '';
            $parts[] = [$matches[1][$i][0], $pattern !== '' ? $pattern : self::DEFAULT_PATTERN];

            $offset = $match[1] + strlen($match[0]);
        }

        $tail = substr($uri, $offset);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        return $parts;
    }

    public function delete(object $component): bool
    {
        return Craft::$app->getRoutes()->deleteRouteByUid($component->uid);
    }
}
