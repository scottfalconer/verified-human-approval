<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\OAuth;

/**
 * Where an authorization code goes, read the way the person's browser reads its redirect URI. PHP's parse_url and a
 * browser disagree about some URIs: in https://attacker.example\@claude.ai/ one finds the host claude.ai, the other
 * attacker.example. So a redirect URI is taken only in a plain form both read alike, and the consent page names
 * the host from that. As MCP requires, it is https, or http back to this computer: an application's own link
 * scheme hands the code to whichever application opens it, and a browser's own, such as x-safari-https:, carries
 * it on to the web, so the page could not say where the code goes.
 */
final readonly class ReturnAddress
{
    public const REFUSED = 'Use a plain redirect URI on https or localhost, like https://app.example/callback, so the consent page can show where the code goes.';

    /**
     * https or http, "//" and a host of letters, digits, hyphens and dots or [::1], an optional port, then a path and
     * query of RFC 3986's characters. So nothing a browser drops, decodes or reads as a separator: no backslash,
     * space, control character, letter beyond ASCII, user name, fragment, or percent sign in the host.
     */
    private const PLAIN = '~^(?<scheme>https?)://(?<host>[a-z0-9-]+(?:\.[a-z0-9-]+)*|\[::1\])(?::(?<port>[0-9]{1,5}))?'
        .'(?:/(?:[a-z0-9._\~!$&\'()*+,;=:@/-]|%[0-9a-f]{2})*)?(?:\?(?:[a-z0-9._\~!$&\'()*+,;=:@/?-]|%[0-9a-f]{2})*)?$~iD';

    /**
     * @param string $host         the host the code goes to
     * @param bool   $thisComputer the code stays on this computer, where any application could be the one listening
     */
    private function __construct(public string $uri, public string $host, public bool $thisComputer)
    {
    }

    /** The return address of a plain https redirect URI, or of a plain http one back to this computer; null for any other. */
    public static function of(string $uri): ?self
    {
        if (1 !== preg_match(self::PLAIN, $uri, $parts, \PREG_UNMATCHED_AS_NULL) || (int) $parts['port'] > 65535) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        // A browser reads a host whose last label is a number as an IPv4 address, written in up to four decimal,
        // octal or hexadecimal parts. Only the four decimal numbers it would show are taken as one.
        $ipv4 = 1 === preg_match('~(?:^|\.)(?:[0-9]+|0x[0-9a-f]*)$~iD', $host);
        if ($ipv4 && false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return null;
        }
        $thisComputer = $ipv4
            ? str_starts_with($host, '127.') || '0.0.0.0' === $host
            : 'localhost' === $host || str_ends_with($host, '.localhost') || '[::1]' === $host;

        // Plain http would carry the code across the network, so it may only stay on this computer.
        return $thisComputer || 'https' === strtolower((string) $parts['scheme']) ? new self($uri, $host, $thisComputer) : null;
    }
}
