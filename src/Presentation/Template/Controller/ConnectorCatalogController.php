<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Template\Controller;

use GlpiPlugin\Uxconnector\Application\Template\Service\GetConnectorTemplateService;
use GlpiPlugin\Uxconnector\Application\Template\Service\ListConnectorTemplatesService;
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;

/**
 * Read-only discovery controller for connector definitions.
 *
 * A catalog never creates a runtime interaction directly. The administrator
 * first creates/opens a Connection, then manages that connection's interaction
 * instances from the Connection screen.
 */
final class ConnectorCatalogController extends AbstractController
{
   public function __construct(
        private readonly ListConnectorTemplatesService $listTemplates,
        private readonly GetConnectorTemplateService $getTemplate
    ) {
   }

   public function index(): void {
       $this->render('template/catalog/list.html.twig', [
           'connectors' => $this->listTemplates->execute(),
       ]);
   }

   public function show(string $connectorId): void {
      try {
          $this->render('template/catalog/detail.html.twig', [
              'connector' => $this->getTemplate->execute($connectorId),
          ]);
      } catch (\Throwable $exception) {
          \Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
          $this->redirect('/plugins/uxconnector/front/catalog.php');
      }
   }
}
