<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #2d3748;
            line-height: 1.5;
            margin: 0;
            padding: 30px;
            font-size: 13px;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #edf2f7;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .header td {
            vertical-align: top;
        }
        .title {
            font-size: 26px;
            font-weight: bold;
            color: #1a202c;
            margin: 0;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 5px;
        }
        .status-draft { background-color: #edf2f7; color: #4a5568; }
        .status-sent { background-color: #ebf8ff; color: #2b6cb0; }
        .status-partially_paid { background-color: #feebc8; color: #c05621; }
        .status-paid { background-color: #c6f6d5; color: #22543d; }
        .status-void { background-color: #fed7d7; color: #9b2c2c; }
        .status-cancelled { background-color: #e2e8f0; color: #718096; }

        .details-table {
            width: 100%;
            margin-bottom: 30px;
        }
        .details-table td {
            vertical-align: top;
            width: 50%;
        }
        .section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #718096;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .entity-name {
            font-size: 15px;
            font-weight: bold;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .items-table th {
            background-color: #f7fafc;
            color: #4a5568;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #edf2f7;
            font-size: 12px;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }

        .summary-table {
            width: 320px;
            float: right;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .summary-table td {
            padding: 6px 10px;
            font-size: 12px;
        }
        .summary-total {
            font-size: 15px;
            font-weight: bold;
            border-top: 2px solid #e2e8f0;
            color: #1a202c;
        }

        .notes-section {
            clear: both;
            padding-top: 20px;
            border-top: 1px solid #edf2f7;
            font-size: 12px;
            color: #718096;
        }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="title">INVOICE</div>
                <div style="color: #718096; font-size: 13px; margin-top: 2px;">#{{ $invoice->invoice_number }}</div>
                <span class="status-badge status-{{ $invoice->status->value }}">
                    {{ strtoupper(str_replace('_', ' ', $invoice->status->value)) }}
                </span>
            </td>
            <td class="text-right">
                <div class="entity-name">{{ $business->name }}</div>
                @if($business->email) <div>{{ $business->email }}</div> @endif
                @if($business->phone) <div>{{ $business->phone }}</div> @endif
                @if($business->address) <div>{!! nl2br(e($business->address)) !!}</div> @endif
                @if($business->tax_id) <div style="font-size: 11px; color: #718096;">Tax ID: {{ $business->tax_id }}</div> @endif
            </td>
        </tr>
    </table>

    <table class="details-table">
        <tr>
            <td>
                <div class="section-title">Billed To</div>
                <div class="entity-name">{{ $customer->name }}</div>
                @if($customer->email) <div>{{ $customer->email }}</div> @endif
                @if($customer->phone) <div>{{ $customer->phone }}</div> @endif
                @if($customer->address) <div>{!! nl2br(e($customer->address)) !!}</div> @endif
            </td>
            <td class="text-right">
                <div class="section-title">Invoice Details</div>
                <div><strong>Issue Date:</strong> {{ $invoice->issue_date->format('d M Y') }}</div>
                <div><strong>Due Date:</strong> {{ $invoice->due_date->format('d M Y') }}</div>
                <div><strong>Currency:</strong> {{ $invoice->currency }}</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 40%;">Description</th>
                <th class="text-center" style="width: 10%;">Qty</th>
                <th class="text-right" style="width: 15%;">Unit Price</th>
                <th class="text-right" style="width: 10%;">Discount</th>
                <th class="text-right" style="width: 10%;">Tax</th>
                <th class="text-right" style="width: 15%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="text-center">{{ number_format($item->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">{{ number_format($item->discount, 2) }}</td>
                    <td class="text-right">{{ number_format($item->tax, 2) }}</td>
                    <td class="text-right"><strong>{{ number_format($item->total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td class="text-right">Subtotal:</td>
            <td class="text-right"><strong>{{ $invoice->currency }} {{ number_format($invoice->subtotal, 2) }}</strong></td>
        </tr>
        @if($invoice->discount > 0)
            <tr>
                <td class="text-right" style="color: #e53e3e;">Discount:</td>
                <td class="text-right" style="color: #e53e3e;">-{{ $invoice->currency }} {{ number_format($invoice->discount, 2) }}</td>
            </tr>
        @endif
        @if($invoice->tax > 0)
            <tr>
                <td class="text-right">Tax:</td>
                <td class="text-right">+{{ $invoice->currency }} {{ number_format($invoice->tax, 2) }}</td>
            </tr>
        @endif
        <tr class="summary-total">
            <td class="text-right">Total:</td>
            <td class="text-right">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</td>
        </tr>
    </table>

    @if($invoice->notes)
        <div class="notes-section">
            <div class="section-title">Notes / Terms</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif
</body>
</html>
