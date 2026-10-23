<?php

namespace App\Modules\Gbp\Services;

/**
 * Renders a generated business microsite + Google Business Profile report as a
 * single self-contained HTML document (inline CSS, Inter font, Eydia branding).
 * Input is the frozen BusinessSite `data` snapshot; all values are escaped.
 */
class BusinessSiteRenderer
{
    /** @param array<string,mixed> $d */
    public function render(array $d): string
    {
        $name = $this->e($d['name'] ?? 'Business');
        $category = $this->e($d['category'] ?? '');
        $address = $this->e($d['address'] ?? '');
        $phone = (string) ($d['phone'] ?? '');
        $website = (string) ($d['website'] ?? '');
        $rating = (float) ($d['rating'] ?? 0);
        $reviewCount = (int) ($d['review_count'] ?? 0);
        $verified = ! empty($d['is_verified']);
        $mapsUrl = $this->e($d['maps_url'] ?? '#');
        $initial = mb_strtoupper(mb_substr($name, 0, 1));

        $report = $d['report'] ?? ['score' => 0, 'grade' => '', 'sub_scores' => [], 'actions' => []];
        $score = (int) ($report['score'] ?? 0);
        $grade = $this->e($report['grade'] ?? '');
        $scoreColor = $score >= 75 ? '#22c55e' : ($score >= 50 ? '#f59e0b' : '#ef4444');

        $ctas = '<a class="btn btn-grad" href="' . $this->telHref($phone) . '">📞 Call now</a>'
            . '<a class="btn btn-ghost" href="' . $mapsUrl . '" target="_blank" rel="noopener">🧭 Directions</a>';
        if ($website !== '') {
            $ctas .= '<a class="btn btn-ghost" href="' . $this->e($website) . '" target="_blank" rel="noopener">🌐 Website</a>';
        }

        $stars = $this->stars($rating);
        $ring = $this->scoreRing($score, $scoreColor);
        $subScores = $this->subScores($report['sub_scores'] ?? []);
        $actions = $this->actions($report['actions'] ?? []);
        $gallery = $this->gallery($d['photos'] ?? []);
        $reviews = $this->reviews($d['reviews'] ?? []);

        $metaDesc = $this->e(trim("{$name} — {$category}. {$rating}★ from {$reviewCount} Google reviews."));

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$name} — Google Business Profile</title>
<meta name="description" content="{$metaDesc}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root{--grad:linear-gradient(120deg,#16b6f2 0%,#2160ef 34%,#6a22f0 70%,#ad17e6 100%);--ink:#0f1b2d;--muted:#5b6577;--line:#e9ecf7;--bg:#f5f6ff;--card:#fff;}
  *{box-sizing:border-box;} html,body{margin:0;padding:0;}
  body{font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;line-height:1.55;}
  .wrap{max-width:960px;margin:0 auto;padding:0 20px;}
  .btn{display:inline-flex;align-items:center;gap:7px;padding:12px 20px;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none;white-space:nowrap;}
  .btn-grad{background:var(--grad);color:#fff;box-shadow:0 10px 24px rgba(76,87,216,.3);}
  .btn-ghost{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.34);backdrop-filter:blur(6px);}
  .hero{background:var(--grad);color:#fff;padding:54px 0 60px;position:relative;overflow:hidden;}
  .hero::after{content:"";position:absolute;right:-80px;top:-80px;width:320px;height:320px;border-radius:50%;background:rgba(255,255,255,.12);}
  .hero-in{position:relative;z-index:2;display:flex;gap:22px;align-items:flex-start;flex-wrap:wrap;}
  .avatar{width:74px;height:74px;border-radius:20px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);display:grid;place-items:center;font-size:34px;font-weight:800;flex-shrink:0;}
  .verified{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;margin-bottom:8px;}
  h1{font-size:34px;font-weight:800;letter-spacing:-.02em;margin:0 0 6px;}
  .cat{opacity:.92;font-weight:600;font-size:15px;}
  .hero-rating{display:flex;align-items:center;gap:8px;margin:14px 0 6px;font-weight:700;}
  .hero-rating .stars{letter-spacing:2px;color:#ffd566;font-size:18px;}
  .hero-addr{opacity:.9;font-size:14px;margin-bottom:20px;}
  .hero-cta{display:flex;gap:10px;flex-wrap:wrap;}
  section{padding:46px 0;}
  .sec-title{font-size:13px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#4c57d8;text-align:center;}
  h2{font-size:27px;font-weight:800;letter-spacing:-.01em;text-align:center;margin:6px 0 28px;}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px;}
  .report{background:var(--card);}
  .report-grid{display:grid;grid-template-columns:230px 1fr;gap:28px;align-items:center;}
  .ring-wrap{text-align:center;}
  .ring-label{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:8px;}
  .subs{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .sub{border:1px solid var(--line);border-radius:12px;padding:12px 14px;}
  .sub-top{display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700;margin-bottom:7px;}
  .sub-pct{font-size:12px;}
  .track{height:7px;border-radius:5px;background:var(--line);overflow:hidden;}
  .fill{height:100%;border-radius:5px;background:var(--grad);}
  .actions{display:grid;gap:8px;margin-top:22px;}
  .act{display:flex;gap:10px;align-items:flex-start;background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:12px 14px;}
  .act .ic{font-size:17px;}
  .act b{font-size:13.5px;} .act span{display:block;font-size:12.5px;color:var(--muted);}
  .pill{font-size:10.5px;font-weight:800;text-transform:uppercase;padding:3px 9px;border-radius:999px;margin-left:auto;white-space:nowrap;}
  .gallery{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
  .gallery img{width:100%;height:170px;object-fit:cover;border-radius:12px;border:1px solid var(--line);}
  .reviews{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
  .rev{border:1px solid var(--line);border-radius:14px;padding:16px;background:var(--card);}
  .rev-top{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
  .rev-av{width:36px;height:36px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;font-weight:800;}
  .rev-stars{color:#f59e0b;font-size:13px;letter-spacing:1px;}
  .rev p{margin:0;font-size:13.5px;color:#36414f;}
  footer{background:#0f1b2d;color:#aeb8cc;text-align:center;padding:30px 20px;font-size:13px;}
  footer a{color:#fff;font-weight:700;text-decoration:none;}
  .foot-brand{font-weight:800;color:#fff;font-size:15px;margin-bottom:4px;}
  @media(max-width:720px){.report-grid{grid-template-columns:1fr;}.subs,.gallery,.reviews{grid-template-columns:1fr;}h1{font-size:28px;}}
</style>
</head>
<body>
  <header class="hero"><div class="wrap hero-in">
    <div class="avatar">{$initial}</div>
    <div style="flex:1;min-width:240px;">
      {$this->verifiedBadge($verified)}
      <h1>{$name}</h1>
      <div class="cat">{$category}</div>
      <div class="hero-rating"><span class="stars">{$stars}</span> {$this->ratingText($rating, $reviewCount)}</div>
      <div class="hero-addr">📍 {$address}</div>
      <div class="hero-cta">{$ctas}</div>
    </div>
  </div></header>

  <section class="report"><div class="wrap">
    <div class="sec-title">Google Business Profile</div>
    <h2>Your Business Health Report</h2>
    <div class="card"><div class="report-grid">
      <div class="ring-wrap">{$ring}<div class="ring-label">{$grade}</div></div>
      <div class="subs">{$subScores}</div>
    </div>
    {$actions}
    </div>
  </div></section>

  {$gallery}
  {$reviews}

  <footer>
    <div class="foot-brand">{$name}</div>
    <div>Powered by <a href="https://eydia.in" target="_blank" rel="noopener">Eydia.ai</a> — Automate · Engage · Grow</div>
  </footer>
</body>
</html>
HTML;
    }

    private function verifiedBadge(bool $v): string
    {
        return $v ? '<span class="verified">✔ Verified on Google</span>' : '';
    }

    private function ratingText(float $rating, int $count): string
    {
        return $rating > 0
            ? $this->e(number_format($rating, 1)) . ' · ' . $this->e(number_format($count)) . ' Google reviews'
            : 'New on Google';
    }

    private function stars(float $rating): string
    {
        $full = (int) floor($rating);
        $out = str_repeat('★', max(0, min(5, $full))) . str_repeat('☆', max(0, 5 - $full));

        return $out;
    }

    private function scoreRing(int $score, string $color): string
    {
        $r = 56;
        $circ = 2 * M_PI * $r;
        $off = $circ - ($circ * max(0, min(100, $score)) / 100);

        return '<svg width="150" height="150" viewBox="0 0 150 150">'
            . '<circle cx="75" cy="75" r="' . $r . '" fill="none" stroke="#e9ecf7" stroke-width="12"/>'
            . '<circle cx="75" cy="75" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="12" stroke-linecap="round"'
            . ' stroke-dasharray="' . round($circ, 1) . '" stroke-dashoffset="' . round($off, 1) . '" transform="rotate(-90 75 75)"/>'
            . '<text x="75" y="72" text-anchor="middle" font-size="38" font-weight="800" fill="' . $color . '">' . $score . '</text>'
            . '<text x="75" y="94" text-anchor="middle" font-size="12" font-weight="700" fill="#5b6577">/ 100</text>'
            . '</svg>';
    }

    /** @param array<int,array> $subs */
    private function subScores(array $subs): string
    {
        $html = '';
        foreach ($subs as $s) {
            $score = (int) ($s['score'] ?? 0);
            $color = $score >= 70 ? '#0a7d47' : ($score >= 40 ? '#b45309' : '#c53030');
            $html .= '<div class="sub"><div class="sub-top"><span>' . $this->e($s['icon'] ?? '') . ' ' . $this->e($s['label'] ?? '')
                . '</span><span class="sub-pct" style="color:' . $color . '">' . $score . '%</span></div>'
                . '<div class="track"><div class="fill" style="width:' . max(0, min(100, $score)) . '%"></div></div></div>';
        }

        return $html ?: '<p style="color:#5b6577">Connect Google to see your sub-scores.</p>';
    }

    /** @param array<int,array> $actions */
    private function actions(array $actions): string
    {
        if (empty($actions)) {
            return '';
        }
        $rows = '';
        foreach (array_slice($actions, 0, 5) as $a) {
            $impact = strtolower((string) ($a['priority'] ?? ''));
            [$fg, $bg] = match ($impact) {
                'critical' => ['#c53030', '#fdecec'],
                'high' => ['#b45309', '#fef3e2'],
                'medium' => ['#0e7490', '#e6f8fb'],
                default => ['#5b6577', '#eef1f8'],
            };
            $title = $this->e($a['title'] ?? $a['text'] ?? '');
            $why = isset($a['why']) ? '<span>' . $this->e($a['why']) . '</span>' : '';
            $rows .= '<div class="act"><span class="ic">' . $this->e($a['icon'] ?? '•') . '</span><div style="flex:1"><b>'
                . $title . '</b>' . $why . '</div><span class="pill" style="color:' . $fg . ';background:' . $bg . '">'
                . $this->e(ucfirst($impact)) . '</span></div>';
        }

        return '<div style="margin-top:22px"><div style="font-weight:800;font-size:15px;margin-bottom:10px">What to improve next</div><div class="actions">' . $rows . '</div></div>';
    }

    /** @param array<int,string> $photos */
    private function gallery(array $photos): string
    {
        if (empty($photos)) {
            return '';
        }
        $imgs = '';
        foreach ($photos as $url) {
            $imgs .= '<img src="' . $this->e($url) . '" alt="" loading="lazy">';
        }

        return '<section><div class="wrap"><div class="sec-title">Gallery</div><h2>Photos</h2><div class="gallery">' . $imgs . '</div></div></section>';
    }

    /** @param array<int,array> $reviews */
    private function reviews(array $reviews): string
    {
        if (empty($reviews)) {
            return '';
        }
        $cards = '';
        foreach ($reviews as $r) {
            $av = mb_strtoupper(mb_substr((string) ($r['author'] ?? '?'), 0, 1));
            $cards .= '<div class="rev"><div class="rev-top"><div class="rev-av">' . $this->e($av) . '</div><div><b>'
                . $this->e($r['author'] ?? 'Customer') . '</b><div class="rev-stars">' . $this->stars((float) ($r['rating'] ?? 0))
                . '</div></div></div><p>' . $this->e($r['comment'] ?? '') . '</p></div>';
        }

        return '<section style="background:var(--bg)"><div class="wrap"><div class="sec-title">Testimonials</div><h2>What customers say</h2><div class="reviews">' . $cards . '</div></div></section>';
    }

    private function telHref(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', $phone);

        return $clean ? 'tel:' . $this->e($clean) : '#';
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
