<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Domain\Exceptions\EvidenceValidationException;
use App\Modules\Commerce\Infrastructure\Storage\PaymentEvidenceStorage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The MIME → extension map is small but security-critical: a bad mapping
 * could write a `.html` file inside the private disk under a PDF
 * pretense. Lock the whitelist explicitly.
 */
final class PaymentEvidenceStorageMimeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private PaymentEvidenceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new PaymentEvidenceStorage;
    }

    public function test_jpeg_maps_to_jpg(): void
    {
        $this->assertSame('jpg', $this->storage->mimeToExtension('image/jpeg'));
    }

    public function test_png_maps_to_png(): void
    {
        $this->assertSame('png', $this->storage->mimeToExtension('image/png'));
    }

    public function test_webp_maps_to_webp(): void
    {
        $this->assertSame('webp', $this->storage->mimeToExtension('image/webp'));
    }

    public function test_pdf_maps_to_pdf(): void
    {
        $this->assertSame('pdf', $this->storage->mimeToExtension('application/pdf'));
    }

    public function test_unsupported_mime_throws(): void
    {
        $this->expectException(EvidenceValidationException::class);
        $this->storage->mimeToExtension('text/html');
    }

    public function test_svg_is_not_allowed(): void
    {
        // SVG would be a XSS hazard if ever served — explicit assertion.
        $this->expectException(EvidenceValidationException::class);
        $this->storage->mimeToExtension('image/svg+xml');
    }
}
