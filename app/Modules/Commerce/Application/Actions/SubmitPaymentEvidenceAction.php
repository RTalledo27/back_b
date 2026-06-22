<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\StoredEvidenceData;
use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceData;
use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Exceptions\EvidenceRejected;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Records a freshly-stored payment evidence and transitions the order +
 * payment to their "under review" states.
 *
 * The file lives on the private disk before this Action runs (see
 * SubmitPaymentEvidenceOrchestrator). The Action only touches PostgreSQL;
 * it never reads or writes the filesystem, and never sees the raw upload.
 *
 * The Action is also idempotent at the file-content level: if a
 * PaymentDocument with the same (payment_id, sha256) already exists, the
 * Action returns the existing result without creating a duplicate row or
 * a duplicate audit event. The Orchestrator inspects result.documentId
 * vs the just-stored documentId and deletes the duplicate file when they
 * differ.
 */
final class SubmitPaymentEvidenceAction
{
    public function execute(
        SubmitPaymentEvidenceData $data,
        StoredEvidenceData $stored,
    ): SubmitPaymentEvidenceResult {
        return DB::transaction(
            fn (): SubmitPaymentEvidenceResult => $this->executeWithinTransaction($data, $stored),
        );
    }

    public function executeWithinTransaction(
        SubmitPaymentEvidenceData $data,
        StoredEvidenceData $stored,
    ): SubmitPaymentEvidenceResult {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'SubmitPaymentEvidenceAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // Canonical lock order: Order -> Payment.
        /** @var Order $order */
        $order = Order::query()
            ->whereKey($data->orderId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->user_id !== $data->userId) {
            // Should already be caught by the Policy; defence in depth.
            throw EvidenceRejected::orderNotPending($order->status);
        }

        /** @var Payment $payment */
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->expires_at !== null && $order->expires_at->isPast()) {
            throw EvidenceRejected::orderExpired();
        }

        // Idempotency at the file-content level: an existing document for
        // this payment with the same SHA-256 short-circuits to the cached
        // result. No duplicate row, no duplicate audit, no dispatch.
        $existingDocument = PaymentDocument::query()
            ->where('payment_id', $payment->id)
            ->where('sha256', $stored->sha256)
            ->first();

        if ($existingDocument !== null) {
            return $this->buildResult($order, $payment, $existingDocument);
        }

        // Brand-new evidence path: validate state for the first submission.
        if ($order->status === OrderStatus::PaymentSubmitted) {
            throw EvidenceRejected::differentEvidenceForSubmittedOrder();
        }

        if ($order->status !== OrderStatus::Pending) {
            throw EvidenceRejected::orderNotPending($order->status);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            throw EvidenceRejected::paymentNotPending($payment->status);
        }

        if (NumberReservation::query()->where('order_id', $order->id)->count() === 0) {
            throw EvidenceRejected::noActiveReservations();
        }

        $document = PaymentDocument::query()->forceCreate([
            'id' => $stored->documentId,
            'payment_id' => $payment->id,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->detectedMimeType,
            'size_bytes' => $stored->sizeBytes,
            'sha256' => $stored->sha256,
            'uploaded_by' => $data->userId,
            'created_at' => now(),
        ]);

        $order->transitionTo(OrderStatus::PaymentSubmitted);
        $order->expires_at = null;
        $order->save();

        $payment->transitionTo(PaymentStatus::UnderReview);
        $payment->submitted_at = now();
        $payment->save();

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::PaymentSubmitted,
            'payload' => [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'document_id' => $document->id,
                'user_id' => $data->userId,
                'sha256' => $stored->sha256,
                'mime_type' => $stored->detectedMimeType,
                'size_bytes' => $stored->sizeBytes,
            ],
            'actor_user_id' => $data->userId,
            'occurred_at' => now(),
        ]);

        // Domain event is dispatched by the Orchestrator AFTER the
        // transaction commits, so a failing listener cannot trigger the
        // file/key compensation path. Auditoría crítica está persistida
        // como parte de esta transacción (game_events arriba).
        return $this->buildResult($order, $payment, $document);
    }

    private function buildResult(
        Order $order,
        Payment $payment,
        PaymentDocument $document,
    ): SubmitPaymentEvidenceResult {
        return new SubmitPaymentEvidenceResult(
            orderId: $order->id,
            paymentId: $payment->id,
            documentId: $document->id,
            orderStatus: $order->status->value,
            paymentStatus: $payment->status->value,
            submittedAt: $payment->submitted_at?->toIso8601String() ?? '',
            originalFilename: $document->original_filename,
            mimeType: $document->mime_type,
            sizeBytes: $document->size_bytes,
            sha256: $document->sha256,
        );
    }
}
