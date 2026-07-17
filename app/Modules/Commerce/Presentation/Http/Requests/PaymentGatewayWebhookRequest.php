<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests;

use App\Modules\Commerce\Application\Gateway\GatewayWebhookRecordRequest;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookSignatureException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PaymentGatewayWebhookRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $contentType = strtolower((string) $this->header('Content-Type', ''));

        if (! str_starts_with($contentType, 'application/json')) {
            $this->reject(415, 'Invalid webhook request.');
        }

        if (strlen($this->getContent()) > (int) config('payment_gateways.webhook_max_body_bytes', 65536)) {
            $this->reject(413, 'Invalid webhook request.');
        }

        $signature = trim((string) $this->header('X-Gateway-Signature', ''));
        if ($signature === '') {
            $this->reject(401, 'Invalid webhook signature.');
        }

        $eventId = trim((string) $this->header('X-Gateway-Event-Id', ''));
        $timestamp = trim((string) $this->header('X-Gateway-Timestamp', ''));

        if ($eventId === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            $this->reject(400, 'Invalid webhook request.');
        }

        $this->merge([
            '_gateway_event_id' => $eventId,
            '_gateway_timestamp' => $timestamp,
            '_gateway_signature' => $signature,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            '_gateway_event_id' => ['required', 'string', 'max:160'],
            '_gateway_timestamp' => ['required', 'integer'],
            '_gateway_signature' => ['required', 'string', 'max:300'],
        ];
    }

    public function toGatewayRequest(string $provider): GatewayWebhookRecordRequest
    {
        $signature = (string) $this->validated('_gateway_signature');
        $timestamp = (int) $this->validated('_gateway_timestamp');

        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $signature, $matches) !== 1
            || (int) $matches[1] !== $timestamp) {
            throw new GatewayWebhookSignatureException;
        }

        return new GatewayWebhookRecordRequest(
            provider: $provider,
            rawPayload: $this->getContent(),
            signature: $signature,
            headers: [
                'X-Gateway-Event-Id' => (string) $this->validated('_gateway_event_id'),
                'X-Gateway-Timestamp' => (string) $timestamp,
            ],
            now: CarbonImmutable::now('UTC'),
        );
    }

    private function reject(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
