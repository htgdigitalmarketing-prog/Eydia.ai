<?php

namespace App\Modules\Gbp\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GbpAutomation;
use App\Models\GbpLocation;
use App\Modules\Gbp\Services\GbpAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, unauthenticated "AI review assistant" reached by scanning a business's
 * QR code (eydia.in/review/{slug}). The customer picks a star rating and a few
 * words; AI drafts a natural review they can tweak, copy, and then post on Google
 * themselves (we never submit on their behalf).
 */
class ReviewAssistController extends Controller
{
    /** The assist page (HTML). */
    public function show(string $slug, Request $request): Response
    {
        [$automation, $location] = $this->resolve($slug);
        if (! $automation || ! $location) {
            return response($this->notFoundHtml(), 404)->header('Content-Type', 'text/html');
        }

        $reviewUrl = $location->meta['review_url'] ?? '';

        return response($this->render($slug, $location->name ?: 'this business', $reviewUrl), 200)
            ->header('Content-Type', 'text/html');
    }

    /** AI-draft a review for the customer (no auth). */
    public function suggest(string $slug, Request $request, GbpAiService $ai): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        [$automation, $location] = $this->resolve($slug);
        if (! $automation || ! $location) {
            return $this->fail('This review link is not available.', [], 404);
        }

        $review = $ai->customerReview($location, $data['rating'], $data['notes'] ?? null)
            ?: $this->fallbackReview($location->name ?: 'this business', $data['rating'], $data['notes'] ?? null);

        return $this->ok(['review' => $review]);
    }

    /** @return array{0: ?GbpAutomation, 1: ?GbpLocation} */
    private function resolve(string $slug): array
    {
        $automation = GbpAutomation::withoutGlobalScopes()->where('slug', $slug)->first();
        if (! $automation) {
            return [null, null];
        }
        $location = $automation->gbp_location_id
            ? GbpLocation::withoutGlobalScopes()->find($automation->gbp_location_id)
            : null;

        return [$automation, $location];
    }

    /** A decent review when no AI provider is configured. */
    private function fallbackReview(string $business, int $rating, ?string $notes): string
    {
        $extra = $notes ? ' ' . trim($notes) : '';
        if ($rating >= 4) {
            return "Had a great experience with {$business}.{$extra} Friendly service and would definitely recommend them!";
        }
        if ($rating === 3) {
            return "Decent experience at {$business}.{$extra} A few things could be better, but overall okay.";
        }

        return "My experience at {$business} wasn't what I expected.{$extra} Hoping they improve.";
    }

    private function render(string $slug, string $business, string $reviewUrl): string
    {
        $b = htmlspecialchars($business, ENT_QUOTES);
        $url = htmlspecialchars($reviewUrl, ENT_QUOTES);
        $suggestUrl = htmlspecialchars('/review/' . $slug . '/suggest', ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Review {$b}</title>
<style>
  :root{--brand:#2160ef;--ink:#1a2230;--muted:#667085;--line:#e6e8ef;--bg:#f5f6ff}
  *{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55}
  .wrap{max-width:520px;margin:0 auto;padding:22px 16px 40px}
  .card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(33,96,239,.07)}
  h1{font-size:21px;margin:0 0 4px}.sub{color:var(--muted);font-size:14px;margin:0 0 18px}
  .stars{display:flex;gap:8px;justify-content:center;margin:6px 0 16px}
  .star{font-size:38px;cursor:pointer;color:#d8dbe6;transition:transform .1s}.star.on{color:#f5b301}.star:active{transform:scale(.9)}
  textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:12px;font:inherit;font-size:15px;resize:vertical;min-height:54px}
  label{font-weight:600;font-size:13.5px;display:block;margin:14px 0 6px}
  .btn{display:block;width:100%;border:none;border-radius:12px;padding:14px;font:inherit;font-weight:700;font-size:15.5px;cursor:pointer;background:linear-gradient(120deg,#16b6f2,#2160ef,#6a22f0);color:#fff;margin-top:16px}
  .btn[disabled]{opacity:.6}
  .btn.google{background:#fff;color:#1a7f37;border:2px solid #1a7f37;margin-top:10px}
  .out{margin-top:16px;display:none}
  .muted{color:var(--muted);font-size:12.5px}.center{text-align:center}
  .foot{margin-top:18px;text-align:center;font-size:11.5px;color:var(--muted)}
</style></head>
<body><div class="wrap"><div class="card">
  <h1>How was your experience?</h1>
  <p class="sub">Help <strong>{$b}</strong> with a quick Google review — we'll help you word it.</p>
  <div class="stars" id="stars">
    <span class="star" data-v="1">★</span><span class="star" data-v="2">★</span><span class="star" data-v="3">★</span><span class="star" data-v="4">★</span><span class="star" data-v="5">★</span>
  </div>
  <label>Anything you'd like to mention? <span class="muted">(optional)</span></label>
  <textarea id="notes" placeholder="e.g. the staff, the service, what stood out…"></textarea>
  <button class="btn" id="gen">✨ Help me write my review</button>
  <div class="out" id="out">
    <label>Your review <span class="muted">(tap to edit)</span></label>
    <textarea id="review" style="min-height:120px"></textarea>
    <button class="btn" id="copy">📋 Copy review</button>
    <a class="btn google" id="post" href="{$url}" target="_blank" rel="noreferrer">Open Google to post it →</a>
    <p class="muted center" style="margin-top:10px">Paste your review on Google and tap Post. You're in control — nothing is submitted for you.</p>
  </div>
  <div class="foot">Powered by Eydia.ai</div>
</div></div>
<script>
  var rating=0;
  var stars=[].slice.call(document.querySelectorAll('.star'));
  stars.forEach(function(s){s.onclick=function(){rating=+s.dataset.v;stars.forEach(function(x){x.classList.toggle('on',+x.dataset.v<=rating)});};});
  var gen=document.getElementById('gen'),out=document.getElementById('out'),rv=document.getElementById('review');
  gen.onclick=function(){
    if(!rating){alert('Please tap the stars to rate first.');return;}
    gen.disabled=true;gen.textContent='✨ Writing…';
    fetch('{$suggestUrl}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({rating:rating,notes:document.getElementById('notes').value})})
      .then(function(r){return r.json();})
      .then(function(j){rv.value=(j&&j.data&&j.data.review)||'';out.style.display='block';out.scrollIntoView({behavior:'smooth'});})
      .catch(function(){alert('Could not generate a review. Please try again.');})
      .finally(function(){gen.disabled=false;gen.textContent='✨ Help me write my review';});
  };
  document.getElementById('copy').onclick=function(){rv.select();try{navigator.clipboard.writeText(rv.value);this.textContent='✓ Copied!';var b=this;setTimeout(function(){b.textContent='📋 Copy review';},1600);}catch(e){document.execCommand('copy');}};
</script>
</body></html>
HTML;
    }

    private function notFoundHtml(): string
    {
        return '<!doctype html><meta charset="utf-8"><title>Not found</title>'
            . '<div style="font-family:system-ui;max-width:420px;margin:60px auto;text-align:center;color:#444">'
            . '<h2>Review link not available</h2><p>This review link is inactive. Please ask the business for a current one.</p></div>';
    }
}
