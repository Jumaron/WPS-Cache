import re

class MinifyCSS:
    T_WHITESPACE = 0
    T_COMMENT    = 1
    T_STRING     = 2
    T_OPEN       = 3 # {
    T_CLOSE      = 4 # }
    T_COLON      = 5 # :
    T_SEMICOLON  = 6 # ;
    T_PAREN_OPEN = 7 # (
    T_PAREN_CLOSE = 8 # )
    T_OPERATOR   = 9 # , > + ~
    T_WORD       = 10 # Selectors, properties, values

    def tokenize(self, css):
        length = len(css)
        i = 0
        while i < length:
            char = css[i]

            # Whitespace
            if char.isspace():
                i += 1
                while i < length and css[i].isspace():
                    i += 1
                yield {'type': self.T_WHITESPACE, 'value': ' '}
                continue

            # Strings
            if char in ('"', "'"):
                quote = char
                start = i
                i += 1
                while i < length:
                    if css[i] == '\\':
                        i += 2
                        continue
                    if css[i] == quote:
                        i += 1
                        break
                    if css[i] == '\n':
                        break
                    i += 1
                yield {'type': self.T_STRING, 'value': css[start:i]}
                continue

            # Comments
            if char == '/' and i + 1 < length and css[i+1] == '*':
                start = i
                i += 2
                while i < length - 1:
                    if css[i] == '*' and css[i+1] == '/':
                        i += 2
                        break
                    i += 1
                yield {'type': self.T_COMMENT, 'value': css[start:i]}
                continue

            # Granular Tokens
            if char == '{':
                yield {'type': self.T_OPEN, 'value': '{'}
                i += 1
                continue
            if char == '}':
                yield {'type': self.T_CLOSE, 'value': '}'}
                i += 1
                continue
            if char == ':':
                yield {'type': self.T_COLON, 'value': ':'}
                i += 1
                continue
            if char == ';':
                yield {'type': self.T_SEMICOLON, 'value': ';'}
                i += 1
                continue
            if char == '(':
                yield {'type': self.T_PAREN_OPEN, 'value': '('}
                i += 1
                continue
            if char == ')':
                yield {'type': self.T_PAREN_CLOSE, 'value': ')'}
                i += 1
                continue

            # Operators
            if char in ',>+~':
                yield {'type': self.T_OPERATOR, 'value': char}
                i += 1
                continue

            # Words
            start = i
            while i < length:
                c = css[i]
                if c.isspace() or c in '{}():;,\'"' or (c == '/' and i + 1 < length and css[i+1] == '*'):
                    break
                if c in '>+~':
                    break
                i += 1

            val = css[start:i]
            yield {'type': self.T_WORD, 'value': val}

    def needsSpace(self, prev, curr, inCalc, lastClosedFunc=None, prevPrev=None, whitespaceSkipped=False):
        # 1. Inside calc
        if inCalc:
            val = curr['value']
            pVal = prev['value']
            if val in ('+', '-') or pVal in ('+', '-'):
                return True

        t1 = prev['type']
        t2 = curr['type']

        # 2. Word + Word
        if t1 == self.T_WORD and t2 == self.T_WORD:
            return True

        # 3. Variable/Function fusion fix
        if t1 == self.T_PAREN_CLOSE and t2 == self.T_WORD:
             # If whitespace was explicitly skipped, KEEP IT (unless safe to remove?)
             # In CSS, space is a combinator.
             # ) .class -> Descendant. ) .class -> Chained.
             # So if whitespaceSkipped, we MUST return true.
             if whitespaceSkipped:
                 return True

             # If NO whitespace was skipped (e.g. `div:not(.a).b` or `var(--a)var(--b)`):

             # Logic: Only add space if NOT a chained selector
             selector_pseudos = ['not', 'is', 'where', 'has', 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'dir', 'lang', 'host', 'host-context', 'part', 'slotted']

             if lastClosedFunc in selector_pseudos:
                 # In Selector Context, no space = chained.
                 # We want to PRESERVE the "no space" if the input had no space.
                 return False

             # Default (Value context or unknown): Add space to be safe/fix fusion?
             # If input was `var(--a)var(--b)` (invalid), adding space fixes it.
             # If input was `var(--a).5em` (maybe valid?), adding space is safer.
             return True

        # 4. Media Query "and ("
        if t1 == self.T_WORD and t2 == self.T_PAREN_OPEN:
            kw = prev['value'].lower()
            if kw in ('and', 'or', 'not'):
                # Check if this is a pseudo-class like :not(
                if kw == 'not' and prevPrev and prevPrev['type'] == self.T_COLON:
                    return False
                return True

        return False

    def minifyCSS(self, css):
        output = []
        prevToken = None
        prevPrevToken = None
        calcDepth = 0
        pendingSemicolon = False

        parenStack = []
        lastClosedFunc = None

        whitespaceSkipped = False

        for token in self.tokenize(css):
            if token['type'] == self.T_COMMENT:
                # Comments might act as separator? "a/* */b".
                # Standard says comments are treated as whitespace? No, comments are ignored.
                # But a/**/b is ab.
                continue

            if token['type'] == self.T_WHITESPACE:
                whitespaceSkipped = True
                continue

            if pendingSemicolon:
                if token['type'] != self.T_CLOSE:
                    output.append(';')
                pendingSemicolon = False

            if token['type'] == self.T_SEMICOLON:
                pendingSemicolon = True
                continue

            # Context Tracking
            if token['type'] == self.T_PAREN_OPEN:
                func = None
                if prevToken and prevToken['type'] == self.T_WORD:
                    func = prevToken['value'].lower()
                    if func in ('calc', 'clamp', 'min', 'max', 'var'):
                        calcDepth += 1
                    elif calcDepth > 0:
                        calcDepth += 1
                elif calcDepth > 0:
                     calcDepth += 1

                parenStack.append(func)

            if token['type'] == self.T_PAREN_CLOSE:
                if calcDepth > 0:
                    calcDepth -= 1
                if parenStack:
                    lastClosedFunc = parenStack.pop()
                else:
                    lastClosedFunc = None

            # Needs Space
            if prevToken and self.needsSpace(prevToken, token, calcDepth > 0, lastClosedFunc, prevPrevToken, whitespaceSkipped):
                output.append(' ')

            output.append(token['value'])
            prevPrevToken = prevToken
            prevToken = token
            whitespaceSkipped = False

        if pendingSemicolon:
            output.append(';')

        return "".join(output)

minifier = MinifyCSS()

test_cases = [
    ("div:not(.a).b { color: red; }", "div:not(.a).b{color:red}"), # Correct: No space
    ("div:not(.a)#b { color: red; }", "div:not(.a)#b{color:red}"), # Correct: No space
    ("div:not(.a) span { color: red; }", "div:not(.a) span{color:red}"), # Correct: Space (Descendant)
    ("div:not(.a) .b { color: red; }", "div:not(.a) .b{color:red}"), # Correct: Space (Descendant .b)
    ("background: url(a.png) no-repeat;", "background:url(a.png) no-repeat;"), # Correct: Space
    ("margin: var(--a) var(--b);", "margin:var(--a) var(--b);"), # Correct: Space
    ("width: calc(100% - 20px);", "width:calc(100% - 20px);"), # Correct: No space
    ("margin: var(--a).5em;", "margin:var(--a) .5em;"), # Correct: Space (Value context default)
    ("@media not (screen) { }", "@media not (screen){}"), # Correct: Space for media query
    ("div:not(.a) { }", "div:not(.a){}"), # Correct: No space for pseudo
]

for inp, expected in test_cases:
    res = minifier.minifyCSS(inp)

    if res != expected:
        print(f"Input:    {inp}")
        print(f"Result:   {res}")
        print(f"Expected: {expected}")
        print("FAIL!")
    else:
        print("PASS")
