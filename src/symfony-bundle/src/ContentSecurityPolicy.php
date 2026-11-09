<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Bundle;

use Symfony\Component\HttpFoundation\Request;

/**
 * The review pages' Content-Security-Policy. By default it allows this site's own scripts, styles, images, data:
 * images and connections, and nothing else: no plugins, no framing, no base URL, and forms post back here.
 *
 * Two things widen it, each only as far as you choose. Every response has a fresh nonce, so an inline script or
 * style in your layout runs when it carries that nonce, such as an importmap:
 *
 *     {{ importmap('app', {nonce: verified_human_approval_csp_nonce()}) }}
 *
 * And the bundle's content_security_policy configuration adds a short list of sources to script-src, style-src,
 * img-src, font-src and connect-src: 'self' and https:// origins, and data: for images and fonts. Anything else,
 * such as 'unsafe-inline', 'unsafe-eval', * or http:, is refused when the container is built.
 */
final class ContentSecurityPolicy
{
    /** The directives an application may add sources to, by configuration key. */
    public const DIRECTIVES = [
        'script_src' => 'script-src',
        'style_src' => 'style-src',
        'img_src' => 'img-src',
        'font_src' => 'font-src',
        'connect_src' => 'connect-src',
    ];

    /** An https:// origin: a host name and an optional port, with no path, query, wildcard or space. */
    private const ORIGIN = '~^https://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::(?<port>[0-9]{1,5}))?$~iD';

    /** @var array<string, list<string>> the added sources, by directive */
    private readonly array $sources;

    /** @var \WeakMap<Request, string> each request's nonce, released with its request */
    private readonly \WeakMap $nonces;

    /**
     * @param array<string, list<string>> $sources added sources by configuration key, such as
     *                                             ['font_src' => ['https://fonts.gstatic.com']]
     *
     * @throws \InvalidArgumentException for a directive or source this policy does not accept
     */
    public function __construct(array $sources = [])
    {
        $accepted = array_fill_keys(array_values(self::DIRECTIVES), []);
        foreach ($sources as $key => $list) {
            $directive = self::DIRECTIVES[$key] ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a directive the review pages’ policy extends. Use %s.', $key, implode(', ', array_keys(self::DIRECTIVES))));
            foreach ($list as $source) {
                if (null !== $refusal = self::refusal($directive, $source)) {
                    throw new \InvalidArgumentException($refusal);
                }
                $accepted[$directive][] = $source;
            }
        }
        $this->sources = $accepted;
        $this->nonces = new \WeakMap();
    }

    /** Why a source cannot be added to a directive, or null when it can. */
    public static function refusal(string $directive, mixed $source): ?string
    {
        $data = \in_array($directive, ['img-src', 'font-src'], true);
        if (\is_string($source)) {
            if ("'self'" === $source || ($data && 'data:' === $source)) {
                return null;
            }
            if (1 === preg_match(self::ORIGIN, $source, $origin) && (!isset($origin['port']) || ((int) $origin['port'] >= 1 && (int) $origin['port'] <= 65535))) {
                return null;
            }
        }

        return \sprintf(
            '%s cannot be added to %s. Use \'self\' or an https:// origin such as https://cdn.example.com%s; inline scripts and styles take the nonce instead.',
            // An environment variable is checked as empty when the container is built, since its value comes later.
            match (true) {
                '' === $source => 'An empty value, or an environment variable,',
                \is_string($source) => '"'.$source.'"',
                default => 'A '.get_debug_type($source),
            },
            $directive,
            $data ? ', or data:' : '',
        );
    }

    /** The nonce this request's policy allows: the same all through the request, and new for every request. */
    public function nonce(Request $request): string
    {
        if (!isset($this->nonces[$request])) {
            $this->nonces[$request] = self::freshNonce();
        }

        return $this->nonces[$request];
    }

    /** A new nonce: 144 random bits in base64url, so nothing in it needs escaping in a header or an attribute. */
    public static function freshNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** The Content-Security-Policy header for the response to this request. */
    public function header(Request $request): string
    {
        $nonce = "'nonce-".$this->nonce($request)."'";
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", $nonce, ...$this->sources['script-src']],
            'style-src' => ["'self'", $nonce, ...$this->sources['style-src']],
            'img-src' => ["'self'", 'data:', ...$this->sources['img-src']],
            // Fonts follow default-src unless the application adds a source of its own.
            'font-src' => [] === $this->sources['font-src'] ? [] : ["'self'", ...$this->sources['font-src']],
            'connect-src' => ["'self'", ...$this->sources['connect-src']],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'none'"],
            'object-src' => ["'none'"],
        ];
        $policy = [];
        foreach ($directives as $directive => $sources) {
            if ([] !== $sources) {
                $policy[] = $directive.' '.implode(' ', array_unique($sources));
            }
        }

        return implode('; ', $policy);
    }
}
