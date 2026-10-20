<?php
/**
 * The product-module template on the wire: every generic Builder and prompt check (see Builder_Adapter_Test_Case),
 * run against the template itself under its placeholder names, on every adapter copy, with no sibling plugin.
 *
 * It has no checks of its own: the template has no product-specific tools. A product copied from it adds a profile
 * and, if it has tools of its own, a subclass like `RealTestimonialsPromptsAdapterTest`.
 *
 * Run it with SHAPED_KIT_PRODUCT=template (the matrix does).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Adapter;

require_once __DIR__ . '/Builder_Adapter_Test_Case.php';

/**
 * Class TemplateAdapterTest
 */
class TemplateAdapterTest extends Builder_Adapter_Test_Case {
}
