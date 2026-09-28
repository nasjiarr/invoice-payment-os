<?php

namespace App\Http\Requests\Invoice;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $businessId = (int) $this->input('business_id');
        if (! $businessId) {
            return false;
        }

        return $this->user()?->can('create', [Invoice::class, $businessId]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
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
            if ($this->business_id && $this->customer_id) {
                $customerBelongs = Customer::where('id', $this->customer_id)
                    ->where('business_id', $this->business_id)
                    ->exists();

                if (! $customerBelongs) {
                    $v->errors()->add('customer_id', 'The selected customer does not belong to this business.');
                }
            }

            if ($this->business_id && $this->invoice_number) {
                $numberExists = Invoice::where('business_id', $this->business_id)
                    ->where('invoice_number', $this->invoice_number)
                    ->exists();

                if ($numberExists) {
                    $v->errors()->add('invoice_number', 'The invoice number has already been taken for this business.');
                }
            }

            if ($this->business_id && is_array($this->items)) {
                foreach ($this->items as $index => $item) {
                    if (! empty($item['product_id'])) {
                        $productBelongs = Product::where('id', $item['product_id'])
                            ->where('business_id', $this->business_id)
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
