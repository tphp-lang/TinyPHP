<?php

declare(strict_types=1);

// ============================================================
// SSAOptPass — SSA 优化 Pass（Task 13）
//
// 实现以下优化：
//   1. CFG 与支配树构建（Cooper-Harvey-Kennedy 2001 迭代算法）
//   2. 支配前沿计算
//   3. 常量折叠（算术/比较/逻辑/COPY 传播/常量条件跳转折叠）
//   4. 死代码消除（标记-清扫，保留有副作用的指令）
//   5. 死块消除（从 entry 做 BFS，删除不可达块，清理 Phi）
//   6. Phi 简化（自引用移除、单一/相同 incoming 替换）
//   7. runAll / runUntilFixpoint
//
// 依赖：SSA.php（数据结构 + dumpSSAFunction）
// ============================================================

require_once __DIR__ . '/SSA.php';

class SSAOptPass
{
    /** @var array<int, array{0:string, 1:mixed}> valueId → ['int'|'float'|'bool'|'null', value] */
    private array $constValues = [];

    // ═══════════════════════════════════════════════════════════════
    // SubTask 13.2: CFG 与支配树
    // ═══════════════════════════════════════════════════════════════

    /**
     * 构建 CFG（邻接表形式）。
     *
     * @param SSAFunction $func
     * @return array<int, array<int>> blockId => [successor blockIds]
     */
    public function buildCFG(SSAFunction $func): array
    {
        $cfg = [];
        foreach ($func->blocks as $bid => $block) {
            $cfg[$bid] = array_values($block->successors);
        }
        return $cfg;
    }

    /**
     * 构建支配树（immediate dominator）。
     *
     * 算法：Cooper, Harvey, Kennedy 2001 的 iterative dominators。
     * 入口块的 idom 为自身；不可达块为 -1。
     *
     * @param SSAFunction $func
     * @return array<int, int> blockId => idom blockId
     */
    public function buildDominatorTree(SSAFunction $func): array
    {
        $entry = $func->entryBlockId;
        $cfg = $this->buildCFG($func);

        // 计算 postorder（DFS）
        $postorder = [];
        $visited = [];
        if ($entry >= 0 && isset($func->blocks[$entry])) {
            $this->dfsPostorder($entry, $cfg, $visited, $postorder);
        }

        // postorder 编号（root 最高）
        $postorderNum = [];
        foreach ($postorder as $i => $bid) {
            $postorderNum[$bid] = $i;
        }

        // 初始化 idom：-1 = 未定义
        $idom = [];
        foreach ($func->blocks as $bid => $_) {
            $idom[$bid] = -1;
        }
        if ($entry >= 0 && isset($func->blocks[$entry])) {
            $idom[$entry] = $entry; // 入口自支配
        }

        // RPO = 反 postorder
        $rpo = array_reverse($postorder);

        // 迭代直到 fixpoint
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($rpo as $bid) {
                if ($bid === $entry) {
                    continue;
                }
                $block = $func->blocks[$bid];
                $newIdom = -1;
                foreach ($block->predecessors as $pid) {
                    if (!isset($idom[$pid]) || $idom[$pid] === -1) {
                        continue;
                    }
                    if ($newIdom === -1) {
                        $newIdom = $pid;
                    } else {
                        $newIdom = $this->domIntersect($pid, $newIdom, $idom, $postorderNum);
                    }
                }
                if ($newIdom !== -1 && $idom[$bid] !== $newIdom) {
                    $idom[$bid] = $newIdom;
                    $changed = true;
                }
            }
        }

        return $idom;
    }

    /**
     * 计算支配前沿（Dominance Frontier）。
     *
     * @param SSAFunction $func
     * @param array<int, int> $domTree buildDominatorTree 的返回值
     * @return array<int, array<int>> blockId => [DF blockIds]
     */
    public function dominanceFrontiers(SSAFunction $func, array $domTree): array
    {
        $df = [];
        foreach ($func->blocks as $bid => $_) {
            $df[$bid] = [];
        }

        foreach ($func->blocks as $bid => $block) {
            if (count($block->predecessors) < 2) {
                continue;
            }
            // 不可达块的 idom 为 -1，跳过
            if (($domTree[$bid] ?? -1) === -1) {
                continue;
            }

            foreach ($block->predecessors as $pid) {
                // 跳过不可达前驱（entry 除外，entry 的 idom 为自身）
                if (($domTree[$pid] ?? -1) === -1 && $pid !== $func->entryBlockId) {
                    continue;
                }
                $runner = $pid;
                while ($runner !== $domTree[$bid] && $runner !== -1) {
                    if (!in_array($bid, $df[$runner], true)) {
                        $df[$runner][] = $bid;
                    }
                    $next = $domTree[$runner] ?? -1;
                    if ($next === $runner) {
                        break; // 到达 entry（自支配），避免死循环
                    }
                    $runner = $next;
                }
            }
        }

        return $df;
    }

    /**
     * DFS 后序遍历（递归）。
     */
    private function dfsPostorder(int $bid, array $cfg, array &$visited, array &$postorder): void
    {
        if (isset($visited[$bid])) {
            return;
        }
        $visited[$bid] = true;
        foreach ($cfg[$bid] ?? [] as $succ) {
            $this->dfsPostorder($succ, $cfg, $visited, $postorder);
        }
        $postorder[] = $bid;
    }

    /**
     * CHK 算法的 intersect 函数：找到 b1 和 b2 在支配树上的最近公共祖先。
     * postorder 编号越大越靠近根（entry 最高）。
     */
    private function domIntersect(int $b1, int $b2, array $idom, array $postorderNum): int
    {
        $finger1 = $b1;
        $finger2 = $b2;
        while ($finger1 !== $finger2) {
            while (isset($postorderNum[$finger1]) && isset($postorderNum[$finger2])
                && $postorderNum[$finger1] < $postorderNum[$finger2]) {
                $finger1 = $idom[$finger1] ?? -1;
                if ($finger1 === -1) {
                    return $finger2;
                }
            }
            while (isset($postorderNum[$finger1]) && isset($postorderNum[$finger2])
                && $postorderNum[$finger2] < $postorderNum[$finger1]) {
                $finger2 = $idom[$finger2] ?? -1;
                if ($finger2 === -1) {
                    return $finger1;
                }
            }
        }
        return $finger1;
    }

    // ═══════════════════════════════════════════════════════════════
    // SubTask 13.3: 常量折叠
    // ═══════════════════════════════════════════════════════════════

    /**
     * 常量折叠：识别常量运算并折叠为 CONST 指令。
     * 同时处理 COPY 传播和常量条件跳转（BR → JMP）。
     * 重复直到 fixpoint。
     *
     * @param SSAFunction $func
     */
    public function constantFolding(SSAFunction $func): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            $this->collectConstants($func);

            foreach ($func->blocks as $bid => $block) {
                $instCount = count($block->instructions);
                for ($i = 0; $i < $instCount; $i++) {
                    $inst = $block->instructions[$i];

                    // 常量条件跳转折叠：BR with const cond → JMP
                    if ($inst->op === OpCode::BR) {
                        $folded = $this->foldBranch($func, $bid, $block, $i, $inst);
                        if ($folded) {
                            $changed = true;
                        }
                        continue;
                    }

                    // 跳过已是常量的指令
                    if ($this->isConstOp($inst->op)) {
                        continue;
                    }

                    $newInst = $this->foldInstruction($inst);
                    if ($newInst !== null) {
                        $block->instructions[$i] = $newInst;
                        $this->registerConst($newInst);
                        $changed = true;
                    }
                }
            }
        }
    }

    /**
     * 收集所有常量值到 $this->constValues。
     * 包括 CONST_* 指令和 COPY of 常量（传播）。
     */
    private function collectConstants(SSAFunction $func): void
    {
        $this->constValues = [];
        // 多趟扫描以传播 COPY 链
        $loopChanged = true;
        while ($loopChanged) {
            $loopChanged = false;
            foreach ($func->blocks as $block) {
                foreach ($block->instructions as $inst) {
                    if ($inst->dst === null) {
                        continue;
                    }
                    if (isset($this->constValues[$inst->dst])) {
                        continue;
                    }
                    switch ($inst->op) {
                        case OpCode::CONST_INT:
                            $this->constValues[$inst->dst] = ['int', $inst->extra['value']];
                            $loopChanged = true;
                            break;
                        case OpCode::CONST_FLOAT:
                            $this->constValues[$inst->dst] = ['float', $inst->extra['value']];
                            $loopChanged = true;
                            break;
                        case OpCode::CONST_BOOL:
                            $this->constValues[$inst->dst] = ['bool', $inst->extra['value']];
                            $loopChanged = true;
                            break;
                        case OpCode::CONST_NULL:
                            $this->constValues[$inst->dst] = ['null', null];
                            $loopChanged = true;
                            break;
                        case OpCode::COPY:
                            $src = $inst->operands[0];
                            if (isset($this->constValues[$src])) {
                                $this->constValues[$inst->dst] = $this->constValues[$src];
                                $loopChanged = true;
                            }
                            break;
                    }
                }
            }
        }
    }

    /**
     * 注册一条 CONST 指令的结果到 constValues。
     */
    private function registerConst(SSAInstruction $inst): void
    {
        if ($inst->dst === null) {
            return;
        }
        switch ($inst->op) {
            case OpCode::CONST_INT:
                $this->constValues[$inst->dst] = ['int', $inst->extra['value']];
                break;
            case OpCode::CONST_FLOAT:
                $this->constValues[$inst->dst] = ['float', $inst->extra['value']];
                break;
            case OpCode::CONST_BOOL:
                $this->constValues[$inst->dst] = ['bool', $inst->extra['value']];
                break;
            case OpCode::CONST_NULL:
                $this->constValues[$inst->dst] = ['null', null];
                break;
        }
    }

    /**
     * 折叠单条指令。返回新的 CONST 指令或 null（无法折叠）。
     */
    private function foldInstruction(SSAInstruction $inst): ?SSAInstruction
    {
        $op = $inst->op;

        // COPY 传播
        if ($op === OpCode::COPY) {
            $src = $inst->operands[0];
            if (!isset($this->constValues[$src])) {
                return null;
            }
            [$type, $value] = $this->constValues[$src];
            return $this->makeConstInst($inst->dst, $type, $value);
        }

        // 一元运算
        if ($op === OpCode::NEG || $op === OpCode::NOT) {
            $operand = $inst->operands[0];
            if (!isset($this->constValues[$operand])) {
                return null;
            }
            [$type, $value] = $this->constValues[$operand];
            if ($op === OpCode::NEG && ($type === 'int' || $type === 'float')) {
                return $this->makeConstInst($inst->dst, $type, -$value);
            }
            if ($op === OpCode::NOT && $type === 'bool') {
                return $this->makeConstInst($inst->dst, 'bool', !$value);
            }
            return null;
        }

        // 二元逻辑运算
        if ($op === OpCode::AND || $op === OpCode::OR) {
            $lhs = $inst->operands[0];
            $rhs = $inst->operands[1];
            if (!isset($this->constValues[$lhs]) || !isset($this->constValues[$rhs])) {
                return null;
            }
            [$ltype, $lval] = $this->constValues[$lhs];
            [$rtype, $rval] = $this->constValues[$rhs];
            if ($ltype !== 'bool' || $rtype !== 'bool') {
                return null;
            }
            $result = ($op === OpCode::AND) ? ($lval && $rval) : ($lval || $rval);
            return $this->makeConstInst($inst->dst, 'bool', $result);
        }

        // 二元比较运算 → bool
        $compareOps = [OpCode::EQ, OpCode::NE, OpCode::LT, OpCode::LE, OpCode::GT, OpCode::GE];
        if (in_array($op, $compareOps, true)) {
            $lhs = $inst->operands[0];
            $rhs = $inst->operands[1];
            if (!isset($this->constValues[$lhs]) || !isset($this->constValues[$rhs])) {
                return null;
            }
            [$ltype, $lval] = $this->constValues[$lhs];
            [$rtype, $rval] = $this->constValues[$rhs];
            if ($ltype === 'null' || $rtype === 'null') {
                return null;
            }
            // 数值比较
            if (in_array($ltype, ['int', 'float']) && in_array($rtype, ['int', 'float'])) {
                $result = match ($op) {
                    OpCode::EQ => $lval == $rval,
                    OpCode::NE => $lval != $rval,
                    OpCode::LT => $lval < $rval,
                    OpCode::LE => $lval <= $rval,
                    OpCode::GT => $lval > $rval,
                    OpCode::GE => $lval >= $rval,
                    default => false,
                };
                return $this->makeConstInst($inst->dst, 'bool', $result);
            }
            // 布尔相等/不等
            if ($ltype === 'bool' && $rtype === 'bool') {
                $result = match ($op) {
                    OpCode::EQ => $lval === $rval,
                    OpCode::NE => $lval !== $rval,
                    default => null,
                };
                if ($result === null) {
                    return null;
                }
                return $this->makeConstInst($inst->dst, 'bool', $result);
            }
            return null;
        }

        // 二元算术运算
        $arithOps = [OpCode::ADD, OpCode::SUB, OpCode::MUL, OpCode::DIV, OpCode::MOD];
        if (in_array($op, $arithOps, true)) {
            $lhs = $inst->operands[0];
            $rhs = $inst->operands[1];
            if (!isset($this->constValues[$lhs]) || !isset($this->constValues[$rhs])) {
                return null;
            }
            [$ltype, $lval] = $this->constValues[$lhs];
            [$rtype, $rval] = $this->constValues[$rhs];
            if ($ltype === 'null' || $rtype === 'null') {
                return null;
            }
            if (!in_array($ltype, ['int', 'float']) || !in_array($rtype, ['int', 'float'])) {
                return null;
            }
            // 除零/模零不折叠
            if (($op === OpCode::DIV || $op === OpCode::MOD) && $rval == 0) {
                return null;
            }
            $isFloat = ($ltype === 'float' || $rtype === 'float');
            $result = match ($op) {
                OpCode::ADD => $lval + $rval,
                OpCode::SUB => $lval - $rval,
                OpCode::MUL => $lval * $rval,
                OpCode::DIV => $lval / $rval,
                OpCode::MOD => (!$isFloat) ? $lval % $rval : fmod((float)$lval, (float)$rval),
                default => 0,
            };
            $type = $isFloat ? 'float' : 'int';
            $value = $isFloat ? (float)$result : (int)$result;
            return $this->makeConstInst($inst->dst, $type, $value);
        }

        return null;
    }

    /**
     * 折叠常量条件跳转 BR → JMP。返回是否发生了折叠。
     */
    private function foldBranch(SSAFunction $func, int $bid, SSABasicBlock $block, int $instIdx, SSAInstruction $inst): bool
    {
        $cond = $inst->operands[0];
        if (!isset($this->constValues[$cond])) {
            return false;
        }
        [$type, $value] = $this->constValues[$cond];
        if ($type !== 'bool') {
            return false;
        }

        $thenBlock = $inst->extra['then_block'] ?? -1;
        $elseBlock = $inst->extra['else_block'] ?? -1;
        $target = $value ? $thenBlock : $elseBlock;
        $untaken = $value ? $elseBlock : $thenBlock;

        // 替换 BR 为 JMP
        $block->instructions[$instIdx] = new SSAInstruction(
            OpCode::JMP, null, [], ['target_block' => $target],
        );

        // 更新当前块的后继：只保留 target
        $block->successors = array_values(array_filter(
            $block->successors,
            fn($s) => $s === $target,
        ));

        // 从 untaken 块的前驱中移除当前块
        if (isset($func->blocks[$untaken])) {
            $func->blocks[$untaken]->predecessors = array_values(array_filter(
                $func->blocks[$untaken]->predecessors,
                fn($p) => $p !== $bid,
            ));
        }

        return true;
    }

    /**
     * 构造常量指令。
     */
    private function makeConstInst(?int $dst, string $type, mixed $value): SSAInstruction
    {
        return match ($type) {
            'int' => new SSAInstruction(OpCode::CONST_INT, $dst, [], ['value' => $value]),
            'float' => new SSAInstruction(OpCode::CONST_FLOAT, $dst, [], ['value' => $value]),
            'bool' => new SSAInstruction(OpCode::CONST_BOOL, $dst, [], ['value' => $value]),
            'null' => new SSAInstruction(OpCode::CONST_NULL, $dst, []),
            default => new SSAInstruction(OpCode::CONST_INT, $dst, [], ['value' => 0]),
        };
    }

    /**
     * 是否为常量指令。
     */
    private function isConstOp(OpCode $op): bool
    {
        return $op === OpCode::CONST_INT
            || $op === OpCode::CONST_FLOAT
            || $op === OpCode::CONST_BOOL
            || $op === OpCode::CONST_NULL;
    }

    // ═══════════════════════════════════════════════════════════════
    // SubTask 13.4: 死代码消除
    // ═══════════════════════════════════════════════════════════════

    /**
     * 死代码消除（标记-清扫法）。
     * 1. 标记有副作用的指令为 live
     * 2. 从副作用指令的操作数反向传播 liveness
     * 3. 删除非 live 且无副作用的指令
     *
     * @param SSAFunction $func
     */
    public function deadCodeElimination(SSAFunction $func): void
    {
        // 构建 valueId → 定义指令的映射
        $defs = [];
        foreach ($func->blocks as $block) {
            foreach ($block->instructions as $inst) {
                if ($inst->dst !== null) {
                    $defs[$inst->dst] = $inst;
                }
            }
        }

        // 初始化 worklist：从有副作用的指令开始
        $live = []; // valueId => true
        $worklist = [];
        foreach ($func->blocks as $block) {
            foreach ($block->instructions as $inst) {
                if ($this->hasSideEffect($inst)) {
                    foreach ($inst->operands as $op) {
                        if (!isset($live[$op])) {
                            $worklist[] = $op;
                        }
                    }
                }
            }
        }

        // 传播 liveness
        while (!empty($worklist)) {
            $v = array_pop($worklist);
            if (isset($live[$v])) {
                continue;
            }
            $live[$v] = true;
            $defInst = $defs[$v] ?? null;
            if ($defInst !== null) {
                foreach ($defInst->operands as $op) {
                    if (!isset($live[$op])) {
                        $worklist[] = $op;
                    }
                }
            }
        }

        // 删除非 live 且无副作用的指令
        foreach ($func->blocks as $block) {
            $newInsts = [];
            foreach ($block->instructions as $inst) {
                if ($this->hasSideEffect($inst)) {
                    $newInsts[] = $inst;
                    continue;
                }
                // 无副作用的指令：dst 必须 live 才保留
                if ($inst->dst !== null && isset($live[$inst->dst])) {
                    $newInsts[] = $inst;
                }
                // 否则删除（无副作用 + dst 不 live / 无 dst）
            }
            $block->instructions = $newInsts;
        }
    }

    /**
     * 指令是否有副作用（不可安全删除）。
     * - RET/BR/JMP: 控制流
     * - STORE: 内存写
     * - CALL: 函数调用（保守视为有副作用）
     */
    private function hasSideEffect(SSAInstruction $inst): bool
    {
        return match ($inst->op) {
            OpCode::RET, OpCode::BR, OpCode::JMP,
            OpCode::STORE, OpCode::CALL => true,
            default => false,
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // SubTask 13.5: 死块消除
    // ═══════════════════════════════════════════════════════════════

    /**
     * 死块消除：从 entry 做 BFS 标记可达块，删除不可达块。
     * 同时更新前驱/后继关系，清理 Phi 节点中对已删除块的引用。
     *
     * @param SSAFunction $func
     */
    public function deadBlockElimination(SSAFunction $func): void
    {
        $entry = $func->entryBlockId;
        if ($entry < 0 || !isset($func->blocks[$entry])) {
            return;
        }

        // BFS 标记可达块
        $reachable = [];
        $queue = [$entry];
        while (!empty($queue)) {
            $bid = array_shift($queue);
            if (isset($reachable[$bid])) {
                continue;
            }
            $reachable[$bid] = true;
            $block = $func->blocks[$bid] ?? null;
            if ($block === null) {
                continue;
            }
            foreach ($block->successors as $succ) {
                if (!isset($reachable[$succ]) && isset($func->blocks[$succ])) {
                    $queue[] = $succ;
                }
            }
        }

        // 找出不可达块
        $unreachable = [];
        foreach ($func->blocks as $bid => $_) {
            if (!isset($reachable[$bid])) {
                $unreachable[$bid] = true;
            }
        }

        if (empty($unreachable)) {
            return;
        }

        // 删除不可达块
        foreach (array_keys($unreachable) as $bid) {
            unset($func->blocks[$bid]);
        }

        // 更新剩余块的前驱/后继列表（移除对已删除块的引用）
        foreach ($func->blocks as $block) {
            $block->successors = array_values(array_filter(
                $block->successors,
                fn($s) => !isset($unreachable[$s]),
            ));
            $block->predecessors = array_values(array_filter(
                $block->predecessors,
                fn($p) => !isset($unreachable[$p]),
            ));
        }

        // 清理 Phi 节点：移除引用已删除块的 incoming entry
        foreach ($func->blocks as $block) {
            $newInsts = [];
            foreach ($block->instructions as $inst) {
                if ($inst->op === OpCode::PHI) {
                    $blocks = $inst->extra['blocks'] ?? [];
                    $newOperands = [];
                    $newBlocks = [];
                    foreach ($inst->operands as $i => $v) {
                        $b = $blocks[$i] ?? -1;
                        if (!isset($unreachable[$b])) {
                            $newOperands[] = $v;
                            $newBlocks[] = $b;
                        }
                    }
                    $inst->operands = $newOperands;
                    $inst->extra['blocks'] = $newBlocks;
                    // 如果 Phi 没有 incoming 了，删除该 Phi
                    if (empty($newOperands)) {
                        continue;
                    }
                }
                $newInsts[] = $inst;
            }
            $block->instructions = $newInsts;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SubTask 13.6: Phi 简化
    // ═══════════════════════════════════════════════════════════════

    /**
     * Phi 简化：
     * 1. 移除自引用 entry（incoming value === phi dst）
     * 2. 所有 incoming 相同 → 替换为该 value
     * 3. 只有一个 incoming → 替换为该 value
     *
     * @param SSAFunction $func
     */
    public function phiSimplification(SSAFunction $func): void
    {
        $replacements = []; // oldVal => newVal

        foreach ($func->blocks as $block) {
            $newInsts = [];
            foreach ($block->instructions as $inst) {
                if ($inst->op !== OpCode::PHI) {
                    $newInsts[] = $inst;
                    continue;
                }

                // 移除自引用
                $blocks = $inst->extra['blocks'] ?? [];
                $newOperands = [];
                $newBlocks = [];
                foreach ($inst->operands as $i => $v) {
                    if ($v === $inst->dst) {
                        continue; // 自引用
                    }
                    $newOperands[] = $v;
                    $newBlocks[] = $blocks[$i] ?? -1;
                }

                // 唯一化 incoming values
                $unique = array_unique($newOperands);

                if (count($unique) === 1) {
                    // 所有 incoming 相同（或只剩一个）→ 替换
                    $replacements[$inst->dst] = $unique[0];
                    // 跳过此 PHI（删除）
                } elseif (count($unique) === 0) {
                    // 全是自引用或空 → 保留（无 incoming 无法简化）
                    $inst->operands = $newOperands;
                    $inst->extra['blocks'] = $newBlocks;
                    $newInsts[] = $inst;
                } else {
                    // 多个不同 incoming → 保留 PHI（自引用已移除）
                    $inst->operands = $newOperands;
                    $inst->extra['blocks'] = $newBlocks;
                    $newInsts[] = $inst;
                }
            }
            $block->instructions = $newInsts;
        }

        // 应用替换（传递解析，处理链式替换）
        if (!empty($replacements)) {
            foreach ($func->blocks as $block) {
                foreach ($block->instructions as $inst) {
                    foreach ($inst->operands as $i => $op) {
                        $cur = $op;
                        // 传递解析直到无替换
                        while (isset($replacements[$cur])) {
                            $cur = $replacements[$cur];
                        }
                        $inst->operands[$i] = $cur;
                    }
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SubTask 13.7: 综合运行
    // ═══════════════════════════════════════════════════════════════

    /**
     * 顺序执行所有优化 Pass：
     * 常量折叠 → 死块消除 → 死代码消除 → Phi 简化
     *
     * @param SSAFunction $func
     */
    public function runAll(SSAFunction $func): void
    {
        $this->constantFolding($func);
        $this->deadBlockElimination($func);
        $this->deadCodeElimination($func);
        $this->phiSimplification($func);
    }

    /**
     * 重复执行 runAll 直到 fixpoint（无变化）或达到最大迭代次数。
     *
     * @param SSAFunction $func
     * @param int $maxIterations
     */
    public function runUntilFixpoint(SSAFunction $func, int $maxIterations = 10): void
    {
        for ($i = 0; $i < $maxIterations; $i++) {
            $before = dumpSSAFunction($func);
            $this->runAll($func);
            $after = dumpSSAFunction($func);
            if ($before === $after) {
                break;
            }
        }
    }
}
