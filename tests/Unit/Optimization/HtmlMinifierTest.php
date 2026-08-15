<?php

declare(strict_types=1);

namespace WPSCache\Tests\Unit\Optimization;

use WPSCache\Optimization\Html\HtmlMinifier;
use WPSCache\Tests\Framework\TestCase;

final class HtmlMinifierTest extends TestCase
{
    public function testMinifiesMarkupButPreservesRawTextNodes(): void
    {
        $html = "<html>\n  <head><!-- remove --><script>var x = 1;\n var y = 2;</script></head>\n <body><pre>  keep\n space </pre><p>hello</p></body>\n</html>";
        $result = (new HtmlMinifier(['html_minify' => true]))->process($html);

        $this->assertFalse(str_contains($result, '<!-- remove -->'));
        $this->assertContains('<script>var x = 1;' . "\n" . ' var y = 2;</script>', $result);
        $this->assertContains('<pre>  keep' . "\n" . ' space </pre>', $result);
        $this->assertContains('</head><body>', $result);
    }
}
