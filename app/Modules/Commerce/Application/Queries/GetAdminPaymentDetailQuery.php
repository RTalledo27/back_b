<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\Payment;

final class GetAdminPaymentDetailQuery
{
    public function find(string $paymentId): ?Payment
    {
        return Payment::query()
            ->with([
                'order:id,user_id,game_id,status,subtotal_cents,total_cents,currency,expires_at,paid_at,cancelled_at,expired_at,created_at',
                'order.game:id,slug,name',
                'order.user:id,name,email',
                'order.items.gameNumber:id,game_id,number,status',
                'reviewer:id,name,email',
                'documents:id,payment_id,original_filename,mime_type,size_bytes,sha256,uploaded_by,created_at',
                'documents.uploader:id,name,email',
            ])
            ->whereKey($paymentId)
            ->first();
    }
}
