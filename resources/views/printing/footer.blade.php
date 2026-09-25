{{--
    The foot of a printed invoice: the center's own closing text in the
    document's language, or the built-in thank-you. Expects `$print`.
--}}
<footer class="print-foot">{{ $print['footer'] ?? __('invoice_public.thank_you') }}</footer>
