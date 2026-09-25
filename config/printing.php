<?php

/*
|--------------------------------------------------------------------------
| Center print appearance
|--------------------------------------------------------------------------
|
| What a center may change about its own operational paper: the 80mm receipt,
| the A4 invoice and the queue ticket. A closed catalog, like config/menu.php —
| choices from these lists, switches, and plain-text lines with a length cap.
|
| Applied when a document is RENDERED, never written into it. An invoice is an
| immutable record (docs/18-SALES.md); what a receipt looks like today is a
| layout decision about that record, so changing the footer tomorrow reprints
| yesterday's invoice with tomorrow's footer and the same numbers.
|
| Meta Style's own SaaS invoices to centers are a different thing entirely
| (SaasBilling's InvoiceTemplate) and never read this.
|
*/

return [

    'appearance' => [
        'choices' => [
            'logo_size' => ['medium', 'small', 'large'],
            'logo_align' => ['center', 'start', 'end'],

            // `staff` prints in the language of whoever presses Print;
            // `center` always prints in the center's primary content language.
            'document_language' => ['staff', 'center'],

            'receipt_text_size' => ['normal', 'large'],
            'a4_density' => ['comfortable', 'compact'],
        ],
        'flags' => [
            'show_logo' => true,
            'show_center_name' => true,
            'show_branch_name' => true,
            'show_branch_address' => true,
            'show_branch_phone' => true,
            'show_customer_name' => true,
            'ticket_show_service' => true,
            'ticket_show_date' => true,
        ],
        'texts' => [
            // Printed under the center name: a registration number, a website.
            'header_text' => 240,
            // Replaces the built-in "Thank you" at the foot of an invoice.
            'footer_text' => 300,
            // Replaces the built-in closing line of a queue ticket.
            'ticket_footer' => 160,
        ],
    ],

];
