<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Requests\Admin;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ScheduleGameRequest extends FormRequest
{
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
            'scheduled_start_at' => ['required', 'date', 'after:now'],
        ];
    }

    public function scheduledStartAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string('scheduled_start_at')->toString());
    }
}
