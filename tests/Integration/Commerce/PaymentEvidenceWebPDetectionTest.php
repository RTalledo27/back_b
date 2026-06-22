<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Domain\Exceptions\EvidenceValidationException;
use App\Modules\Commerce\Infrastructure\Storage\PaymentEvidenceStorage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * WebP detection is guarded by an explicit RIFF/WEBP signature fallback
 * because libmagic on some Windows builds returns the wrong MIME for
 * minimal WebP files. These tests lock the supported / unsupported
 * shapes so the whitelist can never be partial.
 */
final class PaymentEvidenceWebPDetectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private PaymentEvidenceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new PaymentEvidenceStorage;
    }

    /** Minimal valid WebP container: RIFF + 4-byte size + WEBP + payload */
    private function validWebpUpload(): UploadedFile
    {
        $payload = "VP8L\x18\x00\x00\x00\x2F\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
        $size = pack('V', 4 + strlen($payload));   // payload size from "WEBP" onwards
        $bytes = 'RIFF'.$size.'WEBP'.$payload;

        $tmp = tempnam(sys_get_temp_dir(), 'webp');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'photo.webp', 'image/webp', null, true);
    }

    /** RIFF container that is NOT a WebP (here: WAVE audio) */
    private function nonWebpRiffUpload(): UploadedFile
    {
        $bytes = "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00";
        $tmp = tempnam(sys_get_temp_dir(), 'wav');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'audio.wav', 'audio/wav', null, true);
    }

    /** File with .webp extension but plain text content */
    private function fakeWebpExtensionUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($tmp, "not actually a webp\n".str_repeat('x', 100));

        return new UploadedFile($tmp, 'photo.webp', 'image/webp', null, true);
    }

    /** Truncated header — less than 12 bytes */
    private function truncatedUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'trunc');
        file_put_contents($tmp, "RIFF\x04");

        return new UploadedFile($tmp, 'short.webp', 'image/webp', null, true);
    }

    public function test_valid_webp_is_detected_as_image_webp(): void
    {
        $analysis = $this->storage->analyse($this->validWebpUpload());

        $this->assertSame('image/webp', $analysis->mimeType);
        $this->assertSame('webp', $analysis->extension);
    }

    public function test_riff_with_wave_form_is_not_accepted_as_webp(): void
    {
        $this->expectException(EvidenceValidationException::class);
        $this->storage->analyse($this->nonWebpRiffUpload());
    }

    public function test_fake_webp_without_riff_signature_is_rejected(): void
    {
        $this->expectException(EvidenceValidationException::class);
        $this->storage->analyse($this->fakeWebpExtensionUpload());
    }

    public function test_truncated_file_is_rejected(): void
    {
        $this->expectException(EvidenceValidationException::class);
        $this->storage->analyse($this->truncatedUpload());
    }
}
