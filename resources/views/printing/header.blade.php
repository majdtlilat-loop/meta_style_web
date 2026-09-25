{{--
    The top of a printed document in the center's chosen appearance: its logo,
    its name, the branch lines it chose to show, and up to three lines of its
    own text. Shared by the 80mm receipt, the A4 invoice and the Manager's
    print preview, so the preview is the paper.

    Expects `$print` (PrintAppearance::view) and `$doc` — an ARRAY with
    center_name, branch_name, branch_address, branch_phone (already filtered
    by PrintAppearance::invoice). Never a model: nothing here can reach a
    column the renderer did not allow-list.
--}}
<header class="print-head" data-align="{{ $print['logo']['align'] ?? 'center' }}">
    @if ($print['logo'])
        <img class="print-logo" data-size="{{ $print['logo']['size'] }}" src="{{ $print['logo']['url'] }}" alt="">
    @endif
    @if ($print['show_center_name'])
        <div class="center">{{ $doc['center_name'] }}</div>
    @endif
    @if ($print['show_branch_name'] && ($doc['branch_name'] ?? null) !== null)
        <div class="branch">{{ $doc['branch_name'] }}</div>
    @endif
    @if (($doc['branch_address'] ?? null) !== null)
        <div class="address">{{ $doc['branch_address'] }}</div>
    @endif
    @if (($doc['branch_phone'] ?? null) !== null)
        <div class="phone" dir="ltr">{{ $doc['branch_phone'] }}</div>
    @endif
    @foreach ($print['header_lines'] as $line)
        <div class="line">{{ $line }}</div>
    @endforeach
</header>
