<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConversation;
use App\Services\Ai\AiGatewayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The other "AI + WhatsApp" feature — deliberately a separate mode from
 * WhatsappAutoReplyService, not a variant of it (product decision: the two
 * solve different problems). Auto-reply is stateless single-turn Q&A with
 * no knowledge of the business; this is a multi-turn agent that knows the
 * company's product catalog and active offers, and can place/confirm real
 * orders through tool calls — the "AI Commerce Assistant" a brand turns on
 * so its own WhatsApp number can actually sell, not just answer questions.
 *
 * Conversation memory: one WhatsappConversation row per (company, phone),
 * capped at MAX_HISTORY_MESSAGES so a long-running chat doesn't grow the
 * prompt (and the bill) without bound.
 *
 * Tool-calling is bounded to exactly one round trip: the model may call
 * create_order/confirm_order/cancel_order once (possibly several calls in
 * that one round), we execute them for real against the database, feed the
 * results back, and get one final natural-language reply. No open-ended
 * agent loop — this is a fixed, predictable shape, not an autonomous agent
 * that keeps calling tools until it decides to stop.
 *
 * Called from WhatsappWebhookController::receive() — like
 * WhatsappAutoReplyService, never lets an exception escape (a broken agent
 * must never turn Meta's webhook delivery into a failure).
 */
class AiCommerceAgentService
{
    private const MAX_HISTORY_MESSAGES = 20;

    public function __construct(
        private readonly AiGatewayService $ai,
        private readonly WhatsAppGatewayService $whatsapp,
    ) {}

    public function handleInboundMessage(WhatsappAccount $account, string $from, string $text): void
    {
        if (! $account->ai_commerce_enabled || blank($account->ai_autoreply_model)) {
            return;
        }

        $company = $account->company;

        try {
            $conversation = WhatsappConversation::firstOrCreate(
                ['company_id' => $company->id, 'customer_phone' => $from],
                ['messages' => []],
            );

            $messages = [
                ['role' => 'system', 'content' => $this->systemPrompt($account, $company)],
                ...$conversation->messages,
                ['role' => 'user', 'content' => $text],
            ];

            $tools = $this->tools();
            $result = $this->ai->forward($company, $account->ai_autoreply_model, $messages, ['tools' => $tools, 'tool_choice' => 'auto']);
            $choice = $result['choices'][0]['message'] ?? null;

            if (! $choice) {
                Log::warning('AI Commerce Assistant: model returned no choice', ['company_id' => $company->id]);

                return;
            }

            $toolCalls = $choice['tool_calls'] ?? [];

            if ($toolCalls) {
                $messages[] = $choice;

                foreach ($toolCalls as $call) {
                    $name = $call['function']['name'] ?? '';
                    $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                    $toolResult = $this->executeTool($company, $from, $name, $args);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'] ?? '',
                        'content' => json_encode($toolResult),
                    ];
                }

                // One follow-up call so the model can turn the tool results
                // into an actual reply to send the customer — never send raw
                // tool output or a second round of tool calls.
                $result = $this->ai->forward($company, $account->ai_autoreply_model, $messages, ['tools' => $tools]);
                $choice = $result['choices'][0]['message'] ?? null;
            }

            $reply = $choice['content'] ?? null;

            if (blank($reply)) {
                Log::warning('AI Commerce Assistant: no reply content after tool round', ['company_id' => $company->id]);

                return;
            }

            $this->whatsapp->send($company, $from, 'service', 'EG', [
                'type' => 'text',
                'text' => ['body' => $reply],
            ]);

            // Only the plain user/assistant turns go into stored history —
            // not the tool-call/tool-result plumbing, which would bloat
            // every future prompt with raw JSON the model doesn't need to
            // see again once it's already acted on it.
            $history = [...$conversation->messages, ['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => $reply]];
            $conversation->update(['messages' => array_slice($history, -self::MAX_HISTORY_MESSAGES)]);
        } catch (Throwable $e) {
            Log::warning('AI Commerce Assistant failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
        }
    }

    private function systemPrompt(WhatsappAccount $account, Company $company): string
    {
        $persona = filled($account->ai_autoreply_system_prompt)
            ? $account->ai_autoreply_system_prompt."\n\n"
            : '';

        return $persona
            ."You are the AI shopping assistant for {$company->name}, chatting with a customer on WhatsApp. "
            .'Use the catalog below to answer questions and take orders. Only order products listed here, at the '
            ."prices given (they already include any active discount). Quote the total clearly before ordering.\n\n"
            .$this->catalogContext($company)
            ."\n\nWhen the customer has clearly decided what they want, call create_order — then ask them to confirm "
            .'the order and total before it is placed. Only call confirm_order after the customer clearly says yes '
            .'to that specific order. If they change their mind or say no, call cancel_order instead.';
    }

    private function catalogContext(Company $company): string
    {
        $products = $company->products()->where('active', true)->get();

        if ($products->isEmpty()) {
            return 'The catalog is currently empty — let the customer know nothing is available to order yet.';
        }

        $lines = $products->map(function (Product $product) {
            $price = $product->effectivePrice();
            $line = "- #{$product->id} {$product->name} — {$price} {$product->currency}";
            if ((float) $product->price !== $price) {
                $line .= " (discounted from {$product->price})";
            }
            if (filled($product->description)) {
                $line .= " — {$product->description}";
            }

            return $line;
        });

        return "Catalog (product id, name, price, description):\n".$lines->implode("\n");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_order',
                    'description' => 'Start a new order once the customer has clearly decided which products and quantities they want. This does not finalize the order — confirm_order does, after the customer agrees.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'product_id' => ['type' => 'integer', 'description' => 'The catalog #id of the product'],
                                        'quantity' => ['type' => 'integer'],
                                    ],
                                    'required' => ['product_id', 'quantity'],
                                ],
                            ],
                            'customer_name' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                        'required' => ['items'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'confirm_order',
                    'description' => 'Confirm a pending order — call only after the customer explicitly agrees to that order and its total.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['order_id' => ['type' => 'integer']],
                        'required' => ['order_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cancel_order',
                    'description' => 'Cancel a pending order, e.g. if the customer changes their mind or says no.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['order_id' => ['type' => 'integer']],
                        'required' => ['order_id'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeTool(Company $company, string $customerPhone, string $name, array $args): array
    {
        return match ($name) {
            'create_order' => $this->createOrder($company, $customerPhone, $args),
            'confirm_order' => $this->setOrderStatus($company, $customerPhone, (int) ($args['order_id'] ?? 0), 'confirmed'),
            'cancel_order' => $this->setOrderStatus($company, $customerPhone, (int) ($args['order_id'] ?? 0), 'cancelled'),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * @param  array{items?: array<int, array{product_id?: int, quantity?: int}>, customer_name?: string, notes?: string}  $args
     */
    private function createOrder(Company $company, string $customerPhone, array $args): array
    {
        $requested = collect($args['items'] ?? []);

        if ($requested->isEmpty()) {
            return ['error' => 'No items given.'];
        }

        $products = Product::where('company_id', $company->id)
            ->where('active', true)
            ->whereIn('id', $requested->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach ($requested as $item) {
            $product = $products->get($item['product_id'] ?? null);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if (! $product) {
                return ['error' => "Product #{$item['product_id']} isn't in the catalog."];
            }

            $unitPrice = $product->effectivePrice();
            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => round($unitPrice * $quantity, 4),
            ];
        }

        $currency = $products->first()->currency;
        $total = round(collect($lines)->sum('line_total'), 4);

        $order = DB::transaction(function () use ($company, $customerPhone, $args, $lines, $total, $currency) {
            $order = Order::create([
                'company_id' => $company->id,
                'customer_phone' => $customerPhone,
                'customer_name' => $args['customer_name'] ?? null,
                'status' => 'pending_confirmation',
                'total' => $total,
                'currency' => $currency,
                'notes' => $args['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            return $order;
        });

        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'items' => $lines,
            'total' => $total,
            'currency' => $currency,
        ];
    }

    private function setOrderStatus(Company $company, string $customerPhone, int $orderId, string $status): array
    {
        // Scoped to this exact company + customer phone — a customer (or a
        // prompt-injected instruction) can't confirm/cancel someone else's
        // order by guessing an id.
        $order = Order::where('company_id', $company->id)
            ->where('customer_phone', $customerPhone)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            return ['error' => "Order #{$orderId} not found."];
        }

        if ($order->status !== 'pending_confirmation') {
            return ['error' => "Order #{$orderId} is already {$order->status}."];
        }

        $order->update(['status' => $status, 'confirmed_at' => $status === 'confirmed' ? now() : null]);

        return ['order_id' => $order->id, 'status' => $order->status];
    }
}
