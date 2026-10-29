<?php

namespace App\Support;

/**
 * Understands the video links students may share. Only YouTube and Vimeo are accepted,
 * so the page only ever embeds a known player and never an arbitrary address.
 */
class VideoLink
{
    /**
     * @return array{provider: string, id: string}|null  null when the link is not a supported video.
     */
    public static function parse(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) preg_replace('/^(www\.|m\.)/i', '', $parts['host']));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $youtubeId = null;

        if ($host === 'youtu.be') {
            $youtubeId = explode('/', trim($path, '/'))[0];
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                $youtubeId = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:embed|shorts|live)/([^/?]+)#', $path, $m)) {
                $youtubeId = $m[1];
            }
        }

        if ($youtubeId !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId)) {
            return ['provider' => 'youtube', 'id' => $youtubeId];
        }

        if (
            ($host === 'vimeo.com' && preg_match('#^/(\d+)#', $path, $m))
            || ($host === 'player.vimeo.com' && preg_match('#^/video/(\d+)#', $path, $m))
        ) {
            return ['provider' => 'vimeo', 'id' => $m[1]];
        }

        return null;
    }

    public static function embedUrl(string $provider, string $id): string
    {
        return $provider === 'vimeo'
            ? "https://player.vimeo.com/video/{$id}"
            : "https://www.youtube-nocookie.com/embed/{$id}";
    }

    // Vimeo thumbnails need an extra lookup, so only YouTube gets one.
    public static function thumbnailUrl(string $provider, string $id): ?string
    {
        return $provider === 'youtube' ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : null;
    }
}
