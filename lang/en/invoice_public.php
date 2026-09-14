<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Invoices — the strings a CUSTOMER sees
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/18-SALES.md §§22, 46.
|
| The digital invoice, the 80mm printout and the A4 page. Staff screens keep
| their labels inline, like the rest of the center area.
|
| Two words, never `invoice.php`: a dotless `__('Invoice')` is parsed as a
| translation GROUP, and on a case-insensitive filesystem it would find a
| single-word file and return the whole array. The translation-collision
| architecture test enforces this.
|
*/

return [
    'invoice' => 'Invoice',
    'number' => 'Invoice no.',
    'date' => 'Date',
    'time' => 'Time',
    'customer' => 'Customer',
    'item' => 'Item',
    'quantity' => 'Qty',
    'unit_price' => 'Price',
    'amount' => 'Amount',
    'subtotal' => 'Subtotal',
    'discount' => 'Discount',
    'surcharge' => 'Surcharge',
    'tax' => 'Tax',
    'total' => 'Total',
    'voided' => 'VOID',
    'voided_on' => 'This invoice was voided on :date.',
    'thank_you' => 'Thank you for your visit.',
    'print' => 'Print',
];
