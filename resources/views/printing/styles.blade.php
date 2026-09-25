{{--
    Paper styles for the center's print appearance, shared by the receipt, the
    A4 page and the Manager preview. Static CSS; the only variables are enum
    values carried in data attributes. Monochrome on purpose — thermal printers
    lose anything subtle.

    `.invoice > .identity` and `.invoice > .thanks` are the shared invoice
    body's own header and closing line; on paper they are replaced by
    printing/header and printing/footer, which honour the center's choices.
--}}
<style>
    .invoice > .identity, .invoice > .thanks { display: none; }
    .print-head { display: grid; gap: .6mm; margin-block-end: 2mm; text-align: center; justify-items: center; }
    .print-head[data-align="start"] { text-align: start; justify-items: start; }
    .print-head[data-align="end"] { text-align: end; justify-items: end; }
    .print-logo { display: block; max-inline-size: 70%; object-fit: contain; margin-block-end: 1mm; }
    .print-logo[data-size="small"] { max-block-size: 10mm; }
    .print-logo[data-size="medium"] { max-block-size: 16mm; }
    .print-logo[data-size="large"] { max-block-size: 24mm; }
    .print-head .center { font-weight: 700; }
    .print-head .branch { font-weight: 600; }
    .print-head .line { font-size: .92em; }
    .print-foot { margin-block-start: 3mm; text-align: center; white-space: pre-line; }
</style>
