<?php

declare(strict_types=1);

// ============================================================
// Suggestion — 基于 Levenshtein 编辑距离的智能推荐
//
// 设计目标：
//   - 为编译器/解释器的未定义符号错误提供 "Did you mean ...?" 提示
//   - 不依赖 PHP 内置 levenshtein()（其限制 255 字符且对中文不准）
//   - 提供语义化 API：suggestMethod / suggestProperty / suggestClass /
//     suggestFunction / suggestConstant，全部委托 findClosest
//   - 提供错误消息增强：formatDidYouMean / enhanceErrorMessage
//
// 算法：
//   - levenshteinDistance: 标准 DP，O(m*n) 时间与空间
//   - findClosest: 线性扫描候选列表，取距离最小且 ≤ maxDistance 的；
//     相同距离时返回第一个；空候选或无满足条件的返回 null
//
// 运行方式：
//   cd c:\project\php\TinyPHP
//   php tools/AST/suggestion_test.php
// ============================================================

class Suggestion
{
    // ─────────────────────────────────────────────────────────────
    // Levenshtein 编辑距离
    // ─────────────────────────────────────────────────────────────

    /**
     * 计算两个字符串的 Levenshtein 编辑距离。
     *
     * 编辑操作：插入、删除、替换，每种代价 1。
     * 标准动态规划实现，处理空串与相同串边界。
     *
     * @param string $a 源字符串
     * @param string $b 目标字符串
     * @return int 编辑距离（≥ 0）
     */
    public static function levenshteinDistance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);

        // 边界：空串
        if ($la === 0) return $lb;
        if ($lb === 0) return $la;

        // DP 表：(la+1) x (lb+1)
        $dp = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));

        // 基础情形：从空串到 b[0..j] 需要 j 次插入
        for ($i = 0; $i <= $la; $i++) $dp[$i][0] = $i;
        for ($j = 0; $j <= $lb; $j++) $dp[0][$j] = $j;

        // 填表
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $dp[$i][$j] = min(
                    $dp[$i - 1][$j]     + 1,  // 删除 a[i-1]
                    $dp[$i][$j - 1]     + 1,  // 插入 b[j-1]
                    $dp[$i - 1][$j - 1] + $cost // 替换 a[i-1] -> b[j-1]
                );
            }
        }

        return $dp[$la][$lb];
    }

    /**
     * 从候选列表中找出与 target 编辑距离最小且 ≤ maxDistance 的项。
     *
     * 规则：
     *   - 多个候选距离相同时，返回第一个出现的
     *   - 候选列表为空或无满足距离条件的，返回 null
     *
     * @param string $target 目标字符串
     * @param array<int,string> $candidates 候选字符串列表
     * @param int $maxDistance 最大允许距离（默认 3）
     * @return string|null 推荐结果或 null
     */
    public static function findClosest(string $target, array $candidates, int $maxDistance = 3): ?string
    {
        $best = null;
        $bestDist = $maxDistance + 1; // 严格大于 maxDistance，使 dist==maxDistance 仍可命中

        foreach ($candidates as $cand) {
            $dist = self::levenshteinDistance($target, $cand);
            // 严格 < ：相同距离时保留先出现的（首个胜出）
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $cand;
            }
        }

        return $best;
    }

    // ─────────────────────────────────────────────────────────────
    // 语义化推荐 API（均委托 findClosest）
    // ─────────────────────────────────────────────────────────────

    /** 方法名推荐 */
    public static function suggestMethod(string $target, array $methods): ?string
    {
        return self::findClosest($target, $methods);
    }

    /** 属性名推荐 */
    public static function suggestProperty(string $target, array $properties): ?string
    {
        return self::findClosest($target, $properties);
    }

    /** 类名推荐 */
    public static function suggestClass(string $target, array $classes): ?string
    {
        return self::findClosest($target, $classes);
    }

    /** 函数名推荐 */
    public static function suggestFunction(string $target, array $functions): ?string
    {
        return self::findClosest($target, $functions);
    }

    /** 常量名推荐 */
    public static function suggestConstant(string $target, array $constants): ?string
    {
        return self::findClosest($target, $constants);
    }

    // ─────────────────────────────────────────────────────────────
    // 错误消息增强
    // ─────────────────────────────────────────────────────────────

    /**
     * 格式化 "Did you mean ...?" 提示。
     *
     * @param string $target 目标字符串（保留以供未来扩展，如多候选并列展示）
     * @param string|null $suggestion 推荐结果（来自 findClosest 或其语义化包装）
     * @return string 非 null 时返回 "Did you mean '{suggestion}'?"，否则返回空字符串
     */
    public static function formatDidYouMean(string $target, ?string $suggestion): string
    {
        if ($suggestion === null) {
            return '';
        }
        return "Did you mean '{$suggestion}'?";
    }

    /**
     * 在原错误消息后追加 "Did you mean ...?" 提示（若有推荐）。
     *
     * 示例：
     *   "Undefined method 'fooBar'" + suggestion 'fooBarBaz'
     *   → "Undefined method 'fooBar'. Did you mean 'fooBarBaz'?"
     *
     * 若无推荐，原消息原样返回（不附加句点）。
     *
     * @param string $message 原错误消息
     * @param string $target 目标字符串
     * @param array<int,string> $candidates 候选列表
     * @return string 增强后的消息
     */
    public static function enhanceErrorMessage(string $message, string $target, array $candidates): string
    {
        $suggestion = self::findClosest($target, $candidates);
        $hint = self::formatDidYouMean($target, $suggestion);
        if ($hint === '') {
            return $message;
        }
        return "{$message}. {$hint}";
    }
}
