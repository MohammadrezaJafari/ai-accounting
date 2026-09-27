<?php

namespace App\Services\Agents;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Reads news items from a source: an RSS / Atom feed, or the headlines (links) of a news web page.
 *
 * @phpstan-type NewsItem array{title: string, link: string, summary: string, published_at: ?string, source: string}
 */
class FeedFetcher
{
    private const MAX_BYTES = 3_000_000;

    private const MAX_ITEMS_PER_SOURCE = 40;

    public function __construct(private UrlGuard $guard) {}

    /**
     * @return list<NewsItem>
     */
    public function fetch(string $url): array
    {
        $this->guard->assertPublic($url);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; AI-Accounting-NewsMonitor/1.0)'])
                ->withOptions(['allow_redirects' => [
                    'max' => 3,
                    'on_redirect' => fn (RequestInterface $request, ResponseInterface $response, UriInterface $uri) => $this->guard->assertPublic((string) $uri),
                ]])
                ->get($url);
        } catch (AgentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new AgentException("دریافت «{$url}» ممکن نشد.");
        }

        if (! $response->successful()) {
            throw new AgentException("منبع «{$url}» پاسخ {$response->status()} داد.");
        }

        $body = substr($response->body(), 0, self::MAX_BYTES);
        $source = parse_url($url, PHP_URL_HOST) ?: $url;

        $items = $this->looksLikeFeed($body) ? $this->parseFeed($body, $source) : $this->parseHeadlines($body, $url, $source);

        return array_slice($items, 0, self::MAX_ITEMS_PER_SOURCE);
    }

    private function looksLikeFeed(string $body): bool
    {
        $head = strtolower(substr(ltrim($body, "\xEF\xBB\xBF \t\n\r"), 0, 500));

        return str_starts_with($head, '<?xml') || str_contains($head, '<rss') || str_contains($head, '<feed');
    }

    /**
     * @return list<NewsItem>
     */
    private function parseFeed(string $body, string $source): array
    {
        $xml = @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);

        if ($xml === false) {
            return [];
        }

        $items = [];

        foreach ($xml->channel->item ?? [] as $item) {
            $items[] = $this->item((string) $item->title, (string) $item->link, (string) $item->description, (string) $item->pubDate, $source);
        }

        foreach ($xml->entry ?? [] as $entry) {
            $link = '';
            foreach ($entry->link as $candidate) {
                if (in_array((string) ($candidate['rel'] ?? 'alternate'), ['alternate', ''], true)) {
                    $link = (string) $candidate['href'];
                    break;
                }
            }
            $items[] = $this->item((string) $entry->title, $link, (string) ($entry->summary ?: $entry->content), (string) ($entry->published ?: $entry->updated), $source);
        }

        return array_values(array_filter($items, fn (array $item) => $item['title'] !== '' && $item['link'] !== ''));
    }

    /**
     * News pages without a feed: their headlines are links with a sentence-long text.
     *
     * @return list<NewsItem>
     */
    private function parseHeadlines(string $body, string $url, string $source): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR);

        $items = [];

        /** @var DOMElement $anchor */
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $title = $this->clean($anchor->textContent);
            $link = $this->absolute($anchor->getAttribute('href'), $url);
            $length = mb_strlen($title);

            if ($link === null || $length < 25 || $length > 220 || isset($items[$link])) {
                continue;
            }

            $items[$link] = $this->item($title, $link, '', '', $source);
        }

        return array_values($items);
    }

    /**
     * @return NewsItem
     */
    private function item(string $title, string $link, string $summary, string $date, string $source): array
    {
        try {
            $published = $date !== '' ? CarbonImmutable::parse($date)->utc()->toIso8601String() : null;
        } catch (Throwable) {
            $published = null;
        }

        return [
            'title' => Str::limit($this->clean($title), 300, ''),
            'link' => trim($link),
            'summary' => Str::limit($this->clean(strip_tags($summary)), 400),
            'published_at' => $published,
            'source' => $source,
        ];
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function absolute(string $href, string $base): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript|mailto|tel):/i', $href)) {
            return null;
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return match (true) {
            str_starts_with($href, '//') => ($parts['scheme'] ?? 'https').':'.$href,
            str_starts_with($href, '/') => $origin.$href,
            default => $origin.'/'.ltrim(dirname($parts['path'] ?? '/').'/', '/').$href,
        };
    }
}
