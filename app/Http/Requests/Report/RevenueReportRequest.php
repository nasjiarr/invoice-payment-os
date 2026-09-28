<?php

namespace App\Http\Requests\Report;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevenueReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'customer' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_status' => ['nullable', 'string', Rule::in(['draft', 'sent', 'partially_paid', 'paid', 'void', 'cancelled', 'overdue'])],
            'status' => ['nullable', 'string', Rule::in(['draft', 'sent', 'partially_paid', 'paid', 'void', 'cancelled', 'overdue'])],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ];
    }
}
