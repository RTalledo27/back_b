<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

/**
 * Phase 2 supports only Manual. A real gateway (Mercado Pago, Culqi, etc.)
 * will be introduced via its own enum case + Adapter when integrated.
 */
enum PaymentMethod: string
{
    case Manual = 'manual';
}
