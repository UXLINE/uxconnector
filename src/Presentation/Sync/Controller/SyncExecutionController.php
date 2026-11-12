<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncFailuresService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ResumeSyncExecutionService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\RetryFailedItemsService;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;
use Session;

final class SyncExecutionController extends AbstractController
{
    public function __construct(
        private readonly ListSyncFailuresService $listFailures,
        private readonly ResumeSyncExecutionService $resumeExecution,
        private readonly RetryFailedItemsService $retryFailedItems
    ) {
    }

    public function showFailures(array $request): void
    {
        $id = new SyncExecutionId((int) ($request['id'] ?? 0));
        $this->render('sync/syncexecution/failures.html.twig', [
            'executionId' => $id->value(),
            'failures' => $this->listFailures->execute($id),
            'can_update' => Session::haveRight('plugin_uxconnector_syncjob', UPDATE)
                && $this->retryFailedItems->canRetry($id),
        ]);
    }

    public function resume(array $request): void
    {
        try {
            $execution = $this->resumeExecution->execute(new SyncExecutionId((int) ($request['id'] ?? 0)));
            Session::addMessageAfterRedirect(sprintf(
                __('Execution #%d queued for resume.', 'uxconnector'),
                $execution->id()?->value() ?? 0
            ));
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        }
        $this->redirect('/plugins/uxconnector/front/syncjob.php');
    }

    public function retryFailed(array $request): void
    {
        try {
            $id = new SyncExecutionId((int) ($request['id'] ?? 0));
            $queued = $this->retryFailedItems->execute($id);
            Session::addMessageAfterRedirect(sprintf(
                __('%d failed item(s) queued for background retry.', 'uxconnector'),
                $queued,
            ));
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        }
        $this->redirect('/plugins/uxconnector/front/syncexecution.failures.php?id=' . (int) ($request['id'] ?? 0));
    }

    /**
     * Exports the failure details for the selected execution as a spreadsheet-safe CSV.
     *
     * @param array<string, mixed> $request Query parameters with the execution ID.
     * @return void
     */
    public function exportCsv(array $request): void
    {
        $id = new SyncExecutionId((int) ($request['id'] ?? 0));
        $failures = $this->listFailures->execute($id);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="uxconnector-execution-' . $id->value() . '-failures.csv"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new \RuntimeException('Unable to open CSV output.');
        }
        try {
            if (fwrite($out, "\xEF\xBB\xBF") !== 3) {
                throw new \RuntimeException('Unable to write CSV output.');
            }
            if (
                fputcsv($out, [
                    'Execution ID','Source type','Source ID','Source label','Target type','Target ID','Action',
                'Status','Error category','Error code','Error message','Technical context','Attempt','Page','Cursor',
                'Timestamp','Retry status'], ';', '"', '') === false
            ) {
                throw new \RuntimeException('Unable to write CSV header.');
            }
            foreach ($failures as $f) {
                $cells = [
                $id->value(), $f->sourceType, $f->sourceId, $f->sourceLabel, $f->targetType, $f->targetId,
                $f->action, $f->status, $f->category->value, $f->errorCode, $f->errorMessage, $f->technicalContext,
                $f->attempt, $f->page, $f->cursor, $f->createdAt, $f->retryStatus,
                ];
                if (fputcsv($out, array_map(self::escapeCsvCell(...), $cells), ';', '"', '') === false) {
                    throw new \RuntimeException('Unable to write CSV row.');
                }
            }
        } finally {
            fclose($out);
        }
        return;
    }

    /**
     * Prefixes cells that a spreadsheet could interpret as executable formulas.
     *
     * @param string|int|null $cell Value from a synchronization failure.
     * @return string|int|null Value to pass to the CSV writer.
     */
    private static function escapeCsvCell(string|int|null $cell): string|int|null
    {
        if (!is_string($cell)) {
            return $cell;
        }
        // Spreadsheet importers may normalize full-width operators or ignore leading separators.
        $formula = preg_match('/^(?:[\t\r\n]|[\p{Z}\p{C}]*[=+@\-＝＋＠－])/u', $cell);
        if ($formula !== 0) {
            return "'" . $cell;
        }
        return $cell;
    }
}
