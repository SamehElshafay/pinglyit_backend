<?php

namespace Tests\Feature\Ai;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PDFs ride in on the same multimodal content array as images, with one
 * difference that matters more than the feature itself: unless the request
 * pins the parsing engine, OpenRouter bills an OCR pass per page to
 * Pingly's own account, invisibly to the usage block the client is billed
 * from. These pin that behaviour down.
 */
class PdfRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = 'data:application/pdf;base64,JVBERi0xLjQKJeLjz9MK';

    /** @return array{0: Company, 1: string} */
    private function company(): array
    {
        PlatformSetting::set('openrouter_api_key', 'test-key');

        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 50]);
        [, $plaintext] = ApiKey::generate($company, 'Production');

        return [$company, $plaintext];
    }

    private function fakeUpstream(): void
    {
        Cache::flush();

        Http::fake([
            '*/models' => Http::response(['data' => [[
                'id' => 'openai/gpt-4o',
                'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
            ]]], 200),
            '*/chat/completions' => Http::response([
                'id' => 'gen-1',
                'model' => 'openai/gpt-4o',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Total is $420.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 200, 'total_tokens' => 4200],
            ], 200),
        ]);
    }

    private function send(string $token, array $content)
    {
        return $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);
    }

    public function test_a_pdf_is_accepted_and_forwarded_intact(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->send($token, [
            ['type' => 'text', 'text' => 'What is the total on this invoice?'],
            ['type' => 'file', 'file' => ['filename' => 'invoice.pdf', 'file_data' => self::PDF]],
        ])->assertOk()->assertJsonPath('choices.0.message.content', 'Total is $420.');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }
            $file = $request['messages'][0]['content'][1]['file'];

            return $file['filename'] === 'invoice.pdf' && $file['file_data'] === self::PDF;
        });
    }

    /**
     * The one that protects the margin: without this plugin OpenRouter
     * falls back to a per-page OCR charge that never reaches the client.
     */
    public function test_the_native_engine_is_pinned_whenever_a_pdf_is_present(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->send($token, [
            ['type' => 'file', 'file' => ['filename' => 'report.pdf', 'file_data' => self::PDF]],
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }

            return $request['plugins'] === [['id' => 'file-parser', 'pdf' => ['engine' => 'native']]];
        });
    }

    public function test_no_plugin_is_sent_when_there_is_no_pdf(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->send($token, [['type' => 'text', 'text' => 'Just text']])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }

            return ! isset($request['plugins']);
        });
    }

    public function test_an_https_pdf_url_is_accepted(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->send($token, [
            ['type' => 'file', 'file' => ['filename' => 'paper.pdf', 'file_data' => 'https://example.com/paper.pdf']],
        ])->assertOk();
    }

    public function test_a_non_pdf_data_uri_is_rejected(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->send($token, [
            ['type' => 'file', 'file' => ['filename' => 'sheet.xlsx', 'file_data' => 'data:application/vnd.ms-excel;base64,QQ==']],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_file_part_without_a_filename_is_rejected(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        $this->send($token, [
            ['type' => 'file', 'file' => ['file_data' => self::PDF]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_oversized_pdf_is_rejected(): void
    {
        [, $token] = $this->company();
        $this->fakeUpstream();

        // Roughly 12MB once decoded, over the 10MB ceiling.
        $huge = 'data:application/pdf;base64,'.str_repeat('A', 16 * 1024 * 1024);

        $this->send($token, [
            ['type' => 'file', 'file' => ['filename' => 'huge.pdf', 'file_data' => $huge]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }
}
