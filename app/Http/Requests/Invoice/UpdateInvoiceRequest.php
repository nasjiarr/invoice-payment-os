<?php

namespace App\Http\Requests\Invoice;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice && ($this->user()?->can('update', $invoice) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:customers,id'],
            'issue_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01', 'max:999999.99'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var Invoice $invoice */
            $invoice = $this->route('invoice');

            if ($this->has('customer_id') && $invoice) {
                $customerBelongs = Customer::where('id', $this->customer_id)
                    ->where('business_id', $invoice->business_id)
                    ->exists();

                if (! $customerBelongs) {
                    $v->errors()->add('customer_id', 'The selected customer does not belong to this business.');
                }
            }

            $issueDate = $this->has('issue_date')
                ? Carbon::parse($this->issue_date)
                : ($invoice ? Carbon::parse($invoice->issue_date) : null);

            $dueDate = $this->has('due_date')
                ? Carbon::parse($this->due_date)
                : ($invoice ? Carbon::parse($invoice->due_date) : null);

            if ($issueDate && $dueDate && $dueDate->lt($issueDate)) {
                $v->errors()->add('due_date', 'The due date must be a date after or equal to the issue date.');
            }

            if ($invoice && is_array($this->items)) {
                foreach ($this->items as $index => $item) {
                    if (! empty($item['product_id'])) {
                        $productBelongs = Product::where('id', $item['product_id'])
                            ->where('business_id', $invoice->business_id)
                            ->exists();

                        if (! $productBelongs) {
                            $v->errors()->add("items.{$index}.product_id", 'The selected product does not belong to this business.');
                        }
                    }
                }
            }
        });
    }
}
