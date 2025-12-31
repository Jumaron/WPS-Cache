<?php

namespace WPSCache\Tests\Cache\Drivers;

/**
 * Static regression test cases for MinifyCSS.
 *
 * Since the development environment cannot execute PHP, this file serves as a
 * documentation of the regression tests that SHOULD be run.
 * The logic was verified using a Python simulation in `repro_issue.py`.
 */
class MinifyCSSRegressionTest
{
    /**
     * Test cases for the "Variable/Function fusion fix" regression.
     *
     * Issue: The original fix blindly inserted a space after ANY closing parenthesis
     * followed by a word. This broke chained selectors like `div:not(.a).b`.
     *
     * Fix: Context-aware spacing based on the function/pseudo-class name being closed.
     */
    public function testCases()
    {
        return [
            // Case 1: Chained Class Selector on Pseudo-class
            // Expected: No space between ) and .
            [
                'input' => 'div:not(.a).b { color: red; }',
                'expected' => 'div:not(.a).b{color:red}',
            ],

            // Case 2: Chained ID Selector on Pseudo-class
            // Expected: No space between ) and #
            [
                'input' => 'div:not(.a)#b { color: red; }',
                'expected' => 'div:not(.a)#b{color:red}',
            ],

            // Case 3: Descendant Element Selector
            // Expected: Space between ) and span (descendant combinator)
            [
                'input' => 'div:not(.a) span { color: red; }',
                'expected' => 'div:not(.a) span{color:red}',
            ],

            // Case 4: Property Value Function (original fix target)
            // Expected: Space between ) and next value
            [
                'input' => 'background: url(a.png) no-repeat;',
                'expected' => 'background:url(a.png) no-repeat;',
            ],

            // Case 5: Variable fusion
            // Expected: Space between ) and var
            [
                'input' => 'margin: var(--a) var(--b);',
                'expected' => 'margin:var(--a) var(--b);',
            ],

            // Case 6: Unit suffix (edge case, usually invalid if not inside calc, but handled as value)
            // Expected: Space (safe default)
            [
                'input' => 'margin: var(--a).5em;',
                'expected' => 'margin:var(--a) .5em;',
            ],

            // Case 7: Media Query "not ("
            // Expected: Space (operator)
            [
                'input' => '@media not (screen) {}',
                'expected' => '@media not (screen){}',
            ],

            // Case 8: Pseudo-class ":not("
            // Expected: No space (pseudo-class)
            // This was broken by Rule 4 in original code!
            [
                'input' => 'div:not(.a) {}',
                'expected' => 'div:not(.a){}',
            ],
        ];
    }
}
