<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves /sitemap.xml and /robots.txt for the *frontend's* public pages
 * (user_website — the only pages a crawler should ever see; the admin
 * dashboard and every authenticated route are deliberately not listed).
 *
 * Important caveat, not a limitation of this code: the sitemap protocol
 * requires every <loc> in a sitemap to be on the *same host* the sitemap
 * file itself is served from. This controller lists user_website's own
 * URLs (config('pingly.frontend_url')), so it only satisfies that rule if
 * the backend and user_website end up sharing a domain in production (e.g.
 * a reverse proxy routing /sitemap.xml and /robots.txt here, everything
 * else to the built frontend). If they're deployed on separate subdomains
 * instead (api.pingly.com vs. pingly.com), user_website needs its own copy
 * of both files at its own domain root — see user_website/public/
 * sitemap.xml and robots.txt, which exist for exactly that reason and stay
 * in sync with this controller by hand.
 */
class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $base = rtrim(config('pingly.frontend_url'), '/');

        // Only the truly public, crawlable pages — everything else in
        // user_website sits behind the router's auth guard and redirects
        // an unauthenticated visitor straight to /login, so there's no
        // distinct public content there for a crawler to index.
        $pages = [
            ['loc' => "{$base}/welcome", 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['loc' => "{$base}/signup", 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => "{$base}/login", 'changefreq' => 'monthly', 'priority' => '0.3'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($pages as $page) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($page['loc'], ENT_XML1)."</loc>\n";
            $xml .= "    <changefreq>{$page['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$page['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $base = rtrim(config('pingly.frontend_url'), '/');

        // Permissive on purpose — this also covers AI/answer-engine
        // crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended,
        // CCBot, and friends) since none of them are named and disallowed;
        // "User-agent: *, Allow: /" already lets all of them in.
        $body = "User-agent: *\nAllow: /\n\nSitemap: {$base}/sitemap.xml\n";

        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
