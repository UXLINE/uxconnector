# Marketplace packaging

`uxconnector.xml.dist` follows the current GLPI plugin catalog/Marketplace XML shape.
It is intentionally **not** a publishable descriptor yet: replace
`__PUBLIC_RELEASE_URL__` with the public URL of the exact release archive, then copy
it to `uxconnector.xml` before submitting the raw XML URL to the GLPI catalog.

Do not publish a descriptor containing a placeholder or a repository landing-page
URL in `download_url`; GLPI requires a directly downloadable public archive.
