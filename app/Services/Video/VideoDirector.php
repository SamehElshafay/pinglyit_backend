<?php

namespace App\Services\Video;

use App\Models\Company;
use App\Services\Ai\AiGatewayService;
use RuntimeException;

/**
 * Turns a client's brief into a shot list a video model can render.
 *
 * Video models render one short clip at a time and know nothing about the
 * clip before it. Everything that makes a sequence of clips read as *one*
 * film — the same face, the same jacket, the same light, the same lens —
 * has to be written into every single shot's prompt. That's this class's
 * job: an LLM writes the plan once (style bible, character sheets, shots),
 * and composePrompt() re-states the fixed parts on every shot.
 *
 * The LLM call is billed to the client as an ordinary AI Gateway request.
 */
class VideoDirector
{
    public const LANGUAGES = [
        'ar-EG' => 'Egyptian Arabic (Masri) — write lines in Arabic script, colloquial Cairene, not Modern Standard',
        'ar-SA' => 'Gulf / Saudi Arabic — write lines in Arabic script, colloquial Khaleeji',
        'ar' => 'Modern Standard Arabic — Arabic script',
        'en' => 'English',
    ];

    public const LANGUAGE_NAMES = [
        'ar-EG' => 'Egyptian Arabic',
        'ar-SA' => 'Gulf Arabic',
        'ar' => 'Modern Standard Arabic',
        'en' => 'English',
    ];

    public const STYLES = [
        'cinematic' => 'Cinematic commercial: anamorphic look, shallow depth of field, motivated lighting, filmic colour grade, deliberate camera moves (dolly, slider, crane)',
        'luxury' => 'Luxury brand film: slow, elegant motion, macro product details, soft high-key or low-key lighting, rich blacks, premium materials',
        'ugc' => 'UGC / influencer: handheld phone-camera feel, natural light, talking to camera, authentic and casual, vertical-first framing',
        'documentary' => 'Documentary: observational handheld, natural light, real locations, candid moments',
        'energetic' => 'Energetic social ad: fast punchy action, bold saturated colour, dynamic angles, strong motion in every shot',
        'animation' => '3D animated: stylised Pixar-like characters and sets, soft global illumination, appealing shapes',
    ];

    public function __construct(private readonly AiGatewayService $ai) {}

    /**
     * @param  array<int, int>  $durations  one entry per shot, already fitted to the model
     * @param  array<int, array{label: string, role: string}>  $references  in the order they'll be sent to the model
     * @return array<string, mixed> the storyboard
     */
    public function plan(Company $company, string $brief, array $durations, array $references, ?string $language, ?string $style, string $aspectRatio): array
    {
        $shotLines = collect($durations)->map(fn ($d, $i) => '  shot '.($i + 1).": {$d} seconds")->implode("\n");

        $refLines = $references
            ? collect($references)->map(fn ($r, $i) => '  reference image '.($i + 1).": {$r['role']} — \"{$r['label']}\"")->implode("\n")
            : '  (none — invent the characters, and describe them precisely enough that they can be re-drawn identically)';

        $languageLine = $language
            ? 'Spoken language: '.self::LANGUAGES[$language].'. Keep each line short enough to say naturally inside its shot (roughly 2–3 words per second).'
            : 'No dialogue and no voiceover — tell it visually, with music and sound effects only.';

        $styleLine = 'Visual style: '.(self::STYLES[$style] ?? self::STYLES['cinematic']);

        $user = <<<TXT
        BRIEF FROM THE CLIENT:
        {$brief}

        FORMAT: aspect ratio {$aspectRatio}. Exactly these shots, in this order, with these exact durations:
        {$shotLines}

        REFERENCE IMAGES SUPPLIED BY THE CLIENT:
        {$refLines}

        {$languageLine}
        {$styleLine}
        TXT;

        $response = $this->ai->forward($company, (string) config('pingly.video.director_model'), [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $user],
        ], ['max_tokens' => 8000]);

        $storyboard = $this->parse($response['choices'][0]['message']['content'] ?? '');

        return $this->normalise($storyboard, $durations, count($references));
    }

    /**
     * The full prompt one shot is rendered from. Everything that must not
     * change between shots is repeated here verbatim, every time.
     *
     * @param  array<string, mixed>  $storyboard
     * @param  array<string, mixed>  $shot  one entry of $storyboard['shots'], with 'description' possibly edited by the client
     */
    public function composePrompt(array $storyboard, array $shot, int $position, int $total, int $referenceCount, bool $hasAnchor): string
    {
        $parts = [];
        $parts[] = 'STYLE: '.($storyboard['style_bible'] ?? '');

        $byId = collect($storyboard['characters'] ?? [])->merge($storyboard['products'] ?? [])->keyBy('id');
        $present = collect($shot['characters'] ?? [])->merge($shot['products'] ?? [])
            ->map(fn ($id) => $byId->get($id))->filter();

        foreach ($present as $entity) {
            $ref = $entity['reference'] ?? null;
            $refText = $ref ? " Must match reference image {$ref} exactly — same face, features, skin tone, hair and proportions." : '';
            if (! $ref && $hasAnchor && ($entity['kind'] ?? 'character') === 'character') {
                $refText = ' Same person as in reference image '.($referenceCount + 1).' — identical face and wardrobe.';
            }
            $parts[] = strtoupper($entity['kind'] ?? 'character').' '.$entity['name'].': '.$entity['description'].$refText;
        }

        $parts[] = 'SHOT '.($position + 1)." OF {$total} ({$shot['duration']}s): ".$shot['description'];

        $speakers = collect($storyboard['characters'] ?? [])->keyBy('id');
        foreach ($shot['dialogue'] ?? [] as $line) {
            $who = $speakers->get($line['speaker'] ?? '')['name'] ?? 'The character';
            $parts[] = "{$who} says: \"{$line['line']}\"";
        }
        if (! empty($shot['voiceover'])) {
            $parts[] = "Voiceover: \"{$shot['voiceover']}\"";
        }
        if (! empty($shot['sound'])) {
            $parts[] = 'SOUND: '.$shot['sound'];
        }

        $parts[] = 'Keep every character\'s face, hairstyle, skin tone, body and wardrobe identical to their description and reference. '
            .'Photorealistic anatomy, natural motion, no morphing. No on-screen text, captions, subtitles, logos added by you, or watermarks.';

        return implode("\n", $parts);
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are an award-winning commercial director and storyboard artist working for brands in Egypt and the Gulf. You turn a client's brief into a precise shot list that an AI video model will render one shot at a time. The model sees ONLY one shot's text at a time and has no memory of other shots, so continuity lives entirely in what you write.

        Return ONLY one JSON object, no markdown fences, no commentary, in exactly this shape:
        {
          "title": "short film title",
          "logline": "one sentence: what the film is and why it works for the brand",
          "style_bible": "60-100 words, written once and applied to every shot: camera/lens character, lighting approach, colour palette and grade, texture, era, mood, location family. Concrete and visual, no adjectives without a picture behind them.",
          "characters": [
            {"id": "C1", "name": "first name", "reference": 1 or null,
             "description": "40-80 words of FIXED physical identity: age, gender, ethnicity/skin tone, face shape, eyes, eyebrows, nose, hair (colour, length, style), facial hair, build, height impression, plus EXACT wardrobe (garment, colour, fabric, fit) and accessories. This text is pasted into every shot, so never put actions or emotions here."}
          ],
          "products": [
            {"id": "P1", "name": "product name", "reference": 2 or null,
             "description": "exact shape, colour, materials, packaging, label placement — fixed across shots"}
          ],
          "shots": [
            {"duration": 8,
             "description": "60-120 words: shot size and angle, lens feel, camera movement, the setting in concrete detail, light direction and quality, what each named character physically does from start to end of the shot, and the emotional beat. Name characters as 'Name (C1)'.",
             "characters": ["C1"],
             "products": ["P1"],
             "dialogue": [{"speaker": "C1", "line": "..."}],
             "voiceover": null,
             "sound": "music and sound effects for this shot"}
          ]
        }

        Rules:
        - Use exactly the shot count and the exact durations you are given. One clear action per shot; a shot must read in its duration.
        - A real story arc even for an ad: hook in the first 2 seconds, development, payoff, and a final hero shot of the product or brand moment.
        - Vary shot sizes like an editor would (wide establishing, medium, close-up, insert/macro) and keep screen direction consistent between cuts.
        - Reference images: when the client supplied one for a person, product or logo, set "reference" to its number (1-based) and write the description to agree with it. A reference may only be assigned to one character or product.
        - Never ask the model to render written text, Arabic or English — AI video garbles letters. Signs, packaging text and logos must come from a reference image or stay out of frame. No end cards; the brand adds those in editing.
        - Locations, people, wardrobe and behaviour must feel authentic to Egypt / the Gulf when the brief is set there: real Cairo, Alexandria, Riyadh or Dubai textures, not generic Western stock. Wardrobe and conduct appropriate for a mainstream family audience in the region.
        - Dialogue only if a spoken language is given; keep lines short and natural, written in the language and script requested. Otherwise "dialogue": [] and "voiceover": null.
        - No real public figures, no celebrities, no copyrighted characters, no violence, nudity, alcohol or anything a mainstream advertiser couldn't air.
        TXT;
    }

    /** @return array<string, mixed> */
    private function parse(string $content): array
    {
        // Models sometimes wrap JSON in ```json fences or add a line before
        // it despite being told not to — take the outermost object.
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $json = ($start !== false && $end !== false) ? substr($content, $start, $end - $start + 1) : '';
        $data = json_decode($json, true);

        if (! is_array($data) || empty($data['shots']) || ! is_array($data['shots'])) {
            throw new RuntimeException('Could not plan this video — try rephrasing the brief.');
        }

        return $data;
    }

    /**
     * Never trust the LLM with the numbers: shot count and durations are
     * forced back to what was asked for (a shot list that's two shots long
     * or 9s instead of 8s would bill and render something nobody quoted),
     * and reference numbers are clamped to images that actually exist.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $durations
     * @return array<string, mixed>
     */
    private function normalise(array $data, array $durations, int $referenceCount): array
    {
        $clampRef = fn ($r) => (is_int($r) || ctype_digit((string) $r)) && (int) $r >= 1 && (int) $r <= $referenceCount ? (int) $r : null;

        $entities = fn (string $key, string $kind) => collect($data[$key] ?? [])
            ->filter(fn ($e) => is_array($e) && ! empty($e['id']))
            ->map(fn ($e) => [
                'id' => (string) $e['id'],
                'kind' => $kind,
                'name' => (string) ($e['name'] ?? $e['id']),
                'reference' => $clampRef($e['reference'] ?? null),
                'description' => (string) ($e['description'] ?? ''),
            ])->values()->all();

        $shots = array_values(array_filter($data['shots'], 'is_array'));
        $planned = [];
        foreach ($durations as $i => $duration) {
            // Fewer shots than asked: repeat the last one's world rather
            // than fail — the client can edit any shot before rendering.
            $s = $shots[$i] ?? end($shots);
            $planned[] = [
                'duration' => $duration,
                'description' => trim((string) ($s['description'] ?? '')),
                'characters' => array_values(array_map('strval', (array) ($s['characters'] ?? []))),
                'products' => array_values(array_map('strval', (array) ($s['products'] ?? []))),
                'dialogue' => collect($s['dialogue'] ?? [])
                    ->filter(fn ($l) => is_array($l) && filled($l['line'] ?? null))
                    ->map(fn ($l) => ['speaker' => (string) ($l['speaker'] ?? ''), 'line' => (string) $l['line']])
                    ->values()->all(),
                'voiceover' => filled($s['voiceover'] ?? null) ? (string) $s['voiceover'] : null,
                'sound' => (string) ($s['sound'] ?? ''),
            ];
        }

        return [
            'title' => (string) ($data['title'] ?? 'Untitled'),
            'logline' => (string) ($data['logline'] ?? ''),
            'style_bible' => (string) ($data['style_bible'] ?? ''),
            'characters' => $entities('characters', 'character'),
            'products' => $entities('products', 'product'),
            'shots' => $planned,
        ];
    }
}
