<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reports;

use App\View\Reports\ReportPresenter as SharedReportPresenter;

/**
 * The shared report presenter under its original name.
 *
 * The implementation lives in App\View\Reports\ReportPresenter; this name is
 * kept so every caller that still imports it (the Advanced Reports page)
 * gets exactly the same public API.
 *
 * @deprecated Use App\View\Reports\ReportPresenter.
 */
final class ReportPresenter extends SharedReportPresenter {}
