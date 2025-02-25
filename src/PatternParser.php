<?php

namespace Imanghafoori\SearchReplace;

use Imanghafoori\TokenAnalyzer\Str;

class PatternParser
{
    private static function getParams($pToken, $id)
    {
        $pName = trim($pToken[1], '\'\"');

        return rtrim(Str::replaceFirst("<$id:", '', $pName), '>');
    }

    public static function parsePatterns($patterns)
    {
        $defaults = [
            'predicate' => null,
            'mutator' => null,
            'named_patterns' => [],
            'filters' => [],
            'avoid_syntax_errors' => false,
            'post_replace' => [],
        ];

        $analyzedPatterns = [];
        $names = implode(',', [
            'white_space',
            'string',
            'str',
            'variable',
            'var',
            'statement',
            'in_between',
            'any',
            'cast',
            'number',
            'int',
            'integer',
            'doc_block',
            'name',
            'visibility',
            'float',
            'comment',
            'until',
            'full_class_ref',
            'class_ref',
            'bool',
            'boolean',
        ]);
        $prePattern = '<"<name:'.$names.'>">';
        $prePattern2 = '<"<name:'.$names.'>"?>';

        foreach ($patterns as $to) {
            $search = $to['search'];
            if ($search !== $prePattern && $search !== $prePattern2) {
                [$tokens,] = Searcher::search(
                      [
                          ['search' => $prePattern, 'replace' => '"<"<1>">"',],
                          ['search' => $prePattern2, 'replace' => '"<"<1>"?>"',],

                      ]
                    , token_get_all('<?php '.$search));
                unset($tokens[0]);
                $search = Stringify::fromTokens($tokens);
            }
            #---# Searcher::search($patterns, $tokens); #---#
            self::extracted($search, $addedFilters, $tokens);
            $tokens = ['search' => $tokens] + $to + $defaults;
            foreach ($addedFilters as $addedFilter) {
                $tokens['filters'][$addedFilter[0]]['in_array'] = $addedFilter[1];
            }
            $analyzedPatterns[] = $tokens;
        }

        return $analyzedPatterns;
    }

    public static function tokenize($pattern)
    {
        $tokens = token_get_all('<?php '.self::cleanComments($pattern));
        array_shift($tokens);

        return $tokens;
    }

    private static function cleanComments($pattern)
    {
        foreach (['"', "'"] as $c) {
            for ($i = 1; $i !== 11; $i++) {
                $pattern = str_replace("$c<$i:", "$c<", $pattern, $count);
            }
        }

        return $pattern;
    }

    public static function firstNonOptionalPlaceholder($patternTokens)
    {
        $i = 0;
        foreach ($patternTokens as $i => $pt) {
            if (! self::isOptionalPlaceholder($pt)) {
                return $i;
            }
        }

        return $i;
    }

    private static function isOptionalPlaceholder($token)
    {
        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return false;
        }

        return Finder::endsWith($token[1], '>?"') || Finder::endsWith($token[1], ">?'");
    }

    private static function extracted($search, &$addedFilters, &$tokens): void
    {
        $addedFilters = [];
        $tokens = self::tokenize($search);
        $count = 0;
        foreach ($tokens as $i => $pToken) {
            if ($pToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            // If is placeholder "<like_this>"
            if ($pToken[1][1] === '<' && '>' === $pToken[1][strlen($pToken[1]) - 2]) {
                $count++;
            } else {
                continue;
            }

            $ids = self::getPlaceholderIds();
            foreach ($ids as [$id, $mutator]) {
                if (Finder::startsWith(trim($pToken[1], '\'\"'), "<$id:")) {
                    $tokens[$i][1] = "'<$id>'";
                    $readParams = self::getParams($pToken, $id);
                    $mutator && $readParams = $mutator($readParams);
                    $addedFilters[] = [$count, $readParams];
                }
            }
        }
    }

    private static function getPlaceholderIds(): array
    {
        $ids = [
            [
                'global_func_call',
                function ($values) {
                    $values = $u = explode(',', $values);
                    foreach ($u as $val) {
                        $values[] = '\\'.$val;
                    }

                    return $values;
                },
            ],
            ['name', null],
        ];

        return $ids;
    }
}
