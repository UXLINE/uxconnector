<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * REX-GLPI (résolu lors des tests en production) :
 * - AbstractController::render() DOIT auto-préfixer "@uxconnector/" :
 *   les contrôleurs concrets ne passent que le chemin relatif du
 *   template (ex. "engine/authconfig/list.html.twig").
 * - Les templates restent des fragments HTML purs. Les points d'entrée
 *   legacy front/*.php sont responsables d'appeler Html::header() puis
 *   Html::footer() autour du rendu GET. Un simple include de
 *   inc/includes.php n'émet aucun layout GLPI.
 *
 * CORRECTIF GLPI 11 : les formulaires POST portent un jeton CSRF généré
 * par GLPI. Le Kernel GLPI 11 valide ce jeton avant de déléguer aux
 * points d'entrée legacy front/*.php ; les contrôleurs du plugin ne
 * doivent donc pas rappeler Session::checkCSRF(), car les jetons de
 * formulaire sont consommables et une seconde validation échoue.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Controller;

use Glpi\Application\View\TemplateRenderer;
use Html;
use Session;

/**
 * Base class for every UXConnector controller.
 *
 * RULE-CTRL-001/002/003 : a Controller never contains a business
 * rule, never runs SQL, and never calls a third-party API directly -
 * it only orchestrates an Application Service and hands its result
 * to a template.
 */
abstract class AbstractController
{
    private const TEMPLATE_NAMESPACE = '@uxconnector/';

    /**
     * Renders a plugin template fragment.
     *
     * `csrf_token` is always available in the template, even for
     * screens without a form - this keeps render() a single place to
     * reason about rather than requiring every concrete Controller to
     * remember to add it. A template that does not render a form
     * simply never uses the variable.
     *
     * @param string $template Path relative to templates/, WITHOUT the
     *                         "@uxconnector/" namespace prefix and
     *                         WITHOUT any {% extends %} directive in
     *                         the template itself (see class doc-block).
     * @param array<string, mixed> $context ViewModels/scalars only -
     *                         never a Domain Entity or Value Object
     *                         (RULE-048).
     */
    protected function render(string $template, array $context = []): void
    {
        TemplateRenderer::getInstance()->display(
            self::TEMPLATE_NAMESPACE . ltrim($template, '/'),
            ['csrf_token' => Session::getNewCSRFToken(), ...$context],
        );
    }

    /**
     * Redirects the browser using GLPI's own mechanism
     * (RULE-HTML-004 : never call header() directly from a Controller).
     */
    protected function redirect(string $url): void
    {
        Html::redirect($url);
    }

}
