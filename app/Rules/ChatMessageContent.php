<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A chat message's `content` is either a plain string — the ordinary text
 * case — or OpenAI/OpenRouter's multimodal parts array, which is the only
 * way an image reaches a vision model:
 *
 *   [
 *     ['type' => 'text',      'text' => 'What is this?'],
 *     ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,…']],
 *   ]
 *
 * Shape-checked here rather than forwarded blind. This is a gateway, not a
 * proxy: whatever goes upstream is billed to a client's wallet, so a
 * malformed part should fail as a 422 naming the problem, not as an
 * upstream error in a vendor's own format that the client can't act on
 * (and that we deliberately never show them — see AiGatewayService).
 */
class ChatMessageContent implements ValidationRule
{
    /**
     * Per image, before base64 expansion. Chosen to sit under the PHP
     * upload/post limits a default server ships with, so an oversized
     * image fails here with a sentence a developer can act on rather than
     * as a bare 413 from the web server with no JSON body at all.
     */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * Larger than an image because a real document legitimately is, but
     * still well inside the server's 50MB body limit once base64 has added
     * its third — and inside PHP's 128MB memory limit, which decoding a
     * body this size into PHP structures eats several times over.
     */
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    private const MAX_PARTS = 20;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Plain text, or an assistant turn that carries only tool_calls.
        if ($value === null || is_string($value)) {
            return;
        }

        if (! is_array($value) || $value === []) {
            $fail('The :attribute must be a string, or a non-empty array of content parts.');

            return;
        }

        if (count($value) > self::MAX_PARTS) {
            $fail('The :attribute may not contain more than '.self::MAX_PARTS.' parts.');

            return;
        }

        foreach ($value as $index => $part) {
            $this->validatePart($attribute, $index, $part, $fail);
        }
    }

    private function validatePart(string $attribute, mixed $index, mixed $part, Closure $fail): void
    {
        $at = "{$attribute}.{$index}";

        if (! is_array($part)) {
            $fail("The {$at} content part must be an object.");

            return;
        }

        $type = $part['type'] ?? null;

        if (! in_array($type, ['text', 'image_url', 'file'], true)) {
            $fail("The {$at}.type must be one of \"text\", \"image_url\" or \"file\".");

            return;
        }

        if ($type === 'text') {
            if (! isset($part['text']) || ! is_string($part['text'])) {
                $fail("The {$at}.text is required and must be a string.");
            }

            return;
        }

        if ($type === 'file') {
            $this->validateFilePart($at, $part, $fail);

            return;
        }

        $url = $part['image_url']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            $fail("The {$at}.image_url.url is required and must be a string.");

            return;
        }

        $this->validateImageUrl($at, $url, $fail);
    }

    /**
     * A PDF, in OpenRouter's file shape. Only PDFs: every other document
     * format would silently fall through to a paid parsing engine upstream
     * instead of being read by the model directly, which is both worse
     * output and a cost that never reaches the client's bill.
     */
    private function validateFilePart(string $at, array $part, Closure $fail): void
    {
        $filename = $part['file']['filename'] ?? null;
        $data = $part['file']['file_data'] ?? null;

        if (! is_string($filename) || $filename === '') {
            $fail("The {$at}.file.filename is required and must be a string.");

            return;
        }

        if (! is_string($data) || $data === '') {
            $fail("The {$at}.file.file_data is required and must be a string.");

            return;
        }

        if (str_starts_with($data, 'https://')) {
            return;
        }

        if (! preg_match('#^data:application/pdf;base64,#i', $data)) {
            $fail("The {$at}.file.file_data must be an https:// URL or a base64 data: URI for a PDF.");

            return;
        }

        if ($this->decodedSize($data) > self::MAX_PDF_BYTES) {
            $fail("The {$at} PDF is larger than ".(self::MAX_PDF_BYTES / 1024 / 1024).'MB. Split it, or pass an https:// URL instead.');
        }
    }

    /**
     * Only https:// and data: image URIs. http:// is refused because the
     * upstream would fetch it in clear text, and anything else (file://,
     * ftp://, a bare path) is never a legitimate image source here.
     */
    private function validateImageUrl(string $at, string $url, Closure $fail): void
    {
        if (str_starts_with($url, 'https://')) {
            return;
        }

        if (! preg_match('#^data:image/(png|jpeg|jpg|gif|webp);base64,#i', $url)) {
            $fail("The {$at}.image_url.url must be an https:// URL or a base64 data: URI for a PNG, JPEG, GIF or WebP image.");

            return;
        }

        if ($this->decodedSize($url) > self::MAX_IMAGE_BYTES) {
            $fail("The {$at} image is larger than ".(self::MAX_IMAGE_BYTES / 1024 / 1024).'MB. Resize it, or pass an https:// URL instead.');
        }
    }

    /**
     * Decoded byte count of a data: URI's base64 payload, measured without
     * copying it — substr() on a 16MB string allocates another 16MB, so
     * checking "is this too big" was itself capable of exhausting memory on
     * exactly the oversized input the check exists to reject.
     *
     * Base64 carries 3 bytes per 4 characters; padding makes this
     * over-estimate by at most two bytes, which is the safe direction.
     */
    private function decodedSize(string $dataUri): int
    {
        $comma = strpos($dataUri, ',');

        if ($comma === false) {
            return 0;
        }

        return (int) ((strlen($dataUri) - $comma - 1) * 3 / 4);
    }
}
