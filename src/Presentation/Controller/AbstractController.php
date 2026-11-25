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
 * Rendering prefixes relative template paths with @uxconnector/.
 * Templates are HTML fragments; front entry points wrap GET rendering in
 * Html::header()/Html::footer(). GLPI 11 validates the form CSRF token in
 * the kernel before dispatch; controllers must not consume it a second time.
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
