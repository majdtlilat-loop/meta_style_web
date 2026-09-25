<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Contracts;

use App\Modules\AdvancedReports\Data\AnalysisAnswer;
use App\Modules\AdvancedReports\Data\ReportAnalysisContext;

interface ReportAnalyst
{
    /**
     * Whether an analysis can be requested at all on this deployment (a
     * configured provider). A surface uses it to show an unavailable state
     * instead of a button that can only fail; it never says why.
     */
    public function available(): bool;

    /**
     * Answers from the structured context only. `$context->mode` is either a
     * free question or the fixed structured insights request.
     */
    public function analyze(ReportAnalysisContext $context, string $question, int $userId): AnalysisAnswer;
}
